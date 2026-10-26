<?php

namespace App\Services\Answer;

use Illuminate\Support\Str;

/**
 * Turns a validated result into the readable text + numbered sources the UI shows.
 */
class AnswerRenderer
{
    public const REFUSAL = "I couldn't find the answer to this in your uploaded documents.";

    /**
     * @param  array<string, mixed>  $validated  output of ClaimValidator::validate()
     * @return array{content: string, sources: array<int, array<string, mixed>>}
     */
    public function render(array $validated, EvidencePacket $packet): array
    {
        $numbering = $this->numbering($validated);
        $lines = [];

        foreach ($validated['claims'] as $claim) {
            $refs = $this->refs(array_column($claim['citations'], 'evidence_id'), $numbering);
            $tag = $claim['type'] === 'inference' ? ' (inference)' : '';
            $lines[] = "{$claim['text']}{$tag} {$refs}";
        }

        foreach ($validated['conflicts'] as $conflict) {
            $lines[] = 'Conflict: '.$conflict['description'].' '.$this->refs($conflict['evidence_ids'], $numbering);
        }

        foreach ($validated['calculations'] as $calc) {
            $lines[] = $this->renderCalculation($calc, $numbering);
        }

        $sources = [];

        foreach ($numbering as $evidenceId => $n) {
            $item = $packet->get($evidenceId);
            $sources[] = $this->source($item, $evidenceId);
        }

        return ['content' => implode("\n\n", $lines), 'sources' => $sources];
    }

    /**
     * Plain refusal; the closest retrieved passages are returned as sources (UI folds them away).
     *
     * @param  array<int, string>  $missing
     * @return array{content: string, sources: array<int, array<string, mixed>>}
     */
    public function refusal(EvidencePacket $packet, array $missing = []): array
    {
        $content = self::REFUSAL;

        if ($missing !== []) {
            $content .= "\n\nMissing information: ".implode('; ', $missing);
        }

        $sources = [];

        foreach ($packet->all() as $item) {
            $sources[] = $this->source($item, $item['id']);
        }

        return ['content' => $content, 'sources' => $sources];
    }

    /**
     * @return array<string, int> evidence id => source number, in order of first citation
     */
    private function numbering(array $validated): array
    {
        $order = [];

        $add = function (string $id) use (&$order) {
            if ($id !== 'USER' && ! isset($order[$id])) {
                $order[$id] = count($order) + 1;
            }
        };

        foreach ($validated['claims'] as $claim) {
            foreach ($claim['citations'] as $c) {
                $add($c['evidence_id']);
            }
        }

        foreach ($validated['conflicts'] as $conflict) {
            array_map($add, $conflict['evidence_ids']);
        }

        foreach ($validated['calculations'] as $calc) {
            foreach ($calc['inputs'] as $input) {
                $add($input['evidence_id']);
            }
        }

        return $order;
    }

    /**
     * @param  array<int, string>  $ids
     * @param  array<string, int>  $numbering
     */
    private function refs(array $ids, array $numbering): string
    {
        $numbers = array_values(array_unique(array_map(fn ($id) => $numbering[$id] ?? null, $ids)));
        $numbers = array_filter($numbers);
        sort($numbers);

        return implode('', array_map(fn ($n) => "[{$n}]", $numbers));
    }

    /**
     * @param  array<string, mixed>  $calc
     * @param  array<string, int>  $numbering
     */
    private function renderCalculation(array $calc, array $numbering): string
    {
        $unit = $calc['unit'] !== '' ? ' '.$calc['unit'] : '';
        $text = "Calculation — {$calc['label']}: {$calc['result']}{$unit}\n"
            ."Formula: {$calc['expression']}\n"
            .'Inputs: '.collect($calc['inputs'])->map(function ($input, $name) use ($numbering) {
                $from = $input['evidence_id'] === 'USER'
                    ? 'your question'
                    : 'source ['.($numbering[$input['evidence_id']] ?? '?').']';

                return "{$name} = {$input['value']} ({$from})";
            })->implode(', ')."\n"
            .'Calculated by the system (not stated in the document).';

        return $text;
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function source(array $item, string $evidenceId): array
    {
        return [
            'document_name' => $item['document_name'],
            'page' => $item['page'],
            'snippet' => Str::limit($item['text'], 240),
            'score' => $item['score'],
            'evidence_id' => $evidenceId,
            'chunk_id' => $item['vector_id'],
        ];
    }
}
