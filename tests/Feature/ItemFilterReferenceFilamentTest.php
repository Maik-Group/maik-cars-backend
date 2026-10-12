<?php

use App\Filament\Resources\ItemFilterMappings\Pages\CreateItemFilterMapping;
use App\Filament\Resources\ItemFilterMappings\Pages\EditItemFilterMapping;
use App\Filament\Resources\ItemFilterMappings\Pages\ListItemFilterMappings;
use App\Filament\Resources\Items\Pages\EditItem;
use App\Filament\Resources\Items\Pages\ViewItem;
use App\Models\Item;
use App\Models\ItemFilterMapping;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Role::query()->firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
    $this->admin = User::factory()->create(['is_active' => true]);
    $this->admin->assignRole('super_admin');
});

test('only items have a filter reference database column; DPF flag and duplicate filter serial are absent', function (): void {
    expect(Schema::hasColumn('items', 'filter_reference_code'))->toBeTrue()
        ->and(Schema::hasColumn('items', 'is_dpf'))->toBeFalse()
        ->and(Schema::hasColumn('item_filter_mappings', 'filter_serial'))->toBeFalse();
});

test('item filter reference is editable and visible without a DPF toggle', function (): void {
    $item = Item::factory()->create(['filter_reference_code' => null]);

    Livewire::actingAs($this->admin)
        ->test(EditItem::class, ['record' => $item->getKey()])
        ->fillForm(['filter_reference_code' => '208A0-7274-R'])
        ->call('save')
        ->assertHasNoFormErrors();

    Livewire::actingAs($this->admin)
        ->test(ViewItem::class, ['record' => $item->getKey()])
        ->assertSee('208A0-7274-R');

    expect($item->fresh()->filter_reference_code)->toBe('208A0-7274-R');
});

test('creating a filter mapping saves its serial to the same item filter reference', function (): void {
    $item = Item::factory()->create([
        'filter_reference_code' => null,
        'weight_kg' => 3.0,
        'pt_ppm' => 100,
        'pd_ppm' => 20,
        'rh_ppm' => 5,
    ]);
    $filter = Item::factory()->create([
        'serial_code' => 'PF1234',
        'weight_kg' => 1.0,
        'pt_ppm' => 0,
        'pd_ppm' => 0,
        'rh_ppm' => 0,
    ]);

    Livewire::actingAs($this->admin)
        ->test(CreateItemFilterMapping::class)
        ->fillForm(['item_id' => $item->id, 'filter_item_id' => $filter->id])
        ->call('create')
        ->assertHasNoFormErrors();

    $mapping = ItemFilterMapping::query()->where('item_id', $item->id)->firstOrFail();
    expect($item->fresh()->filter_reference_code)->toBe('PF1234')
        ->and($mapping->fresh()->filter_serial)->toBe('PF1234');

    Livewire::actingAs($this->admin)
        ->test(ListItemFilterMappings::class)
        ->filterTable('status', ItemFilterMapping::STATUS_APPROVED)
        ->loadTable()
        ->assertCanSeeTableRecords([$mapping]);
});

test('updating the mapping reference updates the item and the computed mapping accessor', function (): void {
    $item = Item::factory()->create(['filter_reference_code' => 'PF0001']);
    $filter = Item::factory()->create(['serial_code' => 'PF0001']);
    $mapping = ItemFilterMapping::factory()->create([
        'item_id' => $item->id,
        'filter_item_id' => $filter->id,
        'status' => ItemFilterMapping::STATUS_APPROVED,
        'detection_method' => 'manual',
    ]);

    Livewire::actingAs($this->admin)
        ->test(EditItemFilterMapping::class, ['record' => $mapping->id])
        ->fillForm(['filter_serial' => 'PF0002'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($item->fresh()->filter_reference_code)->toBe('PF0002')
        ->and($mapping->fresh()->filter_serial)->toBe('PF0002');
});

test('automatic mapping detection does not overwrite a previously entered item reference', function (): void {
    $item = Item::factory()->create(['filter_reference_code' => 'MANUAL-REF']);
    $mapping = ItemFilterMapping::factory()->create([
        'item_id' => $item->id,
        'detection_method' => 'explicit_filter_serial',
        'filter_serial' => 'AUTO-REF',
    ]);

    expect($item->fresh()->filter_reference_code)->toBe('MANUAL-REF')
        ->and($mapping->fresh()->filter_serial)->toBe('MANUAL-REF');
});
