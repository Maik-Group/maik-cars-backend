<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class SubscriptionAuditLog extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['before' => 'array', 'after' => 'array'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Subscription audit history is append-only.'));
        static::deleting(fn () => throw new LogicException('Subscription audit history is append-only.'));
    }
}
