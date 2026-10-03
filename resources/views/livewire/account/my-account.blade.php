<div class="max-w-2xl">
    <h1 class="text-2xl font-bold mb-1">My account</h1>
    <p class="text-sm text-gray-500 mb-4">
        Change your own sign-in details. You sign in with your <strong>email and password</strong>.
        Every change asks for your current password.
    </p>

    @if ($flashMessage)
        <div @class(['rounded p-3 mb-4 text-sm', 'bg-green-50 text-green-700' => $flashType === 'success', 'bg-red-50 text-red-700' => $flashType === 'error'])>
            {{ $flashMessage }}
        </div>
    @endif

    <x-ui.card class="mb-6">
        <h2 class="text-sm font-semibold mb-3">Details</h2>
        <form wire:submit="saveProfile" class="space-y-3">
            <div>
                <label class="block text-xs font-medium mb-1" for="acc-name">Name</label>
                <input id="acc-name" type="text" wire:model="name" class="w-full rounded border-gray-300 text-sm">
                @error('name') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="block text-xs font-medium mb-1" for="acc-email">Email (your login)</label>
                <input id="acc-email" type="email" wire:model="email" class="w-full rounded border-gray-300 text-sm">
                @error('email') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="block text-xs font-medium mb-1" for="acc-email2">Confirm new email (only when you change it)</label>
                <input id="acc-email2" type="email" wire:model="emailConfirmation" autocomplete="off" class="w-full rounded border-gray-300 text-sm">
                @error('emailConfirmation') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="block text-xs font-medium mb-1" for="acc-phone">Phone (10 digits)</label>
                <input id="acc-phone" type="text" wire:model="phone" class="w-full rounded border-gray-300 text-sm">
                @error('phone') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="block text-xs font-medium mb-1" for="acc-pw1">Current password</label>
                <input id="acc-pw1" type="password" wire:model="profileCurrentPassword" autocomplete="current-password" class="w-full rounded border-gray-300 text-sm">
                @error('profileCurrentPassword') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
            </div>
            <button type="submit" class="rounded bg-slate-800 px-4 py-2 text-sm font-semibold text-white">Save details</button>
        </form>
    </x-ui.card>

    <x-ui.card>
        <h2 class="text-sm font-semibold mb-3">Change password</h2>
        <form wire:submit="changePassword" class="space-y-3">
            <div>
                <label class="block text-xs font-medium mb-1" for="acc-pw2">Current password</label>
                <input id="acc-pw2" type="password" wire:model="currentPassword" autocomplete="current-password" class="w-full rounded border-gray-300 text-sm">
                @error('currentPassword') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="block text-xs font-medium mb-1" for="acc-new">New password (at least 10 characters)</label>
                <input id="acc-new" type="password" wire:model="newPassword" autocomplete="new-password" class="w-full rounded border-gray-300 text-sm">
                @error('newPassword') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="block text-xs font-medium mb-1" for="acc-conf">Confirm new password</label>
                <input id="acc-conf" type="password" wire:model="newPasswordConfirmation" autocomplete="new-password" class="w-full rounded border-gray-300 text-sm">
                @error('newPasswordConfirmation') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
            </div>
            <button type="submit" class="rounded bg-slate-800 px-4 py-2 text-sm font-semibold text-white">Change password</button>
        </form>
    </x-ui.card>

    @if ($isSuperAdmin)
        <x-ui.card class="mt-6">
            <h2 class="text-sm font-semibold mb-1">Login-change notice (Super Admin)</h2>
            <p class="text-xs text-gray-500 mb-3">
                Emailed to an admin's <strong>old</strong> email address whenever their email or password changes.
                Leave blank to use the default wording. Changes are audit-logged.
            </p>
            <form wire:submit="saveNotice" class="space-y-2">
                <textarea wire:model="noticeText" rows="3" class="w-full rounded border-gray-300 text-sm"></textarea>
                @error('noticeText') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                <button type="submit" class="rounded bg-slate-800 px-4 py-2 text-sm font-semibold text-white">Save notice text</button>
            </form>
        </x-ui.card>
    @endif
</div>
