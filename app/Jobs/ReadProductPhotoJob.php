<?php

namespace App\Jobs;

use App\Models\MarketplaceCategory;
use App\Models\ProductPhotoScan;
use App\Services\Ai\ProductPhotoReader;
use App\Support\MarketplaceSettings;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Owner 2026-11-03: reads a seller's photo(s) into a product list in the background, so a slow read never times out
 * the seller's page (the page polls the scan). Runs once (no automatic retry: each read is paid); the seller can
 * simply try again. The photos are deleted from the server once read, success or not.
 */
class ReadProductPhotoJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout = 115; // the default worker's --timeout is 120

    public function __construct(public int $scanId)
    {
    }

    public function handle(ProductPhotoReader $reader): void
    {
        $scan = ProductPhotoScan::with('store')->find($this->scanId);
        if (! $scan || $scan->status !== 'waiting') {
            return; // gone, or already read
        }
        $scan->update(['status' => 'reading']);

        try {
            $photos = collect($scan->photo_paths ?? [])->map(fn ($path) => [
                'data' => base64_encode((string) Storage::disk('local')->get($path)),
                'media_type' => 'image/jpeg', // every photo is re-saved as JPEG when uploaded
            ])->all();
            $categories = MarketplaceCategory::with('parent')->where('module', $scan->store->module)->where('is_active', true)->get()
                ->mapWithKeys(fn ($c) => [$c->id => ($c->parent ? $c->parent->name.' > ' : '').$c->name])->all();
            $result = $reader->read($photos, $categories, MarketplaceSettings::lines('marketplace.product_units'));

            $scan->update([
                'status' => 'done', 'items' => $result['items'], 'input_tokens' => $result['input_tokens'], 'output_tokens' => $result['output_tokens'],
                'error' => $result['items'] === [] ? ($result['notes'] ?: 'No products could be read in this photo.') : null,
            ]);
        } catch (\Throwable $e) {
            if (! $e instanceof \RuntimeException) {
                Log::error('[photo list] reading failed', ['scan' => $scan->id, 'error' => $e->getMessage()]);
            }
            $scan->update(['status' => 'failed', 'error' => $e instanceof \RuntimeException ? mb_substr($e->getMessage(), 0, 500) : 'The photo could not be read. Try again.']);
        } finally {
            Storage::disk('local')->delete($scan->photo_paths ?? []);
            $scan->update(['photo_paths' => null]);
        }
    }

    public function failed(?\Throwable $e): void
    {
        $scan = ProductPhotoScan::find($this->scanId);
        if ($scan && ! $scan->isFinished()) {
            $scan->update(['status' => 'failed', 'error' => 'The photo could not be read. Try again.']);
            Storage::disk('local')->delete($scan->photo_paths ?? []);
        }
    }
}
