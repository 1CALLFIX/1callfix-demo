@php
    $realTabs = [
        'general_system' => 'General / System',
        'dispatch' => 'Dispatch',
        'commission' => 'Commission Defaults',
        'booking' => 'Booking',
        'cancellation' => 'Refund / Cancellation',
        'payment' => 'Payment',
        'wallet' => 'Wallet',
        'ranking' => 'Priority / Ranking',
        'loyalty' => 'Loyalty / Referral',
        'kyc' => 'KYC',
        'compensation' => 'Compensation',
        'security' => 'Security / OTP',
        'operations' => 'Operations',
        'subscriptions' => 'Subscriptions',
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

    @error('permission') <div class="bg-red-50 text-red-700 rounded p-3 mb-4 text-sm">{{ $message }}</div> @enderror

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

        {{-- Payment — which methods the New Booking modal offers, plus the
             active gateway's read-only status. Credentials themselves stay
             in .env (never duplicated into an editable/visible DB row) --
             behind a PaymentGateway abstraction (App\Contracts\PaymentGateway,
             bound in AppServiceProvider) so a second provider is a new
             binding, not a rewrite of this screen or its consumers. --}}
        @elseif ($activeTab === 'payment')
            <p class="text-xs text-gray-400 mb-3">Controls which payment methods the New Booking modal offers, and which provider is currently active. Gateway credentials themselves live only in <code>.env</code> (<code>config/services.php</code>), never shown here.</p>

            <div class="bg-gray-50 rounded p-4 mb-4 flex items-center gap-6 text-sm">
                <div>
                    <div class="text-xs text-gray-400 mb-0.5">Active gateway</div>
                    <div class="font-medium">{{ $paymentGatewayName }}</div>
                </div>
                <div>
                    <div class="text-xs text-gray-400 mb-0.5">Status</div>
                    <span @class(['px-2 py-0.5 rounded text-xs', 'bg-green-100 text-green-700' => $paymentGatewayConfigured, 'bg-red-100 text-red-700' => ! $paymentGatewayConfigured])>
                        {{ $paymentGatewayConfigured ? 'Configured' : 'Not configured' }}
                    </span>
                </div>
                @if ($paymentGatewayMaskedKey)
                    <div>
                        <div class="text-xs text-gray-400 mb-0.5">Public key</div>
                        <div class="font-mono text-xs">{{ $paymentGatewayMaskedKey }}</div>
                    </div>
                @endif
            </div>

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

        {{-- Wallet — customer top-up limits (WalletTopUpService), provider min-balance-to-accept-jobs (AcceptBookingAction), payout min/max (PayoutService). --}}
        @elseif ($activeTab === 'wallet')
            <div class="mb-4">
                <h3 class="text-sm font-semibold mb-1">Customer Top-Up</h3>
                <p class="text-xs text-gray-400 mb-3">Enforced in WalletTopUpService before a Razorpay order is even created — a direct API call can't bypass these.</p>
                <div class="grid grid-cols-2 md:grid-cols-5 gap-4">
                    <div>
                        <label class="block text-xs font-medium mb-1">Min top-up @if ($scoped) <x-setting-override-badge :overridden="in_array('wallet.customer_min_topup', $this->overriddenKeys)" setting-key="wallet.customer_min_topup" /> @endif</label>
                        <input type="number" step="0.01" wire:model="walletCustomerMinTopup" class="w-full border rounded px-3 py-2 text-sm">
                        @error('walletCustomerMinTopup') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="block text-xs font-medium mb-1">Max top-up @if ($scoped) <x-setting-override-badge :overridden="in_array('wallet.customer_max_topup', $this->overriddenKeys)" setting-key="wallet.customer_max_topup" /> @endif</label>
                        <input type="number" step="0.01" wire:model="walletCustomerMaxTopup" class="w-full border rounded px-3 py-2 text-sm">
                        @error('walletCustomerMaxTopup') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="block text-xs font-medium mb-1">Max wallet balance @if ($scoped) <x-setting-override-badge :overridden="in_array('wallet.customer_max_balance', $this->overriddenKeys)" setting-key="wallet.customer_max_balance" /> @endif</label>
                        <input type="number" step="0.01" wire:model="walletCustomerMaxBalance" class="w-full border rounded px-3 py-2 text-sm">
                        @error('walletCustomerMaxBalance') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="block text-xs font-medium mb-1">Daily top-up limit @if ($scoped) <x-setting-override-badge :overridden="in_array('wallet.customer_daily_topup_limit', $this->overriddenKeys)" setting-key="wallet.customer_daily_topup_limit" /> @endif</label>
                        <input type="number" step="0.01" wire:model="walletCustomerDailyTopupLimit" class="w-full border rounded px-3 py-2 text-sm">
                        @error('walletCustomerDailyTopupLimit') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="block text-xs font-medium mb-1">Monthly top-up limit @if ($scoped) <x-setting-override-badge :overridden="in_array('wallet.customer_monthly_topup_limit', $this->overriddenKeys)" setting-key="wallet.customer_monthly_topup_limit" /> @endif</label>
                        <input type="number" step="0.01" wire:model="walletCustomerMonthlyTopupLimit" class="w-full border rounded px-3 py-2 text-sm">
                        @error('walletCustomerMonthlyTopupLimit') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
                    </div>
                </div>
            </div>

            <div class="mb-4 border-t pt-4">
                <h3 class="text-sm font-semibold mb-1">Provider</h3>
                <p class="text-xs text-gray-400 mb-3">"Riders/Drivers" are Provider rows in this schema (no separate rider entity) — this rule covers both. Minimum balance is enforced in AcceptBookingAction before a job can be accepted; payout min/max in PayoutService::request(). 0 = no minimum / no cap.</p>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <div>
                        <label class="block text-xs font-medium mb-1">Min balance to accept jobs @if ($scoped) <x-setting-override-badge :overridden="in_array('wallet.provider_min_balance_to_accept_jobs', $this->overriddenKeys)" setting-key="wallet.provider_min_balance_to_accept_jobs" /> @endif</label>
                        <input type="number" step="0.01" wire:model="walletProviderMinBalanceToAcceptJobs" class="w-full border rounded px-3 py-2 text-sm">
                        @error('walletProviderMinBalanceToAcceptJobs') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="block text-xs font-medium mb-1">Min payout amount @if ($scoped) <x-setting-override-badge :overridden="in_array('wallet.provider_min_payout_amount', $this->overriddenKeys)" setting-key="wallet.provider_min_payout_amount" /> @endif</label>
                        <input type="number" step="0.01" wire:model="walletProviderMinPayoutAmount" class="w-full border rounded px-3 py-2 text-sm">
                        @error('walletProviderMinPayoutAmount') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="block text-xs font-medium mb-1">Max payout amount (0 = no cap) @if ($scoped) <x-setting-override-badge :overridden="in_array('wallet.provider_max_payout_amount', $this->overriddenKeys)" setting-key="wallet.provider_max_payout_amount" /> @endif</label>
                        <input type="number" step="0.01" wire:model="walletProviderMaxPayoutAmount" class="w-full border rounded px-3 py-2 text-sm">
                        @error('walletProviderMaxPayoutAmount') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
                    </div>
                </div>
            </div>

            <div class="mb-4 border-t pt-4">
                <h3 class="text-sm font-semibold mb-1">Franchise</h3>
                <p class="text-xs text-gray-400 mb-3">Enforced in PayoutService::request() for payee_type = franchise_owner.</p>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-medium mb-1">Min payout amount @if ($scoped) <x-setting-override-badge :overridden="in_array('wallet.franchise_min_payout_amount', $this->overriddenKeys)" setting-key="wallet.franchise_min_payout_amount" /> @endif</label>
                        <input type="number" step="0.01" wire:model="walletFranchiseMinPayoutAmount" class="w-full border rounded px-3 py-2 text-sm">
                        @error('walletFranchiseMinPayoutAmount') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="block text-xs font-medium mb-1">Max payout amount (0 = no cap) @if ($scoped) <x-setting-override-badge :overridden="in_array('wallet.franchise_max_payout_amount', $this->overriddenKeys)" setting-key="wallet.franchise_max_payout_amount" /> @endif</label>
                        <input type="number" step="0.01" wire:model="walletFranchiseMaxPayoutAmount" class="w-full border rounded px-3 py-2 text-sm">
                        @error('walletFranchiseMaxPayoutAmount') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
                    </div>
                </div>
            </div>

            <div class="flex justify-end pt-4 mt-4 border-t">
                <button type="button" wire:click="saveWallet" class="bg-slate-900 text-white px-6 py-2 rounded text-sm font-medium hover:bg-slate-800">Save Wallet Settings</button>
            </div>

        {{-- Priority / Ranking — governs provider ordering in DispatchService::findCandidates() and GET /api/providers/nearby. "Riders" use the same Provider rows. --}}
        @elseif ($activeTab === 'ranking')
            @php $rankingCriteria = ['priority' => 'Priority (manual)', 'rating' => 'Rating', 'distance' => 'Distance', 'orders' => 'Orders completed', 'subscription' => 'Active subscription']; @endphp
            <p class="text-xs text-gray-400 mb-3">Ranks eligible providers for job dispatch and nearby-browse. Eligibility (zone, online, KYC-approved, radius, availability) is filtered first — ranking never reorders in an ineligible provider. Default (Distance, ascending) is identical to the app's original dispatch behaviour.</p>

            <div class="mb-4">
                <label class="block text-xs font-medium mb-1">Mode @if ($scoped) <x-setting-override-badge :overridden="in_array('ranking.providers.mode', $this->overriddenKeys)" setting-key="ranking.providers.mode" /> @endif</label>
                <select wire:model.live="rankingMode" class="w-64 border rounded px-3 py-2 text-sm">
                    <option value="sequential">Sequential (Primary / Secondary / Tertiary)</option>
                    <option value="weighted">Weighted score</option>
                </select>
            </div>

            @if ($rankingMode === 'sequential')
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-4">
                    <div>
                        <label class="block text-xs font-medium mb-1">Primary <span class="text-red-500">*</span></label>
                        <div class="flex gap-2">
                            <select wire:model="rankingPrimaryCriterion" class="flex-1 border rounded px-2 py-2 text-sm">
                                @foreach ($rankingCriteria as $key => $label) <option value="{{ $key }}">{{ $label }}</option> @endforeach
                            </select>
                            <select wire:model="rankingPrimaryDirection" class="border rounded px-2 py-2 text-sm">
                                <option value="asc">Asc</option>
                                <option value="desc">Desc</option>
                            </select>
                        </div>
                        @error('rankingPrimaryCriterion') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="block text-xs font-medium mb-1">Secondary</label>
                        <div class="flex gap-2">
                            <select wire:model="rankingSecondaryCriterion" class="flex-1 border rounded px-2 py-2 text-sm">
                                <option value="">None</option>
                                @foreach ($rankingCriteria as $key => $label) <option value="{{ $key }}">{{ $label }}</option> @endforeach
                            </select>
                            <select wire:model="rankingSecondaryDirection" class="border rounded px-2 py-2 text-sm">
                                <option value="asc">Asc</option>
                                <option value="desc">Desc</option>
                            </select>
                        </div>
                    </div>
                    <div>
                        <label class="block text-xs font-medium mb-1">Tertiary</label>
                        <div class="flex gap-2">
                            <select wire:model="rankingTertiaryCriterion" class="flex-1 border rounded px-2 py-2 text-sm">
                                <option value="">None</option>
                                @foreach ($rankingCriteria as $key => $label) <option value="{{ $key }}">{{ $label }}</option> @endforeach
                            </select>
                            <select wire:model="rankingTertiaryDirection" class="border rounded px-2 py-2 text-sm">
                                <option value="asc">Asc</option>
                                <option value="desc">Desc</option>
                            </select>
                        </div>
                    </div>
                </div>
                @error('rankingSecondaryCriterion') <p class="text-red-600 text-xs mb-3">{{ $message }}</p> @enderror
            @else
                <p class="text-xs text-gray-400 mb-2">Each candidate is scored 0–1 per criterion (rating uses a fixed 0–5 scale; the rest are relative to the current candidate set) then combined by these weights — they don't need to add to 100, only their proportion to each other matters. All-zero falls back to distance-ascending.</p>
                <div class="grid grid-cols-2 md:grid-cols-5 gap-4 mb-4">
                    <div>
                        <label class="block text-xs font-medium mb-1">Priority</label>
                        <input type="number" step="1" min="0" wire:model="rankingWeightPriority" class="w-full border rounded px-3 py-2 text-sm">
                    </div>
                    <div>
                        <label class="block text-xs font-medium mb-1">Rating</label>
                        <input type="number" step="1" min="0" wire:model="rankingWeightRating" class="w-full border rounded px-3 py-2 text-sm">
                    </div>
                    <div>
                        <label class="block text-xs font-medium mb-1">Distance</label>
                        <input type="number" step="1" min="0" wire:model="rankingWeightDistance" class="w-full border rounded px-3 py-2 text-sm">
                    </div>
                    <div>
                        <label class="block text-xs font-medium mb-1">Orders</label>
                        <input type="number" step="1" min="0" wire:model="rankingWeightOrders" class="w-full border rounded px-3 py-2 text-sm">
                    </div>
                    <div>
                        <label class="block text-xs font-medium mb-1">Subscription</label>
                        <input type="number" step="1" min="0" wire:model="rankingWeightSubscription" class="w-full border rounded px-3 py-2 text-sm">
                    </div>
                </div>
            @endif

            <div class="flex justify-end pt-4 mt-4 border-t">
                <button type="button" wire:click="saveRanking" class="bg-slate-900 text-white px-6 py-2 rounded text-sm font-medium hover:bg-slate-800">Save Ranking Settings</button>
            </div>

        {{-- Loyalty / Referral — real consumers: App\Services\LoyaltyService (CompleteBookingAction), App\Services\ReferralService. Values below are development defaults, not approved commercial numbers. --}}
        @elseif ($activeTab === 'loyalty')
            <div class="mb-4">
                <h3 class="text-sm font-semibold mb-1">Loyalty Points</h3>
                <p class="text-xs text-gray-400 mb-3">Earned automatically on booking completion (customer per rupee spent, provider a flat amount per job). Redeeming converts points into a real wallet credit at the rate below — not a second balance system.</p>
                <div class="grid grid-cols-2 md:grid-cols-5 gap-4">
                    <div>
                        <label class="block text-xs font-medium mb-1">Customer points / ₹ spent @if ($scoped) <x-setting-override-badge :overridden="in_array('loyalty.customer_points_per_currency_unit', $this->overriddenKeys)" setting-key="loyalty.customer_points_per_currency_unit" /> @endif</label>
                        <input type="number" step="0.001" wire:model="loyaltyCustomerPointsPerCurrencyUnit" class="w-full border rounded px-3 py-2 text-sm">
                        @error('loyaltyCustomerPointsPerCurrencyUnit') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="block text-xs font-medium mb-1">Provider points / job @if ($scoped) <x-setting-override-badge :overridden="in_array('loyalty.provider_points_per_completed_job', $this->overriddenKeys)" setting-key="loyalty.provider_points_per_completed_job" /> @endif</label>
                        <input type="number" step="1" wire:model="loyaltyProviderPointsPerCompletedJob" class="w-full border rounded px-3 py-2 text-sm">
                        @error('loyaltyProviderPointsPerCompletedJob') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="block text-xs font-medium mb-1">Points per ₹ (redemption) @if ($scoped) <x-setting-override-badge :overridden="in_array('loyalty.points_per_rupee_redemption', $this->overriddenKeys)" setting-key="loyalty.points_per_rupee_redemption" /> @endif</label>
                        <input type="number" step="1" min="1" wire:model="loyaltyPointsPerRupeeRedemption" class="w-full border rounded px-3 py-2 text-sm">
                        @error('loyaltyPointsPerRupeeRedemption') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="block text-xs font-medium mb-1">Minimum redemption @if ($scoped) <x-setting-override-badge :overridden="in_array('loyalty.min_redemption_points', $this->overriddenKeys)" setting-key="loyalty.min_redemption_points" /> @endif</label>
                        <input type="number" step="1" wire:model="loyaltyMinRedemptionPoints" class="w-full border rounded px-3 py-2 text-sm">
                        @error('loyaltyMinRedemptionPoints') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="block text-xs font-medium mb-1">Points expiry (days, 0 = never) @if ($scoped) <x-setting-override-badge :overridden="in_array('loyalty.points_expiry_days', $this->overriddenKeys)" setting-key="loyalty.points_expiry_days" /> @endif</label>
                        <input type="number" step="1" wire:model="loyaltyPointsExpiryDays" class="w-full border rounded px-3 py-2 text-sm">
                        @error('loyaltyPointsExpiryDays') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
                    </div>
                </div>
            </div>

            <div class="mb-4 border-t pt-4">
                <h3 class="text-sm font-semibold mb-1">Referral</h3>
                <p class="text-xs text-gray-400 mb-3">Rewards the referrer when the referred customer completes their first-ever booking. Amounts below are development defaults — not approved commercial values.</p>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <div>
                        <label class="block text-xs font-medium mb-1">Reward type @if ($scoped) <x-setting-override-badge :overridden="in_array('referral.reward_type', $this->overriddenKeys)" setting-key="referral.reward_type" /> @endif</label>
                        <select wire:model="referralRewardType" class="w-full border rounded px-3 py-2 text-sm">
                            <option value="wallet">Wallet credit</option>
                            <option value="points">Loyalty points</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-medium mb-1">Wallet reward amount @if ($scoped) <x-setting-override-badge :overridden="in_array('referral.reward_amount', $this->overriddenKeys)" setting-key="referral.reward_amount" /> @endif</label>
                        <input type="number" step="0.01" wire:model="referralRewardAmount" class="w-full border rounded px-3 py-2 text-sm">
                        @error('referralRewardAmount') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="block text-xs font-medium mb-1">Points reward amount @if ($scoped) <x-setting-override-badge :overridden="in_array('referral.reward_points', $this->overriddenKeys)" setting-key="referral.reward_points" /> @endif</label>
                        <input type="number" step="1" wire:model="referralRewardPoints" class="w-full border rounded px-3 py-2 text-sm">
                        @error('referralRewardPoints') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="block text-xs font-medium mb-1">Pending expiry (days, blank = never)</label>
                        <input type="number" step="1" min="1" wire:model="referralPendingExpiryDays" class="w-full border rounded px-3 py-2 text-sm" @disabled($scoped)>
                        <p class="text-[11px] text-gray-400 mt-1">Global only — ReferralService reads this without a scope, so the picker above doesn't apply to this one field.</p>
                        @error('referralPendingExpiryDays') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
                    </div>
                </div>
            </div>

            <div class="flex justify-end pt-4 mt-4 border-t">
                <button type="button" wire:click="saveLoyalty" class="bg-slate-900 text-white px-6 py-2 rounded text-sm font-medium hover:bg-slate-800">Save Loyalty / Referral Settings</button>
            </div>

        {{-- KYC — real consumers: KycWithdrawalPolicyService (withdrawal
             gate), KycVerificationVideoService / KycDocumentService (upload
             size caps), ReviewProviderKycAction (video-required gate). All
             four were previously reachable only via a direct DB/tinker edit. --}}
        @elseif ($activeTab === 'kyc')
            <p class="text-xs text-gray-400 mb-3">Controls Partner KYC enforcement: whether an unverified provider is blocked from withdrawing, whether a verification video is required before approval, and upload size caps for documents/video.</p>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                <div>
                    <label class="block text-xs font-medium mb-1">Withdrawal restriction @if ($scoped) <x-setting-override-badge :overridden="in_array('kyc.withdrawal_restriction_enabled', $this->overriddenKeys)" setting-key="kyc.withdrawal_restriction_enabled" /> @endif</label>
                    <select wire:model="kycWithdrawalRestrictionEnabled" class="w-full border rounded px-3 py-2 text-sm">
                        <option value="1">Enabled — unverified providers can't withdraw</option>
                        <option value="0">Disabled</option>
                    </select>
                    @error('kycWithdrawalRestrictionEnabled') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-xs font-medium mb-1">Verification video required @if ($scoped) <x-setting-override-badge :overridden="in_array('kyc.require_verification_video', $this->overriddenKeys)" setting-key="kyc.require_verification_video" /> @endif</label>
                    <select wire:model="kycRequireVerificationVideo" class="w-full border rounded px-3 py-2 text-sm">
                        <option value="1">Required for approval</option>
                        <option value="0">Not required</option>
                    </select>
                    @error('kycRequireVerificationVideo') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
                </div>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-medium mb-1">Max document size (MB) @if ($scoped) <x-setting-override-badge :overridden="in_array('kyc.max_document_size_mb', $this->overriddenKeys)" setting-key="kyc.max_document_size_mb" /> @endif</label>
                    <input type="number" step="1" wire:model="kycMaxDocumentSizeMb" class="w-full border rounded px-3 py-2 text-sm">
                    @error('kycMaxDocumentSizeMb') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-xs font-medium mb-1">Max video size (MB) @if ($scoped) <x-setting-override-badge :overridden="in_array('kyc.max_video_size_mb', $this->overriddenKeys)" setting-key="kyc.max_video_size_mb" /> @endif</label>
                    <input type="number" step="1" wire:model="kycMaxVideoSizeMb" class="w-full border rounded px-3 py-2 text-sm">
                    @error('kycMaxVideoSizeMb') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
                </div>
            </div>
            <div class="flex justify-end pt-4 mt-4 border-t">
                <button type="button" wire:click="saveKyc" class="bg-slate-900 text-white px-6 py-2 rounded text-sm font-medium hover:bg-slate-800">Save KYC Settings</button>
            </div>

        {{-- Compensation — real consumer: CompensationService, wired into
             CompleteBookingAction since mission Phase 5. Overtime/night/peak
             auto-compute from real booking timestamps; rain/waiting are
             admin-triggered only (no real data source exists to auto-derive
             them) — this tab only sets the RATES, not who gets paid when. --}}
        @elseif ($activeTab === 'compensation')
            <p class="text-xs text-gray-400 mb-3">Every rate defaults to 0 and every time window to -1 (disabled) — nothing pays out until set here. Overtime/night/peak are auto-computed at booking completion from real timestamps; rain/waiting are only ever applied manually by an admin from the booking's own screen (no automatic data source exists for either).</p>
            <div class="mb-4">
                <h3 class="text-sm font-semibold mb-1">Overtime</h3>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-medium mb-1">Rate per minute @if ($scoped) <x-setting-override-badge :overridden="in_array('compensation.overtime_rate_per_minute', $this->overriddenKeys)" setting-key="compensation.overtime_rate_per_minute" /> @endif</label>
                        <input type="number" step="0.01" wire:model="compensationOvertimeRatePerMinute" class="w-full border rounded px-3 py-2 text-sm">
                        @error('compensationOvertimeRatePerMinute') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="block text-xs font-medium mb-1">Grace threshold (minutes) @if ($scoped) <x-setting-override-badge :overridden="in_array('compensation.overtime_threshold_minutes', $this->overriddenKeys)" setting-key="compensation.overtime_threshold_minutes" /> @endif</label>
                        <input type="number" step="1" wire:model="compensationOvertimeThresholdMinutes" class="w-full border rounded px-3 py-2 text-sm">
                        @error('compensationOvertimeThresholdMinutes') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
                    </div>
                </div>
            </div>
            <div class="mb-4 border-t pt-4">
                <h3 class="text-sm font-semibold mb-1">Night window</h3>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <div>
                        <label class="block text-xs font-medium mb-1">Start hour (0-23, -1 = off) @if ($scoped) <x-setting-override-badge :overridden="in_array('compensation.night_window_start_hour', $this->overriddenKeys)" setting-key="compensation.night_window_start_hour" /> @endif</label>
                        <input type="number" step="1" wire:model="compensationNightWindowStartHour" class="w-full border rounded px-3 py-2 text-sm">
                        @error('compensationNightWindowStartHour') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="block text-xs font-medium mb-1">End hour (0-23, -1 = off) @if ($scoped) <x-setting-override-badge :overridden="in_array('compensation.night_window_end_hour', $this->overriddenKeys)" setting-key="compensation.night_window_end_hour" /> @endif</label>
                        <input type="number" step="1" wire:model="compensationNightWindowEndHour" class="w-full border rounded px-3 py-2 text-sm">
                        @error('compensationNightWindowEndHour') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="block text-xs font-medium mb-1">Flat amount @if ($scoped) <x-setting-override-badge :overridden="in_array('compensation.night_flat_amount', $this->overriddenKeys)" setting-key="compensation.night_flat_amount" /> @endif</label>
                        <input type="number" step="0.01" wire:model="compensationNightFlatAmount" class="w-full border rounded px-3 py-2 text-sm">
                        @error('compensationNightFlatAmount') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
                    </div>
                </div>
            </div>
            <div class="mb-4 border-t pt-4">
                <h3 class="text-sm font-semibold mb-1">Peak window</h3>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <div>
                        <label class="block text-xs font-medium mb-1">Start hour (0-23, -1 = off) @if ($scoped) <x-setting-override-badge :overridden="in_array('compensation.peak_window_start_hour', $this->overriddenKeys)" setting-key="compensation.peak_window_start_hour" /> @endif</label>
                        <input type="number" step="1" wire:model="compensationPeakWindowStartHour" class="w-full border rounded px-3 py-2 text-sm">
                        @error('compensationPeakWindowStartHour') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="block text-xs font-medium mb-1">End hour (0-23, -1 = off) @if ($scoped) <x-setting-override-badge :overridden="in_array('compensation.peak_window_end_hour', $this->overriddenKeys)" setting-key="compensation.peak_window_end_hour" /> @endif</label>
                        <input type="number" step="1" wire:model="compensationPeakWindowEndHour" class="w-full border rounded px-3 py-2 text-sm">
                        @error('compensationPeakWindowEndHour') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="block text-xs font-medium mb-1">Flat amount @if ($scoped) <x-setting-override-badge :overridden="in_array('compensation.peak_flat_amount', $this->overriddenKeys)" setting-key="compensation.peak_flat_amount" /> @endif</label>
                        <input type="number" step="0.01" wire:model="compensationPeakFlatAmount" class="w-full border rounded px-3 py-2 text-sm">
                        @error('compensationPeakFlatAmount') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
                    </div>
                </div>
            </div>
            <div class="mb-4 border-t pt-4">
                <h3 class="text-sm font-semibold mb-1">Rain / Waiting (admin-triggered only)</h3>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-medium mb-1">Rain flat amount @if ($scoped) <x-setting-override-badge :overridden="in_array('compensation.rain_flat_amount', $this->overriddenKeys)" setting-key="compensation.rain_flat_amount" /> @endif</label>
                        <input type="number" step="0.01" wire:model="compensationRainFlatAmount" class="w-full border rounded px-3 py-2 text-sm">
                        @error('compensationRainFlatAmount') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="block text-xs font-medium mb-1">Waiting rate per minute @if ($scoped) <x-setting-override-badge :overridden="in_array('compensation.waiting_rate_per_minute', $this->overriddenKeys)" setting-key="compensation.waiting_rate_per_minute" /> @endif</label>
                        <input type="number" step="0.01" wire:model="compensationWaitingRatePerMinute" class="w-full border rounded px-3 py-2 text-sm">
                        @error('compensationWaitingRatePerMinute') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
                    </div>
                </div>
            </div>
            <div class="flex justify-end pt-4 mt-4 border-t">
                <button type="button" wire:click="saveCompensation" class="bg-slate-900 text-white px-6 py-2 rounded text-sm font-medium hover:bg-slate-800">Save Compensation Settings</button>
            </div>

        {{-- Security / OTP — real consumers: OtpService, QrChallengeService.
             GLOBAL ONLY: both read Setting::get() with no scope argument at
             all, so the scope picker above is ignored on this tab. --}}
        @elseif ($activeTab === 'security')
            <p class="text-xs text-gray-400 mb-3">Login/verification OTP and QR device-pairing parameters. <strong>Global only</strong> — these are read without a scope by their consumers, so the scope picker above this tab has no effect here.</p>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div>
                    <label class="block text-xs font-medium mb-1">OTP length (digits)</label>
                    <input type="number" step="1" wire:model="authOtpLength" class="w-full border rounded px-3 py-2 text-sm">
                    @error('authOtpLength') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-xs font-medium mb-1">OTP expiry (seconds)</label>
                    <input type="number" step="1" wire:model="authOtpExpirySeconds" class="w-full border rounded px-3 py-2 text-sm">
                    @error('authOtpExpirySeconds') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-xs font-medium mb-1">OTP resend cooldown (seconds)</label>
                    <input type="number" step="1" wire:model="authOtpResendCooldownSeconds" class="w-full border rounded px-3 py-2 text-sm">
                    @error('authOtpResendCooldownSeconds') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-xs font-medium mb-1">OTP max attempts</label>
                    <input type="number" step="1" wire:model="authOtpMaxAttempts" class="w-full border rounded px-3 py-2 text-sm">
                    @error('authOtpMaxAttempts') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-xs font-medium mb-1">QR challenge expiry (seconds)</label>
                    <input type="number" step="1" wire:model="authQrChallengeExpirySeconds" class="w-full border rounded px-3 py-2 text-sm">
                    @error('authQrChallengeExpirySeconds') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
                </div>
            </div>
            <div class="flex justify-end pt-4 mt-4 border-t">
                <button type="button" wire:click="saveSecurity" class="bg-slate-900 text-white px-6 py-2 rounded text-sm font-medium hover:bg-slate-800">Save Security / OTP Settings</button>
            </div>

        {{-- Operations — real consumers: StuckBookingService,
             DispatchHealthService (mission Phase 10). GLOBAL ONLY: both read
             Setting::get() with no scope argument. --}}
        @elseif ($activeTab === 'operations')
            <p class="text-xs text-gray-400 mb-3">Thresholds for the Operations screen's stuck-booking and stale-offer detection. <strong>Global only</strong> — read without a scope by their consumers, so the scope picker above has no effect here.</p>
            <div class="grid grid-cols-2 md:grid-cols-3 gap-4 mb-4">
                <div>
                    <label class="block text-xs font-medium mb-1">Searching provider (minutes)</label>
                    <input type="number" step="1" wire:model="opsStuckThresholdSearchingProvider" class="w-full border rounded px-3 py-2 text-sm">
                    @error('opsStuckThresholdSearchingProvider') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-xs font-medium mb-1">Assigned (minutes)</label>
                    <input type="number" step="1" wire:model="opsStuckThresholdAssigned" class="w-full border rounded px-3 py-2 text-sm">
                    @error('opsStuckThresholdAssigned') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-xs font-medium mb-1">Provider en route (minutes)</label>
                    <input type="number" step="1" wire:model="opsStuckThresholdProviderEnRoute" class="w-full border rounded px-3 py-2 text-sm">
                    @error('opsStuckThresholdProviderEnRoute') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-xs font-medium mb-1">In progress (minutes)</label>
                    <input type="number" step="1" wire:model="opsStuckThresholdInProgress" class="w-full border rounded px-3 py-2 text-sm">
                    @error('opsStuckThresholdInProgress') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-xs font-medium mb-1">On hold (minutes)</label>
                    <input type="number" step="1" wire:model="opsStuckThresholdOnHold" class="w-full border rounded px-3 py-2 text-sm">
                    @error('opsStuckThresholdOnHold') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-xs font-medium mb-1">Dispatch offer response timeout (minutes)</label>
                    <input type="number" step="1" wire:model="opsDispatchOfferResponseTimeoutMinutes" class="w-full border rounded px-3 py-2 text-sm">
                    @error('opsDispatchOfferResponseTimeoutMinutes') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
                </div>
            </div>
            <div class="flex justify-end pt-4 mt-4 border-t">
                <button type="button" wire:click="saveOperations" class="bg-slate-900 text-white px-6 py-2 rounded text-sm font-medium hover:bg-slate-800">Save Operations Settings</button>
            </div>

        {{-- Subscriptions — real consumer: RenewalService (past-due grace
             period). GLOBAL ONLY. Replaces the old "subscriptions_membership"
             placeholder, whose "zero consumers" note was no longer accurate. --}}
        @elseif ($activeTab === 'subscriptions')
            <p class="text-xs text-gray-400 mb-3">How many days a subscription stays active past its due date before RenewalService lapses it. <strong>Global only</strong> — read without a scope by its consumer. Plan definitions and per-subscriber management live on their own screens (Plans &amp; Memberships, Subscriptions).</p>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div>
                    <label class="block text-xs font-medium mb-1">Grace period (days)</label>
                    <input type="number" step="1" wire:model="subscriptionsGracePeriodDays" class="w-full border rounded px-3 py-2 text-sm">
                    @error('subscriptionsGracePeriodDays') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
                </div>
            </div>
            <div class="flex justify-end pt-4 mt-4 border-t">
                <button type="button" wire:click="saveSubscriptions" class="bg-slate-900 text-white px-6 py-2 rounded text-sm font-medium hover:bg-slate-800">Save Subscription Settings</button>
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
