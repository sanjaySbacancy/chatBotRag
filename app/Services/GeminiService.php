<?php

namespace App\Services;

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class GeminiService
{
    private const BASE = 'https://generativelanguage.googleapis.com/v1beta';

    private const RETRY_TIMES = 4;

    private string $key;

    private string $embedModel;

    private string $chatModel;

    private int $embedDimension;

    public function __construct()
    {
        $this->key = (string) config('services.gemini.key');
        $this->embedModel = (string) config('services.gemini.embed_model');
        $this->chatModel = (string) config('services.gemini.chat_model');
        $this->embedDimension = (int) config('services.pinecone.dimension');
    }

    public function embed(string $text): array
    {
        try {
            $response = Http::retry(self::RETRY_TIMES, $this->backoff(...), $this->retryOn503(...))
                ->post(self::BASE."/models/{$this->embedModel}:embedContent?key={$this->key}", [
                    'content' => ['parts' => [['text' => $text]]],
                    'outputDimensionality' => $this->embedDimension,
                ]);
        } catch (RequestException $e) {
            throw new RuntimeException('Gemini embed failed: '.$e->response->body(), previous: $e);
        }

        return $response->json('embedding.values') ?? [];
    }

    /**
     * @param  string[]  $texts
     * @return array<int, array<int, float>>
     */
    public function embedBatch(array $texts): array
    {
        $requests = array_map(fn (string $text) => [
            'model' => "models/{$this->embedModel}",
            'content' => ['parts' => [['text' => $text]]],
            'outputDimensionality' => $this->embedDimension,
        ], $texts);

        try {
            $response = Http::retry(self::RETRY_TIMES, $this->backoff(...), $this->retryOn503(...))
                ->post(self::BASE."/models/{$this->embedModel}:batchEmbedContents?key={$this->key}", [
                    'requests' => $requests,
                ]);
        } catch (RequestException $e) {
            throw new RuntimeException('Gemini batch embed failed: '.$e->response->body(), previous: $e);
        }

        return array_map(
            fn (array $embedding) => $embedding['values'],
            $response->json('embeddings') ?? []
        );
    }

    /**
     * Streams a Gemini generation, invoking $onToken for every text delta.
     * Returns the full concatenated response text.
     */
    public function streamGenerate(string $systemInstruction, string $userPrompt, callable $onToken): string
    {
        $full = '';
        $buffer = '';

        try {
            $response = Http::withOptions(['stream' => true])
                ->retry(self::RETRY_TIMES, $this->backoff(...), $this->retryOn503(...))
                ->post(self::BASE."/models/{$this->chatModel}:streamGenerateContent?alt=sse&key={$this->key}", [
                    'systemInstruction' => ['parts' => [['text' => $systemInstruction]]],
                    'contents' => [['role' => 'user', 'parts' => [['text' => $userPrompt]]]],
                    'generationConfig' => ['temperature' => 0.2],
                ]);
        } catch (RequestException $e) {
            throw new RuntimeException('Gemini stream generate failed: '.$e->response->body(), previous: $e);
        }

        $body = $response->toPsrResponse()->getBody();

        $consumeEvents = function () use (&$buffer, &$full, $onToken) {
            while (($pos = strpos($buffer, "\n\n")) !== false) {
                $rawEvent = substr($buffer, 0, $pos);
                $buffer = substr($buffer, $pos + 2);

                foreach (explode("\n", $rawEvent) as $line) {
                    $line = trim($line);

                    if (! str_starts_with($line, 'data:')) {
                        continue;
                    }

                    $json = trim(substr($line, 5));

                    if ($json === '' || $json === '[DONE]') {
                        continue;
                    }

                    $data = json_decode($json, true);
                    $delta = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';

                    if ($delta !== '') {
                        $full .= $delta;
                        $onToken($delta);
                    }
                }
            }
        };

        while (! $body->eof()) {
            // Gemini's SSE stream delimits events with CRLF CRLF; normalize to LF LF so the
            // "\n\n" boundary check below works regardless of line-ending style.
            $buffer .= str_replace("\r\n", "\n", $body->read(1024));
            $consumeEvents();
        }

        $buffer .= "\n\n";
        $consumeEvents();

        return $full;
    }

    /**
     * Only retry on 503 (Gemini "high demand" / UNAVAILABLE) responses.
     * Laravel's retry `when` callback can also receive a ConnectionException, so this
     * must not narrow-type to RequestException or non-503 failures crash with a TypeError.
     */
    private function retryOn503(\Throwable $exception): bool
    {
        return $exception instanceof RequestException
            && $exception->response->status() === 503;
    }

    /**
     * Exponential backoff in milliseconds: 1000, 2000, 4000, capped at 8000.
     */
    private function backoff(int $attempt): int
    {
        return min(1000 * 2 ** ($attempt - 1), 8000);
    }
}
