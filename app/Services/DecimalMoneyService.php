<?php

namespace App\Services;

use InvalidArgumentException;

class DecimalMoneyService
{
    /** @return numeric-string */
    public function normalize(float|int|string $amount): string
    {
        return bcround($this->numeric($amount), 2);
    }

    /** @return numeric-string */
    public function add(float|int|string ...$amounts): string
    {
        /** @var numeric-string $total */
        $total = '0.00';

        foreach ($amounts as $amount) {
            $total = bcadd($total, $this->numeric($amount), 2);
        }

        return $total;
    }

    /** @return numeric-string */
    public function subtract(float|int|string $amount, float|int|string $subtrahend): string
    {
        return bcsub($this->numeric($amount), $this->numeric($subtrahend), 2);
    }

    /** @return numeric-string */
    public function multiply(float|int|string $amount, int $quantity): string
    {
        return bcround(bcmul($this->numeric($amount), $this->numeric($quantity), 4), 2);
    }

    /** @return numeric-string */
    public function percentage(float|int|string $amount, string $rate): string
    {
        return bcround(bcmul($this->numeric($amount), $this->numeric($rate), 8), 2);
    }

    /** @return numeric-string */
    public function discountRate(float|int|string $storedRate): string
    {
        $rate = $this->numeric($storedRate);

        if (bccomp($rate, '1', 6) === 1) {
            $rate = bcdiv($rate, '100', 6);
        }

        if (bccomp($rate, '0', 6) === -1) {
            return '0.000000';
        }

        if (bccomp($rate, '1', 6) === 1) {
            return '1.000000';
        }

        return bcadd($rate, '0', 6);
    }

    /** @return numeric-string */
    public function maxZero(float|int|string $amount): string
    {
        return bccomp($this->numeric($amount), '0', 2) === -1
            ? '0.00'
            : $this->normalize($amount);
    }

    public function equals(float|int|string $first, float|int|string $second): bool
    {
        return bccomp($this->numeric($first), $this->numeric($second), 2) === 0;
    }

    /** @return numeric-string */
    private function numeric(float|int|string $amount): string
    {
        $numeric = is_float($amount)
            ? rtrim(rtrim(number_format($amount, 8, '.', ''), '0'), '.')
            : (string) $amount;

        if (! is_numeric($numeric)) {
            throw new InvalidArgumentException('Money values must be numeric.');
        }

        return $numeric;
    }
}
