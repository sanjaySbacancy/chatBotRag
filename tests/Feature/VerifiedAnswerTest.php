<?php

namespace Tests\Feature;

use App\Models\ChatConversation;
use App\Models\Message;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class VerifiedAnswerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.answer.verified' => true]);
    }

    private function fakeProviders(?array $modelJson): void
    {
        $fakes = [
            'generativelanguage.googleapis.com/*embedContent*' => Http::response(['embedding' => ['values' => array_fill(0, 768, 0.01)]]),
            'api.pinecone.io/*' => Http::response(['host' => 'fake-host.pinecone.io', 'status' => ['ready' => true]]),
            'fake-host.pinecone.io/query' => Http::response(['matches' => [
                ['id' => 'doc1-chunk0', 'score' => 0.9, 'metadata' => ['document_id' => 1, 'document_name' => 'Policy.pdf', 'page' => 1, 'text' => 'Employees receive birthday leave of 0.5 day.']],
                ['id' => 'doc1-chunk1', 'score' => 0.8, 'metadata' => ['document_id' => 1, 'document_name' => 'Policy.pdf', 'page' => 8, 'text' => 'Birthday leave is not allowed on weekends.']],
            ]]),
        ];

        if ($modelJson !== null) {
            $payload = json_encode(['candidates' => [['content' => ['parts' => [['text' => json_encode($modelJson)]]]]]]);
            $fakes['generativelanguage.googleapis.com/*streamGenerateContent*'] = Http::response("data: {$payload}\n\n", 200, ['Content-Type' => 'text/event-stream']);
        }

        Http::fake($fakes);
    }

    private function claim(string $text, string $quote, string $id): array
    {
        return ['text' => $text, 'type' => 'quote', 'citations' => [['evidence_id' => $id, 'quote' => $quote]]];
    }

    public function test_answers_with_verified_claims_and_cites_both_pages(): void
    {
        $this->fakeProviders([
            'status' => 'answered',
            'claims' => [
                $this->claim('Employees get 0.5 day of birthday leave.', 'birthday leave of 0.5 day', 'E1'),
                $this->claim('It is not allowed on weekends.', 'not allowed on weekends', 'E2'),
            ],
        ]);

        $content = $this->postJson('/api/chat', ['message' => 'What is the birthday leave?'])->streamedContent();

        $this->assertStringContainsString('"id":"generate","status":"completed"', $content);
        $this->assertStringContainsString('event: done', $content);

        $assistant = Message::where('role', 'assistant')->firstOrFail();
        $this->assertSame('answered', $assistant->meta['status']);
        $this->assertStringContainsString('0.5 day of birthday leave. [1]', $assistant->content);
        $this->assertSame([1, 8], array_column($assistant->sources, 'page'));
        $this->assertSame('doc1-chunk0', $assistant->sources[0]['chunk_id']);
    }

    public function test_unverifiable_claims_become_a_plain_refusal(): void
    {
        $this->fakeProviders([
            'status' => 'answered',
            'claims' => [$this->claim('Employees get 3 days.', 'three days of leave', 'E1')],
        ]);

        $this->postJson('/api/chat', ['message' => 'How much leave?'])->streamedContent();

        $assistant = Message::where('role', 'assistant')->firstOrFail();
        $this->assertSame('insufficient_evidence', $assistant->meta['status']);
        $this->assertStringStartsWith("I couldn't find the answer", $assistant->content);
        $this->assertStringNotContainsString('3 days', $assistant->content);
    }

    public function test_non_json_model_output_is_not_shown(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*embedContent*' => Http::response(['embedding' => ['values' => array_fill(0, 768, 0.01)]]),
            'api.pinecone.io/*' => Http::response(['host' => 'fake-host.pinecone.io', 'status' => ['ready' => true]]),
            'fake-host.pinecone.io/query' => Http::response(['matches' => [
                ['id' => 'doc1-chunk0', 'score' => 0.9, 'metadata' => ['document_name' => 'Policy.pdf', 'page' => 1, 'text' => 'Some text.']],
            ]]),
            'generativelanguage.googleapis.com/*streamGenerateContent*' => Http::response(
                'data: '.json_encode(['candidates' => [['content' => ['parts' => [['text' => 'free text guess']]]]]])."\n\n"
            ),
        ]);

        $this->postJson('/api/chat', ['message' => 'Anything here?'])->streamedContent();

        $assistant = Message::where('role', 'assistant')->firstOrFail();
        $this->assertSame('invalid_model_output', $assistant->meta['status']);
        $this->assertStringNotContainsString('free text guess', $assistant->content);
    }

    public function test_no_matching_passages_refuses_without_calling_the_model(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*embedContent*' => Http::response(['embedding' => ['values' => array_fill(0, 768, 0.01)]]),
            'api.pinecone.io/*' => Http::response(['host' => 'fake-host.pinecone.io', 'status' => ['ready' => true]]),
            'fake-host.pinecone.io/query' => Http::response(['matches' => []]),
        ]);

        $this->postJson('/api/chat', ['message' => 'What is the moon made of?'])->streamedContent();

        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'streamGenerateContent'));
        $this->assertSame('insufficient_evidence', Message::where('role', 'assistant')->firstOrFail()->meta['status']);
    }

    public function test_small_talk_skips_search_and_the_model(): void
    {
        Http::fake();

        $content = $this->postJson('/api/chat', ['message' => 'Hello!'])->streamedContent();

        Http::assertNothingSent();
        $this->assertStringContainsString('event: done', $content);
        $this->assertSame('chitchat', Message::where('role', 'assistant')->firstOrFail()->meta['status']);
    }

    public function test_short_follow_up_searches_with_the_previous_question(): void
    {
        $this->fakeProviders([
            'status' => 'answered',
            'claims' => [$this->claim('Employees get 0.5 day of birthday leave.', 'birthday leave of 0.5 day', 'E1')],
        ]);

        $first = $this->postJson('/api/chat', ['message' => 'What is the birthday leave?']);
        $first->streamedContent();
        $conversation = ChatConversation::firstOrFail();

        $this->postJson('/api/chat', ['message' => 'and weekends?', 'conversation_id' => $conversation->id])->streamedContent();

        $assistant = Message::where('role', 'assistant')->latest('id')->firstOrFail();
        $this->assertTrue($assistant->meta['trace']['follow_up_used']);
        $this->assertSame('What is the birthday leave? and weekends?', $assistant->meta['trace']['query_used']);
    }

    public function test_feedback_is_stored_on_assistant_messages_only(): void
    {
        $this->fakeProviders(['status' => 'insufficient_evidence', 'missing_information' => ['x']]);
        $this->postJson('/api/chat', ['message' => 'Question?'])->streamedContent();

        $assistant = Message::where('role', 'assistant')->firstOrFail();
        $user = Message::where('role', 'user')->firstOrFail();

        $this->postJson("/api/messages/{$assistant->id}/feedback", ['rating' => 'down', 'reason' => 'Missed page 8'])->assertCreated();
        $this->assertDatabaseHas('message_feedback', ['message_id' => $assistant->id, 'rating' => 'down', 'reason' => 'Missed page 8']);

        $this->postJson("/api/messages/{$user->id}/feedback", ['rating' => 'up'])->assertStatus(422);
        $this->postJson("/api/messages/{$assistant->id}/feedback", ['rating' => 'meh'])->assertStatus(422);
    }
}
