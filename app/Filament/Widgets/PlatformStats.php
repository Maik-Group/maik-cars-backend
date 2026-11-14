<?php

namespace App\Filament\Widgets;

use App\Models\AdminNotificationCampaign;
use App\Models\Item;
use App\Models\MetalPrice;
use App\Models\User;
use App\Support\DashboardCurrency;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\DB;

class PlatformStats extends StatsOverviewWidget
{
    protected ?string $pollingInterval = '60s';

    protected function getStats(): array
    {
        $latestMetalPrice = MetalPrice::query()->latest('fetched_at')->first();
        $activeAppUsers = User::query()
            ->appUsers()
            ->where('is_active', true)
            ->count();

        $latestPt = $latestMetalPrice?->pt_usd_per_oz;
        $latestPriceDescription = $latestMetalPrice?->fetched_at
            ? __('admin.ui.stats.snapshot_updated', ['time' => $latestMetalPrice->fetched_at->diffForHumans()])
            : __('admin.ui.stats.snapshot_waiting');

        return [
            Stat::make(__('admin.ui.stats.total_items'), number_format(Item::query()->count()))
                ->description(__('admin.ui.stats.total_items_description'))
                ->icon('heroicon-o-rectangle-stack')
                ->color('primary'),
            Stat::make(__('admin.ui.stats.active_app_users'), number_format($activeAppUsers))
                ->description(__('admin.ui.stats.active_app_users_description'))
                ->icon('heroicon-o-users')
                ->color('success'),
            Stat::make(__('admin.ui.stats.saved_items'), number_format(DB::table('saved_items')->count()))
                ->description(__('admin.ui.stats.saved_items_description'))
                ->icon('heroicon-o-heart')
                ->color('warning'),
            Stat::make(__('admin.ui.stats.sent_campaigns'), number_format(AdminNotificationCampaign::query()->where('status', 'sent')->count()))
                ->description(__('admin.ui.stats.sent_campaigns_description'))
                ->icon('heroicon-o-paper-airplane')
                ->color('primary'),
            Stat::make(
                __('admin.ui.stats.latest_pt', ['currency' => DashboardCurrency::current()]),
                is_numeric($latestPt) ? DashboardCurrency::format(DashboardCurrency::fromUsd((float) $latestPt)) : 'N/A'
            )
                ->description($latestPriceDescription)
                ->icon('heroicon-o-chart-bar-square')
                ->color('gray'),
        ];
    }
}
