<?php

namespace App\Services\Marketing;

use App\Models\City;
use App\Models\Franchise;
use App\Models\MarketingContact;
use App\Models\User;
use App\Support\PhoneNumber;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Concerns\ToArray;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Old customer list → marketing_contacts (owner 2026-11-21). Reads Excel (.xlsx, .xls) or CSV. Columns are found by
 * their heading (name, mobile/phone/number, email, city); a file without headings is read as name, mobile, email, city.
 * preview() checks every row and says in plain words what is wrong; save() adds new contacts and fills blanks on
 * existing ones (same mobile), never overwriting what is already there.
 */
class ContactImporter
{
    public const MAX_ROWS = 5000;

    private const HEADINGS = [
        'name' => ['name', 'customer', 'customer name', 'full name', 'పేరు'],
        'phone' => ['mobile', 'phone', 'mobile number', 'phone number', 'number', 'contact', 'whatsapp', 'మొబైల్'],
        'email' => ['email', 'e-mail', 'mail', 'email id'],
        'city' => ['city', 'town', 'area', 'location', 'ఊరు'],
    ];

    /**
     * @return array{rows: list<array{name: ?string, phone: ?string, email: ?string, city: ?string, line: int}>, problems: list<string>, duplicates: int, existing: int}
     */
    public function preview(string $path): array
    {
        $sheets = Excel::toArray(new class implements ToArray
        {
            public function array(array $array): void {}
        }, $path);
        $raw = array_values(array_filter($sheets[0] ?? [], fn ($r) => is_array($r) && array_filter($r, fn ($v) => trim((string) $v) !== '') !== []));

        $map = $this->columns($raw[0] ?? []);
        if ($map['header']) {
            array_shift($raw);
        }

        $rows = [];
        $problems = [];
        $seen = [];
        $duplicates = 0;
        foreach (array_slice($raw, 0, self::MAX_ROWS) as $i => $r) {
            $line = $i + ($map['header'] ? 2 : 1);
            $name = $this->clean($r[$map['name']] ?? null, 120);
            $email = Str::lower((string) $this->clean($r[$map['email']] ?? null, 190));
            $phone = $this->phone($r[$map['phone']] ?? null);
            $email = filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;

            if (! $phone && ! $email) {
                $problems[] = "Row {$line}: no valid mobile number or email".($name ? " for {$name}" : '').'.';

                continue;
            }
            $key = $phone ?? $email;
            if (isset($seen[$key])) {
                $duplicates++;

                continue;
            }
            $seen[$key] = true;
            $rows[] = ['name' => $name, 'phone' => $phone, 'email' => $email, 'city' => $this->clean($r[$map['city']] ?? null, 80), 'line' => $line];
        }
        if (count($raw) > self::MAX_ROWS) {
            $problems[] = 'Only the first '.self::MAX_ROWS.' rows are read. Split the file and import the rest next.';
        }

        $phones = array_filter(array_column($rows, 'phone'));
        $existing = $phones === [] ? 0 : MarketingContact::whereIn('phone', $phones)->count();

        return ['rows' => $rows, 'problems' => $problems, 'duplicates' => $duplicates, 'existing' => $existing];
    }

    /** @return array{added: int, updated: int, already_customers: int} */
    public function save(array $rows, User $by, ?int $defaultCityId = null): array
    {
        $batch = now()->format('Ymd-His').'-'.$by->id;
        $cities = City::query()->get(['id', 'name', 'country_id'])->keyBy(fn ($c) => Str::lower(trim($c->name)));
        $added = $updated = 0;

        foreach ($rows as $row) {
            $city = $row['city'] ? $cities->get(Str::lower(trim($row['city']))) : null;
            $city ??= $defaultCityId ? $cities->first(fn ($c) => $c->id === $defaultCityId) : null;
            $franchises = $city ? Franchise::where('city_id', $city->id)->limit(2)->pluck('id') : collect();
            $place = ['city_id' => $city?->id, 'country_id' => $city?->country_id, 'franchise_id' => $franchises->count() === 1 ? $franchises->first() : null];

            $contact = $row['phone'] ? MarketingContact::where('phone', $row['phone'])->first() : null;
            $contact ??= (! $row['phone'] && $row['email']) ? MarketingContact::whereNull('phone')->where('email', $row['email'])->first() : null;

            if ($contact) {
                $fill = array_filter(['name' => $contact->name ? null : $row['name'], 'email' => $contact->email ? null : $row['email'],
                    'city_name' => $contact->city_name ? null : $row['city']] + ($contact->city_id ? [] : array_filter($place)));
                if ($fill !== []) {
                    $contact->fill($fill)->save();
                    $updated++;
                }

                continue;
            }

            MarketingContact::create(['name' => $row['name'], 'phone' => $row['phone'], 'email' => $row['email'], 'city_name' => $row['city'],
                'import_batch' => $batch, 'imported_by' => $by->id] + $place);
            $added++;
        }

        return ['added' => $added, 'updated' => $updated, 'already_customers' => MarketingContact::syncJoined()];
    }

    /** @return array{name: int, phone: int, email: int, city: int, header: bool} */
    private function columns(array $first): array
    {
        $found = [];
        foreach ($first as $index => $cell) {
            $cell = Str::lower(trim((string) $cell));
            foreach (self::HEADINGS as $field => $names) {
                if (! isset($found[$field]) && in_array($cell, $names, true)) {
                    $found[$field] = $index;
                }
            }
        }
        if (isset($found['phone']) || isset($found['email'])) {
            return $found + ['name' => 99, 'phone' => 99, 'email' => 99, 'city' => 99, 'header' => true];
        }

        return ['name' => 0, 'phone' => 1, 'email' => 2, 'city' => 3, 'header' => false];
    }

    private function clean(mixed $value, int $max): ?string
    {
        $value = trim(preg_replace('/\s+/', ' ', strip_tags((string) $value)));

        return $value === '' ? null : mb_substr($value, 0, $max);
    }

    /** "98765 43210", "+91-9876543210", 9876543210.0 → "+919876543210"; anything else → null. */
    private function phone(mixed $value): ?string
    {
        $digits = preg_replace('/\D/', '', is_float($value) ? number_format($value, 0, '', '') : (string) $value);
        if (strlen($digits) === 12 && str_starts_with($digits, '91')) {
            $digits = substr($digits, 2);
        } elseif (strlen($digits) === 11 && str_starts_with($digits, '0')) {
            $digits = substr($digits, 1);
        }

        return preg_match('/^[6-9]\d{9}$/', $digits) ? PhoneNumber::e164($digits) : null;
    }
}
