<?php

use App\Filament\Resources\Items\Pages\ListItems;
use App\Models\Item;
use App\Models\User;
use App\Services\Mobile\ItemApiSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Permission::query()->firstOrCreate(['name' => 'manage_items', 'guard_name' => 'web']);
    Role::query()->firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);

    $this->admin = User::factory()->create(['is_active' => true]);
    $this->admin->assignRole('admin');
    $this->admin->givePermissionTo('manage_items');
});

test('item table search matches normalized serial code without spaces or separators', function (): void {
    $matching = Item::factory()->create(['serial_code' => 'GM 16']);
    $other = Item::factory()->create(['serial_code' => 'ABC-99']);

    Livewire::actingAs($this->admin)
        ->test(ListItems::class)
        ->loadTable()
        ->searchTable('gm16')
        ->assertCanSeeTableRecords([$matching])
        ->assertCanNotSeeTableRecords([$other]);
});

test('grouped items uses normalized serial so formatting variants share one duplicate group', function (): void {
    app(ItemApiSettingsService::class)->updateUniqueSerialItems(true);

    $first = Item::factory()->create(['serial_code' => 'GM 16']);
    $second = Item::factory()->create(['serial_code' => 'GM16']);
    $single = Item::factory()->create(['serial_code' => 'ONLY-1']);

    Livewire::actingAs($this->admin)
        ->test(ListItems::class)
        ->set('activeTab', 'grouped')
        ->loadTable()
        ->assertCanSeeTableRecords([$first, $second])
        ->assertCanNotSeeTableRecords([$single]);
});
