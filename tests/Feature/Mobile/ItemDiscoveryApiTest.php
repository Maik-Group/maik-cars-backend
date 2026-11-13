<?php

use App\Models\CarGroup;
use App\Models\ExtraCode;
use App\Models\Item;
use App\Models\ItemFilterMapping;
use App\Models\User;
use App\Services\Credits\CreditWalletService;
use App\Services\Mobile\ItemPriceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

use function Pest\Laravel\getJson;

function itemDiscoveryApiAttachImage(Item $item): void
{
    $path = tempnam(sys_get_temp_dir(), 'item_discovery_');

    if ($path === false) {
        throw new RuntimeException('Failed to create a temporary item image.');
    }

    $pngPath = $path.'.png';
    @unlink($path);

    $image = imagecreatetruecolor(48, 48);
    $background = imagecolorallocate($image, 232, 232, 232);
    $accent = imagecolorallocate($image, 92, 92, 92);

    imagefill($image, 0, 0, $background);
    imagefilledellipse($image, 24, 24, 30, 30, $accent);
    imagepng($image, $pngPath);
    imagedestroy($image);

    $item->addMedia($pngPath)->toMediaCollection('images');
}

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Storage::fake('public');
});

test('car groups endpoint includes API-visible items even when they have no image', function (): void {
    $withImageGroup = CarGroup::factory()->create(['name' => 'BMW']);
    $withoutImageGroup = CarGroup::factory()->create(['name' => 'OPEL']);
    $invalidGroup = CarGroup::factory()->create(['name' => 'INVALID']);
    CarGroup::factory()->create(['name' => 'NO ITEMS']);

    $withImage = Item::factory()->create([
        'car_group_id' => $withImageGroup->id,
    ]);
    itemDiscoveryApiAttachImage($withImage);

    Item::factory()->create([
        'car_group_id' => $withoutImageGroup->id,
    ]);

    Item::factory()->create([
        'car_group_id' => $invalidGroup->id,
        'weight_kg' => 0,
    ]);

    $response = getJson('/api/car_groups');

    $response->assertOk();
    $response->assertJsonCount(2, 'data');
    $response->assertJsonFragment(['id' => $withImageGroup->id]);
    $response->assertJsonFragment(['id' => $withoutImageGroup->id]);
    $response->assertJsonMissing(['id' => $invalidGroup->id]);
});

test('item code suggestions return only matching codes from API-visible items', function (): void {
    $group = CarGroup::factory()->create();

    $visibleItem = Item::factory()->create([
        'car_group_id' => $group->id,
        'serial_code' => 'GM10-ABC',
        'normalized_serial' => 'GM10ABC',
    ]);
    itemDiscoveryApiAttachImage($visibleItem);

    ExtraCode::factory()->create([
        'item_id' => $visibleItem->id,
        'code' => 'ALT-GM10',
    ]);

    $withoutImage = Item::factory()->create([
        'car_group_id' => $group->id,
        'serial_code' => 'GM10-NOIMAGE',
        'normalized_serial' => 'GM10NOIMAGE',
    ]);

    ExtraCode::factory()->create([
        'item_id' => $withoutImage->id,
        'code' => 'ALT-GM10-NOIMAGE',
    ]);

    $hiddenItem = Item::factory()->create([
        'car_group_id' => $group->id,
        'serial_code' => 'GM10-HIDDEN',
        'normalized_serial' => 'GM10HIDDEN',
        'weight_kg' => 0,
    ]);

    ExtraCode::factory()->create([
        'item_id' => $hiddenItem->id,
        'code' => 'GM10-HIDDEN-ALT',
    ]);

    $response = getJson('/api/items/codes?search=GM10&limit=10');

    $response->assertOk();
    $response->assertJsonFragment(['GM10-ABC']);
    $response->assertJsonFragment(['ALT-GM10']);
    $response->assertJsonFragment(['GM10-NOIMAGE']);
    $response->assertJsonFragment(['ALT-GM10-NOIMAGE']);
    $response->assertJsonMissing(['GM10-HIDDEN']);
    $response->assertJsonMissing(['GM10-HIDDEN-ALT']);
});

