<?php

declare(strict_types=1);

namespace App\Enums;

enum SyncStatus: string
{
    case Pending   = 'pending';
    case Running   = 'running';
    case Succeeded = 'succeeded';
    case Partial   = 'partial';
    case Failed    = 'failed';
    case Cancelled = 'cancelled';

    public function isTerminal(): bool
    {
        return match ($this) {
            self::Succeeded, self::Partial, self::Failed, self::Cancelled => true,
            default => false,
        };
    }

    public function isHealthy(): bool
    {
        return $this === self::Succeeded;
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(static fn (self $s): string => $s->value, self::cases());
    }
}
