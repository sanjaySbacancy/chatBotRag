<?php

namespace App\Services\Answer;

use InvalidArgumentException;

/**
 * The LLM is never trusted: it proposes, this class checks.
 */
class ClaimValidator
{
    private const CLAIM_TYPES = ['quote', 'paraphrase', 'inference'];

    private const RESTRICTION_CUES = '/\b(except|unless|not (?:allowed|permitted|eligible)|only if|only when|limited to|restricted|cannot|must not|prohibited|subject to)\b/i';

    public function __construct(
        private readonly NumberExtractor $numbers,
        private readonly Calculator $calculator,
    ) {}

    /**
     * @param  array<string, mixed>  $parsed  decoded model JSON
     * @param  array<int, string>  $userTexts  the user's current and earlier questions (valid source for USER inputs)
     * @return array{status: string, claims: array, conflicts: array, calculations: array, missing_information: array, warnings: array}
     */
    public function validate(array $parsed, EvidencePacket $packet, array $userTexts): array
    {
        $warnings = [];
        $missing = array_values(array_filter(
            array_map('strval', (array) ($parsed['missing_information'] ?? [])),
            fn (string $m) => trim($m) !== ''
        ));

        if (($parsed['status'] ?? null) === 'insufficient_evidence') {
            return $this->result('insufficient_evidence', [], [], [], $missing, $warnings);
        }

        $claims = [];
        $rejected = 0;

        foreach ((array) ($parsed['claims'] ?? []) as $claim) {
            $verified = is_array($claim) ? $this->verifyClaim($claim, $packet) : null;

            $verified === null ? $rejected++ : $claims[] = $verified;
        }

        if ($rejected > 0) {
            $warnings[] = $rejected.' statement'.($rejected === 1 ? '' : 's').' proposed by the model could not be verified against your documents and '.($rejected === 1 ? 'was' : 'were').' removed.';
        }

        if ($claims === []) {
            return $this->result('insufficient_evidence', [], [], [], $missing, $warnings);
        }

        $calculations = [];

        foreach ((array) ($parsed['calculations'] ?? []) as $calc) {
            $verified = is_array($calc) ? $this->verifyCalculation($calc, $packet, $userTexts, $reason) : null;

            if ($verified === null) {
                $warnings[] = 'A calculation was removed because it could not be verified'.(($reason ?? '') !== '' ? " ({$reason})" : '').'.';
            } else {
                $calculations[] = $verified;
            }
        }

        $conflicts = [];

        foreach ((array) ($parsed['conflicts'] ?? []) as $conflict) {
            $ids = is_array($conflict) ? array_values(array_unique(array_filter(
                (array) ($conflict['evidence_ids'] ?? []),
                fn ($id) => is_string($id) && $packet->has($id)
            ))) : [];

            if (count($ids) >= 2 && trim((string) ($conflict['description'] ?? '')) !== '') {
                $conflicts[] = ['description' => trim((string) $conflict['description']), 'evidence_ids' => $ids];
            }
        }

        $warnings = [...$warnings, ...$this->uncoveredRestrictions($claims, $calculations, $conflicts, $packet)];

        return $this->result('answered', $claims, $conflicts, $calculations, $missing, $warnings);
    }

    /**
     * @param  array<string, mixed>  $claim
     * @return array{text: string, type: string, citations: array<int, array{evidence_id: string, quote: string}>}|null
     */
    private function verifyClaim(array $claim, EvidencePacket $packet): ?array
    {
        $text = trim((string) ($claim['text'] ?? ''));
        $type = (string) ($claim['type'] ?? '');
        $citations = $claim['citations'] ?? null;

        if ($text === '' || ! in_array($type, self::CLAIM_TYPES, true) || ! is_array($citations) || $citations === []) {
            return null;
        }

        $clean = [];
        $evidenceText = '';

        foreach ($citations as $citation) {
            $id = is_array($citation) ? ($citation['evidence_id'] ?? null) : null;
            $quote = is_array($citation) ? trim((string) ($citation['quote'] ?? '')) : '';
            $item = is_string($id) ? $packet->get($id) : null;

            if ($item === null || $quote === '' || ! $this->quoteAppears($quote, $item['text'])) {
                return null;
            }

            $clean[] = ['evidence_id' => $id, 'quote' => $quote];
            $evidenceText .= "\n".$item['text'];
        }

        $evidenceKeys = array_flip($this->numbers->keys($evidenceText));

        foreach ($this->numbers->claimKeys($text) as $key) {
            if (! isset($evidenceKeys[$key])) {
                return null;
            }
        }

        return ['text' => $text, 'type' => $type, 'citations' => $clean];
    }

