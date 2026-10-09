<?php

namespace App\Services\Pricing;

/**
 * 期間の販売数と販売金額（税込）の合計。
 */
final readonly class SalesTotal
{
    public function __construct(
        public float $quantity = 0.0,
        public float $amount = 0.0,
    ) {}

    public function add(self $other): self
    {
        return new self($this->quantity + $other->quantity, $this->amount + $other->amount);
    }

    /**
     * 平均単価（販売金額÷販売数）。売れていなければ null。
     */
    public function averagePrice(): ?float
    {
        return $this->quantity > 0 ? $this->amount / $this->quantity : null;
    }
}
