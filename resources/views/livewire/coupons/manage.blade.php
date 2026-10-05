<div>
    <div class="flex items-center justify-between mb-1">
        <h1 class="text-2xl font-bold">Coupons</h1>
        @if ($canManage && ! $showForm)
            <button type="button" wire:click="newCoupon" class="px-4 py-2 text-sm rounded bg-slate-900 text-white">New coupon</button>
        @endif
    </div>
    <p class="text-sm text-gray-500 mb-4">
        Coupons are a benefit: they are valid for <strong>online payments only</strong> (Razorpay, wallet, or both). Cash bookings can never use one,
        and nothing on this screen can change that. Visit and inspection charges are separate from coupon discounts.
        Every change is audit-logged; bookings already made keep the rules they were created with.
    </p>

    @if ($flashMessage)
        <div @class(['rounded px-4 py-2 mb-4 text-sm', 'bg-green-50 text-green-700' => $flashType === 'success', 'bg-red-50 text-red-700' => $flashType === 'error'])>
            {{ $flashMessage }}
        </div>
    @endif

    <x-ui.card class="mb-6">
        <h2 class="text-sm font-semibold mb-2">Coupon settings</h2>
        <p class="text-xs text-gray-500 mb-3">
            Coupons are <strong>{{ $available ? 'AVAILABLE to customers' : 'OFF for customers' }}</strong>.
            They need the switch on <em>and</em> an unpaid-hold length. Blank hold = coupon bookings are not allowed.
        </p>
        @if ($isSuperAdmin)
            <form wire:submit="saveSettings" class="flex flex-wrap items-end gap-4">
                <label class="flex items-center gap-2 text-sm">
                    <input type="checkbox" wire:model="settingEnabled" class="rounded border-gray-300">
                    <span>Coupons enabled (<code>coupons.enabled</code>)</span>
                </label>
                <div>
                    <label class="block text-xs font-medium mb-1" for="cs-hold">Unpaid hold (minutes) (<code>coupons.unpaid_hold_minutes</code>)</label>
                    <input id="cs-hold" type="text" inputmode="numeric" wire:model="settingHoldMinutes" class="w-32 border rounded px-3 py-2 text-sm">
                    @error('settingHoldMinutes') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                </div>
                <button type="submit" class="px-4 py-2 text-sm rounded bg-slate-900 text-white">Save settings</button>
            </form>
        @else
            <p class="text-xs text-gray-500">Enabled: {{ $settingEnabled ? 'yes' : 'no' }} · Unpaid hold: {{ $settingHoldMinutes !== '' ? $settingHoldMinutes.' min' : 'not set' }}. Only a Super Admin can change these.</p>
        @endif
    </x-ui.card>

    @if ($showForm)
        <x-ui.card class="mb-6">
            <h2 class="text-sm font-semibold mb-3">{{ $editingId ? 'Edit coupon' : 'New coupon (saved as a draft)' }}</h2>
            <form wire:submit="save" class="space-y-5">
                <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
                    <div>
                        <label class="block text-xs font-medium mb-1" for="cf-code">Code</label>
                        <input id="cf-code" type="text" wire:model="code" class="w-full border rounded px-3 py-2 text-sm uppercase">
                        @error('code') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                    </div>
                    <div class="md:col-span-2">
                        <label class="block text-xs font-medium mb-1" for="cf-name">Internal name</label>
                        <input id="cf-name" type="text" wire:model="name" class="w-full border rounded px-3 py-2 text-sm">
                        @error('name') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="block text-xs font-medium mb-1" for="cf-tag">Campaign tag (optional)</label>
                        <input id="cf-tag" type="text" wire:model="campaignTag" class="w-full border rounded px-3 py-2 text-sm">
                        @error('campaignTag') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                    </div>
                    <div class="col-span-2 md:col-span-4">
                        <label class="block text-xs font-medium mb-1" for="cf-desc">Description (optional)</label>
                        <input id="cf-desc" type="text" wire:model="description" class="w-full border rounded px-3 py-2 text-sm">
                    </div>
                </div>

                <div>
                    <h3 class="text-xs font-semibold text-gray-500 uppercase mb-2">Discount</h3>
                    <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
                        <div>
                            <label class="block text-xs font-medium mb-1" for="cf-type">Type</label>
                            <select id="cf-type" wire:model="discountType" class="w-full border rounded px-3 py-2 text-sm"><option value="percent">Percent</option><option value="flat">Flat (₹)</option></select>
                        </div>
                        <div>
                            <label class="block text-xs font-medium mb-1" for="cf-value">Value</label>
                            <input id="cf-value" type="text" inputmode="decimal" wire:model="value" class="w-full border rounded px-3 py-2 text-sm">
                            @error('value') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="block text-xs font-medium mb-1" for="cf-max">Max discount ₹ (optional)</label>
                            <input id="cf-max" type="text" inputmode="decimal" wire:model="maxDiscount" class="w-full border rounded px-3 py-2 text-sm">
                            @error('maxDiscount') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="block text-xs font-medium mb-1" for="cf-min">Minimum order ₹</label>
                            <input id="cf-min" type="text" inputmode="decimal" wire:model="minOrderValue" class="w-full border rounded px-3 py-2 text-sm">
                            @error('minOrderValue') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                        </div>
                    </div>
                </div>

                <div>
                    <h3 class="text-xs font-semibold text-gray-500 uppercase mb-2">Limits and caps (blank = no cap)</h3>
                    <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
                        <div>
                            <label class="block text-xs font-medium mb-1" for="cf-ul">Total uses</label>
                            <input id="cf-ul" type="text" inputmode="numeric" wire:model="usageLimit" class="w-full border rounded px-3 py-2 text-sm">
                            @error('usageLimit') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="block text-xs font-medium mb-1" for="cf-pu">Uses per customer (required)</label>
                            <input id="cf-pu" type="text" inputmode="numeric" wire:model="perUserLimit" class="w-full border rounded px-3 py-2 text-sm">
                            @error('perUserLimit') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="block text-xs font-medium mb-1" for="cf-tb">Total budget ₹</label>
                            <input id="cf-tb" type="text" inputmode="decimal" wire:model="totalBudget" class="w-full border rounded px-3 py-2 text-sm">
                            @error('totalBudget') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="block text-xs font-medium mb-1" for="cf-db">Daily cap ₹ (resets 00:00 IST)</label>
                            <input id="cf-db" type="text" inputmode="decimal" wire:model="dailyBudget" class="w-full border rounded px-3 py-2 text-sm">
                            @error('dailyBudget') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="block text-xs font-medium mb-1" for="cf-vf">Valid from</label>
                            <input id="cf-vf" type="datetime-local" wire:model="validFrom" class="w-full border rounded px-3 py-2 text-sm">
                        </div>
                        <div>
                            <label class="block text-xs font-medium mb-1" for="cf-vu">Valid until</label>
                            <input id="cf-vu" type="datetime-local" wire:model="validUntil" class="w-full border rounded px-3 py-2 text-sm">
                            @error('validUntil') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                        </div>
                        <div class="col-span-2 flex items-end gap-6">
                            <label class="flex items-center gap-2 text-sm"><input type="checkbox" wire:model="stackableWithFlash" class="rounded border-gray-300"> Stackable with flash-sale price</label>
                            <span class="text-xs text-gray-500">Funding: <strong>HQ</strong></span>
                        </div>
                    </div>
                </div>

                <div>
                    <h3 class="text-xs font-semibold text-gray-500 uppercase mb-2">Targeting (a coupon must name a city, or be explicitly global)</h3>
                    <div class="mb-3">
                        <label class="flex items-center gap-2 text-sm">
                            <input type="checkbox" wire:model="globalScope" id="ct-global" class="rounded border-gray-300" @disabled(! $canApprove)>
                            Global / all eligible scope (every live franchise; needs approval permission)
                        </label>
                        @error('targets') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                    </div>
                    <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
                        <div>
                            <label class="block text-xs font-medium mb-1" for="ct-city">Cities</label>
                            <select id="ct-city" multiple wire:model="cityIds" class="w-full border rounded px-2 py-1 text-sm h-32">
                                @foreach ($cities as $c)<option value="{{ $c->id }}">{{ $c->name }}</option>@endforeach
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-medium mb-1" for="ct-cat">Categories</label>
                            <select id="ct-cat" multiple wire:model="categoryIds" class="w-full border rounded px-2 py-1 text-sm h-32">
                                @foreach ($categories as $c)<option value="{{ $c->id }}">{{ $c->name }}</option>@endforeach
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-medium mb-1" for="ct-sub">Subcategories</label>
                            <select id="ct-sub" multiple wire:model="subcategoryIds" class="w-full border rounded px-2 py-1 text-sm h-32">
                                @foreach ($subcategories as $c)<option value="{{ $c->id }}">{{ $c->name }}</option>@endforeach
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-medium mb-1" for="ct-svc">Services</label>
                            <select id="ct-svc" multiple wire:model="serviceIds" class="w-full border rounded px-2 py-1 text-sm h-32">
                                @foreach ($services as $c)<option value="{{ $c->id }}">{{ $c->name }}</option>@endforeach
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-medium mb-1" for="ct-cust">Customers</label>
                            <select id="ct-cust" wire:model="customerType" class="w-full border rounded px-3 py-2 text-sm">
                                <option value="">All customers</option>
                                <option value="new">New customers only</option>
                                <option value="returning">Returning customers only</option>
                                <option value="prime">Prime members only</option>
                            </select>
                        </div>
                    </div>
                </div>

                <div class="flex gap-2">
                    <button type="submit" class="px-4 py-2 text-sm rounded bg-slate-900 text-white">Save coupon</button>
                    <button type="button" wire:click="cancelForm" class="px-4 py-2 text-sm rounded border">Cancel</button>
                </div>
            </form>
        </x-ui.card>
    @endif

    @if ($actionCouponId)
        <x-ui.card class="mb-6 border border-amber-300">
            <h2 class="text-sm font-semibold mb-2">
                {{ ['activate' => 'Activate / resume coupon', 'pause' => 'Pause coupon', 'archive' => 'Archive coupon'][$actionType] ?? '' }}
            </h2>
            <form wire:submit="confirmAction" class="flex flex-wrap items-end gap-3">
                <div class="grow max-w-xl">
                    <label class="block text-xs font-medium mb-1" for="ca-reason">Reason (required, audit-logged)</label>
                    <input id="ca-reason" type="text" wire:model="actionReason" class="w-full border rounded px-3 py-2 text-sm">
                    @error('actionReason') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                </div>
                <button type="submit" class="px-4 py-2 text-sm rounded bg-slate-900 text-white">Confirm</button>
                <button type="button" wire:click="cancelAction" class="px-4 py-2 text-sm rounded border">Back</button>
            </form>
        </x-ui.card>
    @endif

    <x-ui.card>
        @if ($rows->isEmpty())
            <p class="text-sm text-gray-500">No coupons yet.</p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-xs text-gray-500 uppercase border-b">
                            <th class="py-2 pr-3">Code</th>
                            <th class="py-2 pr-3">Status</th>
                            <th class="py-2 pr-3">Discount</th>
                            <th class="py-2 pr-3">Campaign</th>
                            <th class="py-2 pr-3">Uses (reserved / confirmed / released / consumed)</th>
                            <th class="py-2 pr-3">Spent / total budget</th>
                            <th class="py-2 pr-3">Today / daily cap</th>
                            <th class="py-2"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $r)
                            @php($c = $r['coupon'])
                            <tr class="border-b align-top" wire:key="coupon-{{ $c->id }}">
                                <td class="py-2 pr-3"><span class="font-mono font-semibold">{{ $c->code }}</span><div class="text-xs text-gray-500">{{ $c->name }}</div></td>
                                <td class="py-2 pr-3">{{ ucfirst($c->status) }}</td>
                                <td class="py-2 pr-3">{{ $c->discount_type === 'percent' ? rtrim(rtrim(number_format((float) $c->value, 2), '0'), '.').'%' : '₹'.number_format((float) $c->value, 2) }}
                                    @if ($c->max_discount !== null)<div class="text-xs text-gray-500">max ₹{{ number_format((float) $c->max_discount, 2) }}</div>@endif
                                </td>
                                <td class="py-2 pr-3">{{ $c->campaign_tag ?: '—' }}</td>
                                <td class="py-2 pr-3">{{ $r['reserved_n'] }} / {{ $r['confirmed_n'] }} / {{ $r['released_n'] }} / {{ $r['consumed_n'] }}
                                    @if ($c->usage_limit !== null)<div class="text-xs text-gray-500">limit {{ $c->usage_limit }}</div>@endif
                                </td>
                                <td class="py-2 pr-3">₹{{ number_format($r['spent'], 2) }} / {{ $c->total_budget !== null ? '₹'.number_format((float) $c->total_budget, 2) : 'no cap' }}</td>
                                <td class="py-2 pr-3">₹{{ number_format($r['today'], 2) }} / {{ $c->daily_budget !== null ? '₹'.number_format((float) $c->daily_budget, 2) : 'no cap' }}</td>
                                <td class="py-2 text-right whitespace-nowrap">
                                    @if ($canManage && ($c->status !== 'active' || $canApprove))
                                        <button type="button" wire:click="edit({{ $c->id }})" class="text-xs text-blue-700 mr-2">Edit</button>
                                    @endif
                                    @if ($c->status === 'active' && $canManage)
                                        <button type="button" wire:click="startAction({{ $c->id }}, 'pause')" class="text-xs text-amber-700 mr-2">Pause</button>
                                    @elseif ($c->status !== 'active' && $canApprove)
                                        <button type="button" wire:click="startAction({{ $c->id }}, 'activate')" class="text-xs text-green-700 mr-2">{{ $c->status === 'draft' ? 'Activate' : 'Resume' }}</button>
                                    @endif
                                    @if ($canManage)
                                        <button type="button" wire:click="startAction({{ $c->id }}, 'archive')" class="text-xs text-red-700">Archive</button>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-ui.card>
</div>
