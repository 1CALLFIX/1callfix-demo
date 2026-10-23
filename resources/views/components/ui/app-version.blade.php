{{-- App version (App\Support\AppVersion): release number and the git commit the server is on. --}}
<p {{ $attributes->merge(['class' => 'text-[11px] leading-4 text-slate-400']) }}>{{ \App\Support\AppVersion::label() }}</p>
