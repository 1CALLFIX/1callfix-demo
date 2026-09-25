<?php

namespace App\Services;

use App\Models\Setting;
use App\Models\User;

/**
 * REF 1CF-PROMPT-20260925-EARN3 — Rule of Law #5. The one audited write
 * path for admin settings screens (Settings\Manage — every tab — and
 * Earnings Control). Wraps the existing Setting::set()/clear() cascade; no
 * second settings system.
 *
 * One activity_log entry per key whose stored value at EXACTLY that scope
 * actually changes: admin (causer), timestamp (created_at), scope type and
 * id, key, old value, new value. Re-saving an identical value logs nothing.
 * A blank/null value CLEARS the key at that scope (UNSET), and is logged as
 * new = null.
 */
class SettingsAuditor
{
    /** @return bool whether anything changed (and was logged) */
    public static function put(?User $admin, string $key, $value, string $scopeType = 'global', ?int $scopeId = null): bool
    {
        $new = ($value === null || trim((string) $value) === '') ? null : (string) $value;

        $row = Setting::where('scope_type', $scopeType)->where('scope_id', $scopeId)->where('key', $key)->first();
        $old = ($row === null || $row->value === null) ? null : (string) $row->value;

        if ($old === $new) {
            return false;
        }

        if ($new === null) {
            Setting::clear($key, $scopeType, $scopeId);
            $subjectId = $row?->id ?? 0;
        } else {
            Setting::set($key, $new, $scopeType, $scopeId);
            $subjectId = (int) Setting::where('scope_type', $scopeType)->where('scope_id', $scopeId)->where('key', $key)->value('id');
        }

        ActivityLogger::log($admin, 'setting', $subjectId, "Setting {$key} changed", [
            'key' => $key,
            'scope_type' => $scopeType,
            'scope_id' => $scopeId,
            'old' => $old,
            'new' => $new,
        ]);

        return true;
    }
}
