<?php

namespace Tests\Feature\Partners;

use App\Livewire\Customer\Auth\Login as CustomerLogin;
use App\Livewire\Partner\Join;
use App\Livewire\PartnerPage\Leads;
use App\Livewire\Provider\Auth\Login as ProviderLogin;
use App\Models\FieldWorkerCapability;
use App\Models\PartnerLead;
use App\Models\Provider;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Feature\Rbac\RbacTestHelpers;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\Feature\Support\RebuiltAuthHelpers;
use Tests\TestCase;

/**
 * Owner 2026-11-20: (1) a partner who tapped "Help me join" was hard to find and had no number to tap — open call
 * requests now sit at the top of Partner leads with Call / WhatsApp; a service partner becoming a rider is filed as a
 * rider. (2) Closing the Partner app signed people out after the 2-hour session — signing in inside the apps now
 * remembers the person; a normal browser is unchanged.
 */
class CallbacksAndStaySignedInTest extends TestCase
{
    use BookingFixtureHelpers;
    use RbacTestHelpers;
    use RebuiltAuthHelpers;
    use RefreshDatabase;

    private function lead(array $over): PartnerLead
    {
        return PartnerLead::capture($over + [
            'name' => 'Lead', 'phone' => '9059729821', 'city' => 'Nellore', 'role' => 'service', 'status' => 'new',
            'consent_text' => 'ok', 'acquisition' => null, 'source' => 'partner_page',
        ]);
    }

    public function test_call_requests_are_on_top_with_call_and_whatsapp_buttons(): void
    {
        $asked = $this->lead(['name' => 'Best AC Rider', 'role' => 'parcel', 'source' => 'help_me_join']);
        $called = $this->lead(['name' => 'Already Called', 'phone' => '9000000002', 'source' => 'help_me_join', 'status' => 'handed_off']);
        $this->lead(['name' => 'Plain Applicant', 'phone' => '9000000003']);

        $c = Livewire::actingAs($this->makeUserWithPermission('partner_page.manage', 'global'))->test(Leads::class)
            ->assertSee('Asked for a call (1)')
            ->assertSeeHtml('data-testid="callback-'.$asked->id.'"')
            ->assertSeeHtml('href="tel:+919059729821"')
            ->assertSeeHtml('href="https://wa.me/919059729821"')
            ->assertDontSeeHtml('data-testid="callback-'.$called->id.'"');

        // Called back: the team changes the status and the request leaves the box.
        $c->call('setStatus', $asked->id, 'handed_off')->assertDontSeeHtml('data-testid="callbacks"');
    }

    public function test_a_service_partner_becoming_a_rider_is_filed_as_a_rider(): void
    {
        [, , $franchise, $zone] = $this->makeFranchiseTree();
        $provider = $this->makeProviderIn($franchise, $zone);
        $this->travel(1)->days();
        $rider = \App\Models\FieldWorker::create([
            'user_id' => $provider->user_id, 'franchise_id' => $franchise->id, 'zone_id' => $zone->id,
            'kyc_status' => 'approved', 'is_active' => true, 'is_online' => false,
        ]);
        FieldWorkerCapability::create(['field_worker_id' => $rider->id, 'capability_type' => 'parcel_rider']);

        Livewire::actingAs($provider->user)->test(Join::class)->call('helpMe')->assertSee('Our team will call you');

        $this->assertSame('parcel', PartnerLead::sole()->role, 'Both approved: the newest one (the rider) is what the call is about.');
    }

    public function test_a_provider_still_waiting_for_approval_is_filed_as_service(): void
    {
        [, , $franchise, $zone] = $this->makeFranchiseTree();
        $provider = $this->makeProviderIn($franchise, $zone);
        $provider->update(['kyc_status' => 'pending']);
        $this->travel(1)->days();
        $rider = \App\Models\FieldWorker::create([
            'user_id' => $provider->user_id, 'franchise_id' => $franchise->id, 'zone_id' => $zone->id,
            'kyc_status' => 'approved', 'is_active' => true, 'is_online' => false,
        ]);
        FieldWorkerCapability::create(['field_worker_id' => $rider->id, 'capability_type' => 'parcel_rider']);

        Livewire::actingAs($provider->user)->test(Join::class)->call('helpMe');

        $this->assertSame('service', PartnerLead::sole()->role);
    }

    private function providerWithPassword(): User
    {
        [, , $franchise, $zone] = $this->makeFranchiseTree();
        $user = User::create([
            'uuid' => (string) Str::uuid(), 'name' => 'App Provider', 'phone' => '9'.fake()->unique()->numerify('#########'),
            'password' => Hash::make('correct-password'), 'role' => 'provider', 'status' => 'active', 'franchise_id' => $franchise->id,
        ]);
        Provider::create([
            'user_id' => $user->id, 'franchise_id' => $franchise->id, 'zone_id' => $zone->id,
            'provider_type' => 'independent', 'kyc_status' => 'approved', 'is_active' => true, 'is_online' => false,
        ]);

        return $user;
    }

    /** Every request in this test comes from this browser / app (Livewire test calls drop custom headers after mount). */
    private function phoneSays(string $userAgent): void
    {
        \Illuminate\Support\Facades\Event::listen(\Illuminate\Routing\Events\RouteMatched::class, fn ($e) => $e->request->headers->set('User-Agent', $userAgent));
    }

    public function test_signing_in_inside_the_partner_app_remembers_the_person(): void
    {
        $user = $this->providerWithPassword();

        $this->phoneSays('Mozilla/5.0 (Linux; Android 14) 1CallFixApp/partner');
        Livewire::test(ProviderLogin::class)
            ->set('identifier', $user->phone)->set('password', 'correct-password')->call('login')
            ->assertHasNoErrors()->assertRedirect(route('provider.dashboard'));

        $this->assertNotNull($user->fresh()->remember_token, 'In the app the sign-in survives closing it.');
    }

    public function test_signing_in_in_a_normal_browser_is_unchanged(): void
    {
        $user = $this->providerWithPassword();

        $this->phoneSays('Mozilla/5.0 (Windows NT 10.0) Chrome/130');
        Livewire::test(ProviderLogin::class)
            ->set('identifier', $user->phone)->set('password', 'correct-password')->call('login')
            ->assertHasNoErrors();

        $this->assertNull($user->fresh()->remember_token);
    }

    public function test_customer_app_sign_in_is_remembered_too(): void
    {
        $user = $this->passwordCustomer('correct-horse');

        $this->phoneSays('Mozilla/5.0 (Linux; Android 14) 1CallFixApp/customer');
        Livewire::test(CustomerLogin::class)
            ->set('identifier', $user->phone)->set('password', 'correct-horse')->call('login')
            ->assertHasNoErrors();

        $this->assertNotNull($user->fresh()->remember_token);
    }
}
