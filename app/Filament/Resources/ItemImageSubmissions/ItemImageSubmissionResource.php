<?php

namespace App\Filament\Resources\ItemImageSubmissions;

use App\Filament\Resources\ItemImageSubmissions\Pages\ListItemImageSubmissions;
use App\Filament\Resources\ItemImageSubmissions\Tables\ItemImageSubmissionsTable;
use App\Models\ItemImageSubmission;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ItemImageSubmissionResource extends Resource
{
    protected static ?string $model = ItemImageSubmission::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPhoto;

    protected static string|\UnitEnum|null $navigationGroup = 'Catalog Management';

    protected static ?int $navigationSort = 12;

    public static function getNavigationLabel(): string
    {
        return __('admin.nav.image_submissions');
    }

    public static function getNavigationGroup(): string|\UnitEnum|null
    {
        return __('admin.groups.catalog_management');
    }

    public static function getModelLabel(): string
    {
        return __('admin.models.image_submission');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.nav.image_submissions');
    }

    public static function table(Table $table): Table
    {
        return ItemImageSubmissionsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListItemImageSubmissions::route('/'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with(['item.carGroup', 'submitter', 'reviewer', 'media']);
    }

    public static function getNavigationBadge(): ?string
    {
        $count = ItemImageSubmission::query()
            ->where('status', ItemImageSubmission::STATUS_PENDING)
            ->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): string|array|null
    {
        return 'warning';
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('manage_items') ?? false;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }
}
