<?php

namespace App\Services\Answer;

class ModelOutputParser
{
    /**
     * Strict JSON parse. Tolerates a surrounding code fence and a trailing-comma slip, nothing else.
     *
     * @return array<string, mixed>|null
     */
    public function parse(string $raw): ?array
    {
        $raw = trim($raw);
        $raw = preg_replace('/^```(?:json)?\s*|\s*```$/i', '', $raw);

        $data = json_decode($raw, true);

        if ($data === null) {
            $data = json_decode(preg_replace('/,\s*([}\]])/', '$1', $raw), true);
        }

        return is_array($data) && ! array_is_list($data) && isset($data['status']) ? $data : null;
    }
}
