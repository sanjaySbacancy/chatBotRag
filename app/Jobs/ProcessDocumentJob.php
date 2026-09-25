<?php

namespace App\Jobs;

use App\Models\Chunk;
use App\Models\Document;
use App\Services\DocumentChunker;
use App\Services\GeminiService;
use App\Services\PineconeService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class ProcessDocumentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, SerializesModels;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(private readonly int $documentId) {}

    public function handle(DocumentChunker $chunker, GeminiService $gemini, PineconeService $pinecone): void
    {
        $document = Document::findOrFail($this->documentId);
        $document->update(['status' => 'processing']);

        try {
            $pinecone->ensureIndex();

            $extracted = $chunker->extract(Storage::path($document->stored_path));
            $chunks = $extracted['chunks'];

            if (empty($chunks)) {
                throw new RuntimeException('No extractable text found in this PDF.');
            }

            $chunkIndex = 0;

            foreach (array_chunk($chunks, 20) as $batch) {
                $embeddings = $gemini->embedBatch(array_column($batch, 'content'));
                $vectors = [];
                $rows = [];

                foreach ($batch as $i => $chunkData) {
                    $vectorId = "doc{$document->id}-chunk{$chunkIndex}";

                    $vectors[] = [
                        'id' => $vectorId,
                        'values' => $embeddings[$i],
                        'metadata' => [
                            'document_id' => $document->id,
                            'document_name' => $document->original_name,
                            'page' => $chunkData['page'],
                            'text' => mb_substr($chunkData['content'], 0, 2000),
                        ],
                    ];

                    $rows[] = [
                        'document_id' => $document->id,
                        'chunk_index' => $chunkIndex,
                        'page_number' => $chunkData['page'],
                        'content' => $chunkData['content'],
                        'pinecone_vector_id' => $vectorId,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];

                    $chunkIndex++;
                }

                $pinecone->upsert($vectors);
                Chunk::insert($rows);
            }

            $document->update([
                'status' => 'ready',
                'pages' => $extracted['pages'],
                'chunk_count' => $chunkIndex,
                'error_message' => null,
            ]);
        } catch (Throwable $e) {
            $document->update([
                'status' => 'failed',
                'error_message' => mb_substr($e->getMessage(), 0, 1000),
            ]);
        }
    }
}
