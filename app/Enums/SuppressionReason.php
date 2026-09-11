<?php

namespace App\Enums;

/**
 * Why an address sits on the do-not-contact list. Nothing behaves differently
 * per reason — it is there so a block can be explained months later.
 */
enum SuppressionReason: string
{
    case Unsubscribed = 'unsubscribed';
    case Bounced = 'bounced';
    case Complained = 'complained';
    case Manual = 'manual';

    /**
     * Get the human-readable label for the reason.
     */
    public function label(): string
    {
        return match ($this) {
            self::Unsubscribed => __('Asked to be removed'),
            self::Bounced => __('Bounced'),
            self::Complained => __('Marked as spam'),
            self::Manual => __('Blocked by hand'),
        };
    }

    /**
     * Get the Flux badge color for the reason.
     */
    public function color(): string
    {
        return match ($this) {
            self::Unsubscribed => 'amber',
            self::Bounced => 'red',
            self::Complained => 'red',
            self::Manual => 'zinc',
        };
    }

    /**
     * The reasons offered when blocking an address, keyed by value for a select.
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $reason): array => [$reason->value => $reason->label()])
            ->all();
    }
}
