<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\TestCase;

/** P0 Customer Core API — Customer profile (mission item 5). */
class CustomerProfileApiTest extends TestCase
{
    use RefreshDatabase;
    use BookingFixtureHelpers;

    public function test_profile_requires_authentication(): void
    {
        $this->getJson('/api/profile')->assertStatus(401);
    }

    public function test_customer_can_view_their_own_profile(): void
    {
        $customer = $this->makeCustomer();

        $this->actingAs($customer, 'sanctum')
            ->getJson('/api/profile')
            ->assertOk()
            ->assertJsonPath('data.id', $customer->id)
            ->assertJsonPath('data.phone', $customer->phone)
            ->assertJsonMissingPath('data.role')
            ->assertJsonMissingPath('data.status');
    }

    public function test_customer_can_update_permitted_profile_fields(): void
    {
        $customer = $this->makeCustomer();

        $this->actingAs($customer, 'sanctum')
            ->putJson('/api/profile', [
                'name' => 'New Name',
                'email' => 'new-email@example.com',
                'preferred_language' => 'fr',
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'New Name')
            ->assertJsonPath('data.email', 'new-email@example.com');

        $customer->refresh();
        $this->assertSame('New Name', $customer->name);
        $this->assertSame('fr', $customer->preferred_language);
    }

    public function test_forbidden_fields_are_silently_ignored_not_applied(): void
    {
        $customer = $this->makeCustomer();
        $originalPhone = $customer->phone;

        $this->actingAs($customer, 'sanctum')
            ->putJson('/api/profile', [
                'name' => 'Still Allowed',
                'role' => 'super_admin',
                'status' => 'active',
                'phone' => '9999999999',
                'franchise_id' => 999,
                'phone_verified_at' => now()->toISOString(),
            ])
            ->assertOk();

        $customer->refresh();
        $this->assertSame('Still Allowed', $customer->name);
        $this->assertSame('customer', $customer->role, 'role must never be changeable through the profile endpoint.');
        $this->assertSame($originalPhone, $customer->phone, 'phone must never be changeable through the profile endpoint (OTP identity).');
        $this->assertNull($customer->franchise_id);
    }

    public function test_email_must_be_unique_across_users(): void
    {
        $customer = $this->makeCustomer();
        $other = User::create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(), 'name' => 'Other', 'phone' => '9'.fake()->unique()->numerify('#########'),
            'email' => 'taken@example.com', 'role' => 'customer', 'status' => 'active',
        ]);

        $this->actingAs($customer, 'sanctum')
            ->putJson('/api/profile', ['email' => 'taken@example.com'])
            ->assertStatus(422)
            ->assertJson(['success' => false])
            ->assertJsonValidationErrors(['email']);
    }

    public function test_a_customer_can_keep_their_own_current_email_unchanged(): void
    {
        $customer = $this->makeCustomer();
        $customer->update(['email' => 'me@example.com']);

        $this->actingAs($customer, 'sanctum')
            ->putJson('/api/profile', ['email' => 'me@example.com', 'name' => 'Me Again'])
            ->assertOk();
    }
}
