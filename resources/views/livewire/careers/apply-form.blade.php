{{-- Careers application form (owner 2026-11-21). Words: SiteText careers.form.* (Admin → Website text & language). --}}
@php
    $inp = 'block w-full min-h-12 rounded-xl border border-slate-300 bg-white px-4 text-base text-slate-900 placeholder:text-slate-400 focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500/30';
    $lbl = 'mb-1.5 block text-sm font-semibold text-slate-800';
    $err = 'mt-1 text-sm text-red-600';
@endphp
<div id="apply" data-careers-form class="@container scroll-mt-24">
    @if ($done)
        <div role="status" class="rounded-3xl bg-gradient-to-br from-emerald-500 to-teal-600 p-6 text-white shadow-xl sm:p-8">
            <div class="flex h-14 w-14 items-center justify-center rounded-full bg-white/20">
                <svg class="h-8 w-8" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" aria-hidden="true"><path d="M5 13l4 4L19 7"/></svg>
            </div>
            <h3 class="mt-4 text-2xl font-extrabold">{{ $t('careers.form.done_title') }}</h3>
            <p class="mt-2 text-base text-emerald-50">{{ $t('careers.form.done_body', ['name' => \Illuminate\Support\Str::of($name)->trim()->before(' '), 'phone' => '+91 '.\App\Support\PhoneNumber::national($phone)]) }}</p>
            <button type="button" wire:click="again" class="mt-5 inline-flex min-h-12 items-center rounded-xl bg-white px-5 font-bold text-emerald-700 hover:bg-emerald-50">{{ $t('careers.form.again') }}</button>
        </div>
    @else
        <form wire:submit="submit" novalidate class="space-y-5 rounded-3xl bg-white p-5 shadow-xl ring-1 ring-slate-200 sm:p-8">
            <h3 class="text-xl font-extrabold text-slate-900 sm:text-2xl">{{ $chosen ? $t('careers.form.title') : $t('careers.form.title_any') }}</h3>

            <div>
                <label for="cj-job" class="{{ $lbl }}">{{ $t('careers.form.job') }}</label>
                <select id="cj-job" wire:model.live="job" @class([$inp, 'border-red-400' => $errors->has('job')])>
                    @foreach ($jobs as $j)
                        <option value="{{ $j->id }}">{{ $j->title }}</option>
                    @endforeach
                    <option value="{{ \App\Livewire\Careers\ApplyForm::ANY }}">{{ $t('careers.form.any_role') }}</option>
                </select>
                @error('job') <p class="{{ $err }}">{{ $message }}</p> @enderror
            </div>

            <div class="grid gap-5 @lg:grid-cols-2">
                <div>
                    <label for="cj-name" class="{{ $lbl }}">{{ $t('careers.form.name') }}</label>
                    <input id="cj-name" type="text" autocomplete="name" maxlength="120" wire:model="name" @class([$inp, 'border-red-400' => $errors->has('name')])>
                    @error('name') <p class="{{ $err }}">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="cj-phone" class="{{ $lbl }}">{{ $t('careers.form.phone') }}</label>
                    <div class="flex gap-2">
                        <span class="inline-flex min-h-12 items-center rounded-xl bg-slate-100 px-3 font-semibold text-slate-600">+91</span>
                        <input id="cj-phone" type="tel" inputmode="numeric" autocomplete="tel-national" maxlength="20" placeholder="10-digit number" wire:model="phone" @class([$inp, 'border-red-400' => $errors->has('phone')])>
                    </div>
                    @error('phone') <p class="{{ $err }}">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="cj-city" class="{{ $lbl }}">{{ $t('careers.form.city') }}</label>
                    <input id="cj-city" type="text" autocomplete="address-level2" maxlength="120" wire:model="city" @class([$inp, 'border-red-400' => $errors->has('city')])>
                    @error('city') <p class="{{ $err }}">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="cj-email" class="{{ $lbl }}">{{ $t('careers.form.email') }}</label>
                    <input id="cj-email" type="email" autocomplete="email" maxlength="150" wire:model="email" @class([$inp, 'border-red-400' => $errors->has('email')])>
                    @error('email') <p class="{{ $err }}">{{ $message }}</p> @enderror
                </div>
            </div>

            <fieldset>
                <legend class="{{ $lbl }}">{{ $t('careers.form.experience') }}</legend>
                <div class="flex flex-wrap gap-2">
                    @foreach ($experienceOptions as $value => $label)
                        <label class="cursor-pointer">
                            <input type="radio" class="peer sr-only" name="experience" value="{{ $value }}" wire:model="experience">
                            <span class="inline-flex min-h-11 items-center rounded-full border-2 border-slate-200 px-4 font-semibold text-slate-700 transition peer-checked:border-blue-600 peer-checked:bg-blue-600 peer-checked:text-white peer-focus-visible:ring-2 peer-focus-visible:ring-blue-400">{{ $label }}</span>
                        </label>
                    @endforeach
                </div>
                @error('experience') <p class="{{ $err }}">{{ $message }}</p> @enderror
            </fieldset>

            @if ($chosen?->needs_two_wheeler)
                <fieldset>
                    <legend class="{{ $lbl }}">{{ $t('careers.form.two_wheeler') }}</legend>
                    <div class="flex gap-2">
                        @foreach (['yes' => $t('careers.form.yes'), 'no' => $t('careers.form.no')] as $value => $label)
                            <label class="cursor-pointer">
                                <input type="radio" class="peer sr-only" name="two_wheeler" value="{{ $value }}" wire:model="twoWheeler">
                                <span class="inline-flex min-h-11 min-w-20 items-center justify-center rounded-full border-2 border-slate-200 px-4 font-semibold text-slate-700 transition peer-checked:border-blue-600 peer-checked:bg-blue-600 peer-checked:text-white peer-focus-visible:ring-2 peer-focus-visible:ring-blue-400">{{ $label }}</span>
                            </label>
                        @endforeach
                    </div>
                    @error('twoWheeler') <p class="{{ $err }}">{{ $message }}</p> @enderror
                </fieldset>
            @endif

            <div>
                <label for="cj-current" class="{{ $lbl }}">{{ $t('careers.form.current') }}</label>
                <input id="cj-current" type="text" maxlength="150" wire:model="currentWork" class="{{ $inp }}">
                @error('currentWork') <p class="{{ $err }}">{{ $message }}</p> @enderror
            </div>

            <div>
                <label for="cj-cv" class="{{ $lbl }}">{{ $t('careers.form.cv') }}</label>
                <input id="cj-cv" type="file" accept="application/pdf,.pdf" wire:model="cv"
                       class="block w-full text-sm text-slate-600 file:mr-3 file:min-h-11 file:rounded-xl file:border-0 file:bg-blue-50 file:px-4 file:font-semibold file:text-blue-700 hover:file:bg-blue-100">
                <p wire:loading wire:target="cv" class="mt-1 text-sm text-slate-500">Uploading…</p>
                @error('cv') <p class="{{ $err }}">{{ $message }}</p> @enderror
            </div>

            <div>
                <label for="cj-message" class="{{ $lbl }}">{{ $t('careers.form.message') }}</label>
                <textarea id="cj-message" rows="3" maxlength="1000" wire:model="message" class="{{ $inp }} py-3"></textarea>
                @error('message') <p class="{{ $err }}">{{ $message }}</p> @enderror
            </div>

            {{-- Honeypot: hidden from people and assistive tech. --}}
            <div class="absolute -left-[9999px] h-px w-px overflow-hidden" aria-hidden="true">
                <label for="cj-website">Leave this empty</label>
                <input id="cj-website" type="text" tabindex="-1" autocomplete="off" wire:model="website">
            </div>

            <div>
                <label class="flex cursor-pointer items-start gap-3 text-sm text-slate-700">
                    <input type="checkbox" wire:model="consent" class="mt-0.5 h-5 w-5 shrink-0 rounded border-slate-300 text-blue-600">
                    <span>{{ $t('careers.form.consent') }}</span>
                </label>
                @error('consent') <p class="{{ $err }}">{{ $message }}</p> @enderror
            </div>

            @if ($error) <p role="alert" class="rounded-xl bg-red-50 px-4 py-3 text-sm font-semibold text-red-700">{{ $error }}</p> @endif

            <button type="submit" wire:loading.attr="disabled" wire:target="submit,cv"
                    class="inline-flex min-h-14 w-full items-center justify-center gap-2 rounded-2xl bg-blue-600 px-6 text-lg font-bold text-white shadow-lg shadow-blue-600/25 transition hover:bg-blue-700 disabled:opacity-60">
                {{ $t('careers.form.submit') }}
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
            </button>
        </form>
    @endif
</div>
