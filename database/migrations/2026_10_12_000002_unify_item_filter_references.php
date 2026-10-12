<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Preserve existing mapping references before removing the duplicate column.
        // Never replace an item reference already entered by an administrator.
        DB::table('item_filter_mappings')
            ->whereNotNull('filter_serial')
            ->orderBy('id')
            ->chunkById(100, function ($mappings): void {
                foreach ($mappings as $mapping) {
                    $reference = trim((string) $mapping->filter_serial);
                    if ($reference === '') {
                        continue;
                    }

                    DB::table('items')
                        ->where('id', $mapping->item_id)
                        ->where(function ($query): void {
                            $query->whereNull('filter_reference_code')
                                ->orWhere('filter_reference_code', '');
                        })
                        ->update(['filter_reference_code' => $reference]);
                }
            });

        Schema::table('item_filter_mappings', function (Blueprint $table): void {
            $table->dropIndex(['filter_serial']);
        });
        Schema::table('item_filter_mappings', function (Blueprint $table): void {
            $table->dropColumn('filter_serial');
        });
        Schema::table('items', function (Blueprint $table): void {
            $table->dropColumn('is_dpf');
        });
    }

    public function down(): void
    {
        Schema::table('items', function (Blueprint $table): void {
            $table->boolean('is_dpf')->default(false);
        });
        Schema::table('item_filter_mappings', function (Blueprint $table): void {
            $table->string('filter_serial')->nullable()->index();
        });

        // Restore values from the authoritative item reference when rolling back.
        DB::table('item_filter_mappings')
            ->orderBy('id')
            ->chunkById(100, function ($mappings): void {
                foreach ($mappings as $mapping) {
                    $reference = DB::table('items')->where('id', $mapping->item_id)->value('filter_reference_code');
                    DB::table('item_filter_mappings')->where('id', $mapping->id)->update([
                        'filter_serial' => $reference,
                    ]);
                }
            });
    }
};
