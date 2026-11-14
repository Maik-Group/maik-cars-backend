<?php

namespace App\Services\Mobile;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Throwable;

class CurrencyConversionService
{
    private const string CACHE_PREFIX = 'fx_rate_verified_v2:';

    /**
     * Compatibility name: one is valid only for identical currencies.
     * Never substitute parity for an unavailable foreign exchange rate.
     */
    public function rateOrOne(string $from, string $to): float
    {
        $from = strtoupper(trim($from));
        $to = strtoupper(trim($to));

        if ($from === $to) {
            return 1.0;
        }

        $cacheKey = self::CACHE_PREFIX.strtolower($from.'_'.$to);
        $ttl = max(1, (int) config('services.currency.cache_ttl', 1800));
        $lastKnownKey = $cacheKey.':last-known';
        $cached = Cache::get($cacheKey);

        if ($this->validRate($cached)) {
            return (float) $cached;
        }

        try {
            $endpoint = (string) config('services.currency.public_url', 'https://api.frankfurter.app/latest');
            $response = Http::timeout((int) config('services.currency.timeout', 6))
                ->acceptJson()
                ->get($endpoint, ['from' => $from, 'to' => $to]);

            $rate = $response->successful() ? $response->json('rates.'.$to) : null;

            if (! $this->validRate($rate)) {
                throw new \RuntimeException('Invalid exchange rate response.');
            }

            $rate = (float) $rate;
            Cache::put($lastKnownKey, $rate, max($ttl, (int) config('services.currency.fallback_ttl', 604800)));
            Cache::put($cacheKey, $rate, $ttl);

            return $rate;
        } catch (Throwable $exception) {
            Log::warning('Currency conversion fetch failed', [
                'from' => $from,
                'to' => $to,
                'error' => $exception->getMessage(),
            ]);

            $lastKnown = Cache::get($lastKnownKey);

            if ($this->validRate($lastKnown)) {
                // Retry soon, but do not extend the last-known lifetime.
                Cache::put($cacheKey, (float) $lastKnown, min($ttl, 60));

                return (float) $lastKnown;
            }

            throw new ServiceUnavailableHttpException(null, 'Currency exchange rate is temporarily unavailable.');
        }
    }

    private function validRate(mixed $rate): bool
    {
        return is_numeric($rate) && is_finite((float) $rate) && (float) $rate > 0.0;
    }

    public function convert(float $amount, string $from, string $to, int $precision = 2): float
    {
        return round($amount * $this->rateOrOne($from, $to), $precision);
    }
}
