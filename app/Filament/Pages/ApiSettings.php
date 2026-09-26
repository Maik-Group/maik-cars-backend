<?php

namespace App\Filament\Pages;

use App\Services\Mobile\ItemApiSettingsService;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;

class ApiSettings extends Page implements HasSchemas
{
    use InteractsWithSchemas;

    protected static ?string $title = 'Customer App Item Display';

    protected static ?string $navigationLabel = 'App Item Display';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-adjustments-horizontal';

    protected static string|\UnitEnum|null $navigationGroup = 'Settings';

    public static function getNavigationLabel(): string
    {
        return __('admin.nav.app_item_display');
    }

    public static function getNavigationGroup(): string|\UnitEnum|null
    {
        return __('admin.groups.settings');
    }

    public function getTitle(): string
    {
        return __('admin.nav.app_item_display');
    }

    protected static ?int $navigationSort = 20;

    protected static string $routePath = 'api-settings';

    protected string $view = 'filament.pages.api-settings';

    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill([
            'unique_serial_items' => app(ItemApiSettingsService::class)->uniqueSerialItemsEnabled(),
        ]);
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->hasAnyRole(['super_admin', 'admin']) ?? false;
    }

    public function getSubheading(): ?string
    {
        return __('admin.ui.subheadings.app_display');
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('admin.ui.app_display.section'))
                    ->description(__('admin.ui.app_display.description'))
                    ->components([
                        Toggle::make('unique_serial_items')
                            ->label(__('admin.ui.app_display.unique_label'))
                            ->helperText(__('admin.ui.app_display.unique_help'))
                            ->default(ItemApiSettingsService::DEFAULT_UNIQUE_SERIAL_ITEMS),
                    ]),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        $state = $this->form->getState();
        $enabled = (bool) ($state['unique_serial_items'] ?? false);

        app(ItemApiSettingsService::class)->updateUniqueSerialItems($enabled);

        $this->form->fill([
            'unique_serial_items' => $enabled,
        ]);

        Notification::make()
            ->title(__('admin.ui.app_display.updated'))
            ->body($enabled
                ? __('admin.ui.app_display.enabled_body')
                : __('admin.ui.app_display.disabled_body'))
            ->success()
            ->send();
    }
}
