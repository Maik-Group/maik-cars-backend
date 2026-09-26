<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

use function Pest\Laravel\patchJson;

uses(RefreshDatabase::class);

test('profile update accepts preferred language', function () {
    $user = User::factory()->create([
        'preferred_language' => 'en',
    ]);

    Sanctum::actingAs($user);

    $response = patchJson('/api/profile', [
        'name' => 'Updated Name',
        'email' => $user->email,
        'preferredLanguage' => 'hr',
    ]);

    $response->assertOk();
    $response->assertJsonPath('data.preferredLanguage', 'hr');

    expect($user->fresh()->preferred_language)->toBe('hr');
});

test('profile update rejects invalid preferred language', function () {
    $user = User::factory()->create();

    Sanctum::actingAs($user);

    $response = patchJson('/api/profile', [
        'name' => $user->name,
        'email' => $user->email,
        'preferredLanguage' => 'xx',
    ]);

    $response->assertUnprocessable();
    $response->assertJsonValidationErrors(['preferredLanguage']);
});
