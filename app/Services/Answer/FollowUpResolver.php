<?php

namespace App\Services\Answer;

/**
 * Deterministic follow-up handling: short questions or ones opening with
 * "and / what about / how about" are searched together with the previous user question.
 * Only earlier questions are reused here, never earlier model answers.
 */
class FollowUpResolver
{
    private const OPENERS = '/^(and|also|then|what about|how about|what if|why|how so)\b/i';

    private const SHORT_WORD_LIMIT = 4;

    /**
     * @return array{query: string, follow_up: bool}
     */
    public function resolve(string $message, ?string $previousQuestion): array
    {
        $message = trim($message);

        if ($previousQuestion === null || trim($previousQuestion) === '') {
            return ['query' => $message, 'follow_up' => false];
        }

        $wordCount = count(preg_split('/\s+/u', $message, -1, PREG_SPLIT_NO_EMPTY));
        $isFollowUp = $wordCount <= self::SHORT_WORD_LIMIT || preg_match(self::OPENERS, $message) === 1;

        return $isFollowUp
            ? ['query' => trim($previousQuestion).' '.$message, 'follow_up' => true]
            : ['query' => $message, 'follow_up' => false];
    }
}
