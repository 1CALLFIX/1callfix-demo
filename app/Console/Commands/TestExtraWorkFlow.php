<?php

namespace App\Console\Commands;

use App\Actions\AcceptBookingAction;
use App\Actions\CompleteBookingAction;
use App\Actions\ProposeExtraWorkAction;
use App\Actions\RespondToExtraWorkAction;
use App\Jobs\ServiceMatchingJob;
use App\Models\Address;
use App\Models\Booking;
use App\Models\Franchise;
use App\Models\Provider;
use App\Models\Service;
use App\Models\User;
use App\Services\DispatchService;
use Illuminate\Console\Command;

class TestExtraWorkFlow extends Command
{
    protected $signature = 'test:extra-work-flow';
    protected $description = 'Runs the full plumber/extra-work scenario end to end in one shot: book, dispatch, accept, propose extra work, approve, complete.';

    public function handle(): int
    {
        $franchise = Franchise::where('code', 'NLR')->first();
        $customer = User::where('phone', '9000000001')->first();
        $address = Address::where('user_id', $customer->id)->first();
        $service = Service::first();
        $provider = Provider::first();

        if (!$franchise || !$customer || !$address || !$service || !$provider) {
            $this->error('Missing base test data (franchise/customer/address/service/provider). Run the earlier test data setup first.');
            return self::FAILURE;
        }

        $provider->update(['is_online' => true]);

        $this->info('--- Creating booking (₹' . $service->base_price . ' base) ---');
        $booking = Booking::create([
            'franchise_id' => $franchise->id,
            'zone_id' => $address->zone_id,
            'customer_id' => $customer->id,
            'service_id' => $service->id,
            'address_id' => $address->id,
            'status' => 'pending',
            'price_quoted' => $service->base_price,
            'payment_method' => 'online',
        ]);
        $this->line("Booking code: {$booking->code}");

        $this->info("\n--- Dispatching (synchronous, not queued) ---");
        (new ServiceMatchingJob($booking->id))->handle(app(DispatchService::class));
        $booking->refresh();
        $attempts = $booking->dispatchAttempts()->get();
        $this->line("Dispatch attempts: {$attempts->count()}");
        if ($attempts->isEmpty()) {
            $this->error('No provider was found — check provider is_online/zone/skills.');
            return self::FAILURE;
        }

        $this->info("\n--- Accepting ---");
        $accepted = (new AcceptBookingAction())->execute($booking->id, $provider);
        $this->line("Status: {$accepted->status}, completion_otp: {$accepted->completion_otp}");

        $this->info("\n--- Provider proposes extra work: kitchen sink leak, ₹1000 ---");
        $item = (new ProposeExtraWorkAction())->execute(
            $booking->id, $provider, 'Kitchen sink leak repair', 1000.00
        );
        $booking->refresh();
        $this->line("Booking status: {$booking->status} (should be on_hold)");
        $this->line("Hold reason: {$booking->hold_reason} (should be awaiting_customer_approval)");
        $this->line("Extra item status: {$item->status}");

        $this->info("\n--- Customer approves the extra work ---");
        $item = (new RespondToExtraWorkAction())->execute($item->id, $customer->id, approved: true);
        $booking->refresh();
        $this->line("Booking status: {$booking->status} (should be back to in_progress)");
        $this->line("Extra item status: {$item->status}");

        $this->info("\n--- Completing the job ---");
        $completed = app(CompleteBookingAction::class)->execute($booking->id, $provider, $booking->completion_otp);
        $this->line("Final status: {$completed->status}");
        $this->line("price_quoted: {$completed->price_quoted}");
        $this->line("price_final: {$completed->price_final} (should be base + ₹1000 extra)");

        $commission = $completed->commission;
        $this->line("Provider commission: " . ($commission ? $commission->provider_commission : 'NONE'));
        $this->line("Platform commission: " . ($commission ? $commission->platform_commission : 'NONE'));

        return self::SUCCESS;
    }
}
