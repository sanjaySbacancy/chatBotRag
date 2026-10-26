<?php

namespace App\Services\Answer;

use InvalidArgumentException;

/**
 * Safe arithmetic evaluator: + - * / ** (small integer exponents), parentheses,
 * named inputs and numeric constants. No function calls, no eval. Uses bcmath.
 */
class Calculator
{
    private const MAX_LENGTH = 200;

    private const MAX_TOKENS = 60;

    private const MAX_EXPONENT = 10;

    private const SCALE = 12;

    /** @var array<int, array{0: string, 1: string}> */
    private array $tokens = [];

    private int $pos = 0;

    /** @var array<string, string> */
    private array $vars = [];

    /**
     * @param  array<string, string>  $inputs  name => numeric string
     */
    public function evaluate(string $expression, array $inputs): string
    {
        if ($expression === '' || strlen($expression) > self::MAX_LENGTH) {
            throw new InvalidArgumentException('Expression is empty or too long.');
        }

        $this->vars = $inputs;
        $this->tokens = $this->tokenize($expression);
        $this->pos = 0;

        $result = $this->parseExpression();

        if ($this->pos !== count($this->tokens)) {
            throw new InvalidArgumentException('Unexpected token in expression.');
        }

        return $this->format($result);
    }

    /**
     * @return array<int, string> identifiers used in the expression
     */
    public function identifiers(string $expression): array
    {
        preg_match_all('/[A-Za-z_][A-Za-z0-9_]*/', $expression, $m);

        return array_values(array_unique($m[0]));
    }

    /**
     * @return array<int, array{0: string, 1: string}>
     */
    private function tokenize(string $expression): array
    {
        $tokens = [];
        $length = strlen($expression);
        $i = 0;

        while ($i < $length) {
            $char = $expression[$i];

            if (ctype_space($char)) {
                $i++;

                continue;
            }

            if (ctype_digit($char) || ($char === '.' && $i + 1 < $length && ctype_digit($expression[$i + 1]))) {
                preg_match('/\G\d*\.?\d+|\G\d+/', $expression, $m, 0, $i);
                $tokens[] = ['num', $m[0]];
                $i += strlen($m[0]);

                continue;
            }

            if (ctype_alpha($char) || $char === '_') {
                preg_match('/\G[A-Za-z_][A-Za-z0-9_]*/', $expression, $m, 0, $i);
                $tokens[] = ['id', $m[0]];
                $i += strlen($m[0]);

                continue;
            }

            if ($char === '*' && ($expression[$i + 1] ?? '') === '*') {
                $tokens[] = ['op', '**'];
                $i += 2;

                continue;
            }

            if (str_contains('+-*/()', $char)) {
                $tokens[] = ['op', $char];
                $i++;

                continue;
            }

            throw new InvalidArgumentException("Disallowed character [{$char}] in expression.");
        }

        if (count($tokens) > self::MAX_TOKENS) {
            throw new InvalidArgumentException('Expression is too complex.');
        }

        return $tokens;
    }

    private function peek(): ?array
    {
        return $this->tokens[$this->pos] ?? null;
    }

    private function parseExpression(): string
    {
        $left = $this->parseTerm();

        while (($t = $this->peek()) && $t[0] === 'op' && ($t[1] === '+' || $t[1] === '-')) {
            $this->pos++;
            $right = $this->parseTerm();
            $left = $t[1] === '+' ? bcadd($left, $right, self::SCALE) : bcsub($left, $right, self::SCALE);
        }

        return $left;
    }

    private function parseTerm(): string
    {
        $left = $this->parseUnary();

        while (($t = $this->peek()) && $t[0] === 'op' && ($t[1] === '*' || $t[1] === '/')) {
            $this->pos++;
            $right = $this->parseUnary();

            if ($t[1] === '/') {
                if (bccomp($right, '0', self::SCALE) === 0) {
                    throw new InvalidArgumentException('Division by zero.');
                }
                $left = bcdiv($left, $right, self::SCALE);
            } else {
                $left = bcmul($left, $right, self::SCALE);
            }
        }

        return $left;
    }

    private function parseUnary(): string
    {
        $t = $this->peek();

        if ($t && $t[0] === 'op' && ($t[1] === '-' || $t[1] === '+')) {
            $this->pos++;
            $value = $this->parseUnary();

            return $t[1] === '-' ? bcmul($value, '-1', self::SCALE) : $value;
        }

        return $this->parsePower();
    }

    private function parsePower(): string
    {
        $base = $this->parseAtom();
        $t = $this->peek();

        if ($t && $t[0] === 'op' && $t[1] === '**') {
            $this->pos++;
            $exponent = $this->parseUnary();

            if (bccomp(bcmod($exponent, '1', self::SCALE), '0', self::SCALE) !== 0
                || bccomp($exponent, '0', self::SCALE) < 0
                || bccomp($exponent, (string) self::MAX_EXPONENT, self::SCALE) > 0) {
                throw new InvalidArgumentException('Exponent must be an integer from 0 to '.self::MAX_EXPONENT.'.');
            }

            return bcpow($base, bcadd($exponent, '0', 0), self::SCALE);
        }

        return $base;
    }

    private function parseAtom(): string
    {
        $t = $this->peek();

        if ($t === null) {
            throw new InvalidArgumentException('Unexpected end of expression.');
        }

        $this->pos++;

        if ($t[0] === 'num') {
            return $t[1];
        }

        if ($t[0] === 'id') {
            if (! array_key_exists($t[1], $this->vars)) {
                throw new InvalidArgumentException("Unknown input [{$t[1]}].");
            }

            return $this->vars[$t[1]];
        }

        if ($t[1] === '(') {
            $value = $this->parseExpression();
            $close = $this->peek();

            if (! $close || $close[1] !== ')') {
                throw new InvalidArgumentException('Missing closing parenthesis.');
            }
            $this->pos++;

            return $value;
        }

        throw new InvalidArgumentException("Unexpected token [{$t[1]}].");
    }

    private function format(string $value): string
    {
        $value = bcadd($value, '0', 6);

        if (str_contains($value, '.')) {
            $value = rtrim(rtrim($value, '0'), '.');
        }

        return $value === '-0' || $value === '' ? '0' : $value;
    }
}
