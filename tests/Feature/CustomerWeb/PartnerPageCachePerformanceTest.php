<?php

namespace Tests\Feature\CustomerWeb;

use App\Models\PartnerBenefit;
use App\Models\Setting;
use App\Support\PartnerPage\PartnerPageSettings as P;
use Illuminate\Cache\Events\CacheHit;
use Illuminate\Cache\Events\CacheMissed;
use Illuminate\Cache\Events\KeyWritten;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/** REF 1CF-PARTNER-PAGE-001: one /partners request reads the partner settings array once and writes nothing for unset partner keys. */
class PartnerPageCachePerformanceTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{gets:int, puts:array<int,string>} */
    private function measure(): array
    {
        $gets = 0;
        $puts = [];
        Event::listen([CacheHit::class, CacheMissed::class], function ($e) use (&$gets) {
            if ($e->key === P::CACHE_KEY) {
                $gets++;
            }
        });
        Event::listen(KeyWritten::class, function ($e) use (&$puts) {
            $puts[] = $e->key;
        });
        $this->get(route('customer.partners'))->assertOk();

        return ['gets' => $gets, 'puts' => $puts];
    }

    public function test_partner_settings_are_read_from_cache_once_per_request(): void
    {
        PartnerBenefit::query()->delete();
        $this->get(route('customer.partners'))->assertOk(); // warm
        $this->assertSame(1, $this->measure()['gets']);
    }

    public function test_unset_partner_settings_write_no_cache_rows(): void
    {
        $this->get(route('customer.partners'))->assertOk(); // warm
        $partnerWrites = array_filter($this->measure()['puts'], fn ($k) => str_contains($k, 'partner_page.'));
        $this->assertSame([], array_values($partnerWrites));
    }

    public function test_saving_a_partner_setting_is_visible_in_the_next_request(): void
    {
        $this->get(route('customer.partners'))->assertOk();
        Setting::set(P::key('hero.title'), 'A brand new headline');
        $this->get(route('customer.partners'))->assertOk()->assertSeeText('A brand new headline');
    }
}
