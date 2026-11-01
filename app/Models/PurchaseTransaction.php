<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class PurchaseTransaction extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected $hidden = ['transaction_reference', 'transaction_fingerprint', 'purchase_fingerprint'];

    protected function casts(): array
    {
        return ['transaction_reference' => 'encrypted', 'starts_at' => 'immutable_datetime', 'ends_at' => 'immutable_datetime', 'revoked_at' => 'immutable_datetime', 'amount' => 'decimal:6'];
    }

    protected static function booted(): void
    {
        static::updating(function (self $transaction): void {
            if (array_diff(array_keys($transaction->getDirty()), ['revoked_at'])) {
                throw new LogicException('Verified transaction evidence is immutable.');
            }
        });
        static::deleting(fn () => throw new LogicException('Verified transaction evidence cannot be deleted.'));
    }

    public function agreement(): BelongsTo
    {
        return $this->belongsTo(BillingAgreement::class, 'billing_agreement_id');
    }
}
