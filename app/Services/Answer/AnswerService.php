<?php

namespace App\Services\Answer;

use App\Services\GeminiService;

class AnswerService
{
    public const ANSWERED = 'answered';

    public const INSUFFICIENT = 'insufficient_evidence';

    public const INVALID_OUTPUT = 'invalid_model_output';

    public const PROVIDER_ERROR = 'provider_error';

    public const CHITCHAT = 'chitchat';

    public function __construct(
        private readonly GeminiService $gemini,
        private readonly PromptBuilder $prompts,
        private readonly ModelOutputParser $parser,
        private readonly ClaimValidator $validator,
        private readonly AnswerRenderer $renderer,
    ) {}

    /**
     * Ask the model for strict JSON, validate it, and render the verified result.
     * Provider failures are not caught here; the caller reports them as `provider_error`.
     *
     * @param  array<int, string>  $userTexts  current + earlier user questions
     * @return array{status: string, content: string, sources: array, claims: array, conflicts: array, calculations: array, warnings: array}
     */
    public function answer(string $question, ?string $previousQuestion, EvidencePacket $packet, array $userTexts): array
    {
        if ($packet->isEmpty()) {
            return $this->build(self::INSUFFICIENT, $this->renderer->refusal($packet));
        }

        $raw = $this->gemini->streamGenerate(
            PromptBuilder::SYSTEM,
            $this->prompts->user($question, $packet, $previousQuestion),
            fn () => null,
            ['responseMimeType' => 'application/json'],
        );

        $parsed = $this->parser->parse($raw);

        if ($parsed === null) {
            return $this->build(self::INVALID_OUTPUT, [
                'content' => 'The model returned an answer in an unexpected format, so it was not shown. Please try again.',
                'sources' => [],
            ]);
        }

        $validated = $this->validator->validate($parsed, $packet, $userTexts);

        if ($validated['status'] === self::INSUFFICIENT) {
            return $this->build(
                self::INSUFFICIENT,
                $this->renderer->refusal($packet, $validated['missing_information']),
                $validated,
            );
        }

        return $this->build(self::ANSWERED, $this->renderer->render($validated, $packet), $validated);
    }

    /**
     * @param  array{content: string, sources: array}  $rendered
     * @param  array<string, mixed>  $validated
     */
    private function build(string $status, array $rendered, array $validated = []): array
    {
        return [
            'status' => $status,
            'content' => $rendered['content'],
            'sources' => $rendered['sources'],
            'claims' => $validated['claims'] ?? [],
            'conflicts' => $validated['conflicts'] ?? [],
            'calculations' => $validated['calculations'] ?? [],
            'warnings' => $validated['warnings'] ?? [],
        ];
    }
}
