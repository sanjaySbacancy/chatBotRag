<?php

namespace Tests\Feature;

use App\Models\ChatConversation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ChatProcessStepsTest extends TestCase
{
    use RefreshDatabase;

    public function test_multi_step_flow_emits_a_step_event_for_each_pipeline_stage(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*embedContent*' => Http::response([
                'embedding' => ['values' => array_fill(0, 768, 0.01)],
            ]),
            'generativelanguage.googleapis.com/*streamGenerateContent*' => Http::response(
                $this->sseChunk('Layered architecture.'),
                200,
                ['Content-Type' => 'text/event-stream']
            ),
            'api.pinecone.io/*' => Http::response(['host' => 'fake-host.pinecone.io', 'status' => ['ready' => true]]),
            'fake-host.pinecone.io/query' => Http::response([
                'matches' => [
                    [
                        'id' => 'doc1-chunk0',
                        'score' => 0.87,
                        'metadata' => [
                            'document_id' => 1,
                            'document_name' => 'ARCHITECTURE.pdf',
                            'page' => 2,
                            'text' => 'The system uses a layered architecture.',
                        ],
                    ],
                ],
            ]),
        ]);

        $response = $this->postJson('/api/chat', ['message' => 'What architecture is used?']);

        $response->assertOk();
        $content = $response->streamedContent();

        foreach (['understand', 'embed', 'search', 'results', 'generate'] as $stepId) {
            $this->assertStringContainsString('"id":"'.$stepId.'"', $content, "Missing step event for [{$stepId}]");
        }

        // Every real pipeline stage should transition in_progress -> completed.
        foreach (['embed', 'search', 'generate'] as $stepId) {
            $this->assertMatchesRegularExpression(
                '/"id":"'.$stepId.'","status":"in_progress"/',
                $content
            );
        }
        $this->assertSame(5, substr_count($content, '"status":"completed"'));
        $this->assertStringNotContainsString('"status":"error"', $content);

        // Steps arrive before the final `done` event (progressive reveal, not all-at-once).
        $this->assertLessThan(strpos($content, 'event: done'), strpos($content, '"id":"understand"'));
    }

    public function test_a_failing_pipeline_stage_marks_its_step_as_error_and_still_streams_gracefully(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*embedContent*' => Http::response(
                ['error' => ['message' => 'boom', 'status' => 'INTERNAL']],
                500
            ),
        ]);

        $response = $this->postJson('/api/chat', ['message' => 'Anything in the docs?']);

        $response->assertOk();
        $content = $response->streamedContent();

        $this->assertStringContainsString('"id":"understand","status":"completed"', $content);
        $this->assertMatchesRegularExpression('/"id":"embed","status":"in_progress"/', $content);
        $this->assertMatchesRegularExpression('/"id":"embed","status":"error"/', $content);
        $this->assertStringNotContainsString('"id":"search"', $content);
        $this->assertStringContainsString('event: error', $content);
        $this->assertStringNotContainsString('event: done', $content);

        $assistant = ChatConversation::firstOrFail()->messages()->where('role', 'assistant')->firstOrFail();
        $this->assertSame('Something went wrong while answering. Please try again.', $assistant->content);
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
