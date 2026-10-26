<?php

namespace Tests\Unit\Answer;

use App\Services\Answer\Calculator;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class CalculatorTest extends TestCase
{
    public function test_evaluates_arithmetic_with_named_inputs(): void
    {
        $this->assertSame('1049.4', (new Calculator)->evaluate('a * (1 + p / 100)', ['a' => '874.5', 'p' => '20']));
    }

    public function test_supports_small_integer_exponents_and_unary_minus(): void
    {
        $this->assertSame('8', (new Calculator)->evaluate('2 ** 3', []));
        $this->assertSame('-3', (new Calculator)->evaluate('-x', ['x' => '3']));
    }

    public function test_rejects_function_calls_and_unknown_characters(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new Calculator)->evaluate('system("ls")', []);
    }

    public function test_rejects_division_by_zero(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new Calculator)->evaluate('1 / 0', []);
    }

    public function test_rejects_large_exponents(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new Calculator)->evaluate('2 ** 99', []);
    }

    public function test_rejects_unknown_inputs(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new Calculator)->evaluate('a + b', ['a' => '1']);
    }
}
