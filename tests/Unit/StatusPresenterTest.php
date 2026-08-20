<?php

namespace Tests\Unit;

use App\Support\StatusPresenter;
use PHPUnit\Framework\TestCase;

/**
 * Admin Polish + AI session, Part 1 — pure unit coverage for the
 * centralized status -> color/icon/label mapping every touched screen's
 * badges now go through.
 */
class StatusPresenterTest extends TestCase
{
    public function test_known_booking_status_maps_to_a_real_color_and_icon(): void
    {
        $result = StatusPresenter::for('booking', 'completed');

        $this->assertSame('green', $result['color']);
        $this->assertSame('check-circle', $result['icon']);
        $this->assertSame('completed', $result['label']);
    }

    public function test_label_replaces_underscores_with_spaces(): void
    {
        $result = StatusPresenter::for('booking', 'searching_provider');

        $this->assertSame('searching provider', $result['label']);
    }

    public function test_unknown_status_falls_back_to_gray_without_throwing(): void
    {
        $result = StatusPresenter::for('booking', 'some_future_status_not_yet_known');

        $this->assertSame('gray', $result['color']);
        $this->assertSame('some future status not yet known', $result['label']);
    }

    public function test_null_status_does_not_throw(): void
    {
        $result = StatusPresenter::for('booking', null);

        $this->assertSame('gray', $result['color']);
    }

    public function test_provider_kyc_and_document_maps_are_independent_of_booking(): void
    {
        $this->assertSame('amber', StatusPresenter::for('provider_kyc', 'pending')['color']);
        $this->assertSame('amber', StatusPresenter::for('document', 'pending')['color']);
        $this->assertSame('gray', StatusPresenter::for('booking', 'pending')['color']);
    }

    public function test_customer_status_map(): void
    {
        $this->assertSame('red', StatusPresenter::for('customer', 'suspended')['color']);
        $this->assertSame('green', StatusPresenter::for('customer', 'active')['color']);
    }
}
