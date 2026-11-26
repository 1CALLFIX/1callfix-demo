<?php

namespace Tests\Feature\CustomerWeb;

use App\Models\Setting;
use App\Support\InfoPages\ContactDetails;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Owner 2026-11-26: the company number is shown as "+91 9014 609 609" everywhere, so people remember it. */
class CompanyNumberFormatTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_indian_mobile_is_written_four_three_three(): void
    {
        foreach (['+919014609609', '919014609609', '9014609609', '+91 90146 09609', '+91-9014-609609'] as $typed) {
            $this->assertSame('+91 9014 609 609', ContactDetails::pretty($typed), $typed);
        }
    }

    public function test_anything_else_stays_as_typed(): void
    {
        $this->assertSame('Nellore office 9014609609', ContactDetails::pretty('Nellore office 9014609609'));
        $this->assertSame('0861 2345678', ContactDetails::pretty('0861 2345678'));
        $this->assertSame('+44 20 7946 0958', ContactDetails::pretty('+44 20 7946 0958'));
        $this->assertNull(ContactDetails::pretty(null));
    }

    public function test_the_contact_page_shows_the_easy_number_and_keeps_the_plain_call_link(): void
    {
        Setting::set('contact.phone', '+919014609609');
        Setting::set('contact.whatsapp', '+919014609609');

        $page = $this->get(route('customer.contact'));

        $page->assertOk();
        $page->assertSee('+91 9014 609 609');
        $page->assertDontSee('>+919014609609<', false);
        $page->assertSee('tel:+919014609609', false);
        $page->assertSee('https://wa.me/919014609609', false);
    }
}
