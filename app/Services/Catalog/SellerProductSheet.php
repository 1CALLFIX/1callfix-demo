<?php

namespace App\Services\Catalog;

use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\PendingChangeService;
use App\Support\MarketplaceSettings;
use Illuminate\Support\Facades\DB;

/**
 * Owner 2026-11-03: a seller downloads their own products, changes them in Excel and uploads the file back.
 * The same rules as the Products page (PendingChangeService): price, discount, stock and show/hide change at once; a
 * new name on a live item waits for approval while customers keep the old one; a row without an id is a new product
 * (it waits for approval when Shop Settings says so). Rows with a problem are skipped and reported by row number.
 */
class SellerProductSheet
{
    /** "photos": up to 5 direct photo links separated by | (owner 2026-11-03, e.g. https://i.postimg.cc/...jpg). */
    public const COLUMNS = ['id', 'name', 'price', 'discount_percent', 'stock', 'show', 'photos'];
    private const MAX_ROWS = 1000;

    /** CSV text of the shop's products (opens in Excel). */
    public function export(Store $store): string
    {
        $out = fopen('php://temp', 'r+');
        fwrite($out, "\xEF\xBB\xBF"); // so Excel reads ₹ and Telugu correctly
        fputcsv($out, self::COLUMNS);
        Product::where('store_id', $store->id)->orderBy('name')->get()->each(fn (Product $p) => fputcsv($out, [
            $p->id, $p->name, (float) $p->price, $p->discount_percent !== null ? (float) $p->discount_percent : '',
            $p->option_names ? 'by choice' : (int) $p->stock, $p->is_active ? 'yes' : 'no', implode(' | ', $p->image_urls),
        ]));
        rewind($out);
        $csv = (string) stream_get_contents($out);
        fclose($out);

        return $csv;
    }

