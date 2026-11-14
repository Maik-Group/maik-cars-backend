<?php

namespace App\Support;

use App\Services\Mobile\CurrencyConversionService;

final class DashboardCurrency
{
    public const DEFAULT = 'EUR';

    public static function current(): string
    {
        return session('dashboard_currency') === 'USD' ? 'USD' : self::DEFAULT;
    }

    public static function format(float $amount): string
    {
        return number_format($amount, 2).' '.self::current();
    }

    public static function fromUsd(float $amount): float
    {
        return app(CurrencyConversionService::class)->convert($amount, 'USD', self::current());
    }
}
