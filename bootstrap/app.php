<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {

        // Two distinct audiences now share this application: staff on
        // /admin/* (session login at admin.login) and customers on the
        // Phase B customer web app (OTP session login at customer.login).
        // Previously this unconditionally sent EVERY unauthenticated guest
        // to the admin login screen — correct while /admin was the only
        // authenticated surface, but it would drop a customer who followed
        // a link to their own account onto a staff email/password form.
        // Admin behaviour is unchanged; only non-admin paths are affected.
        $middleware->redirectGuestsTo(function (Request $request) {
            if ($request->is('admin', 'admin/*')) {
                return route('admin.login');
            }

            if ($request->is('provider', 'provider/*')) {
                return route('provider.login');
            }

            return route('customer.login');
        });

        // Mission Phase 16 -- see AppServiceProvider::boot()'s docblock for
        // the finding this closes. Applies Laravel's standard throttle:api
        // middleware (using the 'api' limiter registered there) to every
        // route in routes/api.php.
        $middleware->throttleApi();

    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // api/* is stateless JSON. The Phase 2 push-token endpoints
        // (push/*, admin/push/*) are session-guarded but are only ever
        // called by fetch() from the web apps — they must answer 401/422
        // as JSON too, not redirect to a login page.
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*', 'push/*', 'admin/push/*'),
        );
    })->create();