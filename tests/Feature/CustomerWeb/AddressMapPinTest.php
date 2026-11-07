<?php

namespace Tests\Feature\CustomerWeb;

use App\Support\AddressPin;
use App\Support\SiteText;
use Tests\TestCase;

/**
 * Owner 2026-11-07: customers could not point at their door ("Use my current location" filled nothing, no map). Every
 * customer add-address form carries the pin map, and none saves the area centre (or the shop) as the address when
 * the map is available. Behaviour is tested end to end in CustomerAddressesTest; this guards the other two forms.
 */
class AddressMapPinTest extends TestCase
{
    public function test_every_customer_add_address_form_has_the_map(): void
    {
        foreach ([
            'livewire/customer/account/addresses' => 'prefix="form"',
            'livewire/customer/booking/wizard' => 'prefix="newAddress"',
            'livewire/customer/shop/checkout' => 'prefix="newAddress"',
        ] as $view => $prefix) {
            $src = file_get_contents(resource_path("views/{$view}.blade.php"));
            $this->assertStringContainsString('<x-customer.address-map', $src, $view);
            $this->assertStringContainsString($prefix, $src, $view);
        }
    }

    public function test_every_form_refuses_an_unpinned_address_when_the_map_is_available(): void
    {
        foreach ([
            'Customer/Account/Addresses.php' => '$this->locatedLat === null && \App\Support\AddressPin::required()',
            'Customer/Booking/Wizard.php' => '$this->newAddressLat === null && \App\Support\AddressPin::required()',
            'Customer/Shop/Checkout.php' => '$this->locatedLat === null && \App\Support\AddressPin::required()',
        ] as $file => $guard) {
            $this->assertStringContainsString($guard, file_get_contents(app_path("Livewire/{$file}")), $file);
        }
    }

    public function test_the_pin_is_required_only_when_a_maps_key_is_configured(): void
    {
        config(['services.google_maps.key' => null]);
        $this->assertFalse(AddressPin::required());
        config(['services.google_maps.key' => 'k']);
        $this->assertTrue(AddressPin::required());
    }

    public function test_the_map_words_are_website_text_in_english_and_telugu(): void
    {
        foreach (['search', 'find', 'hint', 'not_found', 'pin_required', 'outside'] as $k) {
            $entry = SiteText::TEXTS["address_map.{$k}"] ?? null;
            $this->assertNotNull($entry, $k);
            $this->assertNotSame('', $entry[2]);
            $this->assertNotSame('', $entry[3]);
        }
    }

    public function test_the_script_fills_only_empty_or_self_filled_fields_and_checks_the_area(): void
    {
        $js = file_get_contents(resource_path('js/address-map.js'));
        $this->assertStringContainsString("now === '' || now === autoFilled[f]", $js, 'never overwrites what the customer typed');
        $this->assertStringContainsString('wire.useCurrentLocationForNewAddress(lat, lng)', $js);
        $this->assertStringContainsString("import './address-map';", file_get_contents(resource_path('js/app.js')));
    }
}
