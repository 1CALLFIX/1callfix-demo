<?php

namespace Tests\Feature\CustomerWeb;

use App\Livewire\HomeSections\Manage as HomeSectionsManage;
use App\Livewire\Languages\Manage;
use App\Livewire\SiteText\Manage as SiteTextManage;
use App\Models\ActivityLog;
use App\Models\Setting;
use App\Support\HomeCards;
use App\Support\Languages;
use App\Support\SiteText;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use Tests\Feature\Rbac\RbacTestHelpers;
use Tests\Feature\Support\LiveCity;
use Tests\TestCase;

/**
 * Owner 2026-11-07: "is it just on/off or again coding" for Tamil, Urdu, Arabic ... — on/off. Admin → Languages adds a
 * language (off), Website text takes its words (boxes or a spreadsheet), switching it on puts it in the top bar.
 */
class LanguagesScreenTest extends TestCase
{
    use LiveCity;
    use RbacTestHelpers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->liveCity();
    }

    private function addTamil(): void
    {
        $this->actingAs($this->makeSuperAdmin());
        Livewire::test(Manage::class)
            ->set('newEnglish', 'Tamil')->set('newName', 'தமிழ்')->set('newCode', 'TA')
            ->call('add')->assertHasNoErrors()->assertSee('Added Tamil.');
        auth()->logout();
    }

    public function test_only_a_super_admin_opens_the_languages_screen(): void
    {
        $this->actingAs($this->makeUserWithNoPermissions())->get(route('admin.languages'))->assertForbidden();
        $this->actingAs($this->makeSuperAdmin())->get(route('admin.languages'))->assertOk()
            ->assertSee('English')->assertSee('Telugu')->assertSee('Add a language');
    }

    public function test_deploying_this_changes_nothing_telugu_keeps_its_old_switch_until_saved(): void
    {
        $this->assertSame(['en'], Languages::activeCodes());
        Setting::set(SiteText::TELUGU_ENABLED, '1');
        Languages::forget();
        $this->assertSame(['en', 'te'], Languages::activeCodes());
    }

    public function test_a_new_language_starts_off_and_its_words_fall_back_to_english(): void
    {
        $this->addTamil();

        $this->assertArrayHasKey('ta', Languages::all());
        $this->assertFalse(Languages::isActive('ta'), 'a new language starts off');
        $this->assertTrue(ActivityLog::where('description', 'Setting '.Languages::SETTING.' changed')->exists());
        $this->post(route('customer.language', 'ta'))->assertNotFound();
        $this->withCookie(SiteText::COOKIE, 'ta')->get('/')->assertOk()->assertSee('<html lang="en"', false);

        // Switched on: two languages = one switch button showing the other one.
        $this->actingAs($this->makeSuperAdmin());
        Livewire::test(Manage::class)->set('rows.ta.on', true)->call('save')->assertSee('Saved.');
        auth()->logout();
        Setting::set('text.header.menu.help.ta', 'உதவி மையம்');

        $this->withCookie(SiteText::COOKIE, 'en')->get('/')->assertOk()->assertSee('data-testid="language-switch"', false)->assertSee('தமிழ்');
        $this->from('/')->post(route('customer.language', 'ta'))->assertRedirect('/')->assertCookie(SiteText::COOKIE, 'ta');
        $this->withCookie(SiteText::COOKIE, 'ta')->get('/')->assertOk()
            ->assertSee('<html lang="ta"', false)
            ->assertSee('உதவி மையம்')            // typed
            ->assertSee('Become a partner'); // not typed yet: English, never blank
    }

    public function test_three_languages_give_a_menu_and_right_to_left_sets_the_page_direction(): void
    {
        Setting::set(Languages::SETTING, json_encode([
            ['code' => 'te', 'on' => true],
            ['code' => 'ur', 'name' => 'اردو', 'english' => 'Urdu', 'rtl' => true, 'on' => true],
        ], JSON_UNESCAPED_UNICODE));

        $this->get('/')->assertOk()->assertSee('<details class="relative" data-testid="language-switch"', false)
            ->assertSee('తెలుగు')->assertSee('اردو')->assertSee('Choose language');
        $this->withCookie(SiteText::COOKIE, 'ur')->get('/')->assertOk()->assertSee('<html lang="ur" dir="rtl"', false);
        $this->withCookie(SiteText::COOKIE, 'te')->get('/')->assertOk()->assertDontSee('dir="rtl" class="h-full"', false);
    }

    public function test_bad_codes_and_duplicates_are_refused_and_only_an_off_added_language_can_be_removed(): void
    {
        $this->addTamil();
        $this->actingAs($this->makeSuperAdmin());

        Livewire::test(Manage::class)->set('newEnglish', 'X')->set('newName', 'X')->set('newCode', 'tamil')->call('add')->assertHasErrors('newCode');
        Livewire::test(Manage::class)->set('newEnglish', 'X')->set('newName', 'X')->set('newCode', 'ta')->call('add')->assertHasErrors('newCode');
        Livewire::test(Manage::class)->set('newEnglish', 'X')->set('newName', 'X')->set('newCode', 'te')->call('add')->assertHasErrors('newCode');

        Livewire::test(Manage::class)->set('rows.ta.on', true)->call('save');
        Livewire::test(Manage::class)->call('remove', 'ta')->assertSee('Switch it off and save first.');
        $this->assertArrayHasKey('ta', Languages::all());

        Livewire::test(Manage::class)->set('rows.ta.on', false)->call('save');
        Livewire::test(Manage::class)->call('remove', 'ta')->assertSee('Removed.');
        Languages::forget();
        $this->assertArrayNotHasKey('ta', Languages::all());

        Livewire::test(Manage::class)->call('remove', 'te')->assertNotFound();
    }

    public function test_the_first_time_language_must_be_switched_on(): void
    {
        $this->addTamil();
        $this->actingAs($this->makeSuperAdmin());

        Livewire::test(Manage::class)->set('defaultLocale', 'ta')->call('save')->assertHasErrors('defaultLocale');
        Livewire::test(Manage::class)->set('rows.te.on', true)->set('defaultLocale', 'te')->call('save')->assertHasNoErrors();
        $this->assertSame('te', Setting::get(SiteText::DEFAULT_LOCALE));
        auth()->logout();
        $this->get('/')->assertOk()->assertSee('<html lang="te"', false);
    }

    public function test_website_text_edits_the_picked_language_and_the_spreadsheet_round_trips(): void
    {
        $this->addTamil();
        $this->actingAs($this->makeSuperAdmin());

        $page = Livewire::withQueryParams(['language' => 'ta'])->test(SiteTextManage::class)
            ->assertSet('language', 'ta')
            ->assertSee(count(SiteText::TEXTS).' of '.count(SiteText::TEXTS).' not typed yet');

        $download = $page->call('download');
        $download->assertFileDownloaded('website-text-ta.csv');

        // A translator fills column 4 and uploads it back: it only fills the boxes until Save.
        $csv = "\xEF\xBB\xBF".'"key","what it is","English","Tamil (ta)"'."\n"
            .'"header.menu.help","Help","Help Center","உதவி மையம்"'."\n"
            .'"share.message","msg","Check out :name on 1CallFix","1CallFix இல் பாருங்கள்"'."\n"
            .'"not.a.key","x","x","y"'."\n";
        $page->set('sheet', UploadedFile::fake()->createWithContent('website-text-ta.csv', $csv))
            ->assertHasNoErrors()
            ->assertSee('Loaded 2 text(s) from the sheet.')
            ->assertSee('keep :name as it is');
        $this->assertNull(Setting::get('text.header.menu.help.ta'), 'not live before Save');

        $page->call('save')->assertSee('Saved 2 change(s).');
        $this->assertSame('உதவி மையம்', Setting::get('text.header.menu.help.ta'));

        // A Telugu sheet is not loaded into Tamil.
        $wrong = '"key","what it is","English","Telugu (te)"'."\n".'"header.menu.help","Help","Help Center","సహాయం"'."\n";
        $page->set('sheet', UploadedFile::fake()->createWithContent('te.csv', $wrong))->assertHasErrors('sheet');
    }

    public function test_homepage_card_offer_lines_take_an_added_language(): void
    {
        $this->addTamil();
        $this->actingAs($this->makeSuperAdmin());

        $page = Livewire::test(HomeSectionsManage::class)->assertSee('Offer line (Tamil)');
        $card = array_key_first($page->get('cardLineEn'));
        $page->set("cardLineOther.ta.{$card}", 'முதல் புக்கிங்கில் ₹100 தள்ளுபடி')->call('save');

        $this->assertSame('முதல் புக்கிங்கில் ₹100 தள்ளுபடி', Setting::get(HomeCards::lineKey($card, 'ta')));
        app()->setLocale('ta');
        HomeCards::forget();
        $this->assertSame('முதல் புக்கிங்கில் ₹100 தள்ளுபடி', HomeCards::line($card));
        app()->setLocale('en');
    }
}
