{{-- Application form (REF 1CF-PARTNER-PAGE-001). Wording comes from the settings store via the component; styles are the .pp scope on the page. --}}
@php
    $current = collect($roles)->firstWhere('code', $role);
    $arrow = '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg>';
@endphp
<div x-data x-on:partner-role-select.window="$wire.set('role', $event.detail.role)" id="formCard">
    @if ($outcome === 'handoff')
        <div role="status" class="done">
            <h3>Saved. Continue in the app.</h3>
            <p>Thanks, {{ \Illuminate\Support\Str::of($name)->trim()->before(' ') }}. Next, verify your mobile number with a one-time code and upload your documents in the app sign-up.</p>
            <a href="{{ route('provider.register') }}" class="btn btn-accent submit">Continue in the app {!! $arrow !!}</a>
            <button type="button" class="btn btn-out" wire:click="again">Add another application</button>
        </div>
    @elseif ($outcome !== '')
        <div role="status" class="done">
            @if (filled($doneTitle)) <h3>{{ $doneTitle }}</h3> @endif
            @if (filled($doneBody)) <p>{{ $doneBody }}</p> @endif
            <button type="button" class="btn btn-out" wire:click="again">Add another application</button>
        </div>
    @else
        <form wire:submit="submit" class="form" novalidate>
            <fieldset style="border:0;margin:0;padding:0;min-width:0">
                <legend>I want to join as</legend>
                <div class="chips">
                    @foreach ($roles as $card)
                        <label>
                            <input type="radio" class="sr" name="role" value="{{ $card['code'] }}" wire:model.live="role">
                            <span class="chip">{{ $card['label'] }}</span>
                        </label>
                    @endforeach
                </div>
                @error('role') <p class="msg">{{ $message }}</p> @enderror
            </fieldset>

            @if ($current && ! $current['live'])
                <div class="waitnote" id="waitNote">This role is not live yet. Apply now to join the waitlist and we will tell you when it opens in your city.</div>
            @endif

            <div>
                <label class="t" for="pl-name">Full name</label>
                <input id="pl-name" type="text" autocomplete="name" maxlength="120" placeholder="As on your ID" wire:model="name" @class(['inp', 'err' => $errors->has('name')])>
                @error('name') <p class="msg">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="t" for="pl-phone">Mobile number</label>
                <div class="row2">
                    <span class="cc">+91</span>
                    <input id="pl-phone" type="tel" inputmode="numeric" autocomplete="tel-national" maxlength="20" placeholder="10-digit number" wire:model="phone" @class(['inp', 'err' => $errors->has('phone')])>
                </div>
                @error('phone') <p class="msg">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="t" for="pl-city">City</label>
                @if ($cities->isNotEmpty())
                <select id="pl-city" wire:model.live="cityChoice" @class(['inp', 'err' => $errors->has('cityChoice')])>
                    <option value="">Select your city</option>
                    @foreach ($cities as $c)
                        <option value="{{ $c->slug }}">{{ $c->name }}</option>
                    @endforeach
                    <option value="{{ \App\Livewire\Partner\ApplyForm::OTHER_CITY }}">My city is not listed</option>
                </select>
                @error('cityChoice') <p class="msg">{{ $message }}</p> @enderror
                @endif
                @if ($cityChoice === \App\Livewire\Partner\ApplyForm::OTHER_CITY)
                    <input id="pl-city-other" type="text" autocomplete="address-level2" maxlength="120" placeholder="Type your city" aria-label="Your city" wire:model="city" style="margin-top:10px" @class(['inp', 'err' => $errors->has('city')])>
                    @error('city') <p class="msg">{{ $message }}</p> @enderror
                @endif
            </div>

            {{-- Honeypot: hidden from people and assistive tech. --}}
            <div class="hp" aria-hidden="true">
                <label for="pl-website">Leave this empty</label>
                <input id="pl-website" type="text" tabindex="-1" autocomplete="off" wire:model="website">
            </div>

            <div>
                <label class="cons">
                    <input type="checkbox" wire:model="consent">
                    <span>{{ $consentText }}</span>
                </label>
                @error('consent') <p class="msg">{{ $message }}</p> @enderror
            </div>

            @if ($error) <p role="alert" class="msg">{{ $error }}</p> @endif

            <button type="submit" wire:loading.attr="disabled" class="btn btn-accent submit">
                {{ ($current['hands_off'] ?? false) ? 'Continue in the app' : 'Join the waitlist' }} {!! $arrow !!}
            </button>
        </form>
    @endif
</div>
