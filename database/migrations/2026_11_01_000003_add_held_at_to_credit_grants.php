<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('credit_grants', fn (Blueprint $table) => $table->timestamp('held_at')->nullable());
    }

    public function down(): void
    {
        Schema::table('credit_grants', fn (Blueprint $table) => $table->dropColumn('held_at'));
    }
};
