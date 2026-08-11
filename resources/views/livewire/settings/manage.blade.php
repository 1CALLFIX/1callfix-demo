@php
    $realTabs = [
        'general_system' => 'General / System',
        'dispatch' => 'Dispatch',
        'commission' => 'Commission Defaults',
        'booking' => 'Booking',
        'cancellation' => 'Refund / Cancellation',
        'payment' => 'Payment',
        'notifications' => 'Notifications',
        'locale' => 'Locale & Currency',
        'branding' => 'Platform / Branding',
        'maps' => 'Maps',
        'websocket' => 'Websocket',
        'website_cms' => 'Website / CMS',
    ];
    $placeholder = \App\Livewire\Settings\Manage::PLACEHOLDER_TABS[$activeTab] ?? null;
    $scoped = $scopeType !== 'global';
@endphp

<div>
    <h1 class="text-2xl font-bold mb-4">Settings</h1>

    @if ($flashMessage)
        <div class="bg-green-50 text-green-700 rounded p-3 mb-4 text-sm">{{ $flashMessage }}</div>
    @endif

    {{-- Scope picker — the real Control Plane behaviour. Global by default;
         drilling into a Country/City/Franchise/Zone makes the five real
         tabs below read/write an override at exactly that scope, falling
         back to whatever's set broader when nothing's overridden here. --}}
    <div class="bg-white rounded-lg shadow-sm p-4 mb-4 max-w-3xl">
        <label class="block text-xs font-medium mb-2">Scope</label>
        <div class="flex flex-wrap gap-2 mb-3">
            @foreach (['global' => 'Global', 'country' => 'Country', 'city' => 'City', 'franchise' => 'Franchise', 'zone' => 'Zone'] as $key => $label)
                <button type="button" wire:click="$set('scopeType', '{{ $key }}')"
                        @class([
                            'px-3 py-1.5 rounded text-sm',
                            'bg-slate-900 text-white' => $scopeType === $key,
                            'bg-gray-100 text-gray-600 hover:bg-gray-200' => $scopeType !== $key,
                        ])>{{ $label }}</button>
            @endforeach
        </div>

        @if ($scopeType === 'country')
            <select wire:model.live="scopeCountryId" class="border rounded px-3 py-2 text-sm w-64">
                <option value="">Select a country…</option>
                @foreach ($scopeCountries as $c) <option value="{{ $c->id }}">{{ $c->name }}</option> @endforeach
            </select>
        @elseif ($scopeType === 'city')
            <div class="flex gap-2">
                <select wire:model.live="scopeCountryId" class="border rounded px-3 py-2 text-sm w-56">
                    <option value="">Country…</option>
                    @foreach ($scopeCountries as $c) <option value="{{ $c->id }}">{{ $c->name }}</option> @endforeach
                </select>
                <select wire:model.live="scopeCityId" class="border rounded px-3 py-2 text-sm w-56" @disabled(! $scopeCountryId)>
                    <option value="">{{ $scopeCountryId ? 'City…' : 'Pick a country first' }}</option>
                    @foreach ($scopeCities as $c) <option value="{{ $c->id }}">{{ $c->name }}</option> @endforeach
                </select>
            </div>
        @elseif ($scopeType === 'franchise')
            <select wire:model.live="scopeFranchiseId" class="border rounded px-3 py-2 text-sm w-64">
                <option value="">Select a franchise…</option>
                @foreach ($scopeFranchises as $f) <option value="{{ $f->id }}">{{ $f->name }} ({{ $f->code }})</option> @endforeach
            </select>
        @elseif ($scopeType === 'zone')
            <div class="flex gap-2">
                <select wire:model.live="scopeFranchiseId" class="border rounded px-3 py-2 text-sm w-56">
                    <option value="">Franchise…</option>
                    @foreach ($scopeFranchises as $f) <option value="{{ $f->id }}">{{ $f->name }} ({{ $f->code }})</option> @endforeach
                </select>
                <select wire:model.live="scopeZoneId" class="border rounded px-3 py-2 text-sm w-56" @disabled(! $scopeFranchiseId)>
                    <option value="">{{ $scopeFranchiseId ? 'Zone…' : 'Pick a franchise first' }}</option>
                    @foreach ($scopeZones as $z) <option value="{{ $z->id }}">{{ $z->name }}</option> @endforeach
                </select>
            </div>
        @endif

        @if ($scoped)
            <p class="text-xs text-gray-400 mt-2">
                Fields below show the effective value — this scope's own override, or inherited from a broader one. Saving writes an override at exactly this scope; it never changes the broader value.
            </p>
        @endif
    </div>

    {{-- Tab bar — real tabs first, then placeholders (greyed, "soon" tag),
         so the full eventual shape of a mature settings hub is visible
         without pretending any of them do something they don't. --}}
    <div class="flex items-center gap-1 border-b mb-6 flex-wrap">
        @foreach ($realTabs as $key => $label)
            <button type="button" wire:click="$set('activeTab', '{{ $key }}')"
                    @class([
                        'px-4 py-2 text-sm border-b-2 -mb-px whitespace-nowrap',
                        'border-slate-900 text-slate-900 font-medium' => $activeTab === $key,
                        'border-transparent text-gray-500 hover:text-gray-800' => $activeTab !== $key,
                    ])>{{ $label }}</button>
        @endforeach
        @foreach (\App\Livewire\Settings\Manage::PLACEHOLDER_TABS as $key => $tab)
            <button type="button" wire:click="$set('activeTab', '{{ $key }}')"
                    @class([
                        'px-4 py-2 text-sm border-b-2 -mb-px whitespace-nowrap',
                        'border-slate-900 text-slate-500 font-medium' => $activeTab === $key,
                        'border-transparent text-gray-400 hover:text-gray-600' => $activeTab !== $key,
                    ])>{{ $tab['label'] }} <span class="text-[10px]">(soon)</span></button>
        @endforeach
    </div>

    <div class="bg-white rounded-lg shadow-sm p-4 max-w-3xl">

        {{-- General / System — Maintenance Mode gates the booking API routes
             (accept/complete/pay) via EnsureNotInMaintenanceMode. Scope-aware:
             a franchise/zone override here only pauses that scope, resolved
             from the booking's own franchise_id/zone_id at request time. --}}
        @if ($activeTab === 'general_system')
            <p class="text-xs text-gray-400 mb-3">Maintenance mode pauses the booking-operation API routes (accept / complete / pay) — used by the provider app, not the admin panel itself, which stays reachable so you can turn this back off. The Razorpay webhook is never paused. Timezone/per-request locale handling isn't built yet — the app runs in UTC.</p>
            <div>
                <label class="block text-xs font-medium mb-1">Maintenance mode @if ($scoped) <x-setting-override-badge :overridden="in_array('system.maintenance_mode', $this->overriddenKeys)" setting-key="system.maintenance_mode" /> @endif</label>
                <select wire:model="systemMaintenanceMode" class="w-48 border rounded px-3 py-2 text-sm">
                    <option value="0">Off</option>
                    <option value="1">On — pause booking operations</option>
                </select>
                @error('systemMaintenanceMode') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
            </div>
            <div class="flex justify-end pt-4 mt-4 border-t">
                <button type="button" wire:click="saveGeneralSystem" class="bg-slate-900 text-white px-6 py-2 rounded text-sm font-medium hover:bg-slate-800">Save General / System Settings</button>
            </div>

        {{-- Dispatch — feeds ServiceMatchingJob's tuning values directly. --}}
        @elseif ($activeTab === 'dispatch')
            <p class="text-xs text-gray-400 mb-3">Tuning for the automatic provider-matching engine. Changes apply to the next dispatch run — bookings already searching keep their in-flight timing.</p>
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                <div>
                    <label class="block text-xs font-medium mb-1">Offer batch size @if ($scoped) <x-setting-override-badge :overridden="in_array('dispatch.offer_batch_size', $this->overriddenKeys)" setting-key="dispatch.offer_batch_size" /> @endif</label>
                    <input type="number" step="1" wire:model="dispatchOfferBatchSize" class="w-full border rounded px-3 py-2 text-sm">
                    <p class="text-[11px] text-gray-400 mt-1">Providers offered at once</p>
                    @error('dispatchOfferBatchSize') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-xs font-medium mb-1">Offer timeout (sec) @if ($scoped) <x-setting-override-badge :overridden="in_array('dispatch.offer_timeout_seconds', $this->overriddenKeys)" setting-key="dispatch.offer_timeout_seconds" /> @endif</label>
                    <input type="number" step="1" wire:model="dispatchOfferTimeoutSeconds" class="w-full border rounded px-3 py-2 text-sm">
                    <p class="text-[11px] text-gray-400 mt-1">Before an offer expires</p>
                    @error('dispatchOfferTimeoutSeconds') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-xs font-medium mb-1">Max rounds @if ($scoped) <x-setting-override-badge :overridden="in_array('dispatch.max_rounds', $this->overriddenKeys)" setting-key="dispatch.max_rounds" /> @endif</label>
                    <input type="number" step="1" wire:model="dispatchMaxRounds" class="w-full border rounded px-3 py-2 text-sm">
                    <p class="text-[11px] text-gray-400 mt-1">Before falling to manual queue</p>
                    @error('dispatchMaxRounds') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-xs font-medium mb-1">Default radius (km) @if ($scoped) <x-setting-override-badge :overridden="in_array('dispatch.default_radius_km', $this->overriddenKeys)" setting-key="dispatch.default_radius_km" /> @endif</label>
                    <input type="number" step="1" wire:model="dispatchDefaultRadiusKm" class="w-full border rounded px-3 py-2 text-sm">
                    <p class="text-[11px] text-gray-400 mt-1">Pre-fill for new zones only</p>
                    @error('dispatchDefaultRadiusKm') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
                </div>
            </div>
            <div class="flex justify-end pt-4 mt-4 border-t">
                <button type="button" wire:click="saveDispatch" class="bg-slate-900 text-white px-6 py-2 rounded text-sm font-medium hover:bg-slate-800">Save Dispatch Settings</button>
            </div>

        {{-- Commission Defaults — pre-fills Franchises' Add New form. --}}
        @elseif ($activeTab === 'commission')
            <p class="text-xs text-gray-400 mb-3">Pre-fills the Add New Franchise form. Franchises already created keep their own commission settings — edit those individually from the Franchises screen.</p>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div>
                    <label class="block text-xs font-medium mb-1">Commission model @if ($scoped) <x-setting-override-badge :overridden="in_array('commission.default_model', $this->overriddenKeys)" setting-key="commission.default_model" /> @endif</label>
                    <select wire:model="commissionDefaultModel" class="w-full border rounded px-3 py-2 text-sm">
                        <option value="revenue_share">Revenue Share</option>
                        <option value="flat_fee">Flat Fee</option>
                        <option value="subscription_only">Subscription Only</option>
                    </select>
                    @error('commissionDefaultModel') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-xs font-medium mb-1">Commission value @if ($scoped) <x-setting-override-badge :overridden="in_array('commission.default_value', $this->overriddenKeys)" setting-key="commission.default_value" /> @endif</label>
                    <input type="number" step="0.01" wire:model="commissionDefaultValue" class="w-full border rounded px-3 py-2 text-sm">
                    @error('commissionDefaultValue') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-xs font-medium mb-1">Platform fee (%) @if ($scoped) <x-setting-override-badge :overridden="in_array('commission.default_platform_fee_percent', $this->overriddenKeys)" setting-key="commission.default_platform_fee_percent" /> @endif</label>
                    <input type="number" step="0.01" wire:model="commissionDefaultPlatformFeePercent" class="w-full border rounded px-3 py-2 text-sm">
                    @error('commissionDefaultPlatformFeePercent') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
                </div>
            </div>
            <div class="flex justify-end pt-4 mt-4 border-t">
                <button type="button" wire:click="saveCommission" class="bg-slate-900 text-white px-6 py-2 rounded text-sm font-medium hover:bg-slate-800">Save Commission Defaults</button>
            </div>

        {{-- Booking — OTP generators (AcceptBookingAction, AdminReassignBookingAction)
             and the New Booking modal's scheduling window. --}}
        @elseif ($activeTab === 'booking')
            <p class="text-xs text-gray-400 mb-3">OTP length feeds the start/completion OTPs generated when a provider is assigned. Schedule window bounds how far ahead a booking can be scheduled from the New Booking form.</p>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-medium mb-1">OTP length (digits) @if ($scoped) <x-setting-override-badge :overridden="in_array('booking.otp_length', $this->overriddenKeys)" setting-key="booking.otp_length" /> @endif</label>
                    <input type="number" step="1" wire:model="bookingOtpLength" class="w-full border rounded px-3 py-2 text-sm">
                    @error('bookingOtpLength') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-xs font-medium mb-1">Max schedule days ahead @if ($scoped) <x-setting-override-badge :overridden="in_array('booking.max_schedule_days_ahead', $this->overriddenKeys)" setting-key="booking.max_schedule_days_ahead" /> @endif</label>
                    <input type="number" step="1" wire:model="bookingMaxScheduleDaysAhead" class="w-full border rounded px-3 py-2 text-sm">
                    @error('bookingMaxScheduleDaysAhead') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
                </div>
            </div>
            <div class="flex justify-end pt-4 mt-4 border-t">
                <button type="button" wire:click="saveBooking" class="bg-slate-900 text-white px-6 py-2 rounded text-sm font-medium hover:bg-slate-800">Save Booking Settings</button>
            </div>

        {{-- Refund / Cancellation — real consumer: CancellationService,
             called from AdminCancelBookingAction. Timer measured from
             booking.created_at (confirmed decision), not provider assignment. --}}
        @elseif ($activeTab === 'cancellation')
            <p class="text-xs text-gray-400 mb-3">Free cancellation window is measured from when the booking was created. Beyond it, the fee applies — flat amount or a percentage of the quoted price. If the booking was already paid via Razorpay, the remaining amount (paid − fee) is refunded automatically; an unpaid booking triggers no refund attempt.</p>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div>
                    <label class="block text-xs font-medium mb-1">Free cancellation (minutes) @if ($scoped) <x-setting-override-badge :overridden="in_array('cancellation.free_minutes', $this->overriddenKeys)" setting-key="cancellation.free_minutes" /> @endif</label>
                    <input type="number" step="1" wire:model="cancellationFreeMinutes" class="w-full border rounded px-3 py-2 text-sm">
                    @error('cancellationFreeMinutes') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-xs font-medium mb-1">Fee type @if ($scoped) <x-setting-override-badge :overridden="in_array('cancellation.fee_type', $this->overriddenKeys)" setting-key="cancellation.fee_type" /> @endif</label>
                    <select wire:model="cancellationFeeType" class="w-full border rounded px-3 py-2 text-sm">
                        <option value="flat">Flat amount</option>
                        <option value="percent">Percent of quoted price</option>
                    </select>
                    @error('cancellationFeeType') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-xs font-medium mb-1">Fee value @if ($scoped) <x-setting-override-badge :overridden="in_array('cancellation.fee_value', $this->overriddenKeys)" setting-key="cancellation.fee_value" /> @endif</label>
                    <input type="number" step="0.01" wire:model="cancellationFeeValue" class="w-full border rounded px-3 py-2 text-sm">
                    @error('cancellationFeeValue') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
                </div>
            </div>
            <div class="flex justify-end pt-4 mt-4 border-t">
                <button type="button" wire:click="saveCancellation" class="bg-slate-900 text-white px-6 py-2 rounded text-sm font-medium hover:bg-slate-800">Save Refund / Cancellation Settings</button>
            </div>

        {{-- Payment — which methods the New Booking modal offers. Gateway
             credentials/mode stay in .env; a real multi-gateway abstraction
             is out of scope (comparable in size to RazorpayService itself). --}}
        @elseif ($activeTab === 'payment')
            <p class="text-xs text-gray-400 mb-3">Controls which payment methods the New Booking modal offers — not gateway configuration. Razorpay credentials/mode live in <code>.env</code> (<code>config/services.php</code>), intentionally not duplicated into an editable DB row.</p>
            <div class="grid grid-cols-3 gap-4">
                <div>
                    <label class="block text-xs font-medium mb-1">Online (Razorpay) @if ($scoped) <x-setting-override-badge :overridden="in_array('payment.online_enabled', $this->overriddenKeys)" setting-key="payment.online_enabled" /> @endif</label>
                    <select wire:model="paymentOnlineEnabled" class="w-full border rounded px-3 py-2 text-sm">
                        <option value="1">Enabled</option>
                        <option value="0">Disabled</option>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-medium mb-1">Cash @if ($scoped) <x-setting-override-badge :overridden="in_array('payment.cash_enabled', $this->overriddenKeys)" setting-key="payment.cash_enabled" /> @endif</label>
                    <select wire:model="paymentCashEnabled" class="w-full border rounded px-3 py-2 text-sm">
                        <option value="1">Enabled</option>
                        <option value="0">Disabled</option>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-medium mb-1">Wallet @if ($scoped) <x-setting-override-badge :overridden="in_array('payment.wallet_enabled', $this->overriddenKeys)" setting-key="payment.wallet_enabled" /> @endif</label>
                    <select wire:model="paymentWalletEnabled" class="w-full border rounded px-3 py-2 text-sm">
                        <option value="1">Enabled</option>
                        <option value="0">Disabled</option>
                    </select>
                </div>
            </div>
            @error('paymentOnlineEnabled') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
            <div class="flex justify-end pt-4 mt-4 border-t">
                <button type="button" wire:click="savePayment" class="bg-slate-900 text-white px-6 py-2 rounded text-sm font-medium hover:bg-slate-800">Save Payment Settings</button>
            </div>

        {{-- Notifications — which channels App\Notifications\Support\ChannelResolver hands back to every dispatch site. --}}
        @elseif ($activeTab === 'notifications')
            <p class="text-xs text-gray-400 mb-3">Controls which channels booking/payment/payout notifications attempt. Mail uses <code>config/mail.php</code> (log driver unless real SMTP is configured); SMS and Push have no real gateway configured yet — both log to <code>notification_logs</code> via a fake adapter so the internal flow is fully real and testable, without sending an actual message.</p>
            <div class="grid grid-cols-4 gap-4">
                <label class="flex items-center gap-2 text-sm">
                    <input type="checkbox" wire:model="notifyMail" class="rounded"> Mail
                </label>
                <label class="flex items-center gap-2 text-sm">
                    <input type="checkbox" wire:model="notifySms" class="rounded"> SMS
                </label>
                <label class="flex items-center gap-2 text-sm">
                    <input type="checkbox" wire:model="notifyPush" class="rounded"> Push
                </label>
                <label class="flex items-center gap-2 text-sm">
                    <input type="checkbox" wire:model="notifyInApp" class="rounded"> In-app
                </label>
            </div>
            @if ($scoped) <p class="text-xs text-gray-400 mt-2"><x-setting-override-badge :overridden="in_array('notifications.channels', $this->overriddenKeys)" setting-key="notifications.channels" /></p> @endif
            <div class="flex justify-end pt-4 mt-4 border-t">
                <button type="button" wire:click="saveNotifications" class="bg-slate-900 text-white px-6 py-2 rounded text-sm font-medium hover:bg-slate-800">Save Notification Settings</button>
            </div>

        {{-- Locale & Currency — the ₹ symbol shown across every admin money field. --}}
        @elseif ($activeTab === 'locale')
            <p class="text-xs text-gray-400 mb-3">Currency symbol shown across the admin panel (Bookings, Services, Banners, Dashboard). This changes display only — every money column stays a fixed 2-decimal figure, not a multi-currency conversion.</p>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div>
                    <label class="block text-xs font-medium mb-1">Currency symbol @if ($scoped) <x-setting-override-badge :overridden="in_array('locale.currency_symbol', $this->overriddenKeys)" setting-key="locale.currency_symbol" /> @endif</label>
                    <input type="text" wire:model="localeCurrencySymbol" class="w-full border rounded px-3 py-2 text-sm" maxlength="5">
                    @error('localeCurrencySymbol') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
                </div>
            </div>
            <div class="flex justify-end pt-4 mt-4 border-t">
                <button type="button" wire:click="saveLocale" class="bg-slate-900 text-white px-6 py-2 rounded text-sm font-medium hover:bg-slate-800">Save Locale Settings</button>
            </div>

        {{-- Platform / Branding — admin header, <title>, login page. --}}
        @elseif ($activeTab === 'branding')
            <p class="text-xs text-gray-400 mb-3">Shown in the admin header, browser tab title, and login page.</p>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-medium mb-1">Platform name @if ($scoped) <x-setting-override-badge :overridden="in_array('branding.platform_name', $this->overriddenKeys)" setting-key="branding.platform_name" /> @endif</label>
                    <input type="text" wire:model="brandingPlatformName" class="w-full border rounded px-3 py-2 text-sm">
                    @error('brandingPlatformName') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-xs font-medium mb-1">Operating city label @if ($scoped) <x-setting-override-badge :overridden="in_array('branding.operating_city_label', $this->overriddenKeys)" setting-key="branding.operating_city_label" /> @endif</label>
                    <input type="text" wire:model="brandingOperatingCityLabel" class="w-full border rounded px-3 py-2 text-sm">
                    @error('brandingOperatingCityLabel') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
                </div>
            </div>
            <div class="flex justify-end pt-4 mt-4 border-t">
                <button type="button" wire:click="saveBranding" class="bg-slate-900 text-white px-6 py-2 rounded text-sm font-medium hover:bg-slate-800">Save Branding Settings</button>
            </div>

        {{-- Maps — real, but read-only: the API key is a credential and stays
             in .env, never duplicated into an editable DB row. --}}
        @elseif ($activeTab === 'maps')
            <p class="text-xs text-gray-400 mb-3">Google Maps JS API key lives in <code>.env</code> (<code>GOOGLE_MAPS_API_KEY</code>) — a credential, so it's shown here as status only, never as an editable field.</p>
            <div class="flex items-center gap-3">
                <span @class([
                    'px-2 py-1 rounded text-xs font-medium',
                    'bg-green-100 text-green-700' => $mapsConfigured,
                    'bg-red-100 text-red-700' => ! $mapsConfigured,
                ])>{{ $mapsConfigured ? 'Configured' : 'Not configured' }}</span>
                <span class="text-xs text-gray-500">Used by: Zones' boundary map, the New Booking modal's address picker</span>
            </div>
            @unless ($mapsConfigured)
                <p class="text-xs text-amber-700 bg-amber-50 rounded p-2 mt-3">Set GOOGLE_MAPS_API_KEY on the server and both maps fall back to plain lat/lng number fields — see zone-map.blade.php / address-map.blade.php.</p>
            @endunless

        {{-- Websocket / Realtime — real, read-only. BookingStatusUpdated and
             NewJobOffered genuinely implement ShouldBroadcast with real
             private channels and payloads (app/Events/) — this shows
             whether a driver is actually connected to deliver them. --}}
        @elseif ($activeTab === 'websocket')
            <p class="text-xs text-gray-400 mb-3">BROADCAST_CONNECTION lives in <code>.env</code> — shown here as status only. <code>BookingStatusUpdated</code> and <code>NewJobOffered</code> (<code>app/Events/</code>) both implement <code>ShouldBroadcast</code> with real private channels (<code>booking.&#123;id&#125;</code>, <code>provider.&#123;id&#125;.new-job</code>) and payloads today — what's missing is a connected realtime service to actually deliver them.</p>
            <div class="flex items-center gap-3">
                <span @class([
                    'px-2 py-1 rounded text-xs font-medium',
                    'bg-green-100 text-green-700' => ! in_array($broadcastDriver, ['log', 'null']),
                    'bg-amber-100 text-amber-700' => in_array($broadcastDriver, ['log', 'null']),
                ])>Driver: {{ $broadcastDriver }}</span>
                @if (in_array($broadcastDriver, ['log', 'null']))
                    <span class="text-xs text-gray-500">Events fire and get logged, but nothing is actually pushed over a websocket connection yet.</span>
                @endif
            </div>

        {{-- Website / CMS — points at the real screen (admin.cms.index).
             Pages/FAQs are records to manage, not toggles, so they get their
             own {Module}\Manage-style screen rather than living in Settings. --}}
        @elseif ($activeTab === 'website_cms')
            <p class="text-xs text-gray-400 mb-3">Pages and FAQs are records to manage, not settings to toggle — they live on their own screen, following the same pattern as Categories/Zones.</p>
            <div class="flex items-center gap-4">
                <span class="text-sm">{{ $cmsPageCount }} {{ \Illuminate\Support\Str::plural('page', $cmsPageCount) }}, {{ $cmsFaqCount }} {{ \Illuminate\Support\Str::plural('FAQ', $cmsFaqCount) }}</span>
                <a href="{{ route('admin.cms.index') }}" class="bg-slate-900 text-white px-4 py-2 rounded text-sm font-medium hover:bg-slate-800">Open Website / CMS →</a>
            </div>

        {{-- Not built yet — no inputs, since nothing in the app reads a
             setting here. Named honestly, with the reason, rather than
             hidden or faked. --}}
        @elseif ($placeholder)
            <div class="opacity-60 py-6 text-center">
                <p class="text-sm font-medium text-gray-500">{{ $placeholder['label'] }} <span class="text-xs font-normal">(coming soon)</span></p>
                <p class="text-xs text-gray-400 mt-2 max-w-md mx-auto">{{ $placeholder['note'] }}</p>
            </div>
        @endif
    </div>
</div>
