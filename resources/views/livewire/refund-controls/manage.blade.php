<div class="max-w-3xl">
    <h1 class="text-2xl font-bold mb-1">Refund Controls</h1>
    <p class="text-sm text-gray-500 mb-4">
        Limits, second-approval threshold, escalation and customer messages for mismatch refunds.
        Leave a field blank to leave it <strong>not configured</strong>. Super Admin only; every change is audit-logged.
    </p>

    @if ($flashMessage)
        <div @class(['rounded px-4 py-2 mb-4 text-sm', 'bg-green-50 text-green-700' => $flashType === 'success', 'bg-red-50 text-red-700' => $flashType === 'error'])>
            {{ $flashMessage }}
        </div>
    @endif

    <form wire:submit="save" class="space-y-6">
        <x-ui.card>
            <h2 class="text-sm font-semibold mb-3">Approval limits (₹)</h2>
            <p class="text-xs text-gray-500 mb-3">
                The most each level may refund in one payment. Blank = that level cannot approve any refund.
                Anything above the HQ limit can only be approved by a Super Admin.
            </p>
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs font-medium mb-1" for="rc-fl">Franchise limit</label>
                    <input id="rc-fl" type="text" inputmode="decimal" wire:model="franchiseLimit" class="w-full rounded border-gray-300 text-sm">
                    @error('franchiseLimit') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-xs font-medium mb-1" for="rc-hq">HQ limit</label>
                    <input id="rc-hq" type="text" inputmode="decimal" wire:model="hqLimit" class="w-full rounded border-gray-300 text-sm">
                    @error('hqLimit') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                </div>
            </div>
        </x-ui.card>

        <x-ui.card>
            <h2 class="text-sm font-semibold mb-3">Second approval and escalation</h2>
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs font-medium mb-1" for="rc-dual">Second approval above (₹)</label>
                    <input id="rc-dual" type="text" inputmode="decimal" wire:model="dualApprovalAbove" class="w-full rounded border-gray-300 text-sm">
                    <p class="text-xs text-gray-500 mt-1">Above this amount a different user must approve. Blank = off.</p>
                    @error('dualApprovalAbove') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-xs font-medium mb-1" for="rc-esc">Escalate after (hours)</label>
                    <input id="rc-esc" type="text" inputmode="numeric" wire:model="escalateAfterHours" class="w-full rounded border-gray-300 text-sm">
                    <p class="text-xs text-gray-500 mt-1">Alert the next level up once per level. Blank = off.</p>
                    @error('escalateAfterHours') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                </div>
            </div>
        </x-ui.card>

        <x-ui.card class="mb-6">
            <h2 class="text-sm font-semibold mb-3">Shop return refunds (₹)</h2>
            <p class="text-xs text-gray-500 mb-3">Same model for refunds of shop returns (damaged / wrong item). Blank limit = that level cannot approve; blank threshold / escalation = off.</p>
            <div class="grid gap-4 sm:grid-cols-2">
                <label class="block text-sm"><span class="block text-xs font-medium mb-1">Franchise limit</span>
                    <input type="text" inputmode="decimal" wire:model="retFranchiseLimit" class="w-full rounded border-gray-300 text-sm">
                    @error('retFranchiseLimit') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror</label>
                <label class="block text-sm"><span class="block text-xs font-medium mb-1">HQ limit</span>
                    <input type="text" inputmode="decimal" wire:model="retHqLimit" class="w-full rounded border-gray-300 text-sm">
                    @error('retHqLimit') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror</label>
                <label class="block text-sm"><span class="block text-xs font-medium mb-1">Second approval above</span>
                    <input type="text" inputmode="decimal" wire:model="retDualApprovalAbove" class="w-full rounded border-gray-300 text-sm">
                    @error('retDualApprovalAbove') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror</label>
                <label class="block text-sm"><span class="block text-xs font-medium mb-1">Escalate after (hours)</span>
                    <input type="text" inputmode="numeric" wire:model="retEscalateAfterHours" class="w-full rounded border-gray-300 text-sm">
                    @error('retEscalateAfterHours') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror</label>
            </div>
        </x-ui.card>

        <x-ui.card>
            <h2 class="text-sm font-semibold mb-3">Pricing-dispute refunds (₹)</h2>
            <p class="text-xs text-gray-500 mb-3">
                Same model for refunds decided in a customer's pricing dispute. Blank limit = that level cannot approve; blank threshold / escalation = off.
                Refunds are credited to the customer's wallet, never moved by franchise users.
            </p>
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs font-medium mb-1" for="rc-dfl">Franchise limit</label>
                    <input id="rc-dfl" type="text" inputmode="decimal" wire:model="dispFranchiseLimit" class="w-full rounded border-gray-300 text-sm">
                    @error('dispFranchiseLimit') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-xs font-medium mb-1" for="rc-dhq">HQ limit</label>
                    <input id="rc-dhq" type="text" inputmode="decimal" wire:model="dispHqLimit" class="w-full rounded border-gray-300 text-sm">
                    @error('dispHqLimit') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-xs font-medium mb-1" for="rc-ddual">Second approval above</label>
                    <input id="rc-ddual" type="text" inputmode="decimal" wire:model="dispDualApprovalAbove" class="w-full rounded border-gray-300 text-sm">
                    @error('dispDualApprovalAbove') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-xs font-medium mb-1" for="rc-desc">Escalate after (hours)</label>
                    <input id="rc-desc" type="text" inputmode="numeric" wire:model="dispEscalateAfterHours" class="w-full rounded border-gray-300 text-sm">
                    @error('dispEscalateAfterHours') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                </div>
            </div>
        </x-ui.card>

        <x-ui.card>
            <h2 class="text-sm font-semibold mb-3">Charge waivers (₹)</h2>
            <p class="text-xs text-gray-500 mb-3">
                Waiving a charge the customer owes: a cancellation charge, an admin-cancel fee, or a lowered work-done amount. Needs the
                "Waive a customer charge" permission. Blank limit = that level cannot approve (only Super Admin can); blank threshold / escalation = off.
            </p>
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs font-medium mb-1" for="rc-wfl">Franchise limit</label>
                    <input id="rc-wfl" type="text" inputmode="decimal" wire:model="waiveFranchiseLimit" class="w-full rounded border-gray-300 text-sm">
                    @error('waiveFranchiseLimit') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-xs font-medium mb-1" for="rc-whq">HQ limit</label>
                    <input id="rc-whq" type="text" inputmode="decimal" wire:model="waiveHqLimit" class="w-full rounded border-gray-300 text-sm">
                    @error('waiveHqLimit') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-xs font-medium mb-1" for="rc-wdual">Second approval above</label>
                    <input id="rc-wdual" type="text" inputmode="decimal" wire:model="waiveDualApprovalAbove" class="w-full rounded border-gray-300 text-sm">
                    @error('waiveDualApprovalAbove') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-xs font-medium mb-1" for="rc-wesc">Escalate after (hours)</label>
                    <input id="rc-wesc" type="text" inputmode="numeric" wire:model="waiveEscalateAfterHours" class="w-full rounded border-gray-300 text-sm">
                    @error('waiveEscalateAfterHours') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                </div>
            </div>
        </x-ui.card>

        <x-ui.card>
            <h2 class="text-sm font-semibold mb-3">Customer messages</h2>
            <div class="space-y-3">
                <div>
                    <label class="block text-xs font-medium mb-1" for="rc-n1">When a payment is held for review</label>
                    <textarea id="rc-n1" rows="2" wire:model="noticeUnderReview" class="w-full rounded border-gray-300 text-sm"></textarea>
                    @error('noticeUnderReview') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-xs font-medium mb-1" for="rc-n2">When the refund is processed (keep [amount])</label>
                    <textarea id="rc-n2" rows="2" wire:model="noticeRefunded" class="w-full rounded border-gray-300 text-sm"></textarea>
                    @error('noticeRefunded') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                </div>
            </div>
        </x-ui.card>

        <x-ui.button type="submit">Save refund controls</x-ui.button>
    </form>
</div>
