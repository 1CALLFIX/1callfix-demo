<?php

namespace Tests\Feature\Branding;

use App\Livewire\Settings\Manage as SettingsManage;
use App\Models\Setting;
use App\Models\SocialMediaLink;
use App\Models\User;
use App\Services\BrandingAssetService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/** REF 1CF-BRANDING-LOGO-001 — admin logo, generated favicons, social links, credit line. */
class SiteBrandingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    private function admin(): User
    {
        return User::create([
            'uuid' => (string) Str::uuid(), 'name' => 'Super Admin',
            'phone' => '9'.fake()->unique()->numerify('#########'), 'role' => 'super_admin', 'status' => 'active',
        ]);
    }

    /** 200x100 PNG: opaque red disc in the middle, fully transparent elsewhere. */
    private function transparentPng(): UploadedFile
    {
        $im = imagecreatetruecolor(200, 100);
        imagealphablending($im, false);
        imagesavealpha($im, true);
        imagefill($im, 0, 0, imagecolorallocatealpha($im, 0, 0, 0, 127));
        imagefilledellipse($im, 100, 50, 80, 80, imagecolorallocatealpha($im, 255, 0, 0, 0));
        ob_start();
        imagepng($im);

        return UploadedFile::fake()->createWithContent('logo.png', ob_get_clean());
    }

    public function test_upload_stores_logo_and_generates_transparent_favicons(): void
    {
        Livewire::actingAs($this->admin())->test(SettingsManage::class)
            ->set('brandingLogoUpload', $this->transparentPng())
            ->call('uploadLogo')
            ->assertHasNoErrors();

        $disk = Storage::disk('public');
        foreach (['logo_path', 'logo_display_path', 'favicon_path', 'apple_touch_path'] as $key) {
            $this->assertTrue($disk->exists(Setting::get("branding.{$key}")), $key);
        }

        foreach (['favicon_path' => 32, 'apple_touch_path' => 180] as $key => $size) {
            $img = imagecreatefromstring($disk->get(Setting::get("branding.{$key}")));
            $this->assertSame([$size, $size], [imagesx($img), imagesy($img)]);
            // Corner stays fully transparent (GD alpha 127); centre is opaque.
            $this->assertSame(127, (imagecolorat($img, 0, 0) >> 24) & 127, "$key corner");
            $this->assertSame(0, (imagecolorat($img, intdiv($size, 2), intdiv($size, 2)) >> 24) & 127, "$key centre");
        }
    }

    public function test_reupload_replaces_and_deletes_old_files(): void
    {
        $component = Livewire::actingAs($this->admin())->test(SettingsManage::class);
        $component->set('brandingLogoUpload', $this->transparentPng())->call('uploadLogo');
        $first = Setting::get('branding.logo_path');
        $component->set('brandingLogoUpload', $this->transparentPng())->call('uploadLogo');

        $this->assertNotSame($first, Setting::get('branding.logo_path'));
        Storage::disk('public')->assertMissing($first);
        $this->assertCount(4, Storage::disk('public')->files('branding'));
    }

    public function test_rejects_wrong_type_and_scripted_svg(): void
    {
        $component = Livewire::actingAs($this->admin())->test(SettingsManage::class);

        $component->set('brandingLogoUpload', UploadedFile::fake()->create('logo.pdf', 10, 'application/pdf'))
            ->call('uploadLogo')->assertHasErrors('brandingLogoUpload');

        $evil = UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');
        $component->set('brandingLogoUpload', $evil)->call('uploadLogo')->assertHasErrors('brandingLogoUpload');

        $this->assertNull(Setting::get('branding.logo_path'));
    }

    public function test_clean_svg_is_accepted_and_used_as_favicon(): void
    {
        $svg = UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><circle cx="5" cy="5" r="4"/></svg>');
        Livewire::actingAs($this->admin())->test(SettingsManage::class)
            ->set('brandingLogoUpload', $svg)->call('uploadLogo')->assertHasNoErrors();

        $this->assertSame(Setting::get('branding.logo_path'), Setting::get('branding.favicon_path'));
        $this->assertSame('', Setting::get('branding.apple_touch_path'));
    }

    public function test_social_links_only_render_for_platforms_with_a_url(): void
    {
        Livewire::actingAs($this->admin())->test(SettingsManage::class)
            ->set('brandingSocial.instagram', 'https://instagram.com/1callfix')
            ->set('brandingSocial.facebook', '')
            ->call('saveSiteLinks')->assertHasNoErrors();

        $html = Blade::render('<x-customer.footer />');
        $this->assertStringContainsString('https://instagram.com/1callfix', $html);
        $this->assertStringContainsString('on Instagram', $html);
        $this->assertStringNotContainsString('on Facebook', $html);
        $this->assertStringNotContainsString('on YouTube', $html);
    }

    public function test_social_url_must_be_http_or_https(): void
    {
        Livewire::actingAs($this->admin())->test(SettingsManage::class)
            ->set('brandingSocial.x', 'javascript:alert(1)')
            ->call('saveSiteLinks')->assertHasErrors('brandingSocial.x');
        $this->assertSame(0, SocialMediaLink::count());
    }

    public function test_table_has_unused_oauth_columns_for_future_auto_posting(): void
    {
        $this->assertTrue(Schema::hasColumns('social_media_links', ['platform', 'profile_url', 'access_token', 'token_expiry', 'connected_at']));
    }

    public function test_credit_line_is_blank_on_fresh_install_and_shows_nothing(): void
    {
        $this->assertSame('', BrandingAssetService::creditLine());
        $html = Blade::render('<x-customer.footer />');
        $this->assertStringNotContainsString('Made', $html);
        $this->assertStringNotContainsString('text-red-500', $html);

        $admin = Livewire::actingAs($this->admin())->test(SettingsManage::class);
        $admin->assertSet('brandingFooterCredit', '');
    }

    public function test_credit_line_with_heart_emoji_saves_and_renders(): void
    {
        // U+2764 U+FE0F, i.e. what typing the emoji on most keyboards produces.
        $text = "Made in Love \u{2764}\u{FE0F} with India by 1CallFix";
        Livewire::actingAs($this->admin())->test(SettingsManage::class)
            ->set('brandingFooterCredit', $text)->call('saveSiteLinks')->assertHasNoErrors();

        $this->assertSame($text, Setting::get('branding.footer_credit'));
        $html = Blade::render('<x-customer.footer />');
        $this->assertStringContainsString("Made in Love <span class=\"text-red-500\">\u{2764}&#xFE0E;</span> with India by 1CallFix", $html);

        Livewire::actingAs($this->admin())->test(SettingsManage::class)
            ->set('brandingFooterCredit', '')->call('saveSiteLinks');
        $this->assertStringNotContainsString('Made in Love', Blade::render('<x-customer.footer />'));
    }

    public function test_fallbacks_and_uploaded_logo_in_header_footer_and_head(): void
    {
        // Nothing set: original initial mark, no <img> logo.
        $this->assertStringNotContainsString('<img', Blade::render('<x-customer.header />'));

        Livewire::actingAs($this->admin())->test(SettingsManage::class)
            ->set('brandingLogoUpload', $this->transparentPng())->call('uploadLogo');

        $logo = Setting::get('branding.logo_display_path');
        $this->assertStringContainsString($logo, Blade::render('<x-customer.header />'));
        $this->assertStringContainsString($logo, Blade::render('<x-customer.footer />'));

        $home = $this->get('/')->assertOk()->getContent();
        $this->assertStringContainsString(Setting::get('branding.favicon_path'), $home);
        $this->assertStringContainsString(Setting::get('branding.apple_touch_path'), $home);
    }

    public function test_site_identity_is_global_scope_only(): void
    {
        Livewire::actingAs($this->admin())->test(SettingsManage::class)
            ->set('scopeType', 'city')
            ->call('saveSiteLinks')->assertForbidden();
    }
}