    /** @return array{updated:int, added:int, waiting:int, unchanged:int, errors: array<int, string>} */
    public function import(Store $store, User $by, string $path, array $scope): array
    {
        $result = ['updated' => 0, 'added' => 0, 'waiting' => 0, 'unchanged' => 0, 'errors' => []];
        $rows = $this->read($path, $result['errors']);
        if ($rows === null) {
            return $result;
        }
        $needsApproval = MarketplaceSettings::on('marketplace.seller_products_need_approval', $scope);
        $changes = app(PendingChangeService::class);

        DB::transaction(function () use ($rows, $store, $by, $needsApproval, $changes, &$result) {
            $names = Product::where('store_id', $store->id)->pluck('name', 'id')->map(fn ($n) => mb_strtolower(trim($n)));
            foreach ($rows as $line => $r) {
                $name = trim((string) ($r['name'] ?? ''));
                $price = trim((string) ($r['price'] ?? ''));
                $discount = trim((string) ($r['discount_percent'] ?? ''));
                $stock = trim((string) ($r['stock'] ?? ''));
                $show = mb_strtolower(trim((string) ($r['show'] ?? 'yes')));
                $problem = match (true) {
                    mb_strlen($name) < 2 || mb_strlen($name) > 150 => 'the name must be 2 to 150 letters',
                    ! is_numeric($price) || (float) $price < 1 => 'the price must be a number of at least 1',
                    $discount !== '' && (! is_numeric($discount) || (float) $discount < 0 || (float) $discount > 90) => 'the discount must be 0 to 90',
                    $stock !== '' && $stock !== 'by choice' && (! ctype_digit($stock) || (int) $stock > 1000000) => 'the stock must be a whole number',
                    ! in_array($show, ['yes', 'no', '1', '0', ''], true) => 'show must be yes or no',
                    MarketplaceSettings::blockedWordIn($name) !== null => '“'.MarketplaceSettings::blockedWordIn($name).'” cannot be sold',
                    default => null,
                };
                if ($problem) {
                    $result['errors'][] = "Row {$line}: {$problem}.";

                    continue;
                }
                $fields = [
                    'name' => $name,
                    'price' => round((float) $price, 2),
                    'discount_percent' => $discount === '' ? null : round((float) $discount, 2),
                    'is_active' => ! in_array($show, ['no', '0'], true),
                ];
                if ($stock !== '' && $stock !== 'by choice') {
                    $fields['stock'] = (int) $stock;
                }

                $id = trim((string) ($r['id'] ?? ''));
                $product = $id !== '' && ctype_digit($id) ? Product::where('store_id', $store->id)->find((int) $id) : null;
                if ($id !== '' && ! $product) {
                    $result['errors'][] = "Row {$line}: product {$id} is not in your shop. Leave id empty to add a new product.";

                    continue;
                }

                // Photos: empty cell = leave them as they are. A link this file gave out for a photo already on
                // 1CallFix maps back to that photo; any other link must be a direct https photo link.
                $links = \App\Support\ProductPhotoLink::split((string) ($r['photos'] ?? ''));
                if ($links !== []) {
                    $own = collect($product?->images ?? [])->mapWithKeys(fn ($path) => [Product::photoUrl($path) => $path]);
                    $photoProblem = count($links) > \App\Support\ProductPhotoLink::MAX ? 'up to '.\App\Support\ProductPhotoLink::MAX.' photos' : null;
                    $images = [];
                    foreach ($links as $link) {
                        if ($own->has($link)) {
                            $images[] = $own[$link];
                        } elseif ($problem = \App\Support\ProductPhotoLink::problem($link)) {
                            $photoProblem ??= $problem;
                        } else {
                            $images[] = $link;
                        }
                    }
                    if ($photoProblem) {
                        $result['errors'][] = "Row {$line}: {$photoProblem}.";

                        continue;
                    }
                    $fields['images'] = $images;
                }

                if ($id !== '') {
                    if ($product->option_names) {
                        unset($fields['stock']); // stock is set per size / colour
                    }
                    $review = $needsApproval && $product->is_approved;
                    [$now, $wait] = $review ? $changes->split($product, $fields) : [$fields, []];
                    $product->fill($now);
                    $dirty = $product->isDirty();
                    $product->save();
                    if ($wait !== []) {
                        $changes->request($product, $wait, $by, $store->id);
                        $result['waiting']++;
                    }
                    $dirty ? $result['updated']++ : ($wait === [] ? $result['unchanged']++ : null);
                    $names[$product->id] = mb_strtolower($product->name);

                    continue;
                }

                if ($names->contains(mb_strtolower($name))) {
                    $result['errors'][] = "Row {$line}: {$name} is already in your shop (add its id to change it).";

                    continue;
                }
                if (! array_key_exists('stock', $fields)) {
                    $result['errors'][] = "Row {$line}: give the stock for a new product.";

                    continue;
                }
                $product = Product::create($fields + ['store_id' => $store->id, 'tax_percent' => 0, 'is_approved' => ! $needsApproval]);
                $names[$product->id] = mb_strtolower($name);
                $result['added']++;
            }
        });
        ActivityLogger::logModel($by, $store, 'Seller uploaded a products sheet', array_diff_key($result, ['errors' => 1]) + ['errors' => count($result['errors'])]);

        return $result;
    }

    /** @return array<int, array<string, string>>|null row number => cells */
    private function read(string $path, array &$errors): ?array
    {
        $handle = fopen($path, 'r');
        if (! $handle) {
            $errors[] = 'The file could not be opened.';

            return null;
        }
        $first = (string) fgets($handle);
        $delimiter = substr_count($first, ';') > substr_count($first, ',') ? ';' : ',';
        $header = array_map(fn ($h) => strtolower(trim(preg_replace('/^\xEF\xBB\xBF/', '', (string) $h))), str_getcsv($first, $delimiter));
        if (! in_array('name', $header, true) || ! in_array('price', $header, true)) {
            fclose($handle);
            $errors[] = 'The first row must be the column names: '.implode(', ', self::COLUMNS).'. Download your products to get the right file.';

            return null;
        }
        $rows = [];
        $line = 1;
        while (($data = fgetcsv($handle, 0, $delimiter)) !== false) {
            $line++;
            if (count(array_filter($data, fn ($v) => trim((string) $v) !== '')) === 0) {
                continue;
            }
            if (count($rows) >= self::MAX_ROWS) {
                $errors[] = 'Only the first '.self::MAX_ROWS.' rows were read.';
                break;
            }
            $rows[$line] = array_combine($header, array_slice(array_pad($data, count($header), ''), 0, count($header)));
        }
        fclose($handle);

        return $rows;
    }
}
