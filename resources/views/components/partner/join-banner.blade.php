{{-- Join in 2 minutes (owner 2026-11-12): while a partner still has required documents to upload, every portal home
     shows one line pointing to "Go live". Nothing shows once they are all in. --}}
@php
    $user = auth()->user();
    $kyc = app(\App\Services\Kyc\KycDocumentService::class);
    $missing = 0;
    if ($user) {
        if ($p = $user->providerProfile()->with('franchise')->first()) {
            $missing += count($kyc->missingRequiredForSubmission($p, $p->franchise?->country_id));
        }
        if (($fw = $user->fieldWorkerProfile()->with('franchise')->first()) && $fw->hasCapability('parcel_rider')) {
            $missing += count($kyc->missingRequiredForSubmission($fw, $fw->franchise?->country_id));
        }
    }
@endphp
@if ($missing > 0)
    <a href="{{ route('partner.join') }}" wire:navigate {{ $attributes->merge(['class' => 'flex min-h-12 items-center justify-between gap-3 rounded-2xl border border-amber-300 bg-amber-50 px-4 py-3 text-sm font-semibold text-amber-900 hover:bg-amber-100']) }} data-testid="join-banner">
        <span>Go live: upload {{ $missing }} {{ \Illuminate\Support\Str::plural('document', $missing) }} so we can approve you.</span>
        <span aria-hidden="true">→</span>
    </a>
@endif
