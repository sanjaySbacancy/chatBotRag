<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class PineconeService
{
    private const CONTROL_BASE = 'https://api.pinecone.io';

    private string $key;

    private string $index;

    private string $cloud;

    private string $region;

    private int $dimension;

    public function __construct()
    {
        $this->key = (string) config('services.pinecone.key');
        $this->index = (string) config('services.pinecone.index');
        $this->cloud = (string) config('services.pinecone.cloud');
        $this->region = (string) config('services.pinecone.region');
        $this->dimension = (int) config('services.pinecone.dimension');
    }

    private function controlHeaders(): array
    {
        return [
            'Api-Key' => $this->key,
            'X-Pinecone-API-Version' => '2025-04',
            'Content-Type' => 'application/json',
        ];
    }

    public function ensureIndex(): void
    {
        $describe = Http::withHeaders($this->controlHeaders())
            ->get(self::CONTROL_BASE."/indexes/{$this->index}");

        if ($describe->ok()) {
            return;
        }

        $create = Http::withHeaders($this->controlHeaders())
            ->post(self::CONTROL_BASE.'/indexes', [
                'name' => $this->index,
                'dimension' => $this->dimension,
                'metric' => 'cosine',
                'spec' => [
                    'serverless' => [
                        'cloud' => $this->cloud,
                        'region' => $this->region,
                    ],
                ],
            ]);

        if ($create->failed()) {
            throw new RuntimeException('Pinecone index creation failed: '.$create->body());
        }

        for ($i = 0; $i < 30; $i++) {
            $describe = Http::withHeaders($this->controlHeaders())->get(self::CONTROL_BASE."/indexes/{$this->index}");

            if ($describe->ok() && ($describe->json('status.ready') === true)) {
                return;
            }

            sleep(2);
        }
    }

    private function host(): string
    {
        return Cache::remember("pinecone_host:{$this->index}", 3600, function () {
            $response = Http::withHeaders($this->controlHeaders())
                ->get(self::CONTROL_BASE."/indexes/{$this->index}");

            if ($response->failed()) {
                throw new RuntimeException('Could not resolve Pinecone index host: '.$response->body());
            }

            return $response->json('host');
        });
    }

    private function dataHeaders(): array
    {
        return [
            'Api-Key' => $this->key,
            'X-Pinecone-API-Version' => '2025-04',
            'Content-Type' => 'application/json',
        ];
    }

    /**
     * @param  array<int, array{id: string, values: array<int, float>, metadata: array<string, mixed>}>  $vectors
     */
    public function upsert(array $vectors): void
    {
        $response = Http::withHeaders($this->dataHeaders())
            ->post('https://'.$this->host().'/vectors/upsert', [
                'vectors' => $vectors,
                'namespace' => 'default',
            ]);

        if ($response->failed()) {
            throw new RuntimeException('Pinecone upsert failed: '.$response->body());
        }
    }

    /**
     * @param  array<int, float>  $vector
     * @return array<int, array{id: string, score: float, metadata: array<string, mixed>}>
     */
    public function query(array $vector, int $topK = 5): array
    {
        $response = Http::withHeaders($this->dataHeaders())
            ->post('https://'.$this->host().'/query', [
                'vector' => $vector,
                'topK' => $topK,
                'includeMetadata' => true,
                'namespace' => 'default',
            ]);

        if ($response->failed()) {
            throw new RuntimeException('Pinecone query failed: '.$response->body());
        }

        return $response->json('matches') ?? [];
    }

    public function deleteByDocument(int $documentId): void
    {
        Http::withHeaders($this->dataHeaders())
            ->post('https://'.$this->host().'/vectors/delete', [
                'filter' => ['document_id' => $documentId],
                'namespace' => 'default',
            ]);
    }
}
