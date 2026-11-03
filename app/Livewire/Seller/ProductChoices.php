<?php

namespace App\Livewire\Seller;

use App\Livewire\Seller\Concerns\InteractsWithSeller;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Setting;
use App\Services\ActivityLogger;
use App\Support\MarketplaceSettings;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

/**
 * Seller → Products → "Units & choices" (owner 2026-11-02: size, colour, units and the other choices a shop and food
 * delivery need). On one page the seller says:
 *  - what one item is (unit): "500 g", "1 kg", "6 piece" — units list from Shop Settings;
 *  - up to two choice types the customer picks (Size + Colour, Storage + Colour, Portion…) — names suggested from
 *    Shop Settings, any name allowed — and the values ("S, M, L, XL").
 * "Make the list" lays out every combination; each gets its own price (blank = the product price), stock, photo
 * (one of the product's photos) and on/off. The product's discount applies to every choice. Price and stock go live
 * at once, like on the Products page. A combination that is dropped is switched off, never deleted (old orders keep
 * pointing at it).
 */
class ProductChoices extends Component
{
    use InteractsWithSeller;

    public const MAX_TYPES = 2;
    public const MAX_VALUES = 20;
    public const MAX_ROWS = 100;

    public int $productId;

    public string $unitValue = '';
    public string $unit = '';

    /** [['name' => 'Size', 'values' => 'S, M, L'], …] */
    public array $types = [];

    /** [['options' => ['Size' => 'M'], 'label' => 'M', 'price' => '', 'stock' => '0', 'active' => true, 'image' => ''], …] */
    public array $rows = [];

    public string $flashMessage = '';
    public string $flashType = 'success';

    public function mount(int $product): void
    {
        $model = $this->ownProduct($product);
        $this->productId = $model->id;
        $this->unitValue = $model->unit_value !== null ? rtrim(rtrim(number_format((float) $model->unit_value, 3, '.', ''), '0'), '.') : '';
        $this->unit = (string) $model->unit;

        $names = $model->option_names ?? [];
        $variants = $model->variants()->where('is_active', true)->orderBy('id')->get();
        foreach ($names as $name) {
            $values = $variants->map(fn ($v) => $v->options[$name] ?? null)->filter()->unique()->values()->all();
            $this->types[] = ['name' => $name, 'values' => implode(', ', $values)];
        }
        foreach ($variants as $v) {
            if (! is_array($v->options) || array_keys($v->options) !== $names) {
                continue;
            }
            $this->rows[] = [
                'options' => $v->options, 'label' => $v->name,
                'price' => $v->price_override !== null ? (string) (float) $v->price_override : '',
                'stock' => (string) $v->stock, 'active' => true, 'image' => (string) $v->image,
            ];
        }
    }

    public function addType(): void
    {
        if (count($this->types) < self::MAX_TYPES) {
            $this->types[] = ['name' => '', 'values' => ''];
        }
    }

    public function removeType(int $index): void
    {
        unset($this->types[$index]);
        $this->types = array_values($this->types);
        $this->buildRows();
    }

    /** Lays out every combination of the values, keeping what was already typed for a combination. */
    public function buildRows(): void
    {
        $this->resetErrorBag();
        $types = $this->parsedTypes();
        if ($types === null) {
            return;
        }
        if ($types === []) {
            $this->rows = [];

            return;
        }

        $combos = [[]];
        foreach ($types as $name => $values) {
            $next = [];
            foreach ($combos as $combo) {
                foreach ($values as $value) {
                    $next[] = $combo + [$name => $value];
                }
            }
            $combos = $next;
        }
        if (count($combos) > self::MAX_ROWS) {
            $this->addError('types', 'That makes '.count($combos).' combinations. Keep it to '.self::MAX_ROWS.' or fewer.');

            return;
        }

        $old = collect($this->rows)->keyBy(fn ($r) => json_encode($r['options']));
        $this->rows = array_map(function (array $options) use ($old) {
            $kept = $old->get(json_encode($options));

            return [
                'options' => $options,
                'label' => implode(' / ', $options),
                'price' => $kept['price'] ?? '',
                'stock' => $kept['stock'] ?? '0',
                'active' => $kept['active'] ?? true,
                'image' => $kept['image'] ?? '',
            ];
        }, $combos);
    }

