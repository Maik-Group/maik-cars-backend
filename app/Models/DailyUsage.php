<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DailyUsage extends Model
{
    protected $table = 'daily_usage';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['used' => 'integer'];
    }

    public function reveals(): HasMany
    {
        return $this->hasMany(DailyUsageReveal::class);
    }
}
