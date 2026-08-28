<?php

namespace App\Livewire\Customer\Concerns;

use App\Models\Service;
use App\Models\ServiceOption;
use App\Models\ServiceOptionGroup;
use Illuminate\Support\Collection;

/**
 * Phase E6 — the service-option selection + estimate maths, extracted
 * VERBATIM from App\Livewire\Customer\Catalog\ServiceShow (Phase C) so the
 * booking wizard configures options exactly the same way the catalog
 * detail page prices them, with no second copy to drift.
 *
 * The security boundary is unchanged from ServiceShow's own docblock:
 * selections arrive as option IDs, are validated against THIS service's own
 * active option groups, and every `price_delta` is re-read from the
 * database — a tampered payload can at most pick an option that genuinely
 * belongs to this service at the price the database genuinely holds. And,
 * as ServiceShow already records, nothing in `app/` writes a
 * `booking_options` row and `CreateBookingAction` takes no options
 * argument, so this total is a DISPLAY estimate only — the authoritative
 * charge is still whatever CreateBookingAction computes from the Phase-D
 * cascade at booking time.
 */
trait ConfiguresServiceOptions
{
    /**
     * group id => selected option id (single-choice) or array of ids
     * (allow_multiple). Written only through selectOption()/toggleOption(),
     * both of which validate against this service's own groups.
     */
    public array $selected = [];

    /** @var Collection<int, ServiceOptionGroup>|null per-request memo; private so Livewire never serialises it */
    private ?Collection $optionGroupCache = null;

    /** Single-choice group: one option replaces any previous choice. */
    public function selectOption(int $groupId, int $optionId): void
    {
        $group = $this->optionGroups()->firstWhere('id', $groupId);

        if (! $group || $group->allow_multiple || ! $group->options->contains('id', $optionId)) {
            return;
        }

        $this->selected[$groupId] = $optionId;
    }

    /** Multi-choice group: toggle one option in or out of the set. */
    public function toggleOption(int $groupId, int $optionId): void
    {
        $group = $this->optionGroups()->firstWhere('id', $groupId);

        if (! $group || ! $group->allow_multiple || ! $group->options->contains('id', $optionId)) {
            return;
        }

        $current = collect((array) ($this->selected[$groupId] ?? []))->map(fn ($id) => (int) $id);

        $this->selected[$groupId] = $current->contains($optionId)
            ? $current->reject(fn ($id) => $id === $optionId)->values()->all()
            : $current->push($optionId)->values()->all();
    }

    /**
     * This service's active option groups with their active options, ordered
     * as the admin arranged them. Memoised on the INSTANCE (a private
     * property, so Livewire never serialises it into the page payload).
     *
     * @return Collection<int, ServiceOptionGroup>
     */
    protected function optionGroups(): Collection
    {
        return $this->optionGroupCache ??= ServiceOptionGroup::query()
            ->where('service_id', $this->optionServiceId())
            ->with(['options' => fn ($q) => $q->where('is_active', true)->orderBy('sort_order')->orderBy('id')])
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->filter(fn (ServiceOptionGroup $g) => $g->options->isNotEmpty())
            ->values();
    }

    /**
     * Required single-choice groups start on their first option so the
     * estimate is a complete, buyable configuration from first paint.
     * Required multi-choice groups are left empty on purpose.
     */
    protected function preselectRequiredGroups(): void
    {
        foreach ($this->optionGroups() as $group) {
            if ($group->is_required && ! $group->allow_multiple) {
                $this->selected[$group->id] = $group->options->first()->id;
            }
        }
    }

    /**
     * The real ServiceOption rows behind the current selection, re-read from
     * the database. The validation boundary: an id that is not an active
     * option of one of THIS service's groups is discarded.
     *
     * @return Collection<int, ServiceOption>
     */
    protected function selectedOptions(): Collection
    {
        $groups = $this->optionGroups()->keyBy('id');

        return collect($this->selected)
            ->flatMap(function ($value, $groupId) use ($groups) {
                $group = $groups->get((int) $groupId);

                if (! $group) {
                    return [];
                }

                return collect((array) $value)
                    ->map(fn ($id) => $group->options->firstWhere('id', (int) $id))
                    ->filter();
            })
            ->values();
    }

    /** @param  Collection<int, ServiceOption>  $options */
    protected function optionsTotal(Collection $options): float
    {
        return (float) $options->sum(fn (ServiceOption $option) => (float) $option->price_delta);
    }

    /**
     * Required groups the customer has not answered yet.
     *
     * @return Collection<int, ServiceOptionGroup>
     */
    protected function missingRequiredGroups(): Collection
    {
        return $this->optionGroups()->filter(function (ServiceOptionGroup $group) {
            if (! $group->is_required) {
                return false;
            }

            return empty($this->selected[$group->id] ?? null);
        })->values();
    }

    /** The service whose option groups this trait operates on. */
    abstract protected function optionServiceId(): int;
}