    public function save(): void
    {
        $product = $this->ownProduct($this->productId);
        $units = MarketplaceSettings::lines('marketplace.product_units');
        $this->validate([
            'unitValue' => ['nullable', 'numeric', 'gt:0', 'max:100000'],
            'unit' => ['nullable', 'string', 'in:'.implode(',', $units)],
            'rows.*.price' => ['nullable', 'numeric', 'min:1', 'max:10000000'],
            'rows.*.stock' => ['required', 'integer', 'min:0', 'max:1000000'],
        ], [], ['unitValue' => 'unit amount', 'rows.*.price' => 'price', 'rows.*.stock' => 'stock']);
        if ($this->unitValue !== '' && $this->unit === '') {
            $this->addError('unit', 'Pick the unit (g, kg, piece…).');

            return;
        }

        $types = $this->parsedTypes();
        if ($types === null) {
            return;
        }
        $names = array_keys($types);
        $expected = array_product(array_map('count', $types));
        if ($names !== [] && $this->rows !== [] && (count($this->rows) !== $expected
            || collect($this->rows)->contains(fn ($r) => array_keys($r['options']) !== $names || ! $this->rowMatches($r['options'], $types)))) {
            $this->addError('types', 'Press "Make the list" after changing the choices.');

            return;
        }
        if ($names !== [] && $this->rows === []) {
            $this->addError('types', 'Press "Make the list" to add the choices.');

            return;
        }
        $text = implode(' ', $names).' '.collect($types)->flatten()->implode(' ');
        if (($word = MarketplaceSettings::blockedWordIn($text)) !== null) {
            $this->addError('types', "Choices with “{$word}” cannot be sold on the shop.");

            return;
        }

        DB::transaction(function () use ($product, $names) {
            $product->update([
                'unit_value' => $this->unitValue === '' ? null : round((float) $this->unitValue, 3),
                'unit' => $this->unit === '' ? null : $this->unit,
                'option_names' => $names === [] ? null : $names,
            ]);

            $existing = $product->variants()->get();
            $kept = [];
            foreach ($this->rows as $row) {
                $match = $existing->first(fn (ProductVariant $v) => $v->options === $row['options']);
                $fields = [
                    'name' => $row['label'],
                    'options' => $row['options'],
                    'price_override' => $row['price'] === '' ? null : round((float) $row['price'], 2),
                    'stock' => (int) $row['stock'],
                    'is_active' => (bool) $row['active'],
                    'image' => in_array($row['image'], $product->images ?? [], true) ? $row['image'] : null,
                ];
                $variant = $match ? tap($match)->update($fields) : $product->variants()->create($fields);
                $kept[] = $variant->id;
            }
            // Dropped combinations are switched off, never deleted: past orders point at them.
            $product->variants()->whereNotIn('id', $kept)->where('is_active', true)->get()->each->update(['is_active' => false]);
            $product->refresh()->syncStockFromChoices();
        });

        ActivityLogger::logModel(auth()->user(), $product, 'Seller updated product units and choices', ['choices' => $names, 'rows' => count($this->rows)]);
        $this->flashMessage = $names === [] ? 'Saved.' : 'Saved. Customers now pick '.implode(' and ', $names).' before adding it.';
        $this->flashType = 'success';
        $this->dispatch('seller-scroll-top'); // the message is at the top; Save is at the bottom
    }

    /** Takes all choices off this product: it sells as one item again, with its own stock. */
    public function removeChoices(): void
    {
        $product = $this->ownProduct($this->productId);
        DB::transaction(function () use ($product) {
            $total = (int) $product->variants()->where('is_active', true)->sum('stock');
            $product->update(['option_names' => null, 'stock' => $total]);
            $product->variants()->where('is_active', true)->get()->each->update(['is_active' => false]);
        });
        ActivityLogger::logModel(auth()->user(), $product, 'Seller removed product choices');
        $this->types = [];
        $this->rows = [];
        $this->flashMessage = 'Choices removed. It sells as one item again.';
        $this->flashType = 'success';
    }

    /** name => [values] from the form, or null (with an error) when something is wrong. */
    private function parsedTypes(): ?array
    {
        $out = [];
        foreach ($this->types as $i => $type) {
            $name = trim(preg_replace('/\s+/', ' ', (string) ($type['name'] ?? '')));
            $values = array_values(array_unique(array_filter(array_map(
                fn ($v) => trim(preg_replace('/\s+/', ' ', $v)),
                explode(',', (string) ($type['values'] ?? ''))
            ), fn ($v) => $v !== '')));
            if ($name === '' && $values === []) {
                continue;
            }
            if ($name === '' || mb_strlen($name) > 30) {
                $this->addError("types.{$i}.name", 'Give the choice a short name, like Size or Colour.');

                return null;
            }
            if (isset($out[$name])) {
                $this->addError("types.{$i}.name", "{$name} is already there.");

                return null;
            }
            if ($values === [] || count($values) > self::MAX_VALUES || collect($values)->contains(fn ($v) => mb_strlen($v) > 40)) {
                $this->addError("types.{$i}.values", 'Type 1 to '.self::MAX_VALUES.' short values, with commas between them.');

                return null;
            }
            $out[$name] = $values;
        }

        return $out;
    }

    private function rowMatches(array $options, array $types): bool
    {
        foreach ($options as $name => $value) {
            if (! in_array($value, $types[$name] ?? [], true)) {
                return false;
            }
        }

        return true;
    }

    private function ownProduct(int $productId): Product
    {
        $product = Product::where('store_id', $this->shop()->id)->find($productId);
        abort_unless($product, 404);

        return $product;
    }

    public function render()
    {
        $product = $this->ownProduct($this->productId);

        return view('livewire.seller.product-choices', [
            'product' => $product,
            'units' => MarketplaceSettings::lines('marketplace.product_units'),
            'choiceNames' => MarketplaceSettings::lines('marketplace.choice_names'),
            'photos' => $product->images ?? [],
            'currencySymbol' => Setting::get('locale.currency_symbol', '₹'),
            'maxTypes' => self::MAX_TYPES,
        ])->layout('components.layouts.seller', ['title' => 'Units & choices — '.$product->name]);
    }
}
