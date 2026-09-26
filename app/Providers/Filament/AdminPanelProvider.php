<?php

namespace App\Providers\Filament;

use App\Filament\Widgets\MetalTrendsChart;
use App\Filament\Widgets\PlatformStats;
use App\Http\Middleware\SetAdminLocale;
use Filament\Actions\Action;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Widgets\AccountWidget;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login()
            ->brandName('Maik Cars Admin')
            ->viteTheme('resources/css/filament/admin/theme.css')
            ->colors([
                'primary' => Color::Amber,
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->profile()
            ->userMenuItems([
                Action::make('languageEnglish')
                    ->label('English')
                    ->icon('heroicon-o-language')
                    ->url(fn (): string => route('dashboard.language', ['locale' => 'en'])),
                Action::make('languageArabic')
                    ->label('العربية')
                    ->icon('heroicon-o-language')
                    ->url(fn (): string => route('dashboard.language', ['locale' => 'ar'])),
                Action::make('languageGerman')
                    ->label('Deutsch')
                    ->icon('heroicon-o-language')
                    ->url(fn (): string => route('dashboard.language', ['locale' => 'de'])),
                Action::make('languageBulgarian')
                    ->label('Български')
                    ->icon('heroicon-o-language')
                    ->url(fn (): string => route('dashboard.language', ['locale' => 'bg'])),
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->widgets([
                PlatformStats::class,
                MetalTrendsChart::class,
                AccountWidget::class,
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                SetAdminLocale::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
