<div>
    <div class="mb-4">
        <h1 class="text-2xl font-bold">Partners</h1>
        <p class="text-sm text-gray-500">Everyone who works with {{ \App\Models\Setting::get('branding.platform_name', '1CallFix') }}. Partners join on the partner page; review their documents here.</p>
    </div>

    <nav aria-label="Partner groups" class="mb-4 flex gap-1 border-b">
        @foreach ($tabs as $key => [$label])
            <a href="{{ route('admin.partners.index', ['tab' => $key]) }}" wire:navigate
               @if ($tab === $key) aria-current="page" @endif
               @class(['-mb-px inline-flex items-center gap-2 border-b-2 px-4 py-2 text-sm font-medium',
                       'border-blue-600 text-blue-700' => $tab === $key,
                       'border-transparent text-gray-600 hover:text-gray-900' => $tab !== $key])>
                {{ $label }}
                @if ($waiting[$key]['waiting'] > 0)
                    <span class="rounded-full bg-amber-100 px-2 py-0.5 text-xs font-semibold text-amber-800" title="Needs review">{{ $waiting[$key]['waiting'] }}</span>
                @endif
                @if ($waiting[$key]['overdue'] > 0)
                    <span class="rounded-full bg-red-600 px-2 py-0.5 text-xs font-semibold text-white" title="Overdue">{{ $waiting[$key]['overdue'] }} overdue</span>
                @endif
            </a>
        @endforeach
    </nav>

    @if ($tab === 'people')
        <livewire:workers.index :embedded="true" key="partners-people" />
    @else
        <livewire:providers.index :embedded="true" key="partners-businesses" />
    @endif
</div>
