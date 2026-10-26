<?php

namespace App\Services\Answer;

/**
 * Cheap rule-based intent classification (no LLM).
 */
class IntentClassifier
{
    public const LOOKUP = 'lookup';

    public const NUMERIC = 'numeric';

    public const COMPARISON = 'comparison';

    public const SUMMARY = 'summary';

    public const CHART = 'chart';

    public const ELIGIBILITY = 'eligibility';

    private const RULES = [
        self::CHART => '/\b(chart|graph|plot|figure|diagram|image|photo|picture|illustration)\b/',
        self::COMPARISON => '/\b(compare|comparison|versus|vs|difference between|differs?|better than)\b/',
        self::SUMMARY => '/\b(summari[sz]e|summary|overview|what is this (pdf|document)|about this (pdf|document))\b/',
        self::ELIGIBILITY => '/\b(eligible|eligibility|entitled|allowed|permitted|qualify|can i|am i|do i get|may i)\b/',
        self::NUMERIC => '/(\d|\b(how much|how many|percent|percentage|total|amount|rate|cost|calculate|sum|average|price)\b)/',
    ];

    public function classify(string $message): string
    {
        $text = mb_strtolower($message);

        foreach (self::RULES as $intent => $pattern) {
            if (preg_match($pattern, $text)) {
                return $intent;
            }
        }

        return self::LOOKUP;
    }

    /**
     * Intents that benefit from more retrieved passages.
     */
    public function topK(string $intent, int $default): int
    {
        return in_array($intent, [self::COMPARISON, self::SUMMARY, self::ELIGIBILITY], true)
            ? $default + 3
            : $default;
    }
}
