<?php

namespace App\Livewire\PartnerPage;

use App\Models\Module;
use App\Models\Setting;
use App\Services\ActivityLogger;
use App\Services\SettingsAuditor;
use App\Support\PartnerPage\PartnerPageSettings as P;
use App\Support\Seo;
use Livewire\Component;

/**
 * Admin → Growth → Partner page. One screen for every word and list on the public /partners page, on the existing
 * settings store. Gated by the grantable `partner_page.manage` permission (Super Admin always holds it), checked
 * server-side on mount and on save. A value containing [ or ] blocks publishing; every changed key is audit-logged
 * by SettingsAuditor and the screen's save by ActivityLogger; the page's cached values are cleared on save.
 * A blank field clears the setting back to the in-code default (or hides a block that has none).
 */
class Settings extends Component
{
    /** @var array<string, string> field name (dots → "__") => value */
    public array $f = [];

    /** @var array<string, bool> */
    public array $sw = [];

    /** @var list<string> */
    public array $hidden = [];

    public string $flashMessage = '';

    public static function field(string $short): string
    {
        return str_replace('.', '__', $short);
    }

    public function mount(): void
    {
        $this->authorizeManage();

        foreach (array_merge(array_keys(P::TEXT), array_keys(P::JSON), [P::LEAD_RETENTION]) as $short) {
            $this->f[self::field($short)] = (string) Setting::get(P::key($short), '');
        }
        foreach (P::SWITCHES as $short => [$label, $default]) {
            $this->sw[self::field($short)] = P::on($short);
        }
        $this->hidden = P::hiddenModules();
        $this->f[self::field('modules_hidden')] = '';
    }

    private function authorizeManage(): void
    {
        abort_unless(auth()->user()?->hasPermission('partner_page.manage'), 403, 'You do not have permission to edit the Partner page.');
    }

    public function save(): void
    {
        $this->authorizeManage();

        $values = [];
        foreach (array_merge(array_keys(P::TEXT), array_keys(P::JSON), [P::LEAD_RETENTION]) as $short) {
            $values[$short] = trim((string) ($this->f[self::field($short)] ?? ''));
        }
        $hidden = array_values(array_unique(array_filter($this->hidden, 'is_string')));
        $values['modules_hidden'] = $hidden === [] ? '' : json_encode($hidden);

        $errors = P::validate($values);
        if ($errors !== []) {
            foreach ($errors as $short => $message) {
                $this->addError('f.'.self::field($short), $message);
            }
            $this->flashMessage = '';

            return;
        }

        $admin = auth()->user();
        $changed = [];
        foreach ($values as $short => $value) {
            if (SettingsAuditor::put($admin, P::key($short), $value === '' ? null : $value)) {
                $changed[] = $short;
            }
        }
        foreach (P::SWITCHES as $short => [$label, $default]) {
            $new = ! empty($this->sw[self::field($short)]);
            if (SettingsAuditor::put($admin, P::key($short), $new ? '1' : '0')) {
                $changed[] = $short;
            }
        }

        P::forgetCache();

        if ($changed !== []) {
            ActivityLogger::log($admin, 'partner_page', 0, 'Partner page saved', ['keys' => $changed]);
            Seo::bumpSitemap();
        }

        $this->resetErrorBag();
        $this->flashMessage = $changed !== [] ? 'Partner page saved.' : 'Nothing changed.';
    }

    public function render()
    {
        $defaultFee = (float) Setting::get('commission.default_platform_fee_percent', '30');
        $min = trim((string) ($this->f[self::field('commission.min')] ?? ''));
        $max = trim((string) ($this->f[self::field('commission.max')] ?? ''));
        $outside = is_numeric($min) && is_numeric($max) && ($defaultFee < (float) $min || $defaultFee > (float) $max);

        return view('livewire.partner-page.settings', [
            'modules' => Module::query()->orderBy('sort_order')->get(['code', 'name', 'is_implemented'])->keyBy('code'),
            'defaultFee' => $defaultFee,
            'feeOutsideRange' => $outside,
        ])->layout('layouts.admin', ['title' => 'Partner page']);
    }
}
