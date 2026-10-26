<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ChatConversation;
use App\Models\Chunk;
use App\Models\Message;
use App\Services\Answer\AnswerService;
use App\Services\Answer\EvidencePacket;
use App\Services\Answer\FollowUpResolver;
use App\Services\Answer\IntentClassifier;
use App\Services\Answer\SmallTalk;
use App\Services\GeminiService;
use App\Services\PineconeService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Throwable;

class ChatController extends Controller
{
    private const SYSTEM_INSTRUCTION = <<<'SYS'
    You are a document Q&A assistant. Answer strictly and only using the CONTEXT provided below, which was retrieved from the user's uploaded documents.
    If the answer is not contained in the context, reply exactly: "I don't have information about that in the uploaded documents."
    Never use outside knowledge, never fabricate facts, and never invent citations. Keep answers concise and directly responsive to the question.
    SYS;

    public function store(Request $request, GeminiService $gemini, PineconeService $pinecone)
    {
        $data = $request->validate([
            'message' => 'required|string|max:4000',
            'conversation_id' => 'nullable|integer|exists:chat_conversations,id',
        ]);

        $conversation = isset($data['conversation_id'])
            ? ChatConversation::findOrFail($data['conversation_id'])
            : ChatConversation::create(['title' => Str::limit($data['message'], 50)]);

        $userMessage = $conversation->messages()->create([
            'role' => 'user',
            'content' => $data['message'],
        ]);

        return response()->stream(function () use ($gemini, $pinecone, $data, $conversation, $userMessage) {
            $this->sendEvent('meta', ['conversation_id' => $conversation->id]);

            if (config('services.answer.verified')) {
                $this->streamVerified($data['message'], $conversation, $userMessage, $gemini, $pinecone);

                return;
            }

            $this->sendStep('understand', 'completed', 'Understanding your question', 'Question: "'.$data['message'].'"');

            try {
                $queryVector = $this->runStep(
                    'embed',
                    'Embedding your question',
                    'Calling Gemini ('.config('services.gemini.embed_model').') to turn your question into a vector for semantic search.',
                    function () use ($gemini, $data) {
                        $vector = $gemini->embed($data['message']);

                        return [$vector, 'Generated a '.count($vector).'-dimension embedding of your question.'];
                    }
                );

                $matches = $this->runStep(
                    'search',
                    'Searching your documents',
                    'Querying the Pinecone index ("'.config('services.pinecone.index').'") for the top 5 most relevant passages.',
                    function () use ($pinecone, $queryVector) {
                        $found = $pinecone->query($queryVector, 5);
                        $detail = count($found) > 0
                            ? count($found).' matching passage(s) found across your uploaded documents.'
                            : 'No matching passages found in your uploaded documents.';

                        return [$found, $detail];
                    }
                );

                [$context, $sources] = $this->buildContext($matches);

                $this->sendStep(
                    'results',
                    'completed',
                    count($sources) > 0 ? 'Found '.count($sources).' relevant passage(s)' : 'No relevant passages found',
                    count($sources) > 0 ? $this->formatSourceLines($sources) : 'None of your uploaded documents matched this question closely enough. The answer below may say so.'
                );

                $chatModel = config('services.gemini.chat_model');
                $prompt = "CONTEXT:\n{$context}\n\nQUESTION:\n{$data['message']}";

                $full = $this->runStep(
                    'generate',
                    'Generating your answer',
                    "Asking Gemini ({$chatModel}) to answer using only the retrieved context above.",
                    function () use ($gemini, $prompt) {
                        $text = $gemini->streamGenerate(self::SYSTEM_INSTRUCTION, $prompt, function (string $delta) {
                            $this->sendEvent('token', ['token' => $delta]);
                        });

                        return [$text, 'Answer generated ('.str_word_count($text).' words).'];
                    }
                );
            } catch (Throwable $e) {
                report($e);

                $message = str_contains($e->getMessage(), 'UNAVAILABLE') || str_contains($e->getMessage(), '503')
                    ? 'The model is temporarily overloaded. Please try asking again in a moment.'
                    : 'Something went wrong while answering. Please try again.';

                $conversation->messages()->create([
                    'role' => 'assistant',
                    'content' => $message,
                    'sources' => [],
                ]);

                $this->sendEvent('error', ['message' => $message]);

                return;
            }

            $assistant = $conversation->messages()->create([
                'role' => 'assistant',
                'content' => $full,
                'sources' => $sources,
            ]);

            $this->sendEvent('done', ['sources' => $sources, 'message_id' => $assistant->id]);
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'X-Accel-Buffering' => 'no',
            'Connection' => 'keep-alive',
        ]);
    }

