<?php

namespace App\Enums;

/**
 * The one Lead2Crm plan, offered on two billing cycles. Everything is included
 * on both; only how often you pay changes.
 */
enum BillingPlan: string
{
    case Monthly = 'monthly';
    case Yearly = 'yearly';

    /**
     * Get the human-readable label for the billing cycle.
     */
    public function label(): string
    {
        return match ($this) {
            self::Monthly => 'Monthly',
            self::Yearly => 'Yearly',
        };
    }

    /**
     * Get the effective price per month, in whole US dollars.
     */
    public function monthlyRate(): int
    {
        return match ($this) {
            self::Monthly => 24,
            self::Yearly => 17,
        };
    }

    /**
     * Get the amount charged each billing cycle, in whole US dollars.
     */
    public function amountPerCycle(): int
    {
        return match ($this) {
            self::Monthly => $this->monthlyRate(),
            self::Yearly => $this->monthlyRate() * 12,
        };
    }

    /**
     * Get a plain description of when and how much the customer is charged.
     */
    public function billingSummary(): string
    {
        return match ($this) {
            self::Monthly => '$'.$this->amountPerCycle().' billed every month',
            self::Yearly => '$'.$this->amountPerCycle().' billed once a year',
        };
    }

    /**
     * Get the percentage saved against paying monthly for a year.
     */
    public function savingsPercent(): int
    {
        $monthly = self::Monthly->monthlyRate();

        return (int) round(($monthly - $this->monthlyRate()) / $monthly * 100);
    }

    /**
     * Get the Paddle price ID for this cycle, or null when it isn't configured.
     */
    public function priceId(): ?string
    {
        return config('services.paddle.prices.'.$this->value) ?: null;
    }

    /**
     * Find the plan a Paddle price ID belongs to.
     */
    public static function fromPriceId(?string $priceId): ?self
    {
        if (blank($priceId)) {
            return null;
        }

        foreach (self::cases() as $plan) {
            if ($plan->priceId() === $priceId) {
                return $plan;
            }
        }

        return null;
    }

    /**
     * Determine whether checkout can be offered: Paddle.js needs a client-side
     * token and every cycle needs a price to sell.
     */
    public static function checkoutIsConfigured(): bool
    {
        if (blank(config('cashier.client_side_token'))) {
            return false;
        }

        foreach (self::cases() as $plan) {
            if ($plan->priceId() === null) {
                return false;
            }
        }

        return true;
    }
}
