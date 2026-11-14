<?php

use App\Models\User;
use App\Support\DashboardCurrency;
use Illuminate\Support\Facades\Http;

test('dashboard currency defaults to euro and converts USD values', function (): void {
    Http::fake(['*' => Http::response(['rates' => ['EUR' => 0.85]])]);

    expect(DashboardCurrency::current())->toBe('EUR')
        ->and(DashboardCurrency::fromUsd(100.0))->toBe(85.0)
        ->and(DashboardCurrency::format(85.0))->toBe('85.00 EUR');

    $this->withSession(['dashboard_currency' => 'USD']);
    expect(DashboardCurrency::current())->toBe('USD')
        ->and(DashboardCurrency::fromUsd(100.0))->toBe(100.0)
        ->and(DashboardCurrency::format(100.0))->toBe('100.00 USD');
});

test('dashboard currency can switch both ways and rejects unsupported values', function (): void {
    $this->actingAs(new User);

    $this->from('/admin')->get('/dashboard/currency/USD')
        ->assertRedirect('/admin')->assertSessionHas('dashboard_currency', 'USD');

    $this->from('/admin')->get('/dashboard/currency/EUR')
        ->assertRedirect('/admin')->assertSessionHas('dashboard_currency', 'EUR');

    $this->get('/dashboard/currency/GBP')->assertNotFound();
    expect(DashboardCurrency::current())->toBe('EUR');
});
