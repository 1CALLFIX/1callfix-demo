<?php

namespace App\Support\InfoPages;

use App\Models\Setting;

/**
 * The company's contact details for the public /contact page (owner 2026-11-07), set by the Super Admin only on
 * Admin → Website & Pages → Contact page. Nothing is defaulted: a detail that is not filled in is simply not shown
 * (a made-up phone number is worse than none). The page's own words are Website text (group "Contact page", en + te).
 */
final class ContactDetails
{
    public const PREFIX = 'contact.';

    /** key => [admin label, max length, hint] */
    public const FIELDS = [
        'phone' => ['Phone number', 20, 'Shown with a Call button, e.g. +91 98765 43210'],
        'whatsapp' => ['WhatsApp number', 20, 'Opens WhatsApp chat. Digits with country code, e.g. 919876543210'],
        'email' => ['Email', 120, 'e.g. support@1callfix.com'],
        'hours' => ['Working hours', 120, 'e.g. Every day, 8 am to 9 pm'],
        'company' => ['Company name', 160, 'Registered name, e.g. 1CallFix Solutions Pvt Ltd'],
        'address' => ['Office address', 400, 'One line per line of the address'],
        'map_url' => ['Google Maps link to the office', 500, 'Must start with https://'],
        'franchise_email' => ['Franchise enquiries email (optional)', 120, 'Shown on the Franchise page; leave blank to use the main email'],
    ];

    public static function key(string $field): string
    {
        return self::PREFIX.$field;
    }

    public static function get(string $field): ?string
    {
        $v = trim((string) Setting::get(self::key($field), ''));

        return $v === '' ? null : $v;
    }

    /** tel: link, digits and a leading + only. */
    public static function telHref(): ?string
    {
        $p = self::get('phone');

        return $p ? 'tel:'.preg_replace('/(?!^\+)[^0-9]/', '', $p) : null;
    }

    public static function whatsappHref(): ?string
    {
        $w = preg_replace('/\D/', '', (string) self::get('whatsapp'));

        return $w !== '' ? 'https://wa.me/'.$w : null;
    }

    public static function hasAny(): bool
    {
        return self::get('phone') || self::get('whatsapp') || self::get('email');
    }

    /** @return array<string, string> field => message */
    public static function validate(array $values): array
    {
        $errors = [];
        foreach (self::FIELDS as $field => [$label, $max]) {
            $v = trim((string) ($values[$field] ?? ''));
            if ($v === '') {
                continue;
            }
            if (mb_strlen($v) > $max) {
                $errors[$field] = "{$label}: at most {$max} characters.";
            } elseif (in_array($field, ['email', 'franchise_email'], true) && ! filter_var($v, FILTER_VALIDATE_EMAIL)) {
                $errors[$field] = 'Enter a valid email address.';
            } elseif ($field === 'phone' && ! preg_match('/^\+?[0-9 ()-]{6,20}$/', $v)) {
                $errors[$field] = 'Digits, spaces, + ( ) and - only.';
            } elseif ($field === 'whatsapp' && ! preg_match('/^\+?[0-9 ]{8,20}$/', $v)) {
                $errors[$field] = 'Digits with the country code, e.g. 919876543210.';
            } elseif ($field === 'map_url' && ! preg_match('#^https://[^\s]+$#', $v)) {
                $errors[$field] = 'Must be a link starting with https://';
            }
        }

        return $errors;
    }
}
