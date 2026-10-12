<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ItemFilterMapping extends Model
{
    use HasFactory;
    use HasUuids;

    public const string STATUS_DETECTED = 'detected';

    public const string STATUS_APPROVED = 'approved';

    public const string STATUS_IGNORED = 'ignored';

    public const string STATUS_NEEDS_REVIEW = 'needs_review';

    // Legacy property used by pricing services; values are persisted only on items.
    private bool $filterSerialProvided = false;

    private ?string $pendingFilterSerial = null;

    protected static function booted(): void
    {
        static::saved(function (self $mapping): void {
            if (! $mapping->filterSerialProvided) {
                return;
            }

            $reference = $mapping->pendingFilterSerial;
            $mapping->filterSerialProvided = false;

            // A manual edit may clear the shared reference; automated detection may not.
            if ($reference === null && $mapping->detection_method !== 'manual') {
                return;
            }

            $query = Item::query()->whereKey($mapping->item_id);
            if ($mapping->detection_method !== 'manual') {
                $query->where(function ($query): void {
                    $query->whereNull('filter_reference_code')
                        ->orWhere('filter_reference_code', '');
                });
            }

            $query->update(['filter_reference_code' => $reference]);
            $mapping->unsetRelation('item');
        });
    }

    public function getFilterSerialAttribute(): ?string
    {
        if ($this->filterSerialProvided) {
            return $this->pendingFilterSerial;
        }

        return $this->item?->filter_reference_code;
    }

    public function setFilterSerialAttribute(?string $reference): void
    {
        $value = trim((string) $reference);
        $this->pendingFilterSerial = $value !== '' ? $value : null;
        $this->filterSerialProvided = true;
    }

    protected $fillable = [
        'item_id',
        'filter_item_id',
        'filter_serial', // virtual compatibility field; not a database column
        'detection_method',
        'confidence',
        'status',
        'filter_weight_override',
        'evidence',
        'notes',
        'approved_by',
        'approved_at',
    ];

    protected $casts = [
        'filter_weight_override' => 'float',
        'evidence' => 'array',
        'approved_at' => 'datetime',
    ];

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function filterItem(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'filter_item_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
