<x-layouts.provider title="1CallFix Partner">
    <div class="mx-auto max-w-md py-6">
        <h1 class="text-2xl font-bold tracking-tight">1CallFix Partner</h1>
        <p class="mt-1 text-sm text-slate-600">Sign in to take jobs or run your shop or restaurant.</p>

        <div class="mt-6 space-y-3">
            <a href="{{ route('provider.login') }}" class="flex min-h-14 items-center justify-between rounded-2xl border border-slate-200 bg-white px-4 py-3 hover:border-blue-300" data-testid="start-service">
                <span>
                    <span class="block font-semibold">Service partner</span>
                    <span class="block text-xs text-slate-500">AC, plumbing, electrical and other home services</span>
                </span>
                <span aria-hidden="true">→</span>
            </a>
            <a href="{{ route('seller.login') }}" class="flex min-h-14 items-center justify-between rounded-2xl border border-slate-200 bg-white px-4 py-3 hover:border-blue-300" data-testid="start-seller">
                <span>
                    <span class="block font-semibold">Shop or restaurant</span>
                    <span class="block text-xs text-slate-500">Manage orders, products and menu</span>
                </span>
                <span aria-hidden="true">→</span>
            </a>
        </div>

        <a href="{{ route('customer.partners') }}" class="mt-6 flex min-h-12 items-center justify-center rounded-xl bg-blue-600 px-4 font-semibold text-white hover:bg-blue-700" data-testid="start-join">
            New here? Join as a partner
        </a>
    </div>
</x-layouts.provider>
