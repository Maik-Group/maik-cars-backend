<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AdminGrantBatch extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['expires_at' => 'immutable_datetime', 'starts_at' => 'immutable_datetime', 'ends_at' => 'immutable_datetime', 'plan_snapshot' => 'array', 'confirmed_at' => 'immutable_datetime', 'cancelled_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(function (self $batch): void {
            if ($batch->isDirty(['actor_id', 'operation_key', 'audience', 'credits', 'expires_at', 'snapshot_hash', 'recipient_count', 'operation_kind', 'months', 'issuance_mode', 'starts_at', 'ends_at', 'subscription_plan_id', 'plan_snapshot', 'environment'])) {
                throw new \LogicException('Batch snapshot is immutable');
            }
        });
    }

    public function recipients(): HasMany
    {
        return $this->hasMany(AdminGrantRecipient::class, 'batch_id');
    }
}
