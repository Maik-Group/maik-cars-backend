<?php

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('dashboard language route stores supported locale in the session', function (): void {
    $this->from('/admin')
        ->get('/dashboard/language/ar')
        ->assertRedirect('/admin');

    expect(session('dashboard_locale'))->toBe('ar');
});

test('admin panel applies the selected dashboard locale', function (): void {
    $this->withSession(['dashboard_locale' => 'de'])
        ->get('/admin/login')
        ->assertOk();

    expect(app()->getLocale())->toBe('de');
});

test('unsupported dashboard locale is rejected', function (): void {
    $this->get('/dashboard/language/zh')->assertNotFound();
});
