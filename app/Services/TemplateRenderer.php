<?php

namespace App\Services;

class TemplateRenderer
{
    /**
     * Replaces {{key}} placeholders with $vars[key]. Missing variables
     * render as an empty string rather than leaving the raw placeholder or
     * throwing — a template referencing {{coupon_code}} must never crash a
     * send just because a particular campaign has no coupon attached.
     */
    public function render(string $template, array $vars): string
    {
        return preg_replace_callback('/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/', function ($matches) use ($vars) {
            return (string) ($vars[$matches[1]] ?? '');
        }, $template);
    }
}
