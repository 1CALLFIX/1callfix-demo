<a href="{{ route('customer.cart') }}" wire:navigate
   class="relative inline-flex min-h-11 items-center rounded-md px-2 text-slate-600 transition hover:bg-slate-50 hover:text-slate-900 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-slate-900">
    <span class="sr-only">Your cart{{ $count > 0 ? ', '.$count.' '.\Illuminate\Support\Str::plural('item', $count) : '' }}</span>
    <x-icon name="shopping-bag" class="h-5 w-5" />
    @if ($count > 0)
        <span aria-hidden="true"
              class="absolute -right-0.5 -top-0.5 grid min-h-4 min-w-4 place-items-center rounded-full bg-slate-900 px-1 text-[10px] font-bold leading-none text-white">
            {{ $count > 99 ? '99+' : $count }}
        </span>
    @endif
</a>
