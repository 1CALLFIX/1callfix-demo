<div>
    <h1 class="text-2xl font-bold mb-1">Plans & Memberships</h1>
    <p class="text-sm text-gray-500 mb-4">Customer Prime, Provider/Vendor Packages, and any future plan family — one catalog, one engine. Global behavioral config (grace period, etc.) still lives under Settings.</p>

    @if ($flashMessage)
        <div @class(['rounded p-3 mb-4 text-sm', 'bg-green-50 text-green-700' => $flashType === 'success', 'bg-red-50 text-red-700' => $flashType === 'error'])>
            {{ $flashMessage }}
        </div>
    @endif

    @if ($canManage)
        <x-ui.card class="mb-6">
            <h2 class="text-sm font-semibold mb-1">Membership settings</h2>
            <p class="text-xs text-gray-500 mb-3">Price, validity, quantities and values are edited per plan and benefit below. The visit charge a free cancellation waives is the one in Cancellation Policy settings.</p>
            <div class="grid grid-cols-3 gap-3 items-end">
                <div>
                    <label class="block text-xs font-medium mb-1">"Ending soon" reminder (days before expiry)</label>
                    <input type="number" min="1" max="90" wire:model="memberReminderDays" class="w-full border rounded px-3 py-2 text-sm">
                    @error('memberReminderDays') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-xs font-medium mb-1">Priority service — offer batch multiplier (1 = off)</label>
                    <input type="number" min="1" max="10" wire:model="memberPriorityMultiplier" class="w-full border rounded px-3 py-2 text-sm">
                    @error('memberPriorityMultiplier') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
                </div>
                <div><x-ui.button wire:click="saveMembershipSettings">Save membership settings</x-ui.button></div>
            </div>
        </x-ui.card>
    @endif

    {{-- New plan --}}
    <x-ui.card class="mb-6">
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
                <label class="block text-xs font-medium mb-1">Validity (months)</label>
                <input type="number" min="1" max="120" wire:model="validityMonths" placeholder="e.g. 11" class="w-full border rounded px-3 py-2 text-sm">
                <p class="text-[11px] text-gray-400 mt-0.5">Optional. Overrides the billing cycle when set.</p>
            </div>
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
        <div class="grid grid-cols-2 gap-3 mt-3">
            <div>
                <label class="block text-xs font-medium mb-1">Description</label>
                <textarea wire:model="description" rows="3" placeholder="Customer-facing summary / printed-card terms"
                    class="w-full border rounded px-3 py-2 text-sm"></textarea>
                @error('description') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="block text-xs font-medium mb-1">Metadata (JSON object)</label>
                <label class="mb-2 flex items-start gap-2 text-xs"><input type="checkbox" wire:model="waivesCancellationVisitCharges" class="mt-0.5"> <span>Waives the cancellation en-route and visit charges for subscribers (Prime-style). Off by default.</span></label>
                <textarea wire:model="metadataJson" rows="3" placeholder='{"address_locked": true, "spare_parts_chargeable": true}'
                    class="w-full border rounded px-3 py-2 text-sm font-mono"></textarea>
                @error('metadataJson') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
            </div>
        </div>
        <x-ui.button class="mt-4 h-[38px]" wire:click="save">Create Plan</x-ui.button>
    </x-ui.card>

    {{-- Plan list --}}
    <x-ui.archive-bars :bars="$archiveBars" />

    <x-ui.filter-tabs class="mb-3" :tabs="['' => 'All', 'active' => 'Active', 'inactive' => 'Inactive', 'archived' => 'Archived']"
                      :active="$activeFilter" model="activeFilter" />

    <x-ui.table>
        <x-slot:footer>{{ $plans->links() }}</x-slot:footer>

        <thead class="bg-gray-50 text-left text-gray-500">
            <tr>
                <x-ui.sno-th />
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
                    <x-ui.sno :rows="$plans" :loop="$loop" />
                    <td class="px-4 py-2 font-medium">{{ $p->name }}</td>
                    <td class="px-4 py-2 text-gray-500">{{ ucwords(str_replace('_', ' ', $p->plan_family)) }}</td>
                    <td class="px-4 py-2 text-gray-500">{{ ucwords(str_replace('_', ' ', $p->eligible_actor_type)) }}</td>
                    <td class="px-4 py-2 text-gray-500">{{ ucfirst($p->scope_type) }}{{ $p->scope_id ? ' #'.$p->scope_id : '' }}</td>
                    <td class="px-4 py-2 font-mono">{{ $currencySymbol }}{{ number_format($p->price, 2) }}/{{ $p->validityLabel() }}</td>
                    <td class="px-4 py-2">{{ $p->subscriptions_count }}</td>
                    <td class="px-4 py-2">
                        <x-ui.badge :color="$p->is_active ? 'green' : 'gray'">{{ $p->is_active ? 'active' : 'inactive' }}</x-ui.badge>
                    </td>
                    <td class="px-4 py-2 text-right whitespace-nowrap">
                        @unless ($p->trashed())
                            <x-ui.button variant="ghost" color="gray" class="mr-3" wire:click="toggleCancellationWaiver({{ $p->id }})" title="Whether this plan's waiver covers the cancellation en-route and visit charges">Cancel-charge waiver: {{ $p->waives_cancellation_visit_charges ? 'ON' : 'OFF' }}</x-ui.button>
                        @endunless
                        @unless ($p->trashed())
                            <x-ui.button variant="ghost" class="mr-3" wire:click="duplicatePlan({{ $p->id }})" wire:confirm="Duplicate this package (inactive copy with all benefits and targets)?">Duplicate</x-ui.button>
                        @endunless
                        <x-ui.button variant="ghost" class="mr-3" wire:click="startEditPlan({{ $p->id }})">Edit</x-ui.button>
                        <x-ui.button variant="ghost" class="mr-3" wire:click="expand({{ $p->id }})">{{ $expandedPlanId === $p->id ? 'Hide' : 'Entitlements' }} ({{ $p->entitlements->count() }})</x-ui.button>
                        @unless ($p->trashed())
                            <x-ui.button variant="ghost" color="gray" wire:click="toggleActive({{ $p->id }})">{{ $p->is_active ? 'Deactivate' : 'Activate' }}</x-ui.button>
                        @endunless
                        <x-ui.row-actions :id="$p->id" :archived="$p->trashed()" :can-manage="$canManage && ($p->trashed() || ($p->subscriptions_count ?? 0) === 0)" :can-force="$canForce" />
                    </td>
                </tr>
                    @if ($editingPlanId === $p->id)
                        <tr class="border-t bg-blue-50/40" wire:key="plan-{{ $p->id }}-edit">
                            <td colspan="8" class="px-4 py-4">
                                <h3 class="text-sm font-semibold mb-1">Edit plan</h3>
                                <p class="text-xs text-gray-500 mb-3">Price, validity and copy apply to future purchases and renewals only — periods already granted keep their balances.@if ($p->subscriptions_count) This plan has {{ $p->subscriptions_count }} subscriber(s), so its family and actor type are locked.@endif</p>
                                <div class="grid grid-cols-4 gap-3">
                                    <div>
                                        <label class="block text-xs font-medium mb-1">Name</label>
                                        <input type="text" wire:model="editName" class="w-full border rounded px-3 py-2 text-sm">
                                        @error('editName') <p class="text-xs text-red-600">{{ $message }}</p> @enderror
                                    </div>
                                    <div>
                                        <label class="block text-xs font-medium mb-1">Price ({{ $currencySymbol }})</label>
                                        <input type="number" step="0.01" wire:model="editPrice" class="w-full border rounded px-3 py-2 text-sm">
                                    </div>
                                    <div>
                                        <label class="block text-xs font-medium mb-1">Validity (months)</label>
                                        <input type="number" min="1" max="120" wire:model="editValidityMonths" class="w-full border rounded px-3 py-2 text-sm">
                                    </div>
                                    <div>
                                        <label class="block text-xs font-medium mb-1">Billing cycle</label>
                                        <select wire:model.live="editBillingCycle" class="w-full border rounded px-3 py-2 text-sm">
                                            @foreach (['daily', 'weekly', 'monthly', 'quarterly', 'half_yearly', 'annual', 'custom'] as $cycle)
                                                <option value="{{ $cycle }}">{{ ucfirst(str_replace('_', '-', $cycle)) }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                    @if ($editBillingCycle === 'custom')
                                        <div>
                                            <label class="block text-xs font-medium mb-1">Custom cycle (days)</label>
                                            <input type="number" wire:model="editCustomCycleDays" class="w-full border rounded px-3 py-2 text-sm">
                                        </div>
                                    @endif
                                    <div class="col-span-2">
                                        <label class="block text-xs font-medium mb-1">Description</label>
                                        <textarea wire:model="editDescription" rows="3" class="w-full border rounded px-3 py-2 text-sm"></textarea>
                                    </div>
                                    <div class="col-span-2">
                                        <label class="block text-xs font-medium mb-1">Metadata JSON (terms, address lock…)</label>
                                        <textarea wire:model="editMetadataJson" rows="6" class="w-full border rounded px-3 py-2 text-xs font-mono"></textarea>
                                    </div>
                                </div>
                                <x-ui.button size="sm" class="mt-3 !h-8" wire:click="updatePlan">Save plan</x-ui.button>
                                <x-ui.button size="sm" variant="ghost" class="mt-3 !h-8" wire:click="cancelEditPlan">Cancel</x-ui.button>
                            </td>
                        </tr>
                    @endif
                    @if ($expandedPlanId === $p->id)
                        <tr class="border-t bg-gray-50" wire:key="plan-{{ $p->id }}-entitlements">
                            <td colspan="9" class="px-4 py-4">
                                @php $priorityEnt = $p->entitlements->firstWhere('entitlement_type', 'priority'); @endphp
                                <div class="flex flex-wrap items-center gap-4 mb-3 text-xs">
                                    <label class="inline-flex items-center gap-2">
                                        <input type="checkbox" @checked($priorityEnt && $priorityEnt->is_enabled) wire:click="togglePriority({{ $p->id }})">
                                        <span class="font-medium">Priority bookings</span>
                                    </label>
                                    <x-ui.button size="sm" variant="ghost" wire:click="quickAdd({{ $p->id }}, 'service')">+ Add included service</x-ui.button>
                                    <x-ui.button size="sm" variant="ghost" wire:click="quickAdd({{ $p->id }}, 'cancellations')">+ Add free cancellations</x-ui.button>
                                </div>
                                <table class="w-full text-xs mb-3">
                                    <thead class="text-left text-gray-500">
                                        <tr>
                                            <x-ui.sno-th class="pr-3 py-1" />
                                            <th class="pr-3 py-1">On</th>
                                            <th class="pr-3 py-1">Type</th>
                                            <th class="pr-3 py-1">Label</th>
                                            <th class="pr-3 py-1">Module</th>
                                            <th class="pr-3 py-1">Redeem categories</th>
                                            <th class="pr-3 py-1">Qty</th>
                                            <th class="pr-3 py-1">{{ $currencySymbol }} value</th>
                                            <th class="pr-3 py-1">% value</th>
                                            <th class="pr-3 py-1">Trigger</th>
                                            <th class="pr-3 py-1">Rollover</th>
                                            <th class="pr-3 py-1">Overage</th>
                                            <th class="pr-3 py-1">Effect</th>
                                            <th class="pr-3 py-1">Catalog targets</th>
                                            <th class="pr-3 py-1"></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @forelse ($p->entitlements as $e)
                                            <tr class="border-t" wire:key="ent-{{ $e->id }}">
                                                <x-ui.sno :rows="$p->entitlements" :loop="$loop" class="pr-3 py-1" />
                                                <td class="pr-3 py-1"><input type="checkbox" @checked($e->is_enabled) wire:click="toggleEntitlementEnabled({{ $e->id }})" title="Tick = part of this package"></td>
                                                <td class="pr-3 py-1">{{ str_replace('_', ' ', $e->entitlement_type) }}</td>
                                                <td class="pr-3 py-1 {{ $e->is_enabled ? '' : 'text-gray-400 line-through' }}">{{ $e->label ?? '—' }}</td>
                                                <td class="pr-3 py-1">{{ $e->module ?? '—' }}</td>
                                                <td class="pr-3 py-1">{{ $e->redeem_categories ? implode(', ', $e->redeem_categories) : '—' }}</td>
                                                <td class="pr-3 py-1 whitespace-nowrap">
                                                    @if ($e->quantity !== null)
                                                        <button type="button" class="px-1.5 border rounded" wire:click="adjustQuantity({{ $e->id }}, -1)" aria-label="Decrease">−</button>
                                                        <span class="px-1 font-mono">{{ $e->quantity }}</span>
                                                        <button type="button" class="px-1.5 border rounded" wire:click="adjustQuantity({{ $e->id }}, 1)" aria-label="Increase">+</button>
                                                    @else — @endif
                                                </td>
                                                <td class="pr-3 py-1">{{ $e->monetary_value !== null ? $currencySymbol.number_format($e->monetary_value, 2) : '—' }}</td>
                                                <td class="pr-3 py-1">{{ $e->percentage_value !== null ? $e->percentage_value.'%' : '—' }}</td>
                                                <td class="pr-3 py-1">{{ str_replace('_', ' ', $e->consumption_trigger) }}</td>
                                                <td class="pr-3 py-1">{{ $e->rollover_policy }}</td>
                                                <td class="pr-3 py-1">{{ $e->overage_enabled ? ($e->overage_rate_type.' '.$e->overage_rate_value) : 'off' }}</td>
                                                <td class="pr-3 py-1">{{ $e->redemption_effect ? str_replace('_', ' ', $e->redemption_effect) : '—' }}</td>
                                                <td class="pr-3 py-1">
                                                    @if ($e->targets->isEmpty())
                                                        <span @class(['text-amber-600' => $e->redemption_effect === 'service_included'])>{{ $e->redemption_effect === 'service_included' ? 'none mapped — inactive' : ($e->redemption_effect ? 'any service' : '—') }}</span>
                                                    @else
                                                        {{ $e->targets->where('is_excluded', false)->count() }} covered @if ($e->targets->where('is_excluded', true)->count()), {{ $e->targets->where('is_excluded', true)->count() }} excluded @endif
                                                    @endif
                                                </td>
                                                <td class="pr-3 py-1 text-right whitespace-nowrap">
                                                    @if ($e->entitlement_type === 'commission_override')
                                                        <x-ui.button variant="ghost" class="mr-2" wire:click="approveOverride({{ $e->id }})">{{ $e->is_approved ? 'Revoke approval' : 'Approve' }}</x-ui.button>
                                                    @endif
                                                    <x-ui.button variant="ghost" class="mr-2" wire:click="startEditEntitlement({{ $e->id }})">Edit</x-ui.button>
                                                    <x-ui.button variant="ghost" class="mr-2" wire:click="toggleTargets({{ $e->id }})">Targets</x-ui.button>
                                                    @if ($e->hasHistory())
                                                        <span class="text-[11px] text-gray-400" title="Has subscriber balances or usage history — cannot be deleted">in use</span>
                                                    @else
                                                        <x-ui.button variant="ghost" color="red" wire:click="deleteEntitlement({{ $e->id }})" wire:confirm="Remove this entitlement?">Remove</x-ui.button>
                                                    @endif
                                                </td>
                                            </tr>
                                        @empty
                                            <tr><td colspan="14" class="py-2 text-gray-400">No entitlements yet.</td></tr>
                                        @endforelse
                                    </tbody>
                                </table>

                                @if ($targetingEntitlementId && ($te = $p->entitlements->firstWhere('id', $targetingEntitlementId)))
                                    <div class="mb-4 rounded border border-blue-200 bg-white p-3" wire:key="targets-{{ $te->id }}">
                                        <h4 class="text-xs font-semibold mb-1">Eligible catalog targets — {{ $te->displayName() }}</h4>
                                        <p class="text-[11px] text-gray-500 mb-2">Maps this benefit onto the existing service catalog. A category or subcategory covers everything under it; mark a row "excluded" to carve a service out. @if ($te->redemption_effect === 'service_included')An included-service benefit with no covered target never applies.@endif</p>
                                        <ul class="mb-2 text-xs">
                                            @forelse ($te->targets as $t)
                                                <li class="flex items-center justify-between border-t py-1" wire:key="tgt-{{ $t->id }}">
                                                    <span>
                                                        <span @class(['text-red-600' => $t->is_excluded, 'text-green-700' => ! $t->is_excluded])>{{ $t->is_excluded ? 'Excluded' : 'Covered' }}</span>
                                                        — {{ $t->label() }}@if ($t->choice_key) <span class="text-gray-500">(choice: {{ $t->choice_key }})</span>@endif
                                                    </span>
                                                    <x-ui.button variant="ghost" color="red" wire:click="removeTarget({{ $t->id }})">Remove</x-ui.button>
                                                </li>
                                            @empty
                                                <li class="text-gray-400">No targets yet.</li>
                                            @endforelse
                                        </ul>
                                        <div class="grid grid-cols-5 gap-2 items-end">
                                            <div>
                                                <label class="block text-xs mb-1">Catalog level</label>
                                                <select wire:model.live="tgtType" class="w-full border rounded px-2 py-1.5 text-xs">
                                                    <option value="category">Category</option>
                                                    <option value="subcategory">Subcategory</option>
                                                    <option value="service">Service</option>
                                                </select>
                                            </div>
                                            <div class="col-span-2">
                                                <label class="block text-xs mb-1">Catalog item</label>
                                                <select wire:model="tgtId" class="w-full border rounded px-2 py-1.5 text-xs">
                                                    <option value="">— choose —</option>
                                                    @foreach ($targetOptions as $opt)
                                                        <option value="{{ $opt['id'] }}">{{ $opt['name'] }}</option>
                                                    @endforeach
                                                </select>
                                            </div>
                                            @if ($te->requiresCategoryChoice())
                                                <div>
                                                    <label class="block text-xs mb-1">Choice</label>
                                                    <select wire:model="tgtChoice" class="w-full border rounded px-2 py-1.5 text-xs">
                                                        <option value="">—</option>
                                                        @foreach ($te->redeem_categories as $choice)
                                                            <option value="{{ $choice }}">{{ $choice }}</option>
                                                        @endforeach
                                                    </select>
                                                </div>
                                            @endif
                                            <div class="flex items-center gap-1 pb-1.5">
                                                <input type="checkbox" wire:model="tgtExcluded" id="tgt-ex-{{ $te->id }}">
                                                <label for="tgt-ex-{{ $te->id }}" class="text-xs">Excluded</label>
                                            </div>
                                        </div>
                                        <x-ui.button size="sm" class="mt-2 !h-8" wire:click="addTarget">Add target</x-ui.button>
                                    </div>
                                @endif

                                @if ($editingEntitlementId)
                                    <p class="text-xs font-semibold text-blue-700 mb-2">Editing entitlement #{{ $editingEntitlementId }} — changes apply to future periods; existing balances are preserved.</p>
                                @endif
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
                                        <label class="block text-xs mb-1">Label</label>
                                        <input type="text" wire:model="entLabel" placeholder="Premium AC Jet Pump Service" class="w-full border rounded px-2 py-1.5 text-xs">
                                    </div>
                                    <div>
                                        <label class="block text-xs mb-1">Redeem categories</label>
                                        <input type="text" wire:model="entRedeemCategories" placeholder="electrical, plumbing, carpenter" class="w-full border rounded px-2 py-1.5 text-xs">
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
                                        <label class="block text-xs mb-1">Redemption effect</label>
                                        <select wire:model="entEffect" class="w-full border rounded px-2 py-1.5 text-xs">
                                            <option value="">Legacy discount rule</option>
                                            <option value="service_included">Service included (waives service price)</option>
                                            <option value="visit_fee_waiver">Visiting charge waived only</option>
                                        </select>
                                    </div>
                                    <div class="col-span-2">
                                        <label class="block text-xs mb-1">Benefit description (customer-facing)</label>
                                        <textarea wire:model="entDescription" rows="2" class="w-full border rounded px-2 py-1.5 text-xs"></textarea>
                                    </div>
                                    <div class="col-span-3">
                                        <label class="block text-xs mb-1">Covered examples — one per line ("# group" starts a group)</label>
                                        <textarea wire:model="entIncludes" rows="3" class="w-full border rounded px-2 py-1.5 text-xs"></textarea>
                                    </div>
                                    <div class="col-span-3">
                                        <label class="block text-xs mb-1">Not included — one per line ("# group" starts a group)</label>
                                        <textarea wire:model="entExcludes" rows="3" class="w-full border rounded px-2 py-1.5 text-xs"></textarea>
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
                                @if ($editingEntitlementId)
                                    <x-ui.button size="sm" class="mt-3 !h-8" wire:click="updateEntitlement">Save changes</x-ui.button>
                                    <x-ui.button size="sm" variant="ghost" class="mt-3 !h-8" wire:click="cancelEditEntitlement">Cancel</x-ui.button>
                                @else
                                    <x-ui.button size="sm" class="mt-3 !h-8" wire:click="addEntitlement">Add Entitlement</x-ui.button>
                                @endif
                            </td>
                        </tr>
                    @endif
                @empty
                    <tr><td colspan="9" class="px-4 py-6 text-center text-gray-400">No plans yet.</td></tr>
                @endforelse
            </tbody>
        </x-ui.table>
</div>
