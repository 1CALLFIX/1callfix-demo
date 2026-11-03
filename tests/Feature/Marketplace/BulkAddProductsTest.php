<?php

namespace Tests\Feature\Marketplace;

use App\Jobs\ReadProductPhotoJob;
use App\Livewire\Seller\AddProducts;
use App\Livewire\Seller\Products;
use App\Models\CatalogItem;
use App\Models\MarketplaceCategory;
use App\Models\PendingChange;
use App\Models\Product;
use App\Models\ProductPhotoScan;
use App\Models\Setting;
use App\Services\Catalog\SellerProductSheet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\Feature\Support\MarketplaceFixtureHelpers;
use Tests\TestCase;

/**
 * Owner 2026-11-03: "every shop has many products; if adding them is hard they will not join". Proves the master list
 * load, "Add from list", "From a photo" (Claude reads it in the background; the seller checks before adding) and the
 * seller's own Excel download / upload — all under the usual rules (approval for new items, blocked words, a new name
 * on a live item waits, no duplicates).
 */
class BulkAddProductsTest extends TestCase
{
    use BookingFixtureHelpers;
    use MarketplaceFixtureHelpers;
    use RefreshDatabase;

    private array $w;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Storage::fake('public');
        [$country, $city, $franchise, $zone] = $this->makeFranchiseTree();
        $this->activateMarketplaceModuleFor($franchise);
        $owner = $this->makeStoreOwner($franchise, $zone);
        $store = $this->makeStore($franchise, $zone, $owner, ['name' => 'Lakshmi Stores']);
        $this->w = compact('franchise', 'zone', 'owner', 'store');
    }

    private function csv(string $body): string
    {
        $path = tempnam(sys_get_temp_dir(), 'cat').'.csv';
        file_put_contents($path, "\xEF\xBB\xBF".$body);

        return $path;
    }

    private function loadList(): void
    {
        $file = $this->csv("category,sub_category,product_name,phase\nClothing,Men's Topwear,T-Shirt Round Neck,Launch\nClothing,Men's Topwear,T-Shirt Polo,Launch\nGrocery,Staples,Basmati Rice,Later\n");
        $this->artisan('catalog:load-master', ['--file' => $file])->assertSuccessful();
    }

    private function seller()
    {
        return Livewire::actingAs($this->w['owner']->user)->test(AddProducts::class);
    }

    public function test_the_master_list_loads_once_with_clean_categories_launch_on_later_off(): void
    {
        $file = $this->csv("category,sub_category,product_name,phase\nClothing,Men's Topwear,T-Shirt Round Neck,Launch\nGrocery,Staples,Basmati Rice,Later\n");
        $this->artisan('catalog:load-master', ['--file' => $file, '--dry-run' => true])->assertSuccessful();
        $this->assertSame(0, CatalogItem::count(), 'a dry run changes nothing');

        $this->artisan('catalog:load-master', ['--file' => $file])->assertSuccessful();
        $this->assertSame(2, CatalogItem::count());
        $clothing = MarketplaceCategory::whereNull('parent_id')->where('name', 'Clothing')->sole();
        $this->assertSame('clothing', $clothing->slug, 'clean link, no random code');
        $this->assertTrue($clothing->is_active);
        $this->assertFalse(MarketplaceCategory::whereNull('parent_id')->where('name', 'Grocery')->sole()->is_active, 'Later = off');
        $tops = MarketplaceCategory::where('parent_id', $clothing->id)->sole();
        $this->assertSame($tops->id, CatalogItem::where('name', 'T-Shirt Round Neck')->sole()->marketplace_category_id);

        // Running it again adds nothing and never changes an existing category.
        $clothing->update(['is_active' => false]);
        $this->artisan('catalog:load-master', ['--file' => $file])->assertSuccessful();
        $this->assertSame(2, CatalogItem::count());
        $this->assertFalse($clothing->fresh()->is_active);
        $this->assertSame(1, MarketplaceCategory::whereNull('parent_id')->where('name', 'Clothing')->count());

        // The real list in the project loads too.
        $this->artisan('catalog:load-master', ['--dry-run' => true])->assertSuccessful()->expectsOutputToContain('804 items');
    }

    public function test_a_seller_picks_from_the_list_types_price_and_stock_and_adds_them_all(): void
    {
        $this->loadList();
        $round = CatalogItem::where('name', 'T-Shirt Round Neck')->sole();
        $polo = CatalogItem::where('name', 'T-Shirt Polo')->sole();
        Product::create(['store_id' => $this->w['store']->id, 'name' => 'T-Shirt Polo', 'price' => 400, 'stock' => 1, 'tax_percent' => 0]);

        $c = $this->seller()->set('category', 'Clothing')->assertSee('T-Shirt Round Neck')->assertSee('In your shop')
            ->call('pickItem', $round->id)->assertCount('rows', 1)
            ->call('addAll')->assertHasErrors(['rows.c'.$round->id.'.price', 'rows.c'.$round->id.'.stock'])
            ->set('rows.c'.$round->id.'.price', '299')->set('rows.c'.$round->id.'.stock', '12')
            ->set('rows.c'.$round->id.'.unit_value', '1')->set('rows.c'.$round->id.'.unit', 'piece')
            ->call('addAll')->assertHasNoErrors()->assertSee('1 products added. They show to customers once approved.')
            ->assertCount('rows', 0);

        $p = Product::where('name', 'T-Shirt Round Neck')->sole();
        $this->assertSame($this->w['store']->id, $p->store_id);
        $this->assertSame($round->marketplace_category_id, $p->marketplace_category_id);
        $this->assertEquals(299, $p->price);
        $this->assertSame(12, (int) $p->stock);
        $this->assertSame('1 piece', $p->unitLabel());
        $this->assertFalse($p->is_approved, 'a new item waits for approval');
        $this->assertSame('t-shirt-round-neck', $p->slug);
    }

    public function test_a_blocked_word_and_a_duplicate_are_never_added(): void
    {
        Setting::updateOrCreate(['key' => 'marketplace.blocked_product_words', 'scope_type' => 'global', 'scope_id' => null], ['value' => 'tobacco']);
        Product::create(['store_id' => $this->w['store']->id, 'name' => 'Steel Plate', 'price' => 50, 'stock' => 1, 'tax_percent' => 0]);
        $this->seller()->set('rows', [
            'p1_0' => ['name' => 'Chewing Tobacco', 'category_id' => null, 'price' => '10', 'stock' => '5', 'unit_value' => '', 'unit' => '', 'from' => 'photo'],
            'p1_1' => ['name' => 'steel plate', 'category_id' => null, 'price' => '60', 'stock' => '5', 'unit_value' => '', 'unit' => '', 'from' => 'photo'],
            'p1_2' => ['name' => 'Steel Glass', 'category_id' => null, 'price' => '40', 'stock' => '5', 'unit_value' => '', 'unit' => '', 'from' => 'photo'],
        ])->call('addAll')->assertSee('1 products added')->assertSee('“tobacco” cannot be sold')->assertSee('already in your shop');

        $this->assertSame(['Steel Glass', 'Steel Plate'], Product::orderBy('name')->pluck('name')->all());
    }

    private function claudeReply(array $items, string $stop = 'end_turn'): array
    {
        return [
            'id' => 'msg_1', 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-opus-5-5', 'stop_reason' => $stop,
            'content' => [['type' => 'text', 'text' => json_encode(['items' => $items, 'notes' => ''])]],
            'usage' => ['input_tokens' => 1800, 'output_tokens' => 400],
        ];
    }

    public function test_from_a_photo_the_products_are_read_in_the_background_and_checked_before_adding(): void
    {
        config(['services.ai.anthropic.api_key' => 'test-key']);
        $this->loadList();
        $tops = MarketplaceCategory::where('name', "Men's Topwear")->sole();
        Queue::fake();

        $c = $this->seller()->set('tab', 'photo')->assertSee('Take photo')
            ->set('photos', [UploadedFile::fake()->image('list.jpg', 3000, 2000)])->call('readPhotos')->assertHasNoErrors()
            ->assertSee('Reading your photo');
        $scan = ProductPhotoScan::sole();
        Queue::assertPushed(ReadProductPhotoJob::class, fn ($job) => $job->scanId === $scan->id);
        Storage::disk('local')->assertExists($scan->photo_paths[0]);
        $photoPath = $scan->photo_paths[0];
        [$w] = getimagesizefromstring(Storage::disk('local')->get($photoPath));
        $this->assertSame(2000, $w, 'downsized to 2000 px');

        Http::fake(['api.anthropic.com/*' => Http::response($this->claudeReply([
            ['name' => 'Aashirvaad Atta', 'price' => 245, 'unit_value' => 5, 'unit' => 'kg', 'quantity' => null, 'category_id' => null],
            ['name' => 'Polo T-Shirt', 'price' => null, 'unit_value' => null, 'unit' => 'barrel', 'quantity' => 4, 'category_id' => $tops->id],
            ['name' => '', 'price' => 10, 'unit_value' => null, 'unit' => null, 'quantity' => null, 'category_id' => 999999],
        ]))]);
        (new ReadProductPhotoJob($scan->id))->handle(app(\App\Services\Ai\ProductPhotoReader::class));

        Http::assertSent(function (Request $r) {
            $body = $r->data();

            return $r->hasHeader('x-api-key', 'test-key') && $body['model'] === 'claude-opus-5-5'
                && $body['messages'][0]['content'][0]['type'] === 'image'
                && $body['messages'][0]['content'][0]['source']['media_type'] === 'image/jpeg'
                && $body['output_config']['format']['type'] === 'json_schema'
                && ! array_key_exists('temperature', $body);
        });
        $scan->refresh();
        $this->assertSame('done', $scan->status);
        $this->assertCount(2, $scan->items, 'an item without a name is dropped');
        $this->assertNull($scan->items[1]['unit'], 'a unit not in the list is dropped');
        Storage::disk('local')->assertMissing($photoPath); // the photo is deleted once read
        $this->assertNull($scan->photo_paths);

        $c->call('checkScan')->assertSee('Read 2 products from the photo')->assertCount('rows', 2)
            ->assertSet('rows.p'.$scan->id.'_0.price', '245')->assertSet('rows.p'.$scan->id.'_0.unit', 'kg')
            ->assertSet('rows.p'.$scan->id.'_1.category_id', $tops->id)
            ->call('addAll')->assertHasErrors('rows.p'.$scan->id.'_0.stock') // nothing is added until the seller fills it in
            ->set('rows.p'.$scan->id.'_0.stock', '20')->set('rows.p'.$scan->id.'_1.price', '350')->call('addAll')->assertHasNoErrors();
        $this->assertSame(2, Product::count());
        $this->assertSame('5 kg', Product::where('name', 'Aashirvaad Atta')->sole()->unitLabel());
    }

    public function test_a_refused_or_unreadable_photo_tells_the_seller_and_nothing_is_added(): void
    {
        config(['services.ai.anthropic.api_key' => 'test-key']);
        Queue::fake();
        $c = $this->seller()->set('photos', [UploadedFile::fake()->image('x.jpg', 400, 400)])->call('readPhotos');
        $scan = ProductPhotoScan::sole();
        Http::fake(['api.anthropic.com/*' => Http::response($this->claudeReply([], 'refusal'))]);
        (new ReadProductPhotoJob($scan->id))->handle(app(\App\Services\Ai\ProductPhotoReader::class));
        $this->assertSame('failed', $scan->fresh()->status);
        $c->call('checkScan')->assertSee('This photo cannot be read')->assertCount('rows', 0);
        $this->assertSame(0, Product::count());

        // Running the same scan again does nothing (each read is paid once).
        Http::fake(['api.anthropic.com/*' => Http::response($this->claudeReply([['name' => 'X', 'price' => 1, 'unit_value' => null, 'unit' => null, 'quantity' => 1, 'category_id' => null]]))]);
        (new ReadProductPhotoJob($scan->id))->handle(app(\App\Services\Ai\ProductPhotoReader::class));
        Http::assertNothingSent();
    }

    public function test_the_photo_way_is_off_without_the_key_or_the_switch_and_capped_per_day(): void
    {
        Queue::fake();
        config(['services.ai.anthropic.api_key' => null]);
        $this->seller()->set('tab', 'photo')->assertSee('not switched on yet')
            ->set('photos', [UploadedFile::fake()->image('a.jpg', 300, 300)])->call('readPhotos')->assertSee('not switched on yet');
        $this->assertSame(0, ProductPhotoScan::count());

        config(['services.ai.anthropic.api_key' => 'test-key']);
        Setting::updateOrCreate(['key' => 'marketplace.photo_list_daily_limit', 'scope_type' => 'global', 'scope_id' => null], ['value' => '1']);
        $this->seller()->set('photos', [UploadedFile::fake()->image('a.jpg', 300, 300)])->call('readPhotos');
        $this->seller()->set('photos', [UploadedFile::fake()->image('b.jpg', 300, 300)])->call('readPhotos')->assertSee('the most for one day');
        $this->assertSame(1, ProductPhotoScan::count());
        Queue::assertPushed(ReadProductPhotoJob::class, 1);
    }

    public function test_the_seller_changes_many_products_in_excel(): void
    {
        $store = $this->w['store'];
        $bottle = Product::create(['store_id' => $store->id, 'name' => 'Steel Bottle', 'price' => 200, 'stock' => 5, 'tax_percent' => 0, 'is_approved' => true]);
        $plate = Product::create(['store_id' => $store->id, 'name' => 'Steel Plate', 'price' => 50, 'stock' => 9, 'tax_percent' => 0, 'is_approved' => true]);
        [, , $f2, $z2] = $this->makeFranchiseTree();
        $other = Product::create(['store_id' => $this->makeStore($f2, $z2)->id, 'name' => 'Other', 'price' => 1, 'stock' => 1, 'tax_percent' => 0]);

        $csv = app(SellerProductSheet::class)->export($store);
        $this->assertStringContainsString('id,name,price,discount_percent,stock,show', $csv);
        $this->assertStringContainsString("{$bottle->id},\"Steel Bottle\",200,,5,yes", $csv);
        $this->assertStringNotContainsString('Other', $csv);

        $upload = UploadedFile::fake()->createWithContent('mine.csv', "id,name,price,discount_percent,stock,show\n"
            ."{$bottle->id},Steel Bottle,220,10,7,yes\n"
            ."{$plate->id},Steel Dinner Plate,50,,9,no\n"
            .",Steel Glass,40,,30,yes\n"
            ."{$other->id},Other,5,,1,yes\n"
            .",Bad Row,abc,,1,yes\n");
        Livewire::actingAs($this->w['owner']->user)->test(Products::class)->set('sheet', $upload)->call('uploadSheet')
            ->assertSee('2 changed, 1 added, 1 changes waiting for approval')->assertSee('2 rows were skipped')
            ->assertSee('is not in your shop')->assertSee('the price must be a number');

        $this->assertEquals(220, $bottle->fresh()->price);
        $this->assertEquals(10, $bottle->fresh()->discount_percent);
        $this->assertSame(7, (int) $bottle->fresh()->stock);
        $this->assertFalse($plate->fresh()->is_active, 'show = no hides it at once');
        $this->assertSame('Steel Plate', $plate->fresh()->name, 'the new name waits');
        $this->assertSame('Steel Dinner Plate', PendingChange::pending()->sole()->new_values['name']);
        $this->assertTrue(Product::where('store_id', $store->id)->where('name', 'Steel Glass')->exists());
        $this->assertEquals(1, $other->fresh()->price, "another shop's product is never touched");
    }

    /** Owner 2026-11-03: a "photos" column with direct links (e.g. postimg.cc) - light files, fast pages. */
    public function test_the_excel_file_takes_direct_photo_links(): void
    {
        $store = $this->w['store'];
        Storage::disk('public')->put('products/'.$store->id.'/own.jpg', 'x');
        $bottle = Product::create(['store_id' => $store->id, 'name' => 'Steel Bottle', 'price' => 200, 'stock' => 5, 'tax_percent' => 0, 'is_approved' => true, 'images' => ['products/'.$store->id.'/own.jpg']]);
        $own = Product::photoUrl('products/'.$store->id.'/own.jpg');
        $this->assertStringContainsString($own, app(SellerProductSheet::class)->export($store), 'the file gives out the photo links');

        $upload = UploadedFile::fake()->createWithContent('mine.csv', "id,name,price,discount_percent,stock,show,photos\n"
            ."{$bottle->id},Steel Bottle,200,,5,yes,{$own} | https://i.postimg.cc/Ab12Cd/bottle-side.jpg\n"
            .",Basmati Rice,70,,40,yes,https://i.postimg.cc/Xy9/basmati.png\n"
            .",Steel Plate,50,,9,yes,https://postimg.cc/Ab12Cd\n");
        Livewire::actingAs($this->w['owner']->user)->test(Products::class)->set('sheet', $upload)->call('uploadSheet')
            ->assertSee('1 added')->assertSee('1 changes waiting for approval')->assertSee('Direct link');

        // A live item's new photos wait for approval; the one already on 1CallFix stays as its stored file.
        $this->assertSame(['products/'.$store->id.'/own.jpg'], $bottle->fresh()->images);
        $this->assertSame(['products/'.$store->id.'/own.jpg', 'https://i.postimg.cc/Ab12Cd/bottle-side.jpg'], PendingChange::pending()->sole()->new_values['images']);
        $this->assertSame(['https://i.postimg.cc/Xy9/basmati.png'], Product::where('name', 'Basmati Rice')->sole()->image_urls);
        $this->assertFalse(Product::where('name', 'Steel Plate')->exists(), 'a page link is refused, not saved as a broken photo');
    }
}
