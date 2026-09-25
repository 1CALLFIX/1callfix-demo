<?php

namespace Tests\Feature\Earnings;

use App\Support\WalletSourceLabel;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * REF 1CF-PROMPT-20260925-EARN3 — D6. Fails if any wallet writer in app/
 * writes a ref shape WalletSourceLabel can't name.
 *
 * Step 1 finds every WalletService credit()/debit() call site in app/ by
 * source scan and requires the per-file count to equal the registry in
 * WalletSourceLabel::WRITERS — a new writer (or a removed one) fails here
 * until the registry, and therefore a label rule, is updated with it.
 * Step 2 requires every registered sample ref to map to a real label.
 */
class WalletSourceLabelCoverageTest extends TestCase
{
    /** Receivers that are a WalletService in this codebase. */
    private const CALL = '/(\$this->walletService|\$this->wallet|app\(WalletService::class\))\s*->\s*(credit|debit)\s*\(/';

    public function test_every_wallet_writer_in_app_is_registered(): void
    {
        $found = [];
        foreach (File::allFiles(app_path()) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $rel = 'app/'.str_replace('\\', '/', $file->getRelativePathname());
            if ($rel === 'app/Services/WalletService.php') {
                continue;
            }
            $count = preg_match_all(self::CALL, $file->getContents());
            if ($count > 0) {
                $found[$rel] = $count;
            }
        }
        ksort($found);

        $registered = array_map('count', WalletSourceLabel::WRITERS);
        ksort($registered);

        $this->assertSame($registered, $found, 'A wallet credit/debit call site was added or removed: register its ref shape in WalletSourceLabel::WRITERS and give it a label rule.');
    }

    public function test_every_registered_ref_shape_has_a_label(): void
    {
        foreach (WalletSourceLabel::WRITERS as $file => $samples) {
            foreach ($samples as $ref) {
                $this->assertNotSame(WalletSourceLabel::UNKNOWN, WalletSourceLabel::keyFor($ref), "{$file}: ref [{$ref}] has no WalletSourceLabel rule");
            }
        }
    }

    public function test_legacy_bundle_refund_ref_still_labels(): void
    {
        $this->assertSame('refund', WalletSourceLabel::keyFor('booking_bundle:7:wallet-refund'));
        $this->assertSame('refund', WalletSourceLabel::keyFor('booking_bundle:7:wallet-refund:12'));
        $this->assertSame('refund', WalletSourceLabel::keyFor('booking_bundle:7:wallet-refund:settle-40000'));
    }

    public function test_payout_and_its_reversal_are_distinguished(): void
    {
        $this->assertSame('payout', WalletSourceLabel::keyFor('payout:0f8b2c1a-1111-4222-8333-444455556666'));
        $this->assertSame('payout_reversal', WalletSourceLabel::keyFor('payout:12:refund'));
    }
}
