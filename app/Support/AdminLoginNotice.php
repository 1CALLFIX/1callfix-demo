<?php

namespace App\Support;

use App\Models\Setting;

/**
 * The text of the notice emailed to an admin's OLD email address when their
 * login details (email or password) change. Super Admin-editable on the My
 * account screen (setting security.admin_login_change_notice, audit-logged
 * through SettingsAuditor); blank falls back to DEFAULT.
 */
final class AdminLoginNotice
{
    public const KEY = 'security.admin_login_change_notice';

    public const DEFAULT = "Your 1CallFix admin login details were changed. If this wasn't you, contact support immediately.";

    public static function message(): string
    {
        $copy = trim((string) Setting::get(self::KEY, ''));

        return $copy !== '' ? $copy : self::DEFAULT;
    }
}