    /**
     * Verified pipeline: small talk shortcut, intent + follow-up resolution, retrieval, strict-JSON
     * generation, code-side validation. Emits the same SSE events as the legacy flow.
     */
    private function streamVerified(
        string $message,
        ChatConversation $conversation,
        Message $userMessage,
        GeminiService $gemini,
        PineconeService $pinecone,
    ): void {
        $smallTalk = app(SmallTalk::class)->reply($message);

        if ($smallTalk !== null) {
            $this->sendStep('understand', 'completed', 'Understanding your question', 'This is small talk, so no document search or model call is needed.');
            $this->finishVerified($conversation, [
                'status' => AnswerService::CHITCHAT,
                'content' => $smallTalk,
                'sources' => [],
                'claims' => [],
                'conflicts' => [],
                'calculations' => [],
                'warnings' => [],
            ], []);

            return;
        }

        $intent = app(IntentClassifier::class)->classify($message);
        $previous = $conversation->messages()
            ->where('role', 'user')
            ->where('id', '<', $userMessage->id)
            ->orderByDesc('id')
            ->first();
        $resolved = app(FollowUpResolver::class)->resolve($message, $previous?->content);

        $this->sendStep(
            'understand',
            'completed',
            'Understanding your question',
            'Question: "'.$message.'"'."\nType: {$intent}".($resolved['follow_up'] ? "\nTreated as a follow-up to your previous question." : '')
        );

        $trace = ['intent' => $intent, 'query_used' => $resolved['query'], 'follow_up_used' => $resolved['follow_up']];

        try {
            $queryVector = $this->runStep(
                'embed',
                'Embedding your question',
                'Calling Gemini ('.config('services.gemini.embed_model').') to turn your question into a vector for semantic search.',
                function () use ($gemini, $resolved) {
                    $vector = $gemini->embed($resolved['query']);

                    return [$vector, 'Generated a '.count($vector).'-dimension embedding of your question.'];
                }
            );

            $topK = app(IntentClassifier::class)->topK($intent, (int) config('services.answer.top_k'));

            $matches = $this->runStep(
                'search',
                'Searching your documents',
                'Querying the Pinecone index ("'.config('services.pinecone.index').'") for the top '.$topK.' most relevant passages.',
                function () use ($pinecone, $queryVector, $topK) {
                    $found = $pinecone->query($queryVector, $topK);
                    $detail = count($found) > 0
                        ? count($found).' matching passage(s) found across your uploaded documents.'
                        : 'No matching passages found in your uploaded documents.';

                    return [$found, $detail];
                }
            );

            $carried = $resolved['follow_up'] ? $this->carriedPassages($conversation, $userMessage) : [];
            $packet = EvidencePacket::fromMatches($matches, $carried, (int) config('services.answer.max_evidence'));
            $trace['retrieved_passages'] = count($matches);
            $trace['carried_passages'] = count($carried);

            $count = count($packet->all());
            $this->sendStep(
                'results',
                'completed',
                $count > 0 ? "Found {$count} relevant passage(s)" : 'No relevant passages found',
                $count > 0
                    ? $this->formatSourceLines(array_map(fn ($i) => $i, array_values($packet->all())))
                    : 'None of your uploaded documents matched this question closely enough.'
            );

            $userTexts = $conversation->messages()->where('role', 'user')->pluck('content')->all();

            $result = $this->runStep(
                'generate',
                'Generating your answer',
                'Asking Gemini ('.config('services.gemini.chat_model').') to answer in structured form using only the retrieved passages, then checking every claim against them.',
                function () use ($message, $previous, $packet, $userTexts, $resolved) {
                    $result = app(AnswerService::class)->answer(
                        $message,
                        $resolved['follow_up'] ? $previous?->content : null,
                        $packet,
                        $userTexts
                    );

                    $detail = match ($result['status']) {
                        AnswerService::ANSWERED => count($result['claims']).' verified statement(s) with citations.',
                        AnswerService::INSUFFICIENT => 'The documents do not contain a verifiable answer.',
                        default => 'The model output could not be used.',
                    };

                    return [$result, $detail];
                }
            );
        } catch (Throwable $e) {
            report($e);

            $text = str_contains($e->getMessage(), 'UNAVAILABLE') || str_contains($e->getMessage(), '503')
                ? 'The model is temporarily overloaded. Please try asking again in a moment.'
                : 'Something went wrong while answering. Please try again.';

            $conversation->messages()->create([
                'role' => 'assistant',
                'content' => $text,
                'sources' => [],
                'meta' => ['status' => AnswerService::PROVIDER_ERROR, 'trace' => $trace],
            ]);

            $this->sendEvent('error', ['message' => $text]);

            return;
        }

        $this->finishVerified($conversation, $result, $trace);
    }

