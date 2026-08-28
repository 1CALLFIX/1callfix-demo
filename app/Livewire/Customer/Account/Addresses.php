<?php

namespace App\Livewire\Customer\Account;

use App\Models\Address;
use App\Models\Booking;
use App\Services\Customer\CustomerLocationContext;
use Livewire\Component;

/**
 * Phase E6 — saved addresses. The write rules are exactly
 * App\Http\Controllers\API\AddressController's:
 *
 *   - user_id is always the authed user, never trusted from input.
 *   - zone comes from the customer's active browsing area
 *     (CustomerLocationContext) and franchise is DERIVED from that zone —
 *     never accepted from the form (a client cannot manufacture a
 *     franchise/zone pairing that doesn't exist).
 *   - delete is refused while any Booking references the address, because
 *     bookings.address_id is a cascadeOnDelete() FK and deleting the
 *     address would silently take the booking (a financial/audit record)
 *     with it — the one guard AddressController::destroy() adds too.
 *   - is_default is stored as-is; there is no "unset every other default"
 *     side effect anywhere in this codebase and none is invented here.
 */
class Addresses extends Component
{
    public array $form = [
        'label' => 'Home',
        'address_line' => '',
        'landmark' => '',
        'city' => '',
        'pincode' => '',
        'is_default' => false,
    ];

    public ?int $editingId = null;

    public bool $showForm = false;

    public string $error = '';

    public string $notice = '';

    protected function rules(): array
    {
        return [
            'form.label' => ['required', 'string', 'max:40'],
            'form.address_line' => ['required', 'string', 'max:255'],
            'form.landmark' => ['nullable', 'string', 'max:120'],
            'form.city' => ['nullable', 'string', 'max:80'],
            'form.pincode' => ['nullable', 'string', 'max:12'],
            'form.is_default' => ['boolean'],
        ];
    }

    public function startAdd(): void
    {
        $this->resetForm();
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $address = $this->ownedOrFail($id);

        $this->editingId = $address->id;
        $this->form = [
            'label' => $address->label,
            'address_line' => $address->address_line,
            'landmark' => $address->landmark ?? '',
            'city' => $address->city ?? '',
            'pincode' => $address->pincode ?? '',
            'is_default' => (bool) $address->is_default,
        ];
        $this->showForm = true;
        $this->reset('error', 'notice');
    }

    public function save(CustomerLocationContext $location): void
    {
        $this->reset('error', 'notice');
        $data = $this->validate()['form'];

        if ($this->editingId) {
            $address = $this->ownedOrFail($this->editingId);
            $address->fill([
                'label' => $data['label'],
                'address_line' => $data['address_line'],
                'landmark' => $data['landmark'] ?: null,
                'city' => $data['city'] ?: null,
                'pincode' => $data['pincode'] ?: null,
                'is_default' => $data['is_default'],
            ])->save();
            $this->notice = 'Address updated.';
        } else {
            $zone = $location->zone();
            if (! $zone) {
                $this->error = 'Choose your area from the top of the page first, so we know which team serves this address.';

                return;
            }

            Address::create([
                'user_id' => auth()->id(),
                'franchise_id' => $zone->franchise_id,
                'zone_id' => $zone->id,
                'label' => $data['label'],
                'lat' => $zone->center_lat ?? 0,
                'lng' => $zone->center_lng ?? 0,
                'address_line' => $data['address_line'],
                'landmark' => $data['landmark'] ?: null,
                'city' => $data['city'] ?: null,
                'pincode' => $data['pincode'] ?: null,
                'is_default' => $data['is_default'],
            ]);
            $this->notice = 'Address added.';
        }

        $this->resetForm();
        $this->showForm = false;
    }

    public function delete(int $id): void
    {
        $this->reset('error', 'notice');
        $address = $this->ownedOrFail($id);

        if (Booking::where('address_id', $address->id)->exists()) {
            $this->error = 'This address is used by an existing booking and can\'t be deleted.';

            return;
        }

        $address->delete();
        $this->notice = 'Address removed.';
    }

    private function ownedOrFail(int $id): Address
    {
        $address = Address::where('id', $id)->where('user_id', auth()->id())->first();

        // 404, never 403 — the same information-hiding convention every
        // customer-scoped surface in this codebase uses.
        abort_if($address === null, 404);

        return $address;
    }

    private function resetForm(): void
    {
        $this->editingId = null;
        $this->form = ['label' => 'Home', 'address_line' => '', 'landmark' => '', 'city' => '', 'pincode' => '', 'is_default' => false];
    }

    public function render()
    {
        return view('livewire.customer.account.addresses', [
            'addresses' => Address::where('user_id', auth()->id())->orderByDesc('is_default')->latest()->get(),
        ])->layout('components.layouts.customer', ['title' => 'Saved addresses']);
    }
}
