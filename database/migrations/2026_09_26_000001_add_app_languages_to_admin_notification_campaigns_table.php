<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('admin_notification_campaigns', function (Blueprint $table): void {
            $table->text('title_bg')->nullable()->after('body_ar');
            $table->text('body_bg')->nullable()->after('title_bg');
            $table->text('title_de')->nullable()->after('body_bg');
            $table->text('body_de')->nullable()->after('title_de');
            $table->text('title_hr')->nullable()->after('body_de');
            $table->text('body_hr')->nullable()->after('title_hr');
        });
    }

    public function down(): void
    {
        Schema::table('admin_notification_campaigns', function (Blueprint $table): void {
            $table->dropColumn([
                'title_bg',
                'body_bg',
                'title_de',
                'body_de',
                'title_hr',
                'body_hr',
            ]);
        });
    }
};
