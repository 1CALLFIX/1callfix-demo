<?php

namespace Tests\Feature\Dispatch;

use App\Jobs\BundleConsolidationJob;
use App\Jobs\ServiceMatchingJob;
use App\Models\ServiceSubcategory;
use App\Services\DispatchService;
use App\Services\ProviderAvailabilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Feature\Support\BundleConsolidationHelpers;
use Tests\TestCase;

/**
 * feature/services-cart — the trade guard added to BundleConsolidationJob.
 *
 * Provider skills / normal dispatch stay category-level. But an
 * auto-consolidation OFFER is only made for a sibling of the SAME
 * subcategory the provider just accepted: an electrician who took a fan job
 * must not be pushed a plumbing sibling from the same "Home Repair"
 * category. A null subcategory on either side falls back to the existing
 * category-level behaviour.
 */
class BundleConsolidationSubcategoryGuardTest extends TestCase
{
    use BundleConsolidationHelpers;
    use RefreshDatabase;

    private function subcategory(int $categoryId, string $name): ServiceSubcategory
    {
        return ServiceSubcategory::create([
            'category_id' => $categoryId,
            'name' => $name,
            'slug' => Str::slug($name.'-'.Str::random(6)),
            'sort_order' => 1,
            'is_active' => true,
        ]);
    }

    private function runFor(int $assignedBookingId): void
    {
        (new BundleConsolidationJob($assignedBookingId))->handle(
            app(DispatchService::class),
            app(ProviderAvailabilityService::class),
        );
    }

    public function test_a_same_subcategory_sibling_is_offered_a_different_subcategory_sibling_is_not(): void
    {
        Queue::fake();

        $ctx = $this->makeWorld();
        $ctx['customer'] = $this->makeCustomer();
        $ctx['address'] = $this->makeAddress($ctx['customer'], $ctx['franchise'], $ctx['zone']);

        $acRepair = $this->subcategory($ctx['category']->id, 'AC Repair');
        $plumbing = $this->subcategory($ctx['category']->id, 'Plumbing');

        $acService = $this->makeService($ctx['category'], 60, ['subcategory_id' => $acRepair->id]);
        $fridge = $this->makeService($ctx['category'], 60, ['subcategory_id' => $acRepair->id]);
        $tap = $this->makeService($ctx['category'], 60, ['subcategory_id' => $plumbing->id]);

        // Provider is skilled at the CATEGORY (that is all skills records).
        $x = $this->makeSkilledProvider($ctx['franchise'], $ctx['zone'], $ctx['category']->id, 1.0, 1.0);

        [$bundle, $rows] = $this->makeBundleWithChildren($ctx, [
            ['service' => $acService, 'scheduled_at' => '2030-07-01 09:00:00'], // accepted
            ['service' => $fridge, 'scheduled_at' => '2030-07-01 11:00:00'],    // same trade -> offered
            ['service' => $tap, 'scheduled_at' => '2030-07-01 13:00:00'],       // different trade -> normal dispatch
        ]);
        [$accepted, $sameTrade, $otherTrade] = $rows;

        $accepted->update(['provider_id' => $x->id, 'status' => 'assigned', 'start_otp' => '1111', 'completion_otp' => '2222']);

        $this->runFor($accepted->fresh()->id);

        $this->assertDatabaseHas('dispatch_attempts', [
            'booking_id' => $sameTrade->id, 'provider_id' => $x->id, 'status' => 'notified',
        ]);

        $this->assertDatabaseMissing('dispatch_attempts', [
            'booking_id' => $otherTrade->id, 'provider_id' => $x->id,
        ]);
        Queue::assertPushed(ServiceMatchingJob::class, fn ($job) => $job->bookingId === $otherTrade->id);
        Queue::assertNotPushed(ServiceMatchingJob::class, fn ($job) => $job->bookingId === $sameTrade->id);
    }

    public function test_a_sibling_with_no_subcategory_falls_back_to_category_level_and_is_offered(): void
    {
        Queue::fake();

        $ctx = $this->makeWorld();
        $ctx['customer'] = $this->makeCustomer();
        $ctx['address'] = $this->makeAddress($ctx['customer'], $ctx['franchise'], $ctx['zone']);

        $acRepair = $this->subcategory($ctx['category']->id, 'AC Repair');
        $acService = $this->makeService($ctx['category'], 60, ['subcategory_id' => $acRepair->id]);
        $noSub = $this->makeService($ctx['category'], 60); // subcategory_id null

        $x = $this->makeSkilledProvider($ctx['franchise'], $ctx['zone'], $ctx['category']->id, 1.0, 1.0);

        [$bundle, $rows] = $this->makeBundleWithChildren($ctx, [
            ['service' => $acService, 'scheduled_at' => '2030-07-01 09:00:00'],
            ['service' => $noSub, 'scheduled_at' => '2030-07-01 11:00:00'],
        ]);
        [$accepted, $sibling] = $rows;

        $accepted->update(['provider_id' => $x->id, 'status' => 'assigned', 'start_otp' => '1111', 'completion_otp' => '2222']);

        $this->runFor($accepted->fresh()->id);

        $this->assertDatabaseHas('dispatch_attempts', [
            'booking_id' => $sibling->id, 'provider_id' => $x->id, 'status' => 'notified',
        ]);
    }

    public function test_the_guard_can_be_disabled_per_scope(): void
    {
        Queue::fake();
        \App\Models\Setting::query()->updateOrCreate(
            ['key' => 'dispatch.consolidation_subcategory_strict'],
            ['value' => '0'],
        );

        $ctx = $this->makeWorld();
        $ctx['customer'] = $this->makeCustomer();
        $ctx['address'] = $this->makeAddress($ctx['customer'], $ctx['franchise'], $ctx['zone']);

        $acRepair = $this->subcategory($ctx['category']->id, 'AC Repair');
        $plumbing = $this->subcategory($ctx['category']->id, 'Plumbing');
        $acService = $this->makeService($ctx['category'], 60, ['subcategory_id' => $acRepair->id]);
        $tap = $this->makeService($ctx['category'], 60, ['subcategory_id' => $plumbing->id]);

        $x = $this->makeSkilledProvider($ctx['franchise'], $ctx['zone'], $ctx['category']->id, 1.0, 1.0);

        [$bundle, $rows] = $this->makeBundleWithChildren($ctx, [
            ['service' => $acService, 'scheduled_at' => '2030-07-01 09:00:00'],
            ['service' => $tap, 'scheduled_at' => '2030-07-01 11:00:00'],
        ]);
        [$accepted, $otherTrade] = $rows;

        $accepted->update(['provider_id' => $x->id, 'status' => 'assigned', 'start_otp' => '1111', 'completion_otp' => '2222']);

        $this->runFor($accepted->fresh()->id);

        // Guard off -> category-level behaviour -> the cross-trade sibling is offered.
        $this->assertDatabaseHas('dispatch_attempts', [
            'booking_id' => $otherTrade->id, 'provider_id' => $x->id, 'status' => 'notified',
        ]);
    }
}
