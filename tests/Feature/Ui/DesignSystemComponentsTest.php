<?php

namespace Tests\Feature\Ui;

use Tests\TestCase;

/**
 * Phase 21 item TECH-6 (Admin UI design system, first increment). Direct
 * rendering coverage for the five new `x-ui.*` primitives themselves
 * (`resources/views/components/ui/*.blade.php`), independent of any real
 * admin screen -- proves each component's props/slots/variant-to-class
 * mapping actually work, not just that a screen using it happens to still
 * render (that's covered separately, by the migrated-screen tests in
 * AdminScreensDesignSystemMigrationTest.php).
 *
 * No RefreshDatabase/Livewire test -- these are plain Blade components with
 * no backing class and no DB dependency, rendered directly via the
 * framework's own $this->blade() test helper (InteractsWithViews).
 */
class DesignSystemComponentsTest extends TestCase
{
    // --- x-ui.button ----------------------------------------------------

    public function test_button_defaults_to_primary_variant_and_md_size(): void
    {
        $view = $this->blade('<x-ui.button>Save</x-ui.button>');

        $view->assertSee('Save');
        $view->assertSee('bg-slate-900', false);
        $view->assertSee('px-4 py-2 text-sm', false);
        $view->assertSee('type="button"', false);
    }

    public function test_button_variant_classes_are_distinct_per_variant(): void
    {
        $this->blade('<x-ui.button variant="secondary">X</x-ui.button>')->assertSee('border-gray-300', false);
        $this->blade('<x-ui.button variant="danger">X</x-ui.button>')->assertSee('bg-red-600', false);
        $this->blade('<x-ui.button variant="ghost">X</x-ui.button>')->assertSee('hover:underline', false);
    }

    public function test_button_renders_as_an_anchor_when_href_is_given(): void
    {
        $view = $this->blade('<x-ui.button href="/admin/customers/1">View</x-ui.button>');

        $view->assertSee('<a href="/admin/customers/1"', false);
        $view->assertDontSee('<button', false);
    }

    public function test_button_forwards_arbitrary_attributes_like_wire_click_and_type(): void
    {
        $view = $this->blade('<x-ui.button wire:click="save" type="submit">Go</x-ui.button>');

        $view->assertSee('wire:click="save"', false);
        $view->assertSee('type="submit"', false);
    }

    // --- x-ui.card -------------------------------------------------------

    public function test_card_renders_slot_content_with_the_shared_shell_classes(): void
    {
        $view = $this->blade('<x-ui.card>Body content</x-ui.card>');

        $view->assertSee('Body content');
        $view->assertSee('bg-white rounded-lg shadow-sm', false);
    }

    public function test_card_title_renders_the_shared_section_header_style(): void
    {
        $view = $this->blade('<x-ui.card title="Assign a badge">Body</x-ui.card>');

        $view->assertSee('Assign a badge');
        $view->assertSee('text-sm font-semibold text-gray-500 uppercase', false);
    }

    public function test_card_with_no_title_renders_no_header_element(): void
    {
        $view = $this->blade('<x-ui.card>Body</x-ui.card>');

        $view->assertDontSee('<h2', false);
    }

    // --- x-ui.table --------------------------------------------------------

    public function test_table_wraps_thead_and_tbody_in_the_shared_shell(): void
    {
        $view = $this->blade('<x-ui.table><thead><tr><th>Name</th></tr></thead><tbody><tr><td>Row</td></tr></tbody></x-ui.table>');

        $view->assertSee('bg-white rounded-lg shadow-sm overflow-hidden', false);
        $view->assertSee('<table class="w-full text-sm"', false);
        $view->assertSee('Name');
        $view->assertSee('Row');
    }

    public function test_table_footer_slot_renders_pagination_style_bottom_bar(): void
    {
        $view = $this->blade('<x-ui.table><tbody></tbody><x-slot:footer>Page 1 of 3</x-slot:footer></x-ui.table>');

        $view->assertSee('Page 1 of 3');
        $view->assertSee('px-4 py-3 border-t', false);
    }

    public function test_table_header_slot_renders_above_the_table_element(): void
    {
        $view = $this->blade('<x-ui.table><x-slot:header>Recent Bookings (3)</x-slot:header><tbody></tbody></x-ui.table>');

        $view->assertSeeInOrder(['Recent Bookings (3)', '<table'], false);
    }

    public function test_table_omits_footer_and_header_wrappers_when_not_provided(): void
    {
        $view = $this->blade('<x-ui.table><tbody></tbody></x-ui.table>');

        $view->assertDontSee('px-4 py-3 border-t', false);
        $view->assertDontSee('font-semibold p-4 pb-2', false);
    }

    // --- x-ui.badge --------------------------------------------------------

    public function test_badge_default_color_and_size(): void
    {
        $view = $this->blade('<x-ui.badge>Inactive</x-ui.badge>');

        $view->assertSee('Inactive');
        $view->assertSee('bg-gray-100 text-gray-700', false);
        $view->assertSee('px-2 py-0.5 text-xs', false);
    }

    public function test_badge_color_map_covers_every_status_color_in_use(): void
    {
        $this->blade('<x-ui.badge color="green">Active</x-ui.badge>')->assertSee('bg-green-100 text-green-700', false);
        $this->blade('<x-ui.badge color="red">Suspended</x-ui.badge>')->assertSee('bg-red-100 text-red-700', false);
        $this->blade('<x-ui.badge color="amber">Pending</x-ui.badge>')->assertSee('bg-amber-100 text-amber-700', false);
        $this->blade('<x-ui.badge color="blue">Refunded</x-ui.badge>')->assertSee('bg-blue-100 text-blue-700', false);
        $this->blade('<x-ui.badge color="slate">Cancelled</x-ui.badge>')->assertSee('bg-slate-100 text-slate-700', false);
    }

    public function test_badge_lg_size_used_by_page_header_status_pills(): void
    {
        $view = $this->blade('<x-ui.badge size="lg" color="green">Active</x-ui.badge>');

        $view->assertSee('px-3 py-1.5 text-sm', false);
    }

    // --- x-ui.modal --------------------------------------------------------

    public function test_modal_renders_nothing_when_show_is_false(): void
    {
        $view = $this->blade('<x-ui.modal :show="false" title="Confirm">Body</x-ui.modal>');

        $view->assertDontSee('Body');
        $view->assertDontSee('Confirm');
    }

    public function test_modal_renders_title_body_and_footer_when_shown(): void
    {
        $view = $this->blade(
            '<x-ui.modal :show="true" title="Confirm delete" onClose="closeModal">Are you sure?<x-slot:footer>Yes / No</x-slot:footer></x-ui.modal>'
        );

        $view->assertSee('Confirm delete');
        $view->assertSee('Are you sure?');
        $view->assertSee('Yes / No');
        $view->assertSee('wire:click="closeModal"', false);
    }

    public function test_modal_without_onclose_renders_no_dismiss_affordance(): void
    {
        $view = $this->blade('<x-ui.modal :show="true">Body only</x-ui.modal>');

        $view->assertSee('Body only');
        $view->assertDontSee('wire:click', false);
    }
}
