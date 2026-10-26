<?php

namespace Tests\Unit\Answer;

use App\Services\Answer\NumberExtractor;
use PHPUnit\Framework\TestCase;

class NumberExtractorTest extends TestCase
{
    public function test_hyphen_inside_year_range_or_date_is_not_a_minus(): void
    {
        $keys = (new NumberExtractor)->keys('Forecast 2027-28 revised in Feb-26');

        $this->assertContains('2027', $keys);
        $this->assertContains('28', $keys);
        $this->assertContains('26', $keys);
        $this->assertNotContains('-28', $keys);
        $this->assertNotContains('-26', $keys);
    }

    public function test_real_negative_numbers_keep_their_sign(): void
    {
        $this->assertContains('-5', (new NumberExtractor)->keys('Change was -5 points'));
        $this->assertSame(['-5'], (new NumberExtractor)->claimKeys('fell by -5'));
    }

    public function test_commas_and_trailing_zeros_are_normalized(): void
    {
        $this->assertSame(['1000.5'], (new NumberExtractor)->claimKeys('about 1,000.50'));
    }

    public function test_evidence_ids_are_not_numbers(): void
    {
        $this->assertSame([], (new NumberExtractor)->claimKeys('see E1 and Q3'));
    }
}
