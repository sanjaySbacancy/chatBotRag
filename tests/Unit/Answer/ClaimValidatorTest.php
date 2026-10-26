<?php

namespace Tests\Unit\Answer;

use App\Services\Answer\Calculator;
use App\Services\Answer\ClaimValidator;
use App\Services\Answer\EvidencePacket;
use App\Services\Answer\NumberExtractor;
use PHPUnit\Framework\TestCase;

class ClaimValidatorTest extends TestCase
{
    private function packet(): EvidencePacket
    {
        return EvidencePacket::fromMatches([
            ['id' => 'v1', 'score' => 0.9, 'metadata' => ['document_name' => 'Policy.pdf', 'page' => 1, 'text' => 'Employees receive birthday leave of 0.5 day. Revenue was 874.5 in 2027-28.']],
            ['id' => 'v2', 'score' => 0.8, 'metadata' => ['document_name' => 'Policy.pdf', 'page' => 8, 'text' => 'Birthday leave is not allowed on weekends.']],
        ]);
    }

    private function validator(): ClaimValidator
    {
        return new ClaimValidator(new NumberExtractor, new Calculator);
    }

    private function claim(string $text, string $quote, string $id = 'E1', string $type = 'quote'): array
    {
        return ['text' => $text, 'type' => $type, 'citations' => [['evidence_id' => $id, 'quote' => $quote]]];
    }

    public function test_keeps_a_claim_with_verbatim_quote_and_supported_numbers(): void
    {
        $result = $this->validator()->validate([
            'status' => 'answered',
            'claims' => [$this->claim('Birthday leave is 0.5 day.', 'birthday leave of 0.5 day')],
        ], $this->packet(), []);

        $this->assertSame('answered', $result['status']);
        $this->assertCount(1, $result['claims']);
    }

    public function test_rejects_a_quote_not_present_in_the_passage(): void
    {
        $result = $this->validator()->validate([
            'status' => 'answered',
            'claims' => [$this->claim('Employees get a full day.', 'a full day off')],
        ], $this->packet(), []);

        $this->assertSame('insufficient_evidence', $result['status']);
        $this->assertNotEmpty($result['warnings']);
    }

    public function test_rejects_a_number_not_in_the_cited_passage(): void
    {
        $result = $this->validator()->validate([
            'status' => 'answered',
            'claims' => [$this->claim('Birthday leave is 2 days.', 'birthday leave of 0.5 day')],
        ], $this->packet(), []);

        $this->assertSame('insufficient_evidence', $result['status']);
    }

    public function test_rejects_unknown_evidence_ids_and_uncited_claims(): void
    {
        $result = $this->validator()->validate([
            'status' => 'answered',
            'claims' => [
                $this->claim('x', 'y', 'E9'),
                ['text' => 'No citation.', 'type' => 'paraphrase', 'citations' => []],
            ],
        ], $this->packet(), []);

        $this->assertSame('insufficient_evidence', $result['status']);
    }

    public function test_keeps_good_claims_and_warns_about_removed_ones(): void
    {
        $result = $this->validator()->validate([
            'status' => 'answered',
            'claims' => [
                $this->claim('Birthday leave is 0.5 day.', 'birthday leave of 0.5 day'),
                $this->claim('Invented.', 'never said this'),
            ],
        ], $this->packet(), []);

        $this->assertSame('answered', $result['status']);
        $this->assertCount(1, $result['claims']);
        $this->assertStringContainsString('1 statement', $result['warnings'][0]);
    }

    public function test_model_declared_insufficient_evidence_is_respected(): void
    {
        $result = $this->validator()->validate([
            'status' => 'insufficient_evidence',
            'claims' => [$this->claim('Birthday leave is 0.5 day.', 'birthday leave of 0.5 day')],
            'missing_information' => ['Payroll rules'],
        ], $this->packet(), []);

        $this->assertSame('insufficient_evidence', $result['status']);
        $this->assertSame([], $result['claims']);
        $this->assertSame(['Payroll rules'], $result['missing_information']);
    }

    public function test_calculation_inputs_must_come_from_evidence_or_the_user(): void
    {
        $base = [
            'status' => 'answered',
            'claims' => [$this->claim('Revenue was 874.5.', 'Revenue was 874.5')],
        ];

        $ok = $this->validator()->validate($base + ['calculations' => [[
            'label' => 'Projected', 'expression' => 'a * (1 + p / 100)', 'unit' => 'units',
            'inputs' => ['a' => ['value' => '874.5', 'evidence_id' => 'E1'], 'p' => ['value' => '20', 'evidence_id' => 'USER']],
        ]]], $this->packet(), ['Assume 20% growth']);

        $this->assertSame('1049.4', $ok['calculations'][0]['result']);

        $bad = $this->validator()->validate($base + ['calculations' => [[
            'label' => 'Projected', 'expression' => 'a * 2',
            'inputs' => ['a' => ['value' => '999', 'evidence_id' => 'E1']],
        ]]], $this->packet(), []);

        $this->assertSame([], $bad['calculations']);
        $this->assertNotEmpty($bad['warnings']);
    }

    public function test_warns_when_an_unused_passage_reads_like_a_restriction(): void
    {
        $result = $this->validator()->validate([
            'status' => 'answered',
            'claims' => [$this->claim('Birthday leave is 0.5 day.', 'birthday leave of 0.5 day')],
        ], $this->packet(), []);

        $this->assertStringContainsString('page 8', implode(' ', $result['warnings']));
    }

    public function test_conflicts_need_two_known_evidence_ids(): void
    {
        $result = $this->validator()->validate([
            'status' => 'answered',
            'claims' => [$this->claim('Birthday leave is 0.5 day.', 'birthday leave of 0.5 day')],
            'conflicts' => [
                ['description' => 'Docs disagree', 'evidence_ids' => ['E1', 'E2']],
                ['description' => 'Bogus', 'evidence_ids' => ['E1', 'E9']],
            ],
        ], $this->packet(), []);

        $this->assertCount(1, $result['conflicts']);
    }
}
