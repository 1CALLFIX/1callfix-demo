@props(['type' => ''])
{{-- REF 1CF-BACKUP-RESTORE-001 — deleted rows of this screen are restored from Backup & Restore > Deleted items. --}}
@if (auth()->user()?->hasPermissionAnywhere('backups.restore'))
    <a href="{{ route('admin.operations.backup-restore', array_filter(['tab' => 'deleted', 'type' => $type])) }}" wire:navigate
       {{ $attributes->merge(['class' => 'inline-block text-xs text-indigo-700 hover:underline mb-3']) }}>Deleted items: restore →</a>
@endif
