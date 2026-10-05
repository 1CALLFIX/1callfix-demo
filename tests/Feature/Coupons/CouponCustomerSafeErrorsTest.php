<?php

namespace Tests\Feature\Coupons;

use App\Exceptions\CouponException;
use Tests\TestCase;

/**
 * C3 item 1 — nothing internal reaches a customer. Whatever reason the engine rejects with, the text on the
 * exception (which every customer surface echoes: API, Form Request, Livewire, JSON, Flutter) is one of two
 * fixed sentences. The precise reason / detail stay on the exception's internal properties and in the log.
 */
class CouponCustomerSafeErrorsTest extends TestCase
{
    private const GENERIC = 'This coupon cannot be applied to this order.';

    private const ONLINE_ONLY = 'Offers apply on online payment only.';

    public function test_every_internal_reason_maps_to_one_of_two_customer_sentences(): void
    {
        $reasons = ['coupons_unavailable', 'invalid_code', 'inactive', 'not_started', 'expired', 'exhausted', 'budget_exhausted',
            'over_per_user_limit', 'below_minimum', 'not_targeted', 'excluded', 'flash_sale_conflict', 'entitlement_covered',
            'no_discount', 'no_scope', 'module_not_connected', 'franchise_not_live', 'daily_cap_reached', 'something_new'];

        foreach ($reasons as $reason) {
            $e = new CouponException($reason, 'Precise internal wording for '.$reason);

            $this->assertSame(self::GENERIC, $e->getMessage(), $reason);
            $this->assertSame($reason, $e->reason, 'The reason stays available internally.');
        }
    }

    public function test_payment_eligibility_rejections_say_offers_apply_on_online_payment_only(): void
    {
        $e = new CouponException('online_payment_required', 'anything');

        $this->assertSame(self::ONLINE_ONLY, $e->getMessage());
    }

    public function test_the_customer_payload_carries_only_the_message(): void
    {
        $payload = (new CouponException('expired', 'This coupon has expired.'))->customerPayload();

        $this->assertSame(['message' => self::GENERIC], $payload);
        $this->assertStringNotContainsString('expired', json_encode($payload));
        $this->assertStringNotContainsString('CouponException', json_encode($payload));
    }

    public function test_no_customer_surface_reads_the_internal_reason_or_diagnostics(): void
    {
        $dirs = ['app/Http/Controllers/API', 'app/Http/Requests', 'app/Livewire/Customer', 'app/Http/Resources'];

        foreach ($dirs as $dir) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path($dir), \FilesystemIterator::SKIP_DOTS)) as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }
                $src = file_get_contents($file->getPathname());
                $this->assertDoesNotMatchRegularExpression('/reasonCode|->detail\b|\$e->reason\b|CouponException::class\s*,\s*get_class/', $src, $file->getPathname());
            }
        }
    }
}
