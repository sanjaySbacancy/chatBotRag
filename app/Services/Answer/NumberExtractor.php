<?php

namespace App\Services\Answer;

/**
 * Pulls numbers out of free text for claim/calculation verification.
 *
 * A hyphen only counts as a minus sign when it is not glued to a preceding letter or digit,
 * so "2027-28" and "Feb-26" never produce negative numbers.
 */
class NumberExtractor
{
    private const PATTERN = '/(?<![\p{L}\d])([-\x{2212}]?)(\d{1,3}(?:,\d{3})+|\d+)(\.\d+)?/u';

    /**
     * Normalized keys: "5" for every number found, plus "-5" when it is genuinely negative.
     *
     * @return array<int, string>
     */
    public function keys(string $text): array
    {
        $keys = [];

        foreach ($this->tokens($text) as [$negative, $value]) {
            $keys[] = $value;

            if ($negative) {
                $keys[] = '-'.$value;
            }
        }

        return array_values(array_unique($keys));
    }

    /**
     * Claim-side keys: a signed number must match a signed number in the evidence.
     *
     * @return array<int, string>
     */
    public function claimKeys(string $text): array
    {
        $keys = [];

        foreach ($this->tokens($text) as [$negative, $value]) {
            $keys[] = $negative ? '-'.$value : $value;
        }

        return array_values(array_unique($keys));
    }

    public function normalize(string $number): ?string
    {
        $number = trim(str_replace([',', "\u{2212}"], ['', '-'], $number));

        if (! preg_match('/^(-?)(\d+)(\.\d+)?$/', $number, $m)) {
            return null;
        }

        $int = ltrim($m[2], '0') ?: '0';
        $frac = isset($m[3]) ? rtrim($m[3], '0') : '';
        $frac = $frac === '.' ? '' : $frac;

        return $m[1].$int.$frac;
    }

    /**
     * @return array<int, array{0: bool, 1: string}>
     */
    private function tokens(string $text): array
    {
        preg_match_all(self::PATTERN, $text, $matches, PREG_SET_ORDER);

        $tokens = [];

        foreach ($matches as $m) {
            $value = $this->normalize($m[2].($m[3] ?? ''));

            if ($value !== null) {
                $tokens[] = [$m[1] !== '', $value];
            }
        }

        return $tokens;
    }
}
