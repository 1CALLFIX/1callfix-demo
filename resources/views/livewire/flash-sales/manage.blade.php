<div>
    <h1 class="text-2xl font-bold mb-4">Flash Sales</h1>

    @if ($flashMessage)
        <div @class(['rounded px-4 py-2 mb-4 text-sm', 'bg-green-50 text-green-700' => $flashType === 'success', 'bg-red-50 text-red-700' => $flashType === 'error'])>
            {{ $flashMessage }}
        </div>
    @endif

    <div class="flex gap-2 mb-4 border-b">
        <button type="button" wire:click="setSection('sales')" @class(['px-4 py-2 text-sm font-medium border-b-2', 'border-slate-900 text-slate-900' => $section === 'sales', 'border-transparent text-gray-400' => $section !== 'sales'])>Sales</button>
        <button type="button" wire:click="setSection('redemptions')" @class(['px-4 py-2 text-sm font-medium border-b-2', 'border-slate-900 text-slate-900' => $section === 'redemptions', 'border-transparent text-gray-400' => $section !== 'redemptions'])>Redemptions</button>
    </div>

    @if ($section === 'sales')
        <div class="bg-white rounded-lg shadow-sm p-4 mb-6">
            <h2 class="text-sm font-semibold text-gray-500 uppercase mb-3">New flash sale (draft)</h2>
            <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
                <div><label class="block text-xs font-medium mb-1">Internal name</label><input type="text" wire:model="name" class="w-full border rounded px-3 py-2 text-sm">@error('name')<p class="text-red-600 text-xs mt-1">{{ $message }}</p>@enderror</div>
                <div><label class="block text-xs font-medium mb-1">Customer-facing title</label><input type="text" wire:model="customerTitle" class="w-full border rounded px-3 py-2 text-sm">@error('customerTitle')<p class="text-red-600 text-xs mt-1">{{ $message }}</p>@enderror</div>
                <div><label class="block text-xs font-medium mb-1">Type</label>
                    <select wire:model="type" class="w-full border rounded px-3 py-2 text-sm">
                        <option value="urgent_sale">Urgent sale</option>
                        <option value="festival_sale">Festival sale</option>
                        <option value="upcoming_sale">Upcoming sale</option>
                        <option value="stock_clearance">Stock clearance</option>
                        <option value="weekend_sale">Weekend sale</option>
                        <option value="last_minute_sale">Last-minute sale</option>
                        <option value="launch_promotion">Launch promotion</option>
                        <option value="service_slot_clearance">Service-slot clearance</option>
                    </select>
                </div>
                <div><label class="block text-xs font-medium mb-1">Badge (optional)</label>
                    <select wire:model="badgeId" class="w-full border rounded px-3 py-2 text-sm">
                        <option value="">None</option>
                        @foreach ($badges as $b)<option value="{{ $b->id }}">{{ $b->label }}</option>@endforeach
                    </select>
                </div>
                <div><label class="block text-xs font-medium mb-1">Discount type</label>
                    <select wire:model="discountType" class="w-full border rounded px-3 py-2 text-sm"><option value="percent">Percent</option><option value="flat">Flat</option></select>
                </div>
                <div><label class="block text-xs font-medium mb-1">Discount value</label><input type="number" step="0.01" wire:model="discountValue" class="w-full border rounded px-3 py-2 text-sm">@error('discountValue')<p class="text-red-600 text-xs mt-1">{{ $message }}</p>@enderror</div>
                <div><label class="block text-xs font-medium mb-1">Max discount (optional)</label><input type="number" step="0.01" wire:model="maxDiscount" class="w-full border rounded px-3 py-2 text-sm"></div>
                <div><label class="block text-xs font-medium mb-1">Min final price</label><input type="number" step="0.01" wire:model="minFinalPrice" class="w-full border rounded px-3 py-2 text-sm"></div>
                <div><label class="block text-xs font-medium mb-1">Total quantity limit</label><input type="number" wire:model="totalQuantityLimit" placeholder="Unlimited" class="w-full border rounded px-3 py-2 text-sm"></div>
                <div><label class="block text-xs font-medium mb-1">Per-customer limit</label><input type="number" wire:model="perCustomerLimit" placeholder="Unlimited" class="w-full border rounded px-3 py-2 text-sm"></div>
                <div><label class="block text-xs font-medium mb-1">Scope</label>
                    <select wire:model.live="scopeType" class="w-full border rounded px-3 py-2 text-sm">
                        <option value="global">Global</option><option value="country">Country</option><option value="city">City</option><option value="franchise">Franchise</option><option value="zone">Zone</option>
                    </select>
                </div>
            </div>
            @if ($scopeType === 'franchise')
                <div class="mt-3"><select wire:model="scopeFranchiseId" class="border rounded px-3 py-2 text-sm"><option value="">Select franchise…</option>@foreach ($franchises as $f)<option value="{{ $f->id }}">{{ $f->name }}</option>@endforeach</select></div>
            @elseif ($scopeType === 'zone')
                <div class="mt-3"><select wire:model="scopeFranchiseId" class="border rounded px-3 py-2 text-sm mr-2"><option value="">Franchise…</option>@foreach ($franchises as $f)<option value="{{ $f->id }}">{{ $f->name }}</option>@endforeach</select>
                <select wire:model="scopeZoneId" class="border rounded px-3 py-2 text-sm"><option value="">Zone…</option>@foreach ($zones as $z)<option value="{{ $z->id }}">{{ $z->name }}</option>@endforeach</select></div>
            @endif
            <div class="flex justify-end pt-4 mt-4 border-t">
                <button type="button" wire:click="create" class="bg-slate-900 text-white px-6 py-2 rounded text-sm font-medium hover:bg-slate-800">Create Draft</button>
            </div>
        </div>

        <div class="space-y-3">
            @foreach ($sales as $sale)
                <div class="bg-white rounded-lg shadow-sm p-4">
                    <div class="flex items-center justify-between">
                        <div>
                            <span class="font-semibold">{{ $sale->name }}</span>
                            <span class="text-gray-400 text-xs ml-2">{{ $sale->customer_title }}</span>
                            <span @class(['px-2 py-0.5 rounded text-xs ml-2', 'bg-gray-100 text-gray-600' => $sale->status === 'draft', 'bg-blue-100 text-blue-700' => $sale->status === 'scheduled', 'bg-green-100 text-green-700' => $sale->status === 'live', 'bg-amber-100 text-amber-700' => $sale->status === 'paused', 'bg-slate-200 text-slate-600' => $sale->status === 'completed', 'bg-red-100 text-red-700' => $sale->status === 'cancelled'])>{{ ucfirst($sale->status) }}</span>
                            @if ($sale->is_currently_active)<span class="px-2 py-0.5 rounded text-xs ml-1 bg-emerald-600 text-white">Currently active</span>@endif
                        </div>
                        <div class="text-sm text-gray-500">
                            {{ $sale->discount_type === 'percent' ? $sale->discount_value.'% off' : $currencySymbol.number_format($sale->discount_value, 2).' off' }}
                            @if ($sale->remaining_quantity !== null) · {{ $sale->remaining_quantity }} left @endif
                        </div>
                    </div>
                    <div class="text-xs text-gray-400 mt-1">
                        {{ $sale->starts_at?->format('d M Y, h:i A') ?? 'Not scheduled' }} → {{ $sale->ends_at?->format('d M Y, h:i A') ?? '—' }}
                        · Scope: {{ ucfirst($sale->scope_type) }}@if($sale->scope_id) #{{ $sale->scope_id }}@endif
                        · Targets: {{ $sale->targets->count() }}
                    </div>

                    <div class="flex gap-2 mt-3 flex-wrap">
                        @if ($sale->status === 'draft')
                            <button type="button" wire:click="startScheduling({{ $sale->id }})" class="text-blue-600 hover:underline text-xs">Schedule</button>
                        @endif
                        @if (in_array($sale->status, ['scheduled', 'paused']))
                            <button type="button" wire:click="lifecycleAction({{ $sale->id }}, 'goLive')" class="text-green-600 hover:underline text-xs">Go Live Now</button>
                        @endif
                        @if (in_array($sale->status, ['scheduled', 'live']))
                            <button type="button" wire:click="lifecycleAction({{ $sale->id }}, 'pause')" class="text-amber-600 hover:underline text-xs">Pause</button>
                        @endif
                        @if ($sale->status === 'paused')
                            <button type="button" wire:click="lifecycleAction({{ $sale->id }}, 'resume')" class="text-blue-600 hover:underline text-xs">Resume</button>
                        @endif
                        @if ($sale->status === 'live')
                            <button type="button" wire:click="lifecycleAction({{ $sale->id }}, 'complete')" wire:confirm="End this sale now?" class="text-slate-600 hover:underline text-xs">End Now</button>
                        @endif
                        @if (! in_array($sale->status, ['completed', 'cancelled']))
                            <button type="button" wire:click="lifecycleAction({{ $sale->id }}, 'cancel')" wire:confirm="Cancel this flash sale?" class="text-red-600 hover:underline text-xs">Cancel</button>
                        @endif
                        <button type="button" wire:click="expandTargets({{ $sale->id }})" class="text-gray-500 hover:underline text-xs">{{ $expandedSaleId === $sale->id ? 'Hide targets' : 'Manage targets' }}</button>
                    </div>

                    @if ($schedulingSaleId === $sale->id)
                        <div class="mt-3 pt-3 border-t grid grid-cols-2 gap-3">
                            <div><label class="block text-xs font-medium mb-1">Starts at</label><input type="datetime-local" wire:model="scheduleStartsAt" class="w-full border rounded px-3 py-2 text-sm">@error('scheduleStartsAt')<p class="text-red-600 text-xs mt-1">{{ $message }}</p>@enderror</div>
                            <div><label class="block text-xs font-medium mb-1">Ends at</label><input type="datetime-local" wire:model="scheduleEndsAt" class="w-full border rounded px-3 py-2 text-sm">@error('scheduleEndsAt')<p class="text-red-600 text-xs mt-1">{{ $message }}</p>@enderror</div>
                            <div class="col-span-2"><button type="button" wire:click="schedule" class="bg-slate-900 text-white px-4 py-1.5 rounded text-xs">Confirm Schedule</button></div>
                        </div>
                    @endif

                    @if ($expandedSaleId === $sale->id)
                        <div class="mt-3 pt-3 border-t">
                            <div class="flex flex-wrap gap-2 mb-2">
                                @foreach ($sale->targets as $t)
                                    <span class="px-2 py-1 bg-gray-100 rounded text-xs flex items-center gap-1">
                                        {{ $t->service->name ?? "#{$t->service_id}" }}
                                        <button type="button" wire:click="removeTarget({{ $t->id }})" class="text-red-500">×</button>
                                    </span>
                                @endforeach
                            </div>
                            <div class="relative w-64">
                                <input type="text" wire:model.live.debounce.300ms="targetServiceSearch" placeholder="Search service to add…" class="w-full border rounded px-3 py-1.5 text-sm">
                                @error('targetServiceId')<p class="text-red-600 text-xs mt-1">{{ $message }}</p>@enderror
                                @if ($this->matchingTargetServices->isNotEmpty())
                                    <div class="absolute z-10 bg-white border rounded shadow-sm w-full mt-1">
                                        @foreach ($this->matchingTargetServices as $s)
                                            <button type="button" wire:click="selectTargetService({{ $s->id }})" class="block w-full text-left px-3 py-1.5 text-sm hover:bg-gray-50">{{ $s->name }}</button>
                                        @endforeach
                                    </div>
                                @endif
                            </div>
                            <button type="button" wire:click="addTarget" class="mt-2 text-blue-600 hover:underline text-xs">Add service</button>
                        </div>
                    @endif
                </div>
            @endforeach
        </div>
    @endif

    @if ($section === 'redemptions')
        <div class="bg-white rounded-lg shadow-sm overflow-hidden">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 text-left text-gray-500">
                    <tr><th class="px-4 py-2">Sale</th><th class="px-4 py-2">Service</th><th class="px-4 py-2">Customer</th><th class="px-4 py-2">Original</th><th class="px-4 py-2">Final</th><th class="px-4 py-2">Discount</th><th class="px-4 py-2">Date</th></tr>
                </thead>
                <tbody>
                    @forelse ($redemptions as $r)
                        <tr class="border-t hover:bg-gray-50">
                            <td class="px-4 py-2">{{ $r->flashSale->name ?? '—' }}</td>
                            <td class="px-4 py-2">{{ $r->service->name ?? '—' }}</td>
                            <td class="px-4 py-2">{{ $r->user->name ?? '—' }}</td>
                            <td class="px-4 py-2 font-mono">{{ $currencySymbol }}{{ number_format($r->original_price, 2) }}</td>
                            <td class="px-4 py-2 font-mono">{{ $currencySymbol }}{{ number_format($r->final_price, 2) }}</td>
                            <td class="px-4 py-2 font-mono text-green-700">{{ $currencySymbol }}{{ number_format($r->discount_applied, 2) }}</td>
                            <td class="px-4 py-2 text-gray-500 whitespace-nowrap">{{ app(\App\Services\TimezoneResolver::class)->format($r->created_at, $r->user?->franchise, 'd M Y, h:i A') }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="px-4 py-6 text-center text-gray-400">No redemptions yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    @endif
</div>
