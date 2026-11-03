<?php

namespace App\Livewire\Seller;

use App\Livewire\Seller\Concerns\InteractsWithSeller;
use App\Models\AddOn;
use App\Models\AddOnGroup;
use App\Models\Setting;
use App\Services\ActivityLogger;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

/**
 * Food F1 — Seller → Add-ons (restaurants only): choice groups with a rule ("Choose your base: pick 1", "Extra
 * toppings: up to 3") and the options inside each, with a price (0 = free). A dish lists the groups it offers on the
 * Menu page. The rule is checked again when the order is placed, never only in the browser.
 */
class AddOns extends Component
{
    use InteractsWithSeller;

    private const MAX_OPTIONS = 30;

    public ?int $editingId = null;
    public bool $creating = false;

    public string $name = '';
    public string $minSelect = '0';
    public string $maxSelect = '1';
    /** list of ['id' => ?int, 'name' => string, 'price' => string] */
    public array $options = [];

    public string $flashMessage = '';
    public string $flashType = 'success';

    public function mount(): void
    {
        abort_unless($this->shop()->isRestaurant(), 404);
    }

    public function startCreate(): void
    {
        $this->resetForm();
        $this->creating = true;
        $this->options = [['id' => null, 'name' => '', 'price' => '0']];
    }

    public function edit(int $groupId): void
    {
        $group = $this->ownGroup($groupId);
        $this->resetForm();
        $this->editingId = $group->id;
        $this->name = $group->name;
        $this->minSelect = (string) $group->min_select;
        $this->maxSelect = (string) $group->max_select;
        $this->options = $group->addOns()->orderBy('id')->get()
            ->map(fn (AddOn $a) => ['id' => $a->id, 'name' => $a->name, 'price' => (string) (float) $a->price])->all();
        $this->creating = true;
    }

    public function addOption(): void
    {
        if (count($this->options) < self::MAX_OPTIONS) {
            $this->options[] = ['id' => null, 'name' => '', 'price' => '0'];
        }
    }

    public function removeOption(int $index): void
    {
        unset($this->options[$index]);
        $this->options = array_values($this->options);
    }

    public function cancel(): void
    {
        $this->resetForm();
    }

    public function save(): void
    {
        $store = $this->shop();
        $this->validate([
            'name' => ['required', 'string', 'min:2', 'max:80'],
            'minSelect' => ['required', 'integer', 'min:0', 'max:20'],
            'maxSelect' => ['required', 'integer', 'min:1', 'max:20', 'gte:minSelect'],
            'options' => ['required', 'array', 'min:1', 'max:'.self::MAX_OPTIONS],
            'options.*.name' => ['required', 'string', 'min:1', 'max:80'],
            'options.*.price' => ['required', 'numeric', 'min:0', 'max:10000'],
        ], [
            'maxSelect.gte' => 'The most a customer can pick cannot be less than the fewest.',
            'options.required' => 'Add at least one option.',
        ], ['minSelect' => 'fewest to pick', 'maxSelect' => 'most to pick', 'options.*.name' => 'option name', 'options.*.price' => 'price']);

        if ((int) $this->minSelect > count($this->options)) {
            $this->addError('minSelect', 'There are fewer options than customers must pick.');

            return;
        }

        DB::transaction(function () use ($store) {
            $group = $this->editingId ? $this->ownGroup($this->editingId) : new AddOnGroup(['store_id' => $store->id]);
            $group->fill(['name' => trim($this->name), 'min_select' => (int) $this->minSelect, 'max_select' => (int) $this->maxSelect])->save();

            $keep = [];
            foreach ($this->options as $option) {
                $values = ['name' => trim($option['name']), 'price' => round((float) $option['price'], 2), 'is_active' => true, 'store_id' => $store->id, 'add_on_group_id' => $group->id];
                $addOn = ! empty($option['id']) ? $group->addOns()->find($option['id']) : null;
                $addOn ? $addOn->update($values) : $addOn = AddOn::create($values);
                $keep[] = $addOn->id;
            }
            // An option taken out is soft-deleted, so past orders keep their snapshot and nothing new can choose it.
            $group->addOns()->whereNotIn('id', $keep)->get()->each->delete();

            ActivityLogger::logModel(auth()->user(), $group, $this->editingId ? 'Restaurant updated add-on group' : 'Restaurant added add-on group', ['options' => count($keep)]);
        });

        $this->flash('Saved. Tick this group on each dish that offers it (Menu → Edit).', 'success');
        $this->resetForm();
    }

    public function toggleActive(int $groupId): void
    {
        $group = $this->ownGroup($groupId);
        $group->update(['is_active' => ! $group->is_active]);
    }

    public function delete(int $groupId): void
    {
        $group = $this->ownGroup($groupId);
        DB::transaction(function () use ($group) {
            $group->products()->detach();
            $group->addOns()->get()->each->delete();
            $group->delete();
        });
        ActivityLogger::logModel(auth()->user(), $group, 'Restaurant removed add-on group');
        $this->flash("{$group->name} removed.", 'success');
    }

    private function ownGroup(int $groupId): AddOnGroup
    {
        $group = AddOnGroup::where('store_id', $this->shop()->id)->find($groupId);
        abort_unless($group, 404);

        return $group;
    }

    private function resetForm(): void
    {
        $this->reset(['editingId', 'creating', 'name', 'options']);
        $this->minSelect = '0';
        $this->maxSelect = '1';
        $this->resetErrorBag();
    }

    private function flash(string $message, string $type): void
    {
        $this->flashMessage = $message;
        $this->flashType = $type;
    }

    public function render()
    {
        return view('livewire.seller.add-ons', [
            'groups' => AddOnGroup::where('store_id', $this->shop()->id)->withCount('products')
                ->with(['addOns' => fn ($q) => $q->orderBy('id')])->orderBy('position')->orderBy('id')->get(),
            'currencySymbol' => Setting::get('locale.currency_symbol', '₹'),
        ])->layout('components.layouts.seller', ['title' => 'Add-ons']);
    }
}
