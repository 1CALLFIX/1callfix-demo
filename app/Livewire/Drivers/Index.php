<?php

namespace App\Livewire\Drivers;

use Livewire\Component;

/**
 * Users Sidebar Reorganization session — a reserved nav slot, not a real
 * screen. The Parcel vertical (admin.parcel-orders.index, "Other
 * Verticals" group) has a real backend already; its riders/drivers do
 * not — Provider rows cover every dispatched worker on this platform
 * today (see Settings\Manage's own note: "'Riders/Drivers' are Provider
 * rows in this schema (no separate rider entity)"). This screen exists
 * only so the admin nav has a named place for that future entity to land
 * in, per the phased roadmap — no queries, no state, no CRUD. Gated by
 * drivers.view, seeded super_admin-only by
 * database/migrations/*_seed_drivers_permission.php, same "any genuinely
 * new capability starts super_admin-only" convention as every other
 * permission this codebase has added (see that migration's own comment).
 */
class Index extends Component
{
    public function mount(): void
    {
        abort_unless(auth()->user()->hasPermissionAnywhere('drivers.view'), 403, 'You do not have permission to view drivers.');
    }

    public function render()
    {
        return view('livewire.drivers.index')
            ->layout('layouts.admin', ['title' => 'Drivers']);
    }
}
