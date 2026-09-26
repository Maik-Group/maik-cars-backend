<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

use function Pest\Laravel\getJson;
use function Pest\Laravel\patchJson;

uses(RefreshDatabase::class);

test('profile returns and updates the saved discount margin', function (): void {
    $user = User::factory()->create([
        'discount_margin_percent' => -15,
    ]);

    Sanctum::actingAs($user);

    getJson('/api/profile')
        ->assertOk()
        ->assertJsonPath('data.discountMarginPercent', -15);

    patchJson('/api/profile', [
        'name' => $user->name,
        'email' => $user->email,
        'discountMarginPercent' => -52,
    ])
        ->assertOk()
        ->assertJsonPath('data.discountMarginPercent', -52);

    expect((float) $user->fresh()->discount_margin_percent)->toBe(-52.0);
});

test('discount margin is validated within the supported range', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    patchJson('/api/profile', [
        'name' => $user->name,
        'email' => $user->email,
        'discountMarginPercent' => 4,
    ])
        ->assertOk()
        ->assertJsonPath('data.discountMarginPercent', 4);

    patchJson('/api/profile', [
        'name' => $user->name,
        'email' => $user->email,
        'discountMarginPercent' => -101,
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('discountMarginPercent');

    patchJson('/api/profile', [
        'name' => $user->name,
        'email' => $user->email,
        'discountMarginPercent' => 5,
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('discountMarginPercent');
});
