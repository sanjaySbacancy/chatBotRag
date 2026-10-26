<?php

namespace App\Services\Answer;

/**
 * Numbered passages E1..En handed to the model and used to verify its claims.
 */
class EvidencePacket
{
    /** @var array<string, array{id: string, vector_id: ?string, document_name: string, page: mixed, text: string, score: ?float}> */
    private array $items = [];

    /**
     * @param  array<int, array<string, mixed>>  $matches  Pinecone-shaped matches (id, score, metadata)
     * @param  array<int, array<string, mixed>>  $carried  same shape, passages carried over from the previous answer
     */
    public static function fromMatches(array $matches, array $carried = [], int $max = 12): self
    {
        $packet = new self;
        $seen = [];
        $n = 0;

        foreach ([...$matches, ...$carried] as $match) {
            $vectorId = $match['id'] ?? null;
            $text = trim((string) ($match['metadata']['text'] ?? ''));

            if ($text === '' || ($vectorId !== null && isset($seen[$vectorId]))) {
                continue;
            }

            if ($n >= $max) {
                break;
            }

            $seen[$vectorId] = true;
            $n++;
            $id = 'E'.$n;

            $packet->items[$id] = [
                'id' => $id,
                'vector_id' => $vectorId,
                'document_name' => (string) ($match['metadata']['document_name'] ?? 'Unknown document'),
                'page' => $match['metadata']['page'] ?? null,
                'text' => $text,
                'score' => $match['score'] ?? null,
            ];
        }

        return $packet;
    }

    public function isEmpty(): bool
    {
        return $this->items === [];
    }

    public function has(string $id): bool
    {
        return isset($this->items[$id]);
    }

    /**
     * @return array{id: string, vector_id: ?string, document_name: string, page: mixed, text: string, score: ?float}|null
     */
    public function get(string $id): ?array
    {
        return $this->items[$id] ?? null;
    }

    /**
     * @return array<string, array{id: string, vector_id: ?string, document_name: string, page: mixed, text: string, score: ?float}>
     */
    public function all(): array
    {
        return $this->items;
    }

    /**
     * Evidence is wrapped in markers; the prompt tells the model it is untrusted data.
     */
    public function render(): string
    {
        $parts = [];

        foreach ($this->items as $item) {
            $parts[] = "[{$item['id']}] document: {$item['document_name']} | page: ".($item['page'] ?? 'unknown')."\n{$item['text']}";
        }

        return "<<<EVIDENCE_START\n".implode("\n\n", $parts)."\nEVIDENCE_END>>>";
    }
}
