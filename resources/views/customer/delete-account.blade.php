@php
    // Google Play account deletion page (owner 2026-11-05). Every word: Admin → Website text & language → Delete account page.
    $t = fn (string $key, array $r = []) => \App\Support\SiteText::get($key, $r);
    $days = \App\Services\Account\AccountDeletionService::ERASE_AFTER_DAYS;
@endphp
<x-layouts.customer :title="$t('account.delete.title')" :indexable="true">
    <div class="mx-auto max-w-2xl px-4 py-10 sm:px-6 sm:py-14" data-testid="delete-account">
        <h1 class="text-2xl font-bold tracking-tight text-slate-900 sm:text-3xl">{{ $t('account.delete.title') }}</h1>
        <p class="mt-2 text-base text-slate-600">{{ $t('account.delete.intro') }}</p>

        @if (session('status'))
            <p class="mt-6 rounded-xl bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-800" role="status">{{ session('status') }}</p>
        @endif

        <h2 class="mt-8 text-lg font-semibold text-slate-900">{{ $t('account.delete.steps_title') }}</h2>
        <ol class="mt-3 list-decimal space-y-2 pl-5 text-sm leading-relaxed text-slate-700">
            <li>{{ $t('account.delete.step_customer') }}</li>
            <li>{{ $t('account.delete.step_partner') }}</li>
            <li>{{ $t('account.delete.step_web') }}</li>
            <li>{{ $t('account.delete.step_help') }} <a href="{{ route('customer.help') }}" class="font-semibold text-blue-700 underline">{{ $t('header.menu.help') }}</a></li>
        </ol>

        <h2 class="mt-8 text-lg font-semibold text-slate-900">{{ $t('account.delete.what_title') }}</h2>
        <ul class="mt-3 list-disc space-y-2 pl-5 text-sm leading-relaxed text-slate-700">
            <li>{{ $t('account.delete.what_1', ['days' => $days]) }}</li>
            <li>{{ $t('account.delete.what_2') }}</li>
            <li>{{ $t('account.delete.what_3') }}</li>
            <li>{{ $t('account.delete.what_partner') }}</li>
        </ul>

        <div class="mt-8 rounded-2xl border border-slate-200 bg-white p-5">
            @if (! $user)
                <a href="{{ route('customer.login') }}"
                   class="inline-flex min-h-11 items-center rounded-lg bg-blue-600 px-5 text-sm font-semibold text-white hover:bg-blue-700">{{ $t('account.delete.sign_in') }}</a>
            @elseif ($partner && $user->deletion_requested_at)
                <p class="text-sm font-medium text-slate-700" data-testid="delete-pending">{{ $t('account.delete.pending') }}</p>
            @elseif ($blockers)
                <ul class="space-y-2 text-sm font-medium text-rose-700" data-testid="delete-blocked">
                    @foreach ($blockers as $reason)<li>{{ $reason }}</li>@endforeach
                </ul>
            @else
                <form method="POST" action="{{ route('account.delete.submit') }}" class="space-y-4">
                    @csrf
                    <label class="flex items-start gap-3 text-sm text-slate-800">
                        <input type="checkbox" name="confirm" value="1" class="mt-0.5 h-5 w-5 rounded border-slate-300">
                        <span>{{ $t('account.delete.confirm') }}</span>
                    </label>
                    @error('confirm')<p class="text-sm text-rose-700">{{ $message }}</p>@enderror
                    <button type="submit" data-testid="delete-submit"
                            class="inline-flex min-h-11 items-center rounded-lg bg-rose-600 px-5 text-sm font-semibold text-white hover:bg-rose-700">
                        {{ $partner ? $t('account.delete.button_partner') : $t('account.delete.button') }}
                    </button>
                </form>
            @endif
        </div>
    </div>
</x-layouts.customer>
