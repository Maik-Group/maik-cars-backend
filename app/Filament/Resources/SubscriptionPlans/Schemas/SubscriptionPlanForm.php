<?php

namespace App\Filament\Resources\SubscriptionPlans\Schemas;

use App\Models\SubscriptionPlan;
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
            Section::make('Plan details')
                ->description('Existing agreements retain their benefits. Credit amounts and checkout prices are managed in Credit Packages.')
                ->columns(2)->schema([
                    TextInput::make('name')->required()->maxLength(255),
                    TextInput::make('key')->required()->maxLength(100),
                    Select::make('duration_months')->options([1 => '1 month', 3 => '3 months', 6 => '6 months', 12 => '12 months'])->required(),
                    Toggle::make('is_active')->label('Active')->default(false),
                    TextInput::make('sort_order')->numeric()->minValue(0)->default(0)->required(),
                ]),
            Section::make('Localized descriptions')->schema(array_map(
                fn (string $locale) => Textarea::make('descriptions.'.$locale)->label(strtoupper($locale))->maxLength(4000),
                ['ar', 'en', 'bg', 'de', 'hr']
            )),
        ]);
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public static function attributesForSave(array $data, ?SubscriptionPlan $plan): array
    {
        $features = $plan?->features ?? [];
        $features['valuation'] ??= true;

        return [
            ...$data,
            'tier_key' => $plan?->tier_key ?? $data['key'],
            'service_level' => $plan?->service_level ?? 1,
            'daily_allowance' => $plan?->daily_allowance,
            'features' => $features,
            'is_visible' => $plan?->is_visible ?? (bool) ($data['is_active'] ?? false),
        ];
    }
}
