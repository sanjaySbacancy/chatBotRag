<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ChatConversation;
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

        $conversation->messages()->create([
            'role' => 'user',
            'content' => $data['message'],
        ]);

        return response()->stream(function () use ($gemini, $pinecone, $data, $conversation) {
            $this->sendEvent('meta', ['conversation_id' => $conversation->id]);

            $this->sendStep('understand', 'completed', 'Understanding your question', 'Question: "'.$data['message'].'"');

            try {
                $queryVector = $this->runStep(
                    'embed',
                    'Embedding your question',
                    'Calling Gemini ('.config('services.gemini.embed_model').") to turn your question into a vector for semantic search.",
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

            $conversation->messages()->create([
                'role' => 'assistant',
                'content' => $full,
                'sources' => $sources,
            ]);

            $this->sendEvent('done', ['sources' => $sources]);
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'X-Accel-Buffering' => 'no',
            'Connection' => 'keep-alive',
        ]);
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
