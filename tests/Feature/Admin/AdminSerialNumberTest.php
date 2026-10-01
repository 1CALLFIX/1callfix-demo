<?php

namespace Tests\Feature\Admin;

use App\Livewire\Customers\Index;
use App\Models\User;
use App\Support\SerialNumber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Livewire;
use Tests\Feature\Rbac\RbacTestHelpers;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\TestCase;

/**
 * REF 1CF-ADMIN-SNO-001 — S.No is a display-only first column on every admin list
 * table: (current page - 1) x per page + row position, never stored.
 */
class AdminSerialNumberTest extends TestCase
{
    use BookingFixtureHelpers;
    use RbacTestHelpers;
    use RefreshDatabase;

    /** S.No cells of the rendered table, in row order (the first <td> of each body row). */
    private function snoCells(string $html): array
    {
        preg_match_all('/<tr class="border-t[^"]*"[^>]*>\s*<td class="[^"]*tabular-nums[^"]*">(\d+)<\/td>/', $html, $m);

        return array_map('intval', $m[1]);
    }

    private function seedCustomers(int $count, string $prefix = 'Cust'): void
    {
        for ($i = 1; $i <= $count; $i++) {
            $c = $this->makeCustomer();
            $c->update(['name' => sprintf('%s %02d', $prefix, $i)]);
        }
    }

    public function test_helper_continues_numbering_across_pages(): void
    {
        $page2 = new LengthAwarePaginator(array_fill(0, 25, 'x'), 60, 25, 2);

        $this->assertSame(26, SerialNumber::at($page2, 1));
        $this->assertSame(50, SerialNumber::at($page2, 25));
        $this->assertSame(1, SerialNumber::at(new LengthAwarePaginator([], 0, 25, 1), 1));
        $this->assertSame(3, SerialNumber::at(collect([1, 2, 3]), 3), 'an unpaginated list counts 1..n');
    }

    public function test_page_one_numbers_from_one(): void
    {
        $this->seedCustomers(25);

        $html = Livewire::actingAs($this->makeSuperAdmin())->test(Index::class)->html();

        $this->assertSame(range(1, 20), $this->snoCells($html));
        $this->assertStringContainsString('S.No', $html);
    }

    public function test_page_two_continues_from_the_page_offset(): void
    {
        $this->seedCustomers(25);

        $html = Livewire::actingAs($this->makeSuperAdmin())->test(Index::class)->call('gotoPage', 2)->html();

        $this->assertSame(range(21, 25), $this->snoCells($html));
    }

    public function test_filtered_list_is_numbered_from_its_own_first_row(): void
    {
        $this->seedCustomers(25, 'Alpha');
        $this->seedCustomers(3, 'Beta');

        $c = Livewire::actingAs($this->makeSuperAdmin())->test(Index::class)->set('search', 'Beta');

        $this->assertSame([1, 2, 3], $this->snoCells($c->html()));
    }

    public function test_every_table_carries_the_column_in_first_position(): void
    {
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(resource_path('views/livewire')));
        $files = array_merge(
            array_map(fn ($f) => $f->getPathname(), array_filter(iterator_to_array($files), fn ($f) => str_ends_with($f->getFilename(), '.blade.php'))),
            [resource_path('views/components/import-panel.blade.php'), resource_path('views/components/prereg-panel.blade.php')],
        );
        $headers = $cells = 0;
        foreach ($files as $file) {
            $src = file_get_contents($file);
            $h = preg_match_all('/<x-ui\.sno-th[^>]*\/>/', $src);
            $c = preg_match_all('/<x-ui\.sno :rows="[^"]+" :loop="\$loop"[^>]*\/>/', $src);
            // the only table without a header row is the Services option-group list
            $this->assertSame(str_contains($file, 'services') && str_contains($file, 'manage') ? $h + 1 : $h, $c, $file.": header $h cells $c mismatch");
            // header must be the first cell of its header row
            $this->assertSame($h, preg_match_all('/<tr[^>]*>\s*<x-ui\.sno-th/', $src), basename($file).': S.No is not the first header column');
            $this->assertSame(0, preg_match_all('/>SL<\/th>/', $src), basename($file).': legacy SL column left behind');
            $headers += $h;
            $cells += $c;
        }
        // 59 list tables + 19 sub-tables (one of them header-less)
        $this->assertSame(77, $headers);
        $this->assertSame(78, $cells);
    }
}
