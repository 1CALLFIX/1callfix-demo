<?php

namespace Tests\Feature\Operations;

use App\Livewire\Operations\DataClear;
use App\Models\User;
use App\Services\Operations\DataClearService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Clear Data tool, STEP 1 — the environment gate. Built and tested first,
 * per the approved design: this tool must be structurally incapable of
 * running against production, not merely discouraged by a permission.
 * There is no --force-equivalent parameter anywhere in DataClearService's
 * signature and no permission bypass — both proven below by calling it
 * with the most privileged actor this codebase has (super_admin) and
 * confirming production is still refused.
 */
class DataClearEnvironmentGateTest extends TestCase
{
    use RefreshDatabase;

    private function makeSuperAdmin(): User
    {
        return User::create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Super Admin',
            'phone' => '9'.fake()->unique()->numerify('#########'),
            'role' => 'super_admin',
            'status' => 'active',
        ]);
    }

    public function test_run_refuses_in_production_even_for_a_super_admin(): void
    {
        $this->app['env'] = 'production';
        $actor = $this->makeSuperAdmin();

        $result = app(DataClearService::class)->run(
            $actor, ['wallet_transactions'], 'anything', 'anything', '127.0.0.1'
        );

        $this->assertTrue($result->refused);
        $this->assertSame('This tool cannot run in a production environment.', $result->reason);
        $this->assertNull($result->operationId);
    }

    /**
     * Proves the refusal happens BEFORE anything else -- an obviously
     * wrong/empty module selection AND a garbage confirmation phrase
     * still surface the environment message specifically, not a
     * "select a module" or "phrase mismatch" message, confirming
     * environment really is checked first.
     */
    public function test_production_refusal_is_the_first_check_not_a_side_effect_of_a_bad_request(): void
    {
        $this->app['env'] = 'production';
        $actor = $this->makeSuperAdmin();

        $result = app(DataClearService::class)->run($actor, [], '', '', '127.0.0.1');

        $this->assertSame('This tool cannot run in a production environment.', $result->reason);
    }

    public function test_run_proceeds_past_the_environment_gate_outside_production(): void
    {
        $this->app['env'] = 'testing';
        $actor = $this->makeSuperAdmin();

        $result = app(DataClearService::class)->run($actor, [], '', '', '127.0.0.1');

        // Past the environment gate, the NEXT gate (empty selection) is
        // what refuses -- proving the environment check itself did not
        // fire outside production.
        $this->assertSame('Select at least one module to clear.', $result->reason);
    }

    // ============================== The screen itself ==============================

    public function test_the_screen_404s_in_production_not_403(): void
    {
        $this->app['env'] = 'production';
        $actor = $this->makeSuperAdmin();

        $this->actingAs($actor)
            ->get(route('admin.operations.data-clear'))
            ->assertNotFound();
    }

    public function test_the_screen_renders_normally_outside_production_for_a_permitted_actor(): void
    {
        $this->app['env'] = 'testing';
        $actor = $this->makeSuperAdmin();

        Livewire::actingAs($actor)->test(DataClear::class)->assertOk();
    }
}
