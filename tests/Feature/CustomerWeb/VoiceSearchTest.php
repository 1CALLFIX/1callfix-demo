<?php

namespace Tests\Feature\CustomerWeb;

use App\Livewire\Customer\SearchBar;
use App\Models\Setting;
use App\Support\SiteControls;
use App\Support\SiteText;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Owner 2026-11-07: a mic button in the search box, Super Admin on/off, words in Website text. */
class VoiceSearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_mic_is_in_the_search_box_by_default_with_website_text_words(): void
    {
        Livewire::test(SearchBar::class, ['compact' => true])
            ->assertSeeHtml('data-voice-btn')
            ->assertSeeHtml('data-voice-status')
            ->assertSee(SiteText::get('search.voice_start'))
            ->assertSeeHtml('data-voice-listening="'.e(SiteText::get('search.voice_listening')).'"');
    }

    public function test_super_admin_can_switch_it_off(): void
    {
        Setting::set(SiteControls::VOICE_SEARCH, '0');

        Livewire::test(SearchBar::class, ['compact' => true])->assertDontSeeHtml('data-voice-btn');
    }

    public function test_the_clear_button_takes_its_place_once_something_is_typed(): void
    {
        Livewire::test(SearchBar::class, ['compact' => true])
            ->set('term', 'ac repair')
            ->assertDontSeeHtml('data-voice-btn')
            ->assertSee('Clear search');
    }

    public function test_every_voice_word_has_english_and_telugu(): void
    {
        foreach (['search.voice_start', 'search.voice_listening', 'search.voice_failed', 'search.voice_blocked'] as $key) {
            $this->assertArrayHasKey($key, SiteText::TEXTS);
            $this->assertNotSame('', SiteText::TEXTS[$key][2]);
            $this->assertNotSame('', SiteText::TEXTS[$key][3]);
        }
    }

    public function test_the_script_only_shows_the_mic_on_browsers_that_can_listen(): void
    {
        $js = file_get_contents(resource_path('js/search-bar.js'));
        $this->assertStringContainsString('window.SpeechRecognition || window.webkitSpeechRecognition', $js);
        $this->assertStringContainsString("classList.add('cf-voice')", $js);
        $this->assertStringContainsString('inNativeApp ? null', $js, 'no mic inside the Android apps (no microphone access there)');
        $this->assertStringContainsString("rec.lang = 'en-IN'", $js);
        $this->assertStringContainsString("new Event('input', { bubbles: true })", $js, 'spoken words behave like typing');
    }
}