test('needs review items are blocked from item discovery APIs until the review is resolved', function (): void {
    $group = CarGroup::factory()->create(['name' => 'MERCEDES']);

    $blocked = Item::factory()->create([
        'car_group_id' => $group->id,
        'serial_code' => 'KT 6044',
        'normalized_serial' => 'KT6044',
    ]);
    itemDiscoveryApiAttachImage($blocked);

    ItemFilterMapping::factory()->create([
        'item_id' => $blocked->id,
        'status' => ItemFilterMapping::STATUS_NEEDS_REVIEW,
        'evidence' => ['pricing_review' => ['type' => 'component_weight']],
    ]);

    $visible = Item::factory()->create([
        'car_group_id' => $group->id,
        'serial_code' => 'KT 6043',
        'normalized_serial' => 'KT6043',
    ]);
    itemDiscoveryApiAttachImage($visible);

    ItemFilterMapping::factory()->create([
        'item_id' => $visible->id,
        'status' => ItemFilterMapping::STATUS_APPROVED,
    ]);

    $items = getJson('/api/items?sort=serial_code');

    $items->assertOk();
    $items->assertJsonPath('meta.total', 1);
    $items->assertJsonPath('data.0.serialCode', 'KT6043');
    $items->assertJsonMissing(['serialCode' => 'KT6044']);

    $codes = getJson('/api/items/codes?search=KT60&limit=10');

    $codes->assertOk();
    $codes->assertJsonMissing(['KT 6044']);

    getJson("/api/items/{$blocked->id}")->assertNotFound();
    getJson("/api/items/{$visible->id}")->assertOk();

    $blockedMapping = $blocked->filterMapping()->firstOrFail();
    $blockedMapping->update(['status' => ItemFilterMapping::STATUS_IGNORED]);

    getJson("/api/items/{$blocked->id}")->assertOk();
});

test('items without images remain visible and return nullable image fields', function (): void {
    $group = CarGroup::factory()->create(['name' => 'NO IMAGE']);
    $item = Item::factory()->create([
        'car_group_id' => $group->id,
        'serial_code' => 'NO-IMG-1',
        'normalized_serial' => 'NOIMG1',
    ]);

    expect($item->isApiVisible())->toBeTrue()
        ->and(Item::query()->apiVisible()->whereKey($item->id)->exists())->toBeTrue()
        ->and(Item::query()
            ->apiVisible()
            ->whereDoesntHave('media', fn ($query) => $query->where('collection_name', 'images'))
            ->whereKey($item->id)
            ->exists())->toBeTrue();

    $list = getJson('/api/items?text=NO-IMG-1');

    $list->assertOk();
    $list->assertJsonPath('meta.total', 1);
    $list->assertJsonPath('data.0.id', $item->id);
    $list->assertJsonPath('data.0.imageUrl', null);
    $list->assertJsonPath('data.0.imageThumbUrl', null);
    $list->assertJsonPath('data.0.imageDetailUrl', null);

    $details = getJson("/api/items/{$item->id}");

    $details->assertOk();
    $details->assertJsonPath('data.id', $item->id);
    $details->assertJsonPath('data.imageUrl', null);
    $details->assertJsonPath('data.imageThumbUrl', null);
    $details->assertJsonPath('data.imageDetailUrl', null);
});

test('authenticated item prices apply the saved user discount margin', function (): void {
    $user = User::factory()->create([
        'discount_margin_percent' => -20,
    ]);
    Sanctum::actingAs($user);

    $group = CarGroup::factory()->create(['name' => 'Discount test']);
    $item = Item::factory()->create([
        'car_group_id' => $group->id,
        'serial_code' => 'DISC-100',
        'weight_kg' => 2.0,
        'pt_ppm' => 100,
        'pd_ppm' => 10,
        'rh_ppm' => 2,
    ]);
    itemDiscoveryApiAttachImage($item);

    $priceService = Mockery::mock(ItemPriceService::class);
    $priceService->shouldReceive('priceFor')->andReturn(100.0);
    app()->instance(ItemPriceService::class, $priceService);

    $response = getJson("/api/items/{$item->id}");

    $response->assertOk();
    $response->assertJsonPath('data.basePrice', null);
    $response->assertJsonPath('data.price', null);
    giveActiveBillingPlan($user);
    app(CreditWalletService::class)->grant($user, 'paid_cycle', 1, 'discount-qa', now('UTC')->toImmutable()->addWeek());
    $search = $this->postJson('/api/v2/items/search', ['text' => 'DISC-100'], ['Idempotency-Key' => 'discount-qa-search-1234'])->assertOk();
    $this->getJson('/api/v2/items/'.$item->id.'/valuation?searchId='.$search->json('searchId'))
        ->assertOk()->assertJsonPath('data.basePrice', 100)->assertJsonPath('data.price', 80);
    $response->assertJsonPath('data.discountPercent', 20);
    $response->assertJsonPath('data.priceAdjustmentPercent', -20);
});
