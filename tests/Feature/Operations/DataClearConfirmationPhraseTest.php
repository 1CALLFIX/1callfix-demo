<?php

namespace Tests\Feature\Operations;

use App\Livewire\Operations\DataClear;
use App\Models\User;
use App\Services\Operations\DataClearService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Feature\Support\DataClearTestHelpers;
use Tests\TestCase;

/**
 * Clear Data tool, STEP 4 — typed confirmation. No plain yes/no, no
 * reusable/guessable phrase: a fresh, single-use phrase per selection,
 * required to match exactly.
 */
class DataClearConfirmationPhraseTest extends TestCase
{
    use RefreshDatabase;
    use DataClearTestHelpers;

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

    // ============================== Service-level: exact match only ==============================

    public function test_a_wrong_phrase_is_rejected_before_any_backup_is_attempted(): void
    {
        // Deliberately NOT calling bindWorkingDataClearBackup() / Process::fake()
        // here -- if the confirmation check genuinely runs before the backup
        // step, this refusal happens without ever touching Process at all.
        $actor = $this->makeSuperAdmin();

        $result = app(DataClearService::class)->run(
            $actor, ['wallet_transactions'], 'totally-wrong', 'CLEAR-WALLET_TRANSACTIONS-ABC123', '127.0.0.1'
        );

        $this->assertTrue($result->refused);
        $this->assertSame('Confirmation phrase did not match.', $result->reason);
    }

    public function test_a_near_miss_one_character_off_is_rejected(): void
    {
        $actor = $this->makeSuperAdmin();
        $expected = app(DataClearService::class)->generateConfirmationPhrase(['wallet_transactions']);
        // Appended, not substituted -- guaranteed different regardless of
        // what the random suffix happens to contain (a same-length
        // single-character substitution could coincidentally collide with
        // the original on a low-probability but real random draw).
        $nearMiss = $expected.'Z';

        $result = app(DataClearService::class)->run(
            $actor, ['wallet_transactions'], $nearMiss, $expected, '127.0.0.1'
        );

        $this->assertTrue($result->refused);
        $this->assertSame('Confirmation phrase did not match.', $result->reason);
    }

    public function test_a_case_mismatch_is_rejected(): void
    {
        $actor = $this->makeSuperAdmin();
        $expected = app(DataClearService::class)->generateConfirmationPhrase(['wallet_transactions']);

        $result = app(DataClearService::class)->run(
            $actor, ['wallet_transactions'], strtolower($expected), $expected, '127.0.0.1'
        );

        $this->assertTrue($result->refused);
    }

    public function test_the_exact_phrase_clears_the_confirmation_gate(): void
    {
        $this->bindWorkingDataClearBackup();
        $actor = $this->makeSuperAdmin();
        $expected = app(DataClearService::class)->generateConfirmationPhrase(['wallet_transactions']);

        $result = app(DataClearService::class)->run(
            $actor, ['wallet_transactions'], $expected, $expected, '127.0.0.1'
        );

        $this->assertFalse($result->refused, $result->reason ?? '');
    }

    public function test_surrounding_whitespace_on_the_typed_phrase_is_tolerated(): void
    {
        $this->bindWorkingDataClearBackup();
        $actor = $this->makeSuperAdmin();
        $expected = app(DataClearService::class)->generateConfirmationPhrase(['wallet_transactions']);

        $result = app(DataClearService::class)->run(
            $actor, ['wallet_transactions'], "  {$expected}\n", $expected, '127.0.0.1'
        );

        $this->assertFalse($result->refused, $result->reason ?? '');
    }

    // ============================== Component-level: single-use across selections and runs ==============================

    public function test_changing_the_selection_generates_a_different_phrase(): void
    {
        $actor = $this->makeSuperAdmin();

        $component = Livewire::actingAs($actor)->test(DataClear::class)
            ->set('selectedModules', ['wallet_transactions']);
        $first = $component->get('confirmationPhrase');

        $component->set('selectedModules', ['wallet_transactions', 'notifications']);
        $second = $component->get('confirmationPhrase');

        $this->assertNotSame('', $first);
        $this->assertNotSame($first, $second);
    }

    /** The literal scenario the acceptance criteria describes: a phrase from a previous run/selection must not work for a later one. */
    public function test_a_phrase_from_a_different_selection_is_rejected_by_the_current_one(): void
    {
        $actor = $this->makeSuperAdmin();

        $component = Livewire::actingAs($actor)->test(DataClear::class)
            ->set('selectedModules', ['wallet_transactions']);
        $stalePhrase = $component->get('confirmationPhrase');

        // Selection changes -- a real user reconsidering scope, or a
        // replayed/stale request -- the component's current expected
        // phrase moves on.
        $component->set('selectedModules', ['notifications']);

        $component->set('typedConfirmation', $stalePhrase)
            ->call('execute')
            ->assertSet('flashType', 'error')
            ->assertSet('flashMessage', 'Confirmation phrase did not match.');
    }

    /** After a successful clear, the SAME phrase must not work again even for re-selecting the identical module. */
    public function test_a_phrase_cannot_be_reused_after_a_successful_run(): void
    {
        $this->bindWorkingDataClearBackup();
        $actor = $this->makeSuperAdmin();

        $component = Livewire::actingAs($actor)->test(DataClear::class)
            ->set('selectedModules', ['wallet_transactions']);
        $usedPhrase = $component->get('confirmationPhrase');

        $component->set('typedConfirmation', $usedPhrase)
            ->call('execute')
            ->assertSet('flashType', 'success');

        // Re-select the exact same module -- a fresh phrase must be issued, not the old one reused.
        $component->set('selectedModules', ['wallet_transactions']);
        $newPhrase = $component->get('confirmationPhrase');

        $this->assertNotSame($usedPhrase, $newPhrase, 'A new phrase must be generated after a completed run, even for the identical module selection.');

        $component->set('typedConfirmation', $usedPhrase)
            ->call('execute')
            ->assertSet('flashType', 'error')
            ->assertSet('flashMessage', 'Confirmation phrase did not match.');
    }

    /** A wrong-phrase typo must NOT force a brand new phrase to be read -- only success or a changed selection rotates it. */
    public function test_a_mistyped_phrase_does_not_rotate_the_phrase(): void
    {
        $actor = $this->makeSuperAdmin();

        $component = Livewire::actingAs($actor)->test(DataClear::class)
            ->set('selectedModules', ['wallet_transactions']);
        $original = $component->get('confirmationPhrase');

        $component->set('typedConfirmation', 'nope')
            ->call('execute')
            ->assertSet('flashType', 'error');

        $this->assertSame($original, $component->get('confirmationPhrase'));
    }
}
