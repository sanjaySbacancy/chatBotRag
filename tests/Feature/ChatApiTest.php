<?php

namespace Tests\Feature;

use App\Models\ChatConversation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ChatApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_chat_streams_a_sourced_answer_and_persists_messages(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*embedContent*' => Http::response([
                'embedding' => ['values' => array_fill(0, 768, 0.01)],
            ]),
            'generativelanguage.googleapis.com/*streamGenerateContent*' => Http::response(
                $this->sseChunk('The answer ').$this->sseChunk('lives in the docs.'),
                200,
                ['Content-Type' => 'text/event-stream']
            ),
            'api.pinecone.io/*' => Http::response(['host' => 'fake-host.pinecone.io', 'status' => ['ready' => true]]),
            'fake-host.pinecone.io/query' => Http::response([
                'matches' => [
                    [
                        'id' => 'doc1-chunk0',
                        'score' => 0.91,
                        'metadata' => [
                            'document_id' => 1,
                            'document_name' => 'ARCHITECTURE.pdf',
                            'page' => 3,
                            'text' => 'The system uses a layered architecture.',
                        ],
                    ],
                ],
            ]),
        ]);

        $response = $this->postJson('/api/chat', ['message' => 'What architecture is used?']);

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/event-stream; charset=UTF-8');
        $response->streamedContent();

        $conversation = ChatConversation::firstOrFail();
        $this->assertCount(2, $conversation->messages);

        $this->assertDatabaseHas('messages', [
            'chat_conversation_id' => $conversation->id,
            'role' => 'user',
            'content' => 'What architecture is used?',
        ]);

        $assistant = $conversation->messages()->where('role', 'assistant')->firstOrFail();
        $this->assertSame('The answer lives in the docs.', $assistant->content);
        $this->assertSame('ARCHITECTURE.pdf', $assistant->sources[0]['document_name']);
        $this->assertSame(3, $assistant->sources[0]['page']);
    }

    private function sseChunk(string $text): string
    {
        $payload = json_encode([
            'candidates' => [
                ['content' => ['parts' => [['text' => $text]]]],
            ],
        ]);

        return "data: {$payload}\n\n";
    }
}
