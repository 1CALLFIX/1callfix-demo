<?php

namespace App\Livewire\Customer\Concerns;

/**
 * Send a customer back to where they were when they were asked to sign in
 * (e.g. "Add to cart" on a service page), instead of always the homepage.
 * Only same-site relative paths are honoured — never an open redirect.
 */
trait RedirectsAfterLogin
{
    protected function rememberIntended(?string $target): void
    {
        $path = $this->safeInternalPath($target);

        if ($path !== null) {
            session()->put('customer.intended', $path);
        }
    }

    protected function redirectAfterLogin(): void
    {
        $path = $this->safeInternalPath(session()->pull('customer.intended'));

        $path !== null
            ? $this->redirect($path, navigate: true)
            : $this->redirectRoute('customer.home', navigate: true);
    }

    private function safeInternalPath(?string $target): ?string
    {
        if (! is_string($target) || $target === '') {
            return null;
        }

        // Accept an absolute URL only when it is this app's own host.
        $parts = parse_url($target);
        if ($parts === false) {
            return null;
        }
        if (isset($parts['host']) && strcasecmp($parts['host'], request()->getHost()) !== 0) {
            return null;
        }

        $path = ($parts['path'] ?? '/').(isset($parts['query']) ? '?'.$parts['query'] : '');

        return str_starts_with($path, '/') && ! str_starts_with($path, '//') ? $path : null;
    }
}
