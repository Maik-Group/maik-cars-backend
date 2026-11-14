<?php

namespace App\Filament\Pages;

use App\Models\Item;
use App\Services\Mobile\ItemPriceService;
use App\Services\Mobile\ItemPriceSettingsService;
use App\Services\Pricing\FilterPriceCorrectionService;
use App\Support\DashboardCurrency;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;

class PricingSettings extends Page implements HasSchemas
{
    use InteractsWithSchemas;

    protected static ?string $title = 'Pricing Settings';

    protected static ?string $navigationLabel = 'Pricing Settings';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-cog-6-tooth';

    protected static string|\UnitEnum|null $navigationGroup = 'Settings';

    public static function getNavigationLabel(): string
    {
        return __('admin.nav.pricing_settings');
    }

    public static function getNavigationGroup(): string|\UnitEnum|null
    {
        return __('admin.groups.settings');
    }

    public function getTitle(): string
    {
        return __('admin.nav.pricing_settings');
    }

    protected static ?int $navigationSort = 10;

    protected static string $routePath = 'pricing-settings';

    protected string $view = 'filament.pages.pricing-settings';

    public ?array $data = [];

    public float $savedRatePercent = ItemPriceSettingsService::DEFAULT_RATE_PERCENT;

    public bool $savedMetalDeductionsEnabled = ItemPriceSettingsService::DEFAULT_METAL_DEDUCTIONS_ENABLED;

    public float $savedPlatinumDeductionPercent = ItemPriceSettingsService::DEFAULT_PLATINUM_DEDUCTION_PERCENT;

    public float $savedPalladiumDeductionPercent = ItemPriceSettingsService::DEFAULT_PALLADIUM_DEDUCTION_PERCENT;

    public float $savedRhodiumDeductionPercent = ItemPriceSettingsService::DEFAULT_RHODIUM_DEDUCTION_PERCENT;

    public string $savedFilterCorrectionMode = ItemPriceSettingsService::DEFAULT_FILTER_CORRECTION_MODE;

    public float $savedFilterCandidatePriceThreshold = ItemPriceSettingsService::DEFAULT_FILTER_CANDIDATE_PRICE_THRESHOLD;

    public float $savedFilterCandidateWeightThresholdKg = ItemPriceSettingsService::DEFAULT_FILTER_CANDIDATE_WEIGHT_THRESHOLD_KG;

    public function mount(): void
    {
        $settings = app(ItemPriceSettingsService::class)->pricingConfiguration();
        $this->applySavedSettings($settings);
        $this->form->fill($settings);
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->hasAnyRole(['super_admin', 'admin']) ?? false;
    }

