<?php

namespace App\Services\Answer;

/**
 * Whole-message matcher for greetings, thanks, goodbyes and "what can you do".
 * "hi, what is the leave policy?" is NOT small talk and falls through to retrieval.
 */
class SmallTalk
{
    private const PATTERNS = [
        'greeting' => '/^(hi|hello|hey|hiya|yo|howdy|greetings|good (morning|afternoon|evening))( (there|team|bot|assistant))?$/',
        'thanks' => '/^(thanks|thank you|thx|ty|cheers|much appreciated|thanks a lot|thank you so much|thanks so much)( (a lot|so much))?$/',
        'goodbye' => '/^(bye|goodbye|good bye|see you|see ya|cya|good night|take care)( (later|soon))?$/',
        'help' => '/^(help|what can you do|who are you|what are you|how do you work|how can you help( me)?|what do you do)$/',
    ];

    private const REPLIES = [
        'greeting' => 'Hello! Ask me a question about your uploaded documents and I will answer only from what they say, with the page for each statement.',
        'thanks' => "You're welcome! Ask another question about your documents any time.",
        'goodbye' => 'Goodbye! Your documents and chats will be here when you come back.',
        'help' => 'I answer questions using only the documents you uploaded. Every statement I make is checked against the document text and cites its page. If your documents do not contain the answer, I will say so instead of guessing.',
    ];

    public function reply(string $message): ?string
    {
        $normalized = trim(preg_replace('/\s+/u', ' ', mb_strtolower($message)));
        $normalized = trim(preg_replace('/[\p{P}\p{S}]+$/u', '', $normalized));

        foreach (self::PATTERNS as $kind => $pattern) {
            if (preg_match($pattern, $normalized)) {
                return self::REPLIES[$kind];
            }
        }

        return null;
    }
}
