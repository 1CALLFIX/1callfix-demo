<?php

namespace Tests\Feature\Payments;

use App\Models\User;
use App\Support\RazorpayPrefill;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/** Owner 2026-11-07: Razorpay must not ask a signed-in customer for their name and mobile again. */
class RazorpayPrefillTest extends TestCase
{
    public function test_known_details_are_passed_in_razorpay_format(): void
    {
        $user = new User(['name' => ' Ravi Kumar ', 'phone' => '9876543210', 'email' => 'ravi@example.com']);

        $this->assertSame(
            ['name' => 'Ravi Kumar', 'contact' => '+919876543210', 'email' => 'ravi@example.com'],
            RazorpayPrefill::for($user)
        );
    }

    public function test_missing_or_bad_details_are_left_out(): void
    {
        $this->assertSame(['name' => 'Asha'], RazorpayPrefill::for(new User(['name' => 'Asha', 'phone' => '12', 'email' => null])));
        $this->assertSame([], RazorpayPrefill::for(null));
        $this->assertEquals(new \stdClass, RazorpayPrefill::forCurrentUser(), 'guest: {} in the browser');
    }

    public function test_every_razorpay_checkout_passes_the_prefill(): void
    {
        $missing = [];
        foreach (File::allFiles(resource_path('views')) as $file) {
            $src = $file->getContents();
            $opens = substr_count($src, 'new window.Razorpay(');
            if ($opens > 0 && substr_count($src, 'prefill: @js(\App\Support\RazorpayPrefill::forCurrentUser())') !== $opens) {
                $missing[] = $file->getRelativePathname();
            }
        }

        $this->assertSame([], $missing, 'Razorpay checkouts without the customer prefill');
    }
}