    /**
     * @param  array<string, mixed>  $calc
     * @param  array<int, string>  $userTexts
     * @return array{label: string, expression: string, unit: string, result: string, inputs: array<string, array{value: string, evidence_id: string}>}|null
     */
    private function verifyCalculation(array $calc, EvidencePacket $packet, array $userTexts, ?string &$reason = null): ?array
    {
        $reason = '';
        $expression = trim((string) ($calc['expression'] ?? ''));
        $inputs = $calc['inputs'] ?? [];

        if ($expression === '' || ! is_array($inputs)) {
            $reason = 'missing expression or inputs';

            return null;
        }

        $values = [];
        $clean = [];

        foreach ($inputs as $name => $input) {
            $value = is_array($input) ? $this->numbers->normalize((string) ($input['value'] ?? '')) : null;
            $source = is_array($input) ? ($input['evidence_id'] ?? null) : null;

            if (! is_string($name) || ! preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name) || $value === null || ! is_string($source)) {
                $reason = 'invalid input';

                return null;
            }

            $haystack = $source === 'USER'
                ? implode("\n", $userTexts)
                : ($packet->get($source)['text'] ?? null);

            if ($haystack === null || ! in_array($value, $this->numbers->keys($haystack), true)) {
                $reason = "input [{$name}] is not in ".($source === 'USER' ? 'your question' : "the cited passage {$source}");

                return null;
            }

            $values[$name] = $value;
            $clean[$name] = ['value' => $value, 'evidence_id' => $source];
        }

        foreach ($this->calculator->identifiers($expression) as $identifier) {
            if (! isset($values[$identifier])) {
                $reason = "unknown input [{$identifier}]";

                return null;
            }
        }

        try {
            $result = $this->calculator->evaluate($expression, $values);
        } catch (InvalidArgumentException $e) {
            $reason = $e->getMessage();

            return null;
        }

        return [
            'label' => trim((string) ($calc['label'] ?? 'Calculation')) ?: 'Calculation',
            'expression' => $expression,
            'unit' => trim((string) ($calc['unit'] ?? '')),
            'result' => $result,
            'inputs' => $clean,
        ];
    }

    private function quoteAppears(string $quote, string $text): bool
    {
        $needle = $this->normalizeForMatch($quote);

        return $needle !== '' && str_contains($this->normalizeForMatch($text), $needle);
    }

    private function normalizeForMatch(string $text): string
    {
        $text = str_replace(
            ["\u{2018}", "\u{2019}", "\u{201C}", "\u{201D}", "\u{2013}", "\u{2014}", "\u{2212}", "\u{00A0}"],
            ["'", "'", '"', '"', '-', '-', '-', ' '],
            $text
        );

        return trim(preg_replace('/\s+/u', ' ', mb_strtolower($text)));
    }

    /**
     * Warn when a retrieved passage that reads like a restriction is not used by the answer.
     *
     * @return array<int, string>
     */
    private function uncoveredRestrictions(array $claims, array $calculations, array $conflicts, EvidencePacket $packet): array
    {
        $used = [];

        foreach ($claims as $claim) {
            foreach ($claim['citations'] as $c) {
                $used[$c['evidence_id']] = true;
            }
        }

        foreach ($calculations as $calc) {
            foreach ($calc['inputs'] as $input) {
                $used[$input['evidence_id']] = true;
            }
        }

        foreach ($conflicts as $conflict) {
            foreach ($conflict['evidence_ids'] as $id) {
                $used[$id] = true;
            }
        }

        $warnings = [];

        foreach ($packet->all() as $item) {
            if (! isset($used[$item['id']]) && preg_match(self::RESTRICTION_CUES, $item['text'])) {
                $warnings[] = "A retrieved passage ({$item['document_name']}, page ".($item['page'] ?? '?').') mentions a possible restriction or exception that is not covered in this answer. Check it before relying on the answer.';
            }
        }

        return $warnings;
    }

    private function result(string $status, array $claims, array $conflicts, array $calculations, array $missing, array $warnings): array
    {
        return [
            'status' => $status,
            'claims' => $claims,
            'conflicts' => $conflicts,
            'calculations' => $calculations,
            'missing_information' => $missing,
            'warnings' => $warnings,
        ];
    }
}