    public function getSubheading(): ?string
    {
        return __('admin.ui.subheadings.pricing');
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('admin.ui.pricing.how'))
                ->description(__('admin.ui.pricing.how_description'))
                ->components([
                    Placeholder::make('pricing_formula_overview')
                        ->label('')
                        ->content($this->pricingStepsCard()),
                ]),
            Section::make(__('admin.ui.pricing.general_rate'))
                ->description(__('admin.ui.pricing.general_rate_description'))
                ->components([
                    $this->percentInput('rate_percent', __('admin.ui.pricing.price_rate'), __('admin.ui.pricing.default_80'))
                        ->live(),
                    Placeholder::make('general_rate_formula')
                        ->label('')
                        ->content(fn (): HtmlString => $this->generalRateCard()),
                ]),
            Section::make(__('admin.ui.pricing.metal_deductions'))
                ->description(__('admin.ui.pricing.metal_deductions_description'))
                ->components([
                    Toggle::make('metal_deductions_enabled')
                        ->label(__('admin.ui.pricing.apply_metal_deductions'))
                        ->live(),
                    $this->percentInput('platinum_deduction_percent', __('admin.ui.pricing.platinum_deduction'), '2%')
                        ->disabled(fn (): bool => ! (bool) ($this->data['metal_deductions_enabled'] ?? false))
                        ->dehydrated(),
                    $this->percentInput('palladium_deduction_percent', __('admin.ui.pricing.palladium_deduction'), '2%')
                        ->disabled(fn (): bool => ! (bool) ($this->data['metal_deductions_enabled'] ?? false))
                        ->dehydrated(),
                    $this->percentInput('rhodium_deduction_percent', __('admin.ui.pricing.rhodium_deduction'), '10%')
                        ->disabled(fn (): bool => ! (bool) ($this->data['metal_deductions_enabled'] ?? false))
                        ->dehydrated(),
                    Placeholder::make('metal_deduction_formula')
                        ->label('')
                        ->content(fn (): HtmlString => $this->metalDeductionCard())
                        ->columnSpanFull(),
                ])
                ->columns(3),
            Section::make(__('admin.ui.pricing.filter_correction'))
                ->description(__('admin.ui.pricing.filter_description'))
                ->components([
                    Placeholder::make('filter_mode_explanation')
                        ->label('')
                        ->content(fn (): HtmlString => $this->filterModeCards())
                        ->columnSpanFull(),
                    Select::make('filter_correction_mode')
                        ->label(__('admin.ui.pricing.correction_mode'))
                        ->options([
                            FilterPriceCorrectionService::MODE_DISABLED => __('admin.ui.pricing.disabled'),
                            FilterPriceCorrectionService::MODE_WEIGHT_ONLY => __('admin.ui.pricing.weight_only'),
                            FilterPriceCorrectionService::MODE_WEIGHT_AND_METALS => __('admin.ui.pricing.weight_metals'),
                        ])
                        ->required()
                        ->live(),
                    TextInput::make('filter_candidate_price_threshold')
                        ->label(__('admin.ui.pricing.candidate_price'))
                        ->numeric()
                        ->minValue(0)
                        ->step(0.01)
                        ->prefix('$')
                        ->required()
                        ->helperText(__('admin.ui.pricing.review_signal_price')),
                    TextInput::make('filter_candidate_weight_threshold_kg')
                        ->label(__('admin.ui.pricing.candidate_weight'))
                        ->numeric()
                        ->minValue(0)
                        ->step(0.001)
                        ->suffix('kg')
                        ->required()
                        ->helperText(__('admin.ui.pricing.review_signal_weight')),
                    Placeholder::make('filter_formula')
                        ->label('')
                        ->content(fn (): HtmlString => $this->filterFormulaCard())
                        ->columnSpanFull(),
                    Placeholder::make('candidate_threshold_help')
                        ->label('')
                        ->content(new HtmlString(
                            '<div class="rounded-xl border border-gray-200 bg-gray-50/70 p-4 text-sm dark:border-white/10 dark:bg-white/5">'
                            .'<div class="font-semibold text-gray-950 dark:text-white">'.__('admin.ui.pricing.review_thresholds').'</div>'
                            .'<div class="mt-1 text-gray-600 dark:text-gray-400">'.__('admin.ui.pricing.review_thresholds_text').'</div>'
                            .'</div>'
                        ))
                        ->columnSpanFull(),
                ])
                ->columns(3),
        ])->statePath('data');
    }

    public function save(): void
    {
        $state = $this->form->getState();
        $settings = app(ItemPriceSettingsService::class)->updatePricingConfiguration(
            (float) $state['rate_percent'],
            (bool) ($state['metal_deductions_enabled'] ?? false),
            (float) $state['platinum_deduction_percent'],
            (float) $state['palladium_deduction_percent'],
            (float) $state['rhodium_deduction_percent'],
            (string) $state['filter_correction_mode'],
            (float) $state['filter_candidate_price_threshold'],
            (float) $state['filter_candidate_weight_threshold_kg'],
        );

        $this->applySavedSettings($settings);
        $this->form->fill($settings);

        Notification::make()
            ->title(__('admin.ui.pricing.saved'))
            ->body(__('admin.ui.pricing.saved_body'))
            ->success()
            ->send();
    }

    /** @return array{rate_percent:float,metal_deductions_enabled:bool,platinum_deduction_percent:float,palladium_deduction_percent:float,rhodium_deduction_percent:float,filter_correction_mode:string,filter_candidate_price_threshold:float,filter_candidate_weight_threshold_kg:float} */
    public function getPreviewConfiguration(): array
    {
        return [
            'rate_percent' => $this->previewPercent('rate_percent', $this->savedRatePercent),
            'metal_deductions_enabled' => (bool) ($this->data['metal_deductions_enabled'] ?? $this->savedMetalDeductionsEnabled),
            'platinum_deduction_percent' => $this->previewPercent('platinum_deduction_percent', $this->savedPlatinumDeductionPercent),
            'palladium_deduction_percent' => $this->previewPercent('palladium_deduction_percent', $this->savedPalladiumDeductionPercent),
            'rhodium_deduction_percent' => $this->previewPercent('rhodium_deduction_percent', $this->savedRhodiumDeductionPercent),
            'filter_correction_mode' => $this->previewMode((string) ($this->data['filter_correction_mode'] ?? $this->savedFilterCorrectionMode)),
            'filter_candidate_price_threshold' => max((float) ($this->data['filter_candidate_price_threshold'] ?? $this->savedFilterCandidatePriceThreshold), 0),
            'filter_candidate_weight_threshold_kg' => max((float) ($this->data['filter_candidate_weight_threshold_kg'] ?? $this->savedFilterCandidateWeightThresholdKg), 0),
        ];
    }

    /** @return array<int,array<string,mixed>> */
    public function getPricePreviewRows(): array
    {
        $preview = $this->getPreviewConfiguration();
        $priceService = app(ItemPriceService::class);

        return Item::query()
            ->apiVisible()
            ->with('carGroup')
            ->orderBy('serial_code')
            ->limit(5)
            ->get()
            ->map(function (Item $item) use ($preview, $priceService): array {
                $currentPrice = $priceService->priceForConfiguration(
                    $item,
                    $this->savedRatePercent,
                    $this->savedPlatinumDeductionPercent,
                    $this->savedPalladiumDeductionPercent,
                    $this->savedRhodiumDeductionPercent,
                    DashboardCurrency::current(),
                    $this->savedMetalDeductionsEnabled,
                    $this->savedFilterCorrectionMode,
                );
                $previewPrice = $priceService->priceForConfiguration(
                    $item,
                    $preview['rate_percent'],
                    $preview['platinum_deduction_percent'],
                    $preview['palladium_deduction_percent'],
                    $preview['rhodium_deduction_percent'],
                    DashboardCurrency::current(),
                    $preview['metal_deductions_enabled'],
                    $preview['filter_correction_mode'],
                );
                $difference = round($previewPrice - $currentPrice, 2);

                return [
                    'serial_code' => (string) $item->serial_code,
                    'model' => (string) $item->model,
                    'group' => (string) ($item->carGroup?->name ?? '-'),
                    'current_price' => $currentPrice,
                    'preview_price' => $previewPrice,
                    'difference' => $difference,
                    'change_percent' => $currentPrice > 0
                        ? round(($difference / $currentPrice) * 100, 2)
                        : 0.0,
                ];
            })
            ->all();
    }

    private function pricingStepsCard(): HtmlString
    {
        return new HtmlString(
            '<div class="grid gap-3 md:grid-cols-2 xl:grid-cols-4">'
            .'<div class="rounded-xl border border-gray-200 bg-gray-50/70 p-4 dark:border-white/10 dark:bg-white/5">'
            .'<div class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">'.__('admin.ui.pricing.step_1').'</div>'
            .'<div class="mt-1 font-semibold text-gray-950 dark:text-white">'.__('admin.ui.pricing.step_1_title').'</div>'
            .'<div class="mt-1 text-sm text-gray-600 dark:text-gray-400">'.__('admin.ui.pricing.step_1_text').'</div>'
            .'</div>'
            .'<div class="rounded-xl border border-gray-200 bg-gray-50/70 p-4 dark:border-white/10 dark:bg-white/5">'
            .'<div class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">'.__('admin.ui.pricing.step_2').'</div>'
            .'<div class="mt-1 font-semibold text-gray-950 dark:text-white">'.__('admin.ui.pricing.step_2_title').'</div>'
            .'<div class="mt-1 text-sm text-gray-600 dark:text-gray-400">'.__('admin.ui.pricing.step_2_text').'</div>'
            .'</div>'
            .'<div class="rounded-xl border border-gray-200 bg-gray-50/70 p-4 dark:border-white/10 dark:bg-white/5">'
            .'<div class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">'.__('admin.ui.pricing.step_3').'</div>'
            .'<div class="mt-1 font-semibold text-gray-950 dark:text-white">'.__('admin.ui.pricing.step_3_title').'</div>'
            .'<div class="mt-1 text-sm text-gray-600 dark:text-gray-400">'.__('admin.ui.pricing.step_3_text').'</div>'
            .'</div>'
            .'<div class="rounded-xl border border-primary-500/30 bg-primary-500/10 p-4">'
            .'<div class="text-xs font-semibold uppercase tracking-wide text-primary-600 dark:text-primary-400">'.__('admin.ui.pricing.step_4').'</div>'
            .'<div class="mt-1 font-semibold text-gray-950 dark:text-white">'.__('admin.ui.pricing.step_4_title').'</div>'
            .'<div class="mt-1 text-sm text-gray-600 dark:text-gray-300">'.__('admin.ui.pricing.step_4_text').'</div>'
            .'</div>'
            .'</div>'
        );
    }

    private function generalRateCard(): HtmlString
    {
        $rate = number_format($this->previewPercent('rate_percent', $this->savedRatePercent), 2);

        return new HtmlString(
            '<div class="rounded-xl border border-primary-500/30 bg-primary-500/10 p-4">'
            .'<div class="text-xs font-semibold uppercase tracking-wide text-primary-600 dark:text-primary-400">'.__('admin.ui.pricing.current_formula').'</div>'
            .'<div class="mt-2 text-base font-semibold text-gray-950 dark:text-white">'.__('admin.ui.pricing.final_price_formula', ['rate' => $rate]).'</div>'
            .'<div class="mt-1 text-sm text-gray-600 dark:text-gray-300">'.__('admin.ui.pricing.formula_updates').'</div>'
            .'</div>'
        );
    }

    private function metalDeductionCard(): HtmlString
    {
        $enabled = (bool) ($this->data['metal_deductions_enabled'] ?? false);
        $rate = number_format($this->previewPercent('rate_percent', $this->savedRatePercent), 2);
        $pt = number_format($this->previewPercent('platinum_deduction_percent', $this->savedPlatinumDeductionPercent), 2);
        $pd = number_format($this->previewPercent('palladium_deduction_percent', $this->savedPalladiumDeductionPercent), 2);
        $rh = number_format($this->previewPercent('rhodium_deduction_percent', $this->savedRhodiumDeductionPercent), 2);

        if (! $enabled) {
            return new HtmlString(
                '<div class="rounded-xl border border-gray-200 bg-gray-50/70 p-4 dark:border-white/10 dark:bg-white/5">'
                .'<div class="flex items-center gap-2"><span class="rounded-full bg-gray-200 px-2 py-0.5 text-xs font-semibold text-gray-700 dark:bg-white/10 dark:text-gray-300">'.__('admin.ui.pricing.off').'</span>'
                .'<span class="font-semibold text-gray-950 dark:text-white">'.__('admin.ui.pricing.deductions_off_title').'</span></div>'
                .'<div class="mt-2 text-sm text-gray-600 dark:text-gray-400">'.__('admin.ui.pricing.deductions_off_formula', ['rate' => $rate]).'</div>'
                .'</div>'
            );
        }

        return new HtmlString(
            '<div class="rounded-xl border border-success-500/30 bg-success-500/10 p-4">'
            .'<div class="flex items-center gap-2"><span class="rounded-full bg-success-500/15 px-2 py-0.5 text-xs font-semibold text-success-700 dark:text-success-400">'.__('admin.ui.pricing.on').'</span>'
            .'<span class="font-semibold text-gray-950 dark:text-white">'.__('admin.ui.pricing.deductions_on_title').'</span></div>'
            .'<div class="mt-2 text-sm text-gray-700 dark:text-gray-300">PT '.$pt.'% · PD '.$pd.'% · RH '.$rh.'%</div>'
            .'<div class="mt-2 rounded-lg bg-white/60 p-3 text-sm text-gray-700 dark:bg-black/10 dark:text-gray-300">'.__('admin.ui.pricing.deductions_on_formula', [
                'pt' => $pt,
                'pd' => $pd,
                'rh' => $rh,
                'rate' => $rate,
            ]).'</div>'
            .'</div>'
        );
    }

    private function filterModeCards(): HtmlString
    {
        $mode = $this->previewMode((string) ($this->data['filter_correction_mode'] ?? $this->savedFilterCorrectionMode));

        $cards = [
            FilterPriceCorrectionService::MODE_DISABLED => [
                'title' => __('admin.ui.pricing.mode_disabled_title'),
                'text' => __('admin.ui.pricing.mode_disabled_text'),
            ],
            FilterPriceCorrectionService::MODE_WEIGHT_ONLY => [
                'title' => __('admin.ui.pricing.mode_weight_title'),
                'text' => __('admin.ui.pricing.mode_weight_text'),
            ],
            FilterPriceCorrectionService::MODE_WEIGHT_AND_METALS => [
                'title' => __('admin.ui.pricing.mode_weight_metals_title'),
                'text' => __('admin.ui.pricing.mode_weight_metals_text'),
            ],
        ];

        $html = '<div class="grid gap-3 lg:grid-cols-3">';

        foreach ($cards as $key => $card) {
            $selected = $key === $mode;
            $classes = $selected
                ? 'border-primary-500/40 bg-primary-500/10'
                : 'border-gray-200 bg-gray-50/70 dark:border-white/10 dark:bg-white/5';
            $badge = $selected
                ? '<span class="rounded-full bg-primary-500/15 px-2 py-0.5 text-xs font-semibold text-primary-700 dark:text-primary-400">'.__('admin.ui.pricing.selected').'</span>'
                : '';

            $html .= '<div class="rounded-xl border p-4 '.$classes.'">'
                .'<div class="flex items-center justify-between gap-2"><div class="font-semibold text-gray-950 dark:text-white">'.$card['title'].'</div>'.$badge.'</div>'
                .'<div class="mt-2 text-sm text-gray-600 dark:text-gray-400">'.$card['text'].'</div>'
                .'</div>';
        }

        return new HtmlString($html.'</div>');
    }

    private function filterFormulaCard(): HtmlString
    {
        $mode = $this->previewMode((string) ($this->data['filter_correction_mode'] ?? $this->savedFilterCorrectionMode));

        [$title, $formula] = match ($mode) {
            FilterPriceCorrectionService::MODE_WEIGHT_ONLY => [
                __('admin.ui.pricing.weight_only_formula_title'),
                __('admin.ui.pricing.weight_only_formula_text'),
            ],
            FilterPriceCorrectionService::MODE_WEIGHT_AND_METALS => [
                __('admin.ui.pricing.weight_metals_formula_title'),
                __('admin.ui.pricing.weight_metals_formula_text'),
            ],
            default => [
                __('admin.ui.pricing.original_formula_title'),
                __('admin.ui.pricing.original_formula_text'),
            ],
        };

        return new HtmlString(
            '<div class="rounded-xl border border-primary-500/30 bg-primary-500/10 p-4">'
            .'<div class="text-xs font-semibold uppercase tracking-wide text-primary-600 dark:text-primary-400">'.__('admin.ui.pricing.selected_calculation').'</div>'
            .'<div class="mt-1 font-semibold text-gray-950 dark:text-white">'.$title.'</div>'
            .'<div class="mt-2 text-sm text-gray-600 dark:text-gray-300">'.$formula.'</div>'
            .'</div>'
        );
    }

    private function percentInput(string $name, string $label, string $helperText): TextInput
    {
        return TextInput::make($name)
            ->label($label)
            ->numeric()
            ->minValue(0)
            ->maxValue(100)
            ->step(0.01)
            ->suffix('%')
            ->required()
            ->live()
            ->helperText($helperText);
    }

    /** @param array<string,mixed> $settings */
    private function applySavedSettings(array $settings): void
    {
        $this->savedRatePercent = $settings['rate_percent'];
        $this->savedMetalDeductionsEnabled = $settings['metal_deductions_enabled'];
        $this->savedPlatinumDeductionPercent = $settings['platinum_deduction_percent'];
        $this->savedPalladiumDeductionPercent = $settings['palladium_deduction_percent'];
        $this->savedRhodiumDeductionPercent = $settings['rhodium_deduction_percent'];
        $this->savedFilterCorrectionMode = $settings['filter_correction_mode'];
        $this->savedFilterCandidatePriceThreshold = $settings['filter_candidate_price_threshold'];
        $this->savedFilterCandidateWeightThresholdKg = $settings['filter_candidate_weight_threshold_kg'];
    }

    private function previewPercent(string $key, float $fallback): float
    {
        $value = $this->data[$key] ?? $fallback;

        return is_numeric($value) ? min(max((float) $value, 0), 100) : $fallback;
    }

    private function previewMode(string $mode): string
    {
        return in_array($mode, [
            FilterPriceCorrectionService::MODE_DISABLED,
            FilterPriceCorrectionService::MODE_WEIGHT_ONLY,
            FilterPriceCorrectionService::MODE_WEIGHT_AND_METALS,
        ], true)
            ? $mode
            : FilterPriceCorrectionService::MODE_DISABLED;
    }
}
