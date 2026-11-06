{{-- Shown inside the Rider app for a Partner app page, or the other way round (App\Http\Middleware\OpenInRightApp). --}}
@php
    $isRider = $app === 'rider';
    $appTitle = $isRider ? '1CallFix Rider' : '1CallFix Partner';
@endphp
<x-layouts.customer :title="'Open the '.$appTitle.' app'" :minimal-chrome="true">
    <div class="mx-auto flex max-w-md flex-col items-center px-4 py-16 text-center" data-testid="open-other-app">
        <h1 class="text-xl font-bold text-gray-900">This is in the {{ $appTitle }} app</h1>
        <p class="mt-3 text-gray-600">
            @if ($isRider)
                Deliveries (parcels, food and shop orders) are done in the 1CallFix Rider app.
            @else
                Services, shops and restaurants are run from the 1CallFix Partner app.
            @endif
            Sign in there with the same phone number.
        </p>
        <a href="{{ $storeUrl }}" class="mt-6 inline-flex min-h-12 items-center rounded-lg bg-blue-600 px-6 font-semibold text-white hover:bg-blue-700">
            Open the {{ $isRider ? 'Rider' : 'Partner' }} app
        </a>
        <a href="{{ url()->previous() !== url()->current() ? url()->previous() : url('/') }}" class="mt-4 text-sm font-medium text-blue-700 hover:underline">Go back</a>
    </div>
</x-layouts.customer>
