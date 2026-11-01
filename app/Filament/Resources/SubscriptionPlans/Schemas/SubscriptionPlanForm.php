<?php

namespace App\Filament\Resources\SubscriptionPlans\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class SubscriptionPlanForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Plan benefits')->description('Changes apply to new agreements after their store mapping is verified. Existing agreements retain their benefits. Checkout prices are controlled by Apple and Google.')
                ->columns(2)->schema([
                    TextInput::make('name')->required()->maxLength(255), TextInput::make('key')->required()->maxLength(100),
                    TextInput::make('tier_key')->required()->maxLength(100),
                    Select::make('duration_months')->options([1 => '1 month', 3 => '3 months', 6 => '6 months', 12 => '12 months'])->required(),
                    TextInput::make('service_level')->numeric()->minValue(1)->default(1)->required(),
                    TextInput::make('daily_allowance')->numeric()->minValue(1)->helperText('Leave empty for unlimited access.'),
                    Toggle::make('features.valuation')->label('Price and analysis access')->default(true),
                    Toggle::make('is_active')->default(false), Toggle::make('is_visible')->default(false),
                    TextInput::make('sort_order')->numeric()->minValue(0)->default(0)->required(),
                ]),
            Section::make('Localized descriptions')->schema(array_map(fn (string $locale) => Textarea::make('descriptions.'.$locale)->label(strtoupper($locale))->maxLength(4000), ['ar', 'en', 'bg', 'de', 'hr'])),
            Textarea::make('reason')->label('Reason for this change')->required()->maxLength(255),
        ]);
    }
}