    /**
     * @param  array<string, mixed>  $result
     * @param  array<string, mixed>  $trace
     */
    private function finishVerified(ChatConversation $conversation, array $result, array $trace): void
    {
        foreach (preg_split('/(?<=\n)/', $result['content'], -1, PREG_SPLIT_NO_EMPTY) as $piece) {
            $this->sendEvent('token', ['token' => $piece]);
        }

        $meta = [
            'status' => $result['status'],
            'claims' => $result['claims'],
            'conflicts' => $result['conflicts'],
            'calculations' => $result['calculations'],
            'warnings' => $result['warnings'],
            'trace' => $trace,
        ];

        $assistant = $conversation->messages()->create([
            'role' => 'assistant',
            'content' => $result['content'],
            'sources' => $result['sources'],
            'meta' => $meta,
        ]);

        $this->sendEvent('done', ['sources' => $result['sources'], 'message_id' => $assistant->id] + $meta);
    }

    /**
     * Passages the previous answer cited, reused so a short follow-up still sees the same document text.
     * Only document text is carried over, never the earlier model answer.
     *
     * @return array<int, array<string, mixed>>
     */
    private function carriedPassages(ChatConversation $conversation, Message $userMessage): array
    {
        $previousAnswer = $conversation->messages()
            ->where('role', 'assistant')
            ->where('id', '<', $userMessage->id)
            ->orderByDesc('id')
            ->first();

        // Only an answered reply cited anything; a refusal's sources are just the closest misses.
        $ids = ($previousAnswer?->meta['status'] ?? null) === AnswerService::ANSWERED
            ? collect($previousAnswer->sources ?? [])->pluck('chunk_id')->filter()->all()
            : [];

        if ($ids === []) {
            return [];
        }

        return Chunk::with('document')
            ->whereIn('pinecone_vector_id', $ids)
            ->get()
            ->map(fn (Chunk $chunk) => [
                'id' => $chunk->pinecone_vector_id,
                'score' => null,
                'metadata' => [
                    'document_name' => $chunk->document?->original_name,
                    'page' => $chunk->page_number,
                    'text' => $chunk->content,
                ],
            ])
            ->all();
    }

    /**
     * Runs one pipeline stage as a visible process step: emits `in_progress`, executes
     * $work (which returns [$result, $completedDetail]), then emits `completed`. On
     * failure it emits `error` on this step and rethrows so the caller's catch block
     * can finish the response gracefully.
     */
    private function runStep(string $id, string $title, string $inProgressDetail, callable $work): mixed
    {
        $this->sendStep($id, 'in_progress', $title, $inProgressDetail);

        try {
            [$result, $completedDetail] = $work();
        } catch (Throwable $e) {
            $this->sendStep($id, 'error', $title, $this->friendlyStepError($e));

            throw $e;
        }

        $this->sendStep($id, 'completed', $title, $completedDetail);

        return $result;
    }

    private function friendlyStepError(Throwable $e): string
    {
        return str_contains($e->getMessage(), 'UNAVAILABLE') || str_contains($e->getMessage(), '503')
            ? 'The model is temporarily overloaded and this step could not complete.'
            : 'This step failed unexpectedly.';
    }

    private function sendStep(string $id, string $status, string $title, string $detail): void
    {
        $this->sendEvent('step', [
            'id' => $id,
            'status' => $status,
            'title' => $title,
            'detail' => $detail,
        ]);
    }

    private function sendEvent(string $event, array $payload): void
    {
        echo "event: {$event}\n";
        echo 'data: '.json_encode($payload)."\n\n";

        if (ob_get_level() > 0) {
            ob_flush();
        }

        flush();
    }

    /**
     * @return array{0: string, 1: array<int, array<string, mixed>>}
     */
    private function buildContext(array $matches): array
    {
        if (empty($matches)) {
            return ['(no relevant context found in the uploaded documents)', []];
        }

        $parts = [];
        $sources = [];

        foreach ($matches as $i => $match) {
            $meta = $match['metadata'] ?? [];
            $text = $meta['text'] ?? '';
            $docName = $meta['document_name'] ?? 'Unknown document';
            $page = $meta['page'] ?? null;

            $parts[] = '['.($i + 1)."] ({$docName}, page {$page})\n{$text}";

            $sources[] = [
                'document_name' => $docName,
                'page' => $page,
                'snippet' => Str::limit($text, 240),
                'score' => $match['score'] ?? null,
            ];
        }

        return [implode("\n\n", $parts), $sources];
    }

    /**
     * @param  array<int, array<string, mixed>>  $sources
     */
    private function formatSourceLines(array $sources): string
    {
        return collect($sources)
            ->map(function (array $source, int $i) {
                $page = $source['page'] ?? '?';
                $score = $source['score'] !== null ? round($source['score'] * 100).'%' : 'n/a';

                return ($i + 1).". {$source['document_name']} — page {$page} (relevance {$score})";
            })
            ->implode("\n");
    }
}
