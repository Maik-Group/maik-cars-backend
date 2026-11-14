<?php

use App\Services\Mobile\CurrencyConversionService;
use App\Services\Mobile\MetalsSpotService;
use App\Support\DashboardCurrency;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

beforeEach(function (): void {
    Cache::flush();
    config([
        'services.currency.public_url' => 'https://fx.example.test/latest',
        'services.currency.cache_ttl' => 1800,
        'services.currency.fallback_ttl' => 604800,
        'services.metals.base_url' => 'https://metals.example.test',
        'services.metals.rapidapi_key' => 'test-only',
    ]);
    Http::preventStrayRequests();
});

test('FX rate is fetched cached refreshed and last known is used when unavailable', function (): void {
    Http::fake(['https://fx.example.test/*' => Http::sequence()
        ->push(['rates' => ['EUR' => 0.85]])
        ->push(['rates' => ['EUR' => 0.80]])
        ->push([], 503)]);
    $fx = app(CurrencyConversionService::class);

    expect($fx->convert(100, 'USD', 'EUR'))->toBe(85.0)
        ->and($fx->convert(100, 'USD', 'EUR'))->toBe(85.0)
        ->and($fx->convert(100, 'USD', 'USD'))->toBe(100.0);
    Http::assertSentCount(1);

    $this->travel(31)->minutes();
    expect($fx->convert(100, 'USD', 'EUR'))->toBe(80.0);
    $this->travel(31)->minutes();
    expect($fx->convert(100, 'USD', 'EUR'))->toBe(80.0)
        ->and($fx->convert(100, 'USD', 'EUR'))->toBe(80.0);
    Http::assertSentCount(3);
    $this->travelBack();
});

test('missing invalid or zero FX never becomes an invented one to one conversion', function (): void {
    Http::fake(['https://fx.example.test/*' => Http::sequence()
        ->push([], 503)
        ->push(['rates' => ['EUR' => 0]])
        ->push(['rates' => ['EUR' => 'invalid']])]);
    $fx = app(CurrencyConversionService::class);

    for ($i = 0; $i < 3; $i++) {
        expect(fn () => $fx->convert(100, 'USD', 'EUR'))->toThrow(ServiceUnavailableHttpException::class);
    }
    expect($fx->convert(100, 'USD', 'USD'))->toBe(100.0);
});

test('USD and EUR metal prices use one USD snapshot and the dashboard exchange rate', function (): void {
    Http::fake(function (Request $request) {
        if (str_contains($request->url(), 'fx.example.test')) {
            return Http::response(['rates' => ['EUR' => 0.85]]);
        }

        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
        expect($query['currency'])->toBe('USD');

        return Http::response(['bid' => 1000, 'change' => 20, 'changePercent' => 2]);
    });

    $metals = app(MetalsSpotService::class);
    $usd = $metals->all('USD');
    $eur = $metals->all('EUR');

    expect($usd['data'][0]['price_oz'])->toBe(1000.0)
        ->and($eur['data'][0]['price_oz'])->toBe(850.0)
        ->and($eur['data'][0]['change_oz'])->toBe(17.0)
        ->and($eur['fx_rate'])->toBe(0.85)
        ->and($eur['updated_at'])->toBe($usd['updated_at'])
        ->and(DashboardCurrency::fromUsd(1000))->toBe(850.0);
    Http::assertSentCount(4);
});
