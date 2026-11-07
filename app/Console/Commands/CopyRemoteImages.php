<?php

namespace App\Console\Commands;

use App\Support\ImageShrinker;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * One-time move (owner 2026-11-07): service, category and sub-category pictures that still point at ImgBB
 * (i.ibb.co, a free host that is slow and sometimes down) are copied onto our own server, shrunk to the size the
 * site shows (App\Support\ImageShrinker), and the item then points at our copy.
 *
 *  - Dry run by default: downloads each picture to check it, changes nothing.
 *  - --apply: saves the copy on the public disk and updates the row, only if the row still has the same address.
 *  - A picture that cannot be downloaded, or is not a picture, is reported and its row left as it is.
 *  - Every change is written to storage/app/image-moves/<time>.json; --undo=<that file> puts the old addresses back.
 */
class CopyRemoteImages extends Command
{
    protected $signature = 'images:copy-remote
        {--apply : Actually copy and update (default is a dry run)}
        {--undo= : A storage/app/image-moves/*.json file: put the old addresses back}';

    protected $description = 'Copy ImgBB service/category/sub-category pictures onto our own server (dry run unless --apply)';

    /** table => [column, folder on the public disk, ImageSizes use] */
    public const TARGETS = [
        'services' => ['cover_image', 'services', 'service_cover'],
        'service_categories' => ['image', 'categories', 'category_icon'],
        'service_subcategories' => ['image', 'subcategories', 'subcategory_icon'],
    ];

    private const HOSTS = ['ibb.co', 'imgbb.com'];

    public function handle(): int
    {
        if ($this->option('undo')) {
            return $this->undo((string) $this->option('undo'));
        }

        $apply = (bool) $this->option('apply');
        $this->info($apply ? 'Copying pictures (--apply).' : 'Dry run: nothing will be changed.');

        $copied = [];   // remote url => stored path (one copy per picture, even if several items share it)
        $moves = [];
        $failed = [];
        $rows = 0;

        foreach (self::TARGETS as $table => [$column, $dir, $use]) {
            $list = DB::table($table)->select('id', $column)->whereNotNull($column)->orderBy('id')->get()
                ->filter(fn ($r) => self::isRemote((string) $r->{$column}));

            foreach ($list as $row) {
                $rows++;
                $url = (string) $row->{$column};
                $key = $use.'|'.$url;

                if (! isset($copied[$key])) {
                    try {
                        $copied[$key] = $this->fetchAndStore($url, $dir, $use, $apply);
                    } catch (Throwable $e) {
                        $failed[] = [$table, $row->id, $url, $e->getMessage()];
                        $this->warn("  not copied: {$table} #{$row->id} {$url} ({$e->getMessage()})");

                        continue;
                    }
                }

                $path = $copied[$key];
                if ($apply) {
                    $n = DB::table($table)->where('id', $row->id)->where($column, $url)->update([$column => $path]);
                    if ($n !== 1) {
                        $failed[] = [$table, $row->id, $url, 'row changed meanwhile'];

                        continue;
                    }
                }
                $moves[] = ['table' => $table, 'id' => $row->id, 'column' => $column, 'old' => $url, 'new' => $path];
                $this->line("  {$table} #{$row->id} → {$path}");
            }
        }

        if ($apply && $moves) {
            $log = 'image-moves/'.now()->format('Ymd-His').'.json';
            Storage::disk('local')->put($log, json_encode($moves, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            $this->info('Undo file: storage/app/'.$log);
        }

        $this->newLine();
        $this->info(sprintf('%s: %d of %d pictures%s. Not copied: %d.',
            $apply ? 'Moved' : 'Would move', count($moves), $rows, $apply ? '' : ' (dry run)', count($failed)));

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    public static function isRemote(string $value): bool
    {
        $host = strtolower((string) parse_url(trim($value), PHP_URL_HOST));
        foreach (self::HOSTS as $h) {
            if ($host === $h || str_ends_with($host, '.'.$h)) {
                return true;
            }
        }

        return false;
    }

    /** Download, check it is a picture, shrink it, and (with --apply) store it. Returns the stored path. */
    private function fetchAndStore(string $url, string $dir, string $use, bool $apply): string
    {
        $res = Http::timeout(40)->retry(3, 2000, throw: false)->get($url);
        if (! $res->successful()) {
            throw new \RuntimeException('HTTP '.$res->status());
        }
        $bytes = $res->body();
        $info = $bytes !== '' ? @getimagesizefromstring($bytes) : false;
        $exts = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp', IMAGETYPE_GIF => 'gif'];
        if (! $info || ! isset($exts[$info[2]])) {
            throw new \RuntimeException('not a picture');
        }

        $tmp = tempnam(sys_get_temp_dir(), 'imgmove');
        try {
            file_put_contents($tmp, $bytes);
            $shrunk = ImageShrinker::shrink($tmp, $use);
        } finally {
            @unlink($tmp);
        }
        [$bytes, $ext] = $shrunk ?? [$bytes, $exts[$info[2]]];

        $path = $dir.'/'.Str::random(40).'.'.$ext;
        if ($apply) {
            Storage::disk('public')->put($path, $bytes);
        }

        return $path;
    }

    private function undo(string $file): int
    {
        $rel = Str::after(str_replace('\\', '/', $file), 'storage/app/');
        if (! Storage::disk('local')->exists($rel)) {
            $this->error("No such file: storage/app/{$rel}");

            return self::FAILURE;
        }
        $n = 0;
        foreach (json_decode(Storage::disk('local')->get($rel), true) ?: [] as $m) {
            if (! isset(self::TARGETS[$m['table'] ?? ''])) {
                continue;
            }
            $n += DB::table($m['table'])->where('id', $m['id'])->where($m['column'], $m['new'])->update([$m['column'] => $m['old']]);
        }
        $this->info("Put back {$n} old addresses. (Our copies stay on disk.)");

        return self::SUCCESS;
    }
}
