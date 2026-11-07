<?php

namespace Tests\Feature\Console;

use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\ServiceSubcategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Owner 2026-11-07: ImgBB pictures are copied onto our own server, shrunk, with a dry run and an undo. */
class CopyRemoteImagesTest extends TestCase
{
    use RefreshDatabase;

    private function jpeg(int $w, int $h): string
    {
        $im = imagecreatetruecolor($w, $h);
        imagefilledrectangle($im, 0, 0, (int) ($w / 2), (int) ($h / 2), imagecolorallocate($im, 200, 40, 40));
        ob_start();
        imagejpeg($im, null, 95);

        return (string) ob_get_clean();
    }

    /** @return array{0: ServiceCategory, 1: ServiceSubcategory, 2: Service} */
    private function catalog(): array
    {
        $cat = ServiceCategory::create([
            'module' => 'service', 'name' => 'Cat', 'slug' => 'cat',
            'image' => 'https://i.ibb.co/abc/cat.jpg', 'sort_order' => 1, 'is_active' => true,
        ]);
        $sub = ServiceSubcategory::create([
            'category_id' => $cat->id, 'name' => 'Sub', 'slug' => 'sub',
            'image' => 'https://i.ibb.co/abc/broken.jpg', 'sort_order' => 1, 'is_active' => true,
        ]);
        $svc = Service::create([
            'category_id' => $cat->id, 'name' => 'Svc', 'slug' => 'svc', 'cover_image' => 'https://i.ibb.co/abc/big.jpg',
            'base_price' => 200, 'price_type' => 'fixed', 'duration_estimate_mins' => 30,
            'is_active' => true, 'location_required' => true, 'age_restriction' => false, 'sort_order' => 1,
        ]);

        return [$cat, $sub, $svc];
    }

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Storage::fake('local');
        Sleep::fake();
        Http::fake([
            'i.ibb.co/abc/cat.jpg' => Http::response($this->jpeg(300, 300), 200, ['Content-Type' => 'image/jpeg']),
            'i.ibb.co/abc/big.jpg' => Http::response($this->jpeg(3000, 2000), 200, ['Content-Type' => 'image/jpeg']),
            'i.ibb.co/abc/broken.jpg' => Http::response('<html>gone</html>', 200),
        ]);
    }

    public function test_dry_run_changes_nothing(): void
    {
        [$cat, , $svc] = $this->catalog();

        $this->artisan('images:copy-remote')->expectsOutputToContain('Dry run')->assertFailed(); // one broken picture

        $this->assertSame('https://i.ibb.co/abc/cat.jpg', $cat->fresh()->image);
        $this->assertSame('https://i.ibb.co/abc/big.jpg', $svc->fresh()->cover_image);
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_apply_copies_shrinks_and_points_items_at_our_copy(): void
    {
        [$cat, $sub, $svc] = $this->catalog();
        $local = ServiceCategory::create([
            'module' => 'service', 'name' => 'Own', 'slug' => 'own',
            'image' => 'categories/own.png', 'sort_order' => 2, 'is_active' => true,
        ]);

        $this->artisan('images:copy-remote --apply')->assertFailed();

        $catPath = $cat->fresh()->image;
        $this->assertStringStartsWith('categories/', $catPath);
        Storage::disk('public')->assertExists($catPath);

        $svcPath = $svc->fresh()->cover_image;
        $this->assertStringStartsWith('services/', $svcPath);
        [$w, $h] = getimagesizefromstring(Storage::disk('public')->get($svcPath));
        $this->assertLessThan(3000, $w, 'big picture was shrunk');
        $this->assertSame(round(3000 / 2000, 2), round($w / $h, 2), 'shape kept');

        $this->assertSame('https://i.ibb.co/abc/broken.jpg', $sub->fresh()->image, 'not a picture: left as it was');
        $this->assertSame('categories/own.png', $local->fresh()->image, 'our own pictures untouched');

        // Undo puts the old addresses back.
        $log = Storage::disk('local')->allFiles('image-moves')[0];
        $this->artisan('images:copy-remote', ['--undo' => 'storage/app/'.$log])->assertSuccessful();
        $this->assertSame('https://i.ibb.co/abc/cat.jpg', $cat->fresh()->image);
        $this->assertSame('https://i.ibb.co/abc/big.jpg', $svc->fresh()->cover_image);
    }

    public function test_running_it_again_does_nothing_more(): void
    {
        $this->catalog();
        $this->artisan('images:copy-remote --apply');
        $files = count(Storage::disk('public')->allFiles());

        $this->artisan('images:copy-remote --apply')->expectsOutputToContain('Moved: 0 of 1 pictures');
        $this->assertCount($files, Storage::disk('public')->allFiles());
    }

    public function test_only_imgbb_addresses_count(): void
    {
        $c = \App\Console\Commands\CopyRemoteImages::class;
        $this->assertTrue($c::isRemote('https://i.ibb.co/x/y.jpg'));
        $this->assertTrue($c::isRemote('https://ibb.co/x'));
        $this->assertFalse($c::isRemote('https://www.1callfix.com/storage/x.jpg'));
        $this->assertFalse($c::isRemote('https://notibb.co/x.jpg'));
        $this->assertFalse($c::isRemote('categories/x.png'));
    }
}
