<div>
    <h1 class="text-2xl font-bold mb-1">Plans & Memberships</h1>
    <p class="text-sm text-gray-500 mb-4">Customer Prime, Provider/Vendor Packages, and any future plan family — one catalog, one engine. Global behavioral config (grace period, etc.) still lives under Settings.</p>

    @if ($flashMessage)
        <div @class(['rounded p-3 mb-4 text-sm', 'bg-green-50 text-green-700' => $flashType === 'success', 'bg-red-50 text-red-700' => $flashType === 'error'])>
            {{ $flashMessage }}
        </div>
    @endif

    {{-- New plan --}}
    <div class="bg-white rounded-lg shadow-sm p-4 mb-6">
        <h2 class="text-sm font-semibold mb-3">New Plan</h2>
        <div class="grid grid-cols-4 gap-3">
            <div>
                <label class="block text-xs font-medium mb-1">Name <span class="text-red-500">*</span></label>
                <input type="text" wire:model="name" class="w-full border rounded px-3 py-2 text-sm">
                @error('name') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="block text-xs font-medium mb-1">Plan family</label>
                <select wire:model="planFamily" class="w-full border rounded px-3 py-2 text-sm">
                    @foreach (\App\Livewire\Plans\Manage::PLAN_FAMILIES as $f)
                        <option value="{{ $f }}">{{ ucwords(str_replace('_', ' ', $f)) }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="block text-xs font-medium mb-1">Eligible actor</label>
                <select wire:model="eligibleActorType" class="w-full border rounded px-3 py-2 text-sm">
                    <option value="customer">Customer</option>
                    <option value="provider">Provider</option>
                    <option value="business_account">Business Account</option>
                </select>
            </div>
            <div>
                <label class="block text-xs font-medium mb-1">Scope</label>
                <div class="flex gap-2">
                    <select wire:model="scopeType" class="w-1/2 border rounded px-3 py-2 text-sm">
                        <option value="global">Global</option>
                        <option value="country">Country</option>
                        <option value="city">City</option>
                        <option value="zone">Zone</option>
                        <option value="franchise">Franchise</option>
                    </select>
                    @if ($scopeType !== 'global')
                        <input type="number" wire:model="scopeId" placeholder="ID" class="w-1/2 border rounded px-3 py-2 text-sm">
                    @endif
                </div>
            </div>
            <div>
                <label class="block text-xs font-medium mb-1">Billing cycle</label>
                <select wire:model="billingCycle" class="w-full border rounded px-3 py-2 text-sm">
                    <option value="daily">Daily</option>
                    <option value="weekly">Weekly</option>
                    <option value="monthly">Monthly</option>
                    <option value="quarterly">Quarterly</option>
                    <option value="half_yearly">Half-yearly</option>
                    <option value="annual">Annual</option>
                    <option value="custom">Custom</option>
                </select>
            </div>
            @if ($billingCycle === 'custom')
                <div>
                    <label class="block text-xs font-medium mb-1">Custom cycle (days)</label>
                    <input type="number" wire:model="customCycleDays" class="w-full border rounded px-3 py-2 text-sm">
                </div>
            @endif
            <div>
                <label class="block text-xs font-medium mb-1">Price ({{ $currencySymbol }})</label>
                <input type="number" step="0.01" wire:model="price" class="w-full border rounded px-3 py-2 text-sm">
            </div>
            <div>
                <label class="block text-xs font-medium mb-1">Stacking strategy</label>
                <select wire:model="stackingStrategy" class="w-full border rounded px-3 py-2 text-sm">
                    <option value="exclusive">Exclusive</option>
                    <option value="stack">Stack</option>
                    <option value="highest_benefit_wins">Highest benefit wins</option>
                    <option value="most_specific_wins">Most specific wins</option>
                    <option value="priority_order">Priority order</option>
                </select>
            </div>
            <div>
                <label class="block text-xs font-medium mb-1">Stacking priority</label>
                <input type="number" wire:model="stackingPriority" class="w-full border rounded px-3 py-2 text-sm">
            </div>
        </div>
        <button type="button" wire:click="save" class="mt-4 bg-slate-900 text-white px-6 h-[38px] rounded text-sm font-medium hover:bg-slate-800">Create Plan</button>
    </div>

    {{-- Plan list --}}
    <div class="bg-white rounded-lg shadow-sm overflow-hidden">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 text-left text-gray-500">
                <tr>
                    <th class="px-4 py-2">Plan</th>
                    <th class="px-4 py-2">Family</th>
                    <th class="px-4 py-2">Actor</th>
                    <th class="px-4 py-2">Scope</th>
                    <th class="px-4 py-2">Price</th>
                    <th class="px-4 py-2">Subscribers</th>
                    <th class="px-4 py-2">Status</th>
                    <th class="px-4 py-2 text-right">Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($plans as $p)
                    <tr class="border-t hover:bg-gray-50" wire:key="plan-{{ $p->id }}">
                        <td class="px-4 py-2 font-medium">{{ $p->name }}</td>
                        <td class="px-4 py-2 text-gray-500">{{ ucwords(str_replace('_', ' ', $p->plan_family)) }}</td>
                        <td class="px-4 py-2 text-gray-500">{{ ucwords(str_replace('_', ' ', $p->eligible_actor_type)) }}</td>
                        <td class="px-4 py-2 text-gray-500">{{ ucfirst($p->scope_type) }}{{ $p->scope_id ? ' #'.$p->scope_id : '' }}</td>
                        <td class="px-4 py-2 font-mono">{{ $currencySymbol }}{{ number_format($p->price, 2) }}/{{ $p->billing_cycle }}</td>
                        <td class="px-4 py-2">{{ $p->subscriptions_count }}</td>
                        <td class="px-4 py-2">
                            <span @class(['px-2 py-0.5 rounded text-xs', 'bg-green-100 text-green-700' => $p->is_active, 'bg-gray-100 text-gray-500' => !$p->is_active])>
                                {{ $p->is_active ? 'active' : 'inactive' }}
                            </span>
                        </td>
                        <td class="px-4 py-2 text-right whitespace-nowrap">
                            <button type="button" wire:click="expand({{ $p->id }})" class="text-xs text-blue-600 hover:underline mr-3">{{ $expandedPlanId === $p->id ? 'Hide' : 'Entitlements' }} ({{ $p->entitlements->count() }})</button>
                            <button type="button" wire:click="toggleActive({{ $p->id }})" class="text-xs text-gray-600 hover:underline">{{ $p->is_active ? 'Deactivate' : 'Activate' }}</button>
                        </td>
                    </tr>
                    @if ($expandedPlanId === $p->id)
                        <tr class="border-t bg-gray-50" wire:key="plan-{{ $p->id }}-entitlements">
                            <td colspan="8" class="px-4 py-4">
                                <table class="w-full text-xs mb-3">
                                    <thead class="text-left text-gray-500">
                                        <tr>
                                            <th class="pr-3 py-1">Type</th>
                                            <th class="pr-3 py-1">Module</th>
                                            <th class="pr-3 py-1">Qty</th>
                                            <th class="pr-3 py-1">{{ $currencySymbol }} value</th>
                                            <th class="pr-3 py-1">% value</th>
                                            <th class="pr-3 py-1">Trigger</th>
                                            <th class="pr-3 py-1">Rollover</th>
                                            <th class="pr-3 py-1">Overage</th>
                                            <th class="pr-3 py-1"></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @forelse ($p->entitlements as $e)
                                            <tr class="border-t" wire:key="ent-{{ $e->id }}">
                                                <td class="pr-3 py-1">{{ str_replace('_', ' ', $e->entitlement_type) }}</td>
                                                <td class="pr-3 py-1">{{ $e->module ?? '—' }}</td>
                                                <td class="pr-3 py-1">{{ $e->quantity ?? '—' }}</td>
                                                <td class="pr-3 py-1">{{ $e->monetary_value !== null ? $currencySymbol.number_format($e->monetary_value, 2) : '—' }}</td>
                                                <td class="pr-3 py-1">{{ $e->percentage_value !== null ? $e->percentage_value.'%' : '—' }}</td>
                                                <td class="pr-3 py-1">{{ str_replace('_', ' ', $e->consumption_trigger) }}</td>
                                                <td class="pr-3 py-1">{{ $e->rollover_policy }}</td>
                                                <td class="pr-3 py-1">{{ $e->overage_enabled ? ($e->overage_rate_type.' '.$e->overage_rate_value) : 'off' }}</td>
                                                <td class="pr-3 py-1 text-right whitespace-nowrap">
                                                    @if ($e->entitlement_type === 'commission_override')
                                                        <button type="button" wire:click="approveOverride({{ $e->id }})" class="text-blue-600 hover:underline mr-2">{{ $e->is_approved ? 'Revoke approval' : 'Approve' }}</button>
                                                    @endif
                                                    <button type="button" wire:click="deleteEntitlement({{ $e->id }})" wire:confirm="Remove this entitlement?" class="text-red-600 hover:underline">Remove</button>
                                                </td>
                                            </tr>
                                        @empty
                                            <tr><td colspan="9" class="py-2 text-gray-400">No entitlements yet.</td></tr>
                                        @endforelse
                                    </tbody>
                                </table>

                                <div class="grid grid-cols-6 gap-2 items-end">
                                    <div>
                                        <label class="block text-xs mb-1">Type</label>
                                        <select wire:model="entType" class="w-full border rounded px-2 py-1.5 text-xs">
                                            @foreach (\App\Livewire\Plans\Manage::ENTITLEMENT_TYPES as $t)
                                                <option value="{{ $t }}">{{ str_replace('_', ' ', $t) }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                    <div>
                                        <label class="block text-xs mb-1">Module</label>
                                        <input type="text" wire:model="entModule" placeholder="service" class="w-full border rounded px-2 py-1.5 text-xs">
                                    </div>
                                    <div>
                                        <label class="block text-xs mb-1">Quantity</label>
                                        <input type="number" wire:model="entQuantity" class="w-full border rounded px-2 py-1.5 text-xs">
                                    </div>
                                    <div>
                                        <label class="block text-xs mb-1">{{ $currencySymbol }} value</label>
                                        <input type="number" step="0.01" wire:model="entMonetaryValue" class="w-full border rounded px-2 py-1.5 text-xs">
                                    </div>
                                    <div>
                                        <label class="block text-xs mb-1">% value</label>
                                        <input type="number" step="0.01" wire:model="entPercentageValue" class="w-full border rounded px-2 py-1.5 text-xs">
                                    </div>
                                    <div>
                                        <label class="block text-xs mb-1">Usage period</label>
                                        <select wire:model="entUsagePeriod" class="w-full border rounded px-2 py-1.5 text-xs">
                                            <option value="per_transaction">Per transaction</option>
                                            <option value="daily">Daily</option>
                                            <option value="monthly">Monthly</option>
                                            <option value="pooled_monthly">Pooled monthly</option>
                                        </select>
                                    </div>
                                    <div>
                                        <label class="block text-xs mb-1">Consumption trigger</label>
                                        <select wire:model="entConsumptionTrigger" class="w-full border rounded px-2 py-1.5 text-xs">
                                            <option value="booking_created">Booking created</option>
                                            <option value="booking_confirmed">Booking confirmed</option>
                                            <option value="provider_assigned">Provider assigned</option>
                                            <option value="payment_completed">Payment completed</option>
                                            <option value="service_completed">Service completed</option>
                                            <option value="module_specific">Module specific</option>
                                        </select>
                                    </div>
                                    <div>
                                        <label class="block text-xs mb-1">Rollover</label>
                                        <select wire:model="entRolloverPolicy" class="w-full border rounded px-2 py-1.5 text-xs">
                                            <option value="none">None</option>
                                            <option value="partial">Partial</option>
                                            <option value="full">Full</option>
                                        </select>
                                    </div>
                                    <div>
                                        <label class="block text-xs mb-1">Rollover cap</label>
                                        <input type="number" wire:model="entRolloverCap" class="w-full border rounded px-2 py-1.5 text-xs">
                                    </div>
                                    <div>
                                        <label class="block text-xs mb-1">Rollover expiry (days)</label>
                                        <input type="number" wire:model="entRolloverExpiryDays" class="w-full border rounded px-2 py-1.5 text-xs">
                                    </div>
                                    <div class="flex items-center gap-1 pb-1.5">
                                        <input type="checkbox" wire:model="entOverageEnabled" id="overage-{{ $p->id }}">
                                        <label for="overage-{{ $p->id }}" class="text-xs">Overage enabled</label>
                                    </div>
                                    @if ($entOverageEnabled)
                                        <div>
                                            <label class="block text-xs mb-1">Overage rate type</label>
                                            <select wire:model="entOverageRateType" class="w-full border rounded px-2 py-1.5 text-xs">
                                                <option value="flat">Flat</option>
                                                <option value="percentage_of_payg">% of PAYG</option>
                                            </select>
                                        </div>
                                        <div>
                                            <label class="block text-xs mb-1">Overage rate value</label>
                                            <input type="number" step="0.01" wire:model="entOverageRateValue" class="w-full border rounded px-2 py-1.5 text-xs">
                                        </div>
                                    @endif
                                </div>
                                <button type="button" wire:click="addEntitlement" class="mt-3 bg-slate-900 text-white px-4 h-8 rounded text-xs font-medium hover:bg-slate-800">Add Entitlement</button>
                            </td>
                        </tr>
                    @endif
                @empty
                    <tr><td colspan="8" class="px-4 py-6 text-center text-gray-400">No plans yet.</td></tr>
                @endforelse
            </tbody>
        </table>
        <div class="px-4 py-3 border-t">{{ $plans->links() }}</div>
    </div>
</div>
