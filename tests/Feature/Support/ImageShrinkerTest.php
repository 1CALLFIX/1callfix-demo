<?php

namespace Tests\Feature\Support;

use App\Support\ImageShrinker;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Automatic shrinking of display-picture uploads (owner 2026-11-07). */
class ImageShrinkerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    private function noisyFile(string $name, int $w, int $h, string $type, bool $transparent = false): UploadedFile
    {
        $img = imagecreatetruecolor($w, $h);
        imagealphablending($img, false);
        imagesavealpha($img, true);
        imagefill($img, 0, 0, imagecolorallocatealpha($img, 0, 0, 0, $transparent ? 127 : 0));
        mt_srand(7);
        for ($i = 0; $i < 3000; $i++) {
            $x = mt_rand(0, $w);
            $y = mt_rand(0, $h);
            imagefilledrectangle($img, $x, $y, $x + mt_rand(5, 60), $y + mt_rand(5, 60),
                imagecolorallocatealpha($img, mt_rand(0, 255), mt_rand(0, 255), mt_rand(0, 255), $transparent ? 60 : 0));
        }
        $path = tempnam(sys_get_temp_dir(), 'shr');
        match ($type) {
            'jpg' => imagejpeg($img, $path, 95),
            'png' => imagepng($img, $path, 0),
            'webp' => imagewebp($img, $path, 95),
        };
        imagedestroy($img);

        return new UploadedFile($path, $name, null, null, true);
    }

    public function test_a_big_jpeg_banner_is_scaled_down_to_still_cover_the_best_size(): void
    {
        $file = $this->noisyFile('big.jpg', 4000, 3000, 'jpg');
        $before = filesize($file->getRealPath());

        $path = ImageShrinker::store($file, 'banners', 'banner');

        $this->assertStringStartsWith('banners/', $path);
        $this->assertStringEndsWith('.jpg', $path);
        $bytes = Storage::disk('public')->get($path);
        [$w, $h] = getimagesizefromstring($bytes);
        // Covers 1920 × 640 on both sides, shape kept (4:3), never cropped.
        $this->assertSame([1920, 1440], [$w, $h]);
        $this->assertLessThan($before, strlen($bytes));
    }

    public function test_a_tall_photo_keeps_its_shape_and_covers_the_box(): void
    {
        $path = ImageShrinker::store($this->noisyFile('tall.jpg', 1500, 6000, 'jpg'), 'banners', 'banner');

        [$w, $h] = getimagesizefromstring(Storage::disk('public')->get($path));
        $this->assertSame([1500, 6000], [$w, $h]); // narrower than the box: never upscaled or cropped
    }

    public function test_a_transparent_png_icon_stays_png_with_transparency(): void
    {
        $path = ImageShrinker::store($this->noisyFile('icon.png', 2000, 2000, 'png', true), 'categories', 'category_icon');

        $this->assertStringEndsWith('.png', $path);
        $img = imagecreatefromstring(Storage::disk('public')->get($path));
        $this->assertSame([512, 512], [imagesx($img), imagesy($img)]);
        $this->assertGreaterThan(0, (imagecolorat($img, 0, 0) >> 24) & 0x7F, 'corner pixel is still see-through');
    }

    public function test_a_webp_product_photo_stays_webp(): void
    {
        $path = ImageShrinker::store($this->noisyFile('p.webp', 3000, 3000, 'webp'), 'products/1', 'product_photo');

        $this->assertStringEndsWith('.webp', $path);
        [$w, $h] = getimagesizefromstring(Storage::disk('public')->get($path));
        $this->assertSame([1000, 1000], [$w, $h]);
    }

    public function test_a_picture_already_small_enough_is_stored_exactly_as_uploaded(): void
    {
        $file = $this->noisyFile('small.png', 300, 300, 'png');
        $original = file_get_contents($file->getRealPath());

        $path = ImageShrinker::store($file, 'categories', 'category_icon');

        $this->assertSame($original, Storage::disk('public')->get($path));
    }

    public function test_gif_svg_and_unknown_uses_are_never_touched(): void
    {
        $gif = UploadedFile::fake()->image('a.gif', 3000, 3000);
        $this->assertNull(ImageShrinker::shrink($gif->getRealPath(), 'banner'));

        $svg = UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"/>');
        $this->assertNull(ImageShrinker::shrink($svg->getRealPath(), 'banner'));

        $jpg = $this->noisyFile('x.jpg', 3000, 3000, 'jpg');
        $this->assertNull(ImageShrinker::shrink($jpg->getRealPath(), 'no_such_use'));
        $this->assertNull(ImageShrinker::shrink($jpg->getRealPath(), 'kyc_document'), 'proof uses have no size: kept original');
    }

    public function test_an_unreadable_file_is_stored_as_uploaded_and_never_blocks_the_upload(): void
    {
        $bad = UploadedFile::fake()->createWithContent('broken.jpg', "\xFF\xD8\xFFnot really a jpeg");

        $path = ImageShrinker::store($bad, 'banners', 'banner');

        $this->assertSame("\xFF\xD8\xFFnot really a jpeg", Storage::disk('public')->get($path));
    }

    /** Every display-picture upload goes through the shrinker; proof photos stay original. */
    public function test_display_uploads_are_shrunk_and_proof_uploads_are_not(): void
    {
        $shrunk = [
            'Banners/Manage.php' => "'banner'",
            'Categories/Manage.php' => "'category_icon'",
            'Subcategories/Manage.php' => "'subcategory_icon'",
            'Services/Manage.php' => "'service_cover'",
            'HomeSections/Manage.php' => "'home_door'",
            'SiteControls/Manage.php' => "'parcel_picture'",
            'InfoPages/FranchiseSettings.php' => "'franchise_hero'",
            'Seller/Shop.php' => "'store_cover'",
            'Seller/Products.php' => "'product_photo'",
        ];
        foreach ($shrunk as $file => $use) {
            $src = file_get_contents(app_path('Livewire/'.$file));
            $this->assertStringContainsString('ImageShrinker::store(', $src, $file);
            $this->assertStringContainsString($use, $src, $file);
            $this->assertDoesNotMatchRegularExpression('/->store\(\s*[\'"](banners|categories|subcategories|services|site\/|stores\/|products\/)/', $src, $file.' still stores unshrunk');
        }

        foreach (['Livewire/Provider/Jobs/Show.php', 'Http/Controllers/API/JobJourneyController.php', 'Actions/RecordParcelSafetyPhotoAction.php', 'Services/Parcel/ParcelProtectionClaimService.php', 'Livewire/Customer/Shop/OrderShow.php'] as $proof) {
            $this->assertStringNotContainsString('ImageShrinker', file_get_contents(app_path($proof)), $proof.' is proof: keep the original');
        }
    }
}
