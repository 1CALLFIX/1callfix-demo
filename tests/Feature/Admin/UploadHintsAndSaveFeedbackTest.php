<?php

namespace Tests\Feature\Admin;

use App\Support\ImageSizes;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Owner 2026-11-07: (1) every image upload shows its best size, (2) "Saved" shows beside the Save button on every
 * admin and partner screen, not only at the top of a long page.
 */
class UploadHintsAndSaveFeedbackTest extends TestCase
{
    public function test_every_image_upload_shows_its_best_size(): void
    {
        $missing = [];
        foreach (File::allFiles(resource_path('views')) as $file) {
            $lines = file($file->getPathname());
            foreach ($lines as $i => $line) {
                if (! str_contains($line, 'type="file"')) {
                    continue;
                }
                $tag = implode('', array_slice($lines, $i, 3));
                if (! preg_match('/accept="[^"]*(image|\.jpg|\.png)/', $tag)) {
                    continue; // spreadsheets
                }
                $after = implode('', array_slice($lines, $i, 16));
                if (! str_contains($after, '<x-ui.image-size')) {
                    $missing[] = str_replace(resource_path('views').DIRECTORY_SEPARATOR, '', $file->getPathname()).':'.($i + 1);
                }
            }
        }

        $this->assertSame([], $missing, 'Image uploads without <x-ui.image-size use="..."> under them.');
    }

    /** The MB shown must be the limit the server enforces. use => [component, validated field]. */
    public function test_the_size_shown_matches_the_servers_limit(): void
    {
        $where = [
            'banner' => ['Banners/Manage.php', "'imageFile'"],
            'category_icon' => ['Categories/Manage.php', "'iconFile'"],
            'subcategory_icon' => ['Subcategories/Manage.php', "'iconFile'"],
            'service_cover' => ['Services/Manage.php', "'coverImageFile'"],
            'home_door' => ['HomeSections/Manage.php', "'doorImage.*'"],
            'parcel_picture' => ['SiteControls/Manage.php', "'parcelImageFile'"],
            'parcel_icon' => ['SiteControls/Manage.php', "'parcelIconFile'"],
            'site_logo' => ['Settings/Manage.php', "'brandingLogoUpload'"],
            'franchise_hero' => ['InfoPages/FranchiseSettings.php', "'heroImageFile'"],
            'store_logo' => ['Seller/Shop.php', "'logo'"],
            'store_cover' => ['Seller/Shop.php', "'cover'"],
            'product_photo' => ['Seller/Products.php', "'newUploads.*'"],
            'dish_photo' => ['Seller/Products.php', "'newUploads.*'"],
            'product_camera' => ['Seller/AddProducts.php', "'photos.*'"],
            'evidence_photo' => ['Provider/Jobs/Show.php', "'sparesEvidence.*'"],
            'safety_photo' => ['Rider/Dashboard.php', "'safetyPhoto'"],
            'claim_photo' => ['Customer/Parcel/Show.php', "'claimPhotos.*'"],
            'return_photo' => ['Customer/Shop/OrderShow.php', "'returnPhotos.*'"],
            'kyc_document' => ['Provider/Auth/Register.php', "'documents.*'"],
        ];
        $this->assertEqualsCanonicalizing(array_keys(ImageSizes::USES), array_keys($where), 'every size has a checked limit');

        foreach ($where as $use => [$component, $field]) {
            $src = file_get_contents(app_path('Livewire/'.$component));
            $at = strpos($src, $field.' =>');
            if ($at === false) {
                $at = strpos($src, '$f('.$field.') =>'); // Banners builds its keys with $f()
            }
            $this->assertNotFalse($at, "{$use}: rule for {$field} not found in {$component}");
            $this->assertMatchesRegularExpression('/max:(\d+)/', substr($src, $at, 300));
            preg_match('/max:(\d+)/', substr($src, $at, 300), $m);
            $this->assertSame(ImageSizes::USES[$use][4] * 1024, (int) $m[1], "{$use}: hint says ".ImageSizes::USES[$use][4].' MB, server allows '.$m[1].' KB');
        }
    }

    public function test_the_line_reads_plainly(): void
    {
        $this->assertSame('Best size 1920 × 640 px (3:1 wide) · PNG or JPG · up to 2 MB. Keep words and faces in the middle: phones show less of the sides.', ImageSizes::line('banner'));
        $this->assertSame('A clear phone photo · up to 8 MB.', ImageSizes::line('safety_photo'));
    }

    public function test_every_admin_and_partner_layout_shows_saved_beside_the_button(): void
    {
        foreach (['layouts/admin', 'components/layouts/seller', 'components/layouts/provider', 'components/layouts/rider'] as $layout) {
            $src = file_get_contents(resource_path("views/{$layout}.blade.php"));
            // Once per tab: wire:navigate must not run it again (two notes per save).
            $this->assertStringContainsString("<script data-navigate-once src=\"{{ asset('js/admin-save-feedback.js') }}", $src, $layout);
        }
        $js = file_get_contents(public_path('js/admin-save-feedback.js'));
        $this->assertStringContainsString("insertAdjacentElement('afterend'", $js, 'the note goes right after the pressed button');
        $this->assertStringContainsString('Not saved: fix the fields marked in red', $js);
        $this->assertStringContainsString('if (window.__cfSaveFeedback) { return; }', $js, 'guard against running twice');
    }
}
