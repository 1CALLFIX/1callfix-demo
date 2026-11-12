<?php
    $step = fn (bool $done, int $n) => $done
        ? '<span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-emerald-600 text-sm font-bold text-white" aria-label="Done">&check;</span>'
        : '<span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-slate-200 text-sm font-bold text-slate-700">'.$n.'</span>';
    $docLabel = ['pending' => ['Checking', 'bg-amber-100 text-amber-800'], 'approved' => ['Approved', 'bg-emerald-100 text-emerald-800'], 'rejected' => ['Please upload again', 'bg-red-100 text-red-800']];
?>
<div class="mx-auto max-w-2xl px-4 py-6 sm:py-10" data-testid="partner-join">
    <h1 class="text-2xl font-bold tracking-tight">Go live</h1>
    <p class="mt-1 text-sm text-slate-600">A few steps, in any order. Customers see you once we approve you.</p>

    {{-- Progress (partners part B) --}}
    <div class="mt-4" data-testid="join-progress">
        <div class="flex items-center justify-between text-sm font-medium text-slate-700"><span>{{ $done }} of 4 done</span>@if ($done === 4)<span class="text-emerald-700">You are live</span>@endif</div>
        <div class="mt-1.5 h-2.5 w-full overflow-hidden rounded-full bg-slate-200" role="progressbar" aria-valuemin="0" aria-valuemax="4" aria-valuenow="{{ $done }}" aria-label="Go live progress">
            <div class="h-full rounded-full bg-emerald-600 transition-all" style="width: {{ $done * 25 }}%"></div>
        </div>
    </div>

    @if ($notice) <div role="status" class="mt-4 rounded-lg bg-emerald-50 px-3 py-2.5 text-sm text-emerald-800">{{ $notice }}</div> @endif
    @if ($error) <div role="alert" class="mt-4 rounded-lg bg-red-50 px-3 py-2.5 text-sm text-red-800">{{ $error }}</div> @endif

    <ol class="mt-6 space-y-4">
        <li class="flex gap-3 rounded-2xl border border-slate-200 bg-white p-4">
            {!! $step(true, 1) !!}
            <div><p class="font-semibold">Applied</p><p class="text-sm text-slate-600">Your mobile number is verified.</p></div>
        </li>

        <li class="flex gap-3 rounded-2xl border border-slate-200 bg-white p-4" data-testid="join-documents">
            {!! $step($allDocs, 2) !!}
            <div class="min-w-0 flex-1">
                <p class="font-semibold">Documents</p>
                <p class="text-sm text-slate-600">Clear photos or PDFs (JPG, PNG or PDF, up to 10 MB). Each one uploads as soon as you pick it.</p>
                @foreach ($groups as $g)
                    @if (count($groups) > 1) <p class="mt-3 text-xs font-semibold uppercase text-slate-500">{{ $g['title'] }}</p> @endif
                    <ul class="mt-2 divide-y divide-slate-100">
                        @foreach ($g['rows'] as $row)
                            @php $doc = $row['doc']; $req = $row['req']; @endphp
                            <li class="flex flex-wrap items-center justify-between gap-2 py-2.5">
                                <div class="min-w-0">
                                    <p class="text-sm font-medium text-slate-900">{{ $req->label }} @unless ($req->is_required)<span class="font-normal text-slate-500">(if you have it)</span>@endunless</p>
                                    @if ($doc)
                                        <span class="mt-0.5 inline-block rounded-full px-2 py-0.5 text-xs font-semibold {{ $docLabel[$doc->status][1] ?? 'bg-slate-100 text-slate-700' }}">{{ $docLabel[$doc->status][0] ?? ucfirst($doc->status) }}</span>
                                        @if ($doc->status === 'rejected' && $doc->rejection_reason) <p class="mt-0.5 text-xs text-red-700">{{ $doc->rejection_reason }}</p> @endif
                                    @endif
                                </div>
                                @if (! $doc || $doc->status === 'rejected')
                                    <label class="inline-flex min-h-11 cursor-pointer items-center rounded-lg border border-blue-600 px-3 text-sm font-semibold text-blue-700 hover:bg-blue-50">
                                        <span wire:loading.remove wire:target="files.{{ $g['key'] }}.{{ $req->document_type }}">{{ $doc ? 'Upload again' : 'Upload' }}</span>
                                        <span wire:loading wire:target="files.{{ $g['key'] }}.{{ $req->document_type }}">Uploading…</span>
                                        <input type="file" accept=".jpg,.jpeg,.png,.pdf" capture="environment" wire:model="files.{{ $g['key'] }}.{{ $req->document_type }}" class="sr-only">
                                    </label>
                                    <x-ui.image-size use="kyc_document" class="w-full text-xs text-slate-500" />
                                @endif
                            </li>
                            @error('files.'.$g['key'].'.'.$req->document_type) <li class="pb-2 text-sm text-red-700">{{ $message }}</li> @enderror
                        @endforeach
                    </ul>
                @endforeach
            </div>
        </li>

        <li class="flex gap-3 rounded-2xl border border-slate-200 bg-white p-4">
            {!! $step($setUp, 3) !!}
            <div class="min-w-0 flex-1">
                <p class="font-semibold">Set up what customers will see</p>
                <div class="mt-2 flex flex-col gap-2">
                    @foreach ($links as $link)
                        <a href="{{ $link['url'] }}" wire:navigate class="flex min-h-11 items-center justify-between rounded-lg bg-slate-50 px-3 text-sm font-medium text-slate-800 hover:bg-slate-100">{{ $link['label'] }} <span aria-hidden="true">→</span></a>
                    @endforeach
                </div>
            </div>
        </li>

        <li class="flex gap-3 rounded-2xl border border-slate-200 bg-white p-4" data-testid="join-approval">
            {!! $step($approved, 4) !!}
            <div>
                <p class="font-semibold">Approval</p>
                <p class="text-sm text-slate-600">
                    @if ($approved) You are approved. Welcome aboard!
                    @elseif (! $allDocs) We start checking as soon as your documents are in.
                    @else We are checking your documents and will contact you soon.
                    @endif
                </p>
            </div>
        </li>
    </ol>

    {{-- Help me join (partners part B): the team calls and does it with them. --}}
    <div class="mt-6 rounded-2xl border border-blue-200 bg-blue-50 p-4 text-sm text-blue-900">
        <p class="font-semibold">Need a hand?</p>
        <p class="mt-0.5">Our team can call you and set everything up with you.</p>
        @if ($helpNotice)
            <p role="status" class="mt-3 rounded-lg bg-white px-3 py-2 font-semibold text-emerald-800" data-testid="help-me-sent">&check; {{ $helpNotice }}</p>
        @else
            <button type="button" wire:click="helpMe" wire:loading.attr="disabled" class="mt-3 inline-flex min-h-11 items-center rounded-lg bg-blue-600 px-4 font-semibold text-white hover:bg-blue-700" data-testid="help-me-join">Call me, help me go live</button>
        @endif
    </div>
</div>
