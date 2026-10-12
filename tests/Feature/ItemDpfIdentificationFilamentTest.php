<?php

use App\Filament\Resources\Items\Pages\EditItem;
use App\Filament\Resources\Items\Pages\ListItems;
use App\Filament\Resources\Items\Pages\ViewItem;
use App\Models\Item;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Role::query()->firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
    $this->admin = User::factory()->create(['is_active' => true]);
    $this->admin->assignRole('super_admin');
});

test('DPF classification and filter reference persist on the item form', function (): void {
    $item = Item::factory()->create([
        'serial_code' => 'REX062C',
        'is_dpf' => false,
        'filter_reference_code' => null,
    ]);

    Livewire::actingAs($this->admin)
        ->test(EditItem::class, ['record' => $item->getKey()])
        ->fillForm([
            'is_dpf' => true,
            'filter_reference_code' => '208A0-7274-R',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($item->fresh()->is_dpf)->toBeTrue()
        ->and($item->fresh()->filter_reference_code)->toBe('208A0-7274-R');
});

test('DPF flag and filter reference display on the item view page', function (): void {
    $item = Item::factory()->create([
        'is_dpf' => true,
        'filter_reference_code' => 'OEM-TEST-123',
    ]);

    Livewire::actingAs($this->admin)
        ->test(ViewItem::class, ['record' => $item->getKey()])
        ->assertSee('DPF')
        ->assertSee('OEM-TEST-123');
});

test('items list filters the DPF flag independently of pricing mappings', function (): void {
    $dpf = Item::factory()->create(['is_dpf' => true, 'filter_reference_code' => 'FILTER-ABC']);
    $other = Item::factory()->create(['is_dpf' => false]);

    Livewire::actingAs($this->admin)
        ->test(ListItems::class)
        ->loadTable()
        ->filterTable('is_dpf', true)
        ->assertCanSeeTableRecords([$dpf])
        ->assertCanNotSeeTableRecords([$other]);
});
