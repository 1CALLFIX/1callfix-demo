<?php

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Tests\Feature\Support\RebuiltAuthHelpers;
use Tests\TestCase;

/**
 * Every password field on a customer/provider auth screen renders the shared
 * <x-ui.password-input> show/hide toggle. One component, one JS behaviour
 * (resources/js/password-toggle.js) — this pins the reuse so a form can't
 * quietly drop back to a bare <input type="password">.
 */
class PasswordVisibilityToggleTest extends TestCase
{
    use RefreshDatabase;
    use RebuiltAuthHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bindFakeFirebase();
    }

    /** The two single-step sign-in screens render the toggle on a plain GET. */
    public function test_login_screens_render_the_toggle(): void
    {
        foreach (['/login', '/provider/login'] as $path) {
            $html = $this->get($path)->assertOk()->getContent();

            $this->assertSame(1, substr_count($html, 'data-password-field'), "{$path}: one password wrapper");
            $this->assertSame(1, substr_count($html, 'data-password-toggle'), "{$path}: one toggle button");
            $this->assertSame(1, substr_count($html, 'type="password"'), "{$path}: the only password input is the component's");
        }
    }

    public function test_component_renders_toggle_and_forwards_attributes(): void
    {
        $html = Blade::render(
            '<x-ui.password-input id="pw" name="password" wire:model="password" autocomplete="new-password" />'
        );

        foreach ([
            'data-password-field', 'data-password-toggle', 'type="password"',
            'id="pw"', 'wire:model="password"', 'autocomplete="new-password"',
            'aria-label="Show password"',
        ] as $needle) {
            $this->assertStringContainsString($needle, $html);
        }
    }

    /**
     * Static guard for the multi-step forms (signup / provider register),
     * whose password fields only exist in the DOM on step 2 — a cold GET
     * can't reach them, but a regression to a bare input still must fail
     * here. Same "static reconciliation guard" style as
     * CustomerAuthPagesRenderTest.
     */
    public function test_every_in_scope_auth_blade_uses_the_shared_component(): void
    {
        $blades = [
            'resources/views/livewire/customer/auth/login.blade.php' => 1,
            'resources/views/livewire/customer/auth/signup.blade.php' => 2,
            'resources/views/livewire/provider/auth/login.blade.php' => 1,
            'resources/views/livewire/provider/auth/register.blade.php' => 2,
        ];

        foreach ($blades as $relative => $expected) {
            $source = file_get_contents(base_path($relative));

            $this->assertSame(
                $expected,
                substr_count($source, '<x-ui.password-input'),
                "{$relative} should use <x-ui.password-input> {$expected}x."
            );
            $this->assertStringNotContainsString(
                'type="password"',
                $source,
                "{$relative} still has a hand-rolled bare password input — route it through the component."
            );
        }
    }
}
