<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AdminGrantRecipient extends Model
{
    protected $guarded = [];

    protected static function booted(): void
    {
        static::updating(function (self $recipient): void {
            if ($recipient->isDirty(['batch_id', 'user_id'])) {
                throw new \LogicException('Recipient identity is immutable');
            }
        });
    }
}
