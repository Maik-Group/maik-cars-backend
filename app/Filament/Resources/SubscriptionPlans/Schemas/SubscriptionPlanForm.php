<?php

namespace App\Filament\Resources\SubscriptionPlans\Schemas;

use App\Models\SubscriptionPlan;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class SubscriptionPlanForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Subscription details')
                ->description('The plan credits are granted when an administrator assigns a subscription. Stripe offer prices are managed separately.')
                ->columns(2)->schema([
                    TextInput::make('name')->required()->maxLength(255),
                    TextInput::make('credits')->label('Credits included')->numeric()->integer()->minValue(1)->maxValue(1000000)->required(),
                    Select::make('duration_months')->label('Duration')->options([1 => '1 month', 3 => '3 months', 6 => '6 months', 12 => '12 months'])->required(),
                    Toggle::make('is_active')->label('Active')->default(false),
                    TextInput::make('sort_order')->numeric()->minValue(0)->default(0)->required(),
                ]),
            Section::make('Descriptions')->collapsed()->schema(array_map(
                fn (string $locale) => Textarea::make('descriptions.'.$locale)->label(strtoupper($locale))->maxLength(4000),
                ['ar', 'en', 'bg', 'de', 'hr']
            )),
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function attributesForSave(array $data, ?SubscriptionPlan $plan): array
    {
        $features = $plan?->features ?? [];
        $features['valuation'] ??= true;

        $key = $plan?->key ?? $data['key'] ?? Str::slug((string) ($data['name'] ?? 'plan'));
        $key = $key !== '' ? $key : 'plan';
        if (! $plan) {
            $original = $key;
            $suffix = 2;
            while (SubscriptionPlan::query()->where('key', $key)->exists()) {
                $key = $original.'-'.$suffix++;
            }
        }

        return [
            ...$data,
            'key' => $key,
            'tier_key' => $plan?->tier_key ?? $key,
            'service_level' => $plan?->service_level ?? 1,
            'daily_allowance' => $plan?->daily_allowance,
            'features' => $features,
            'is_visible' => $plan?->is_visible ?? (bool) ($data['is_active'] ?? false),
        ];
    }
}
