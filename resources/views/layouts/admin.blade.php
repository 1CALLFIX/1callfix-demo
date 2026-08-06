<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? '1CallFix Admin' }}</title>
    <script src="https://cdn.tailwindcss.com"></script>
    @livewireStyles
</head>
<body class="bg-gray-100 text-gray-900">
    @auth
        <nav class="bg-slate-900 text-white px-6 py-4 flex items-center justify-between">
            <div class="font-bold text-lg">1CallFix Admin</div>
            <div class="flex items-center gap-4 text-sm">
                <span class="text-gray-300">{{ auth()->user()->name }}</span>
                <form method="POST" action="{{ route('admin.logout') }}">
                    @csrf
                    <button type="submit" class="text-red-300 hover:text-red-100">Logout</button>
                </form>
            </div>
        </nav>
    @endauth

    <main class="max-w-6xl mx-auto p-6">
        {{ $slot }}
    </main>

    @livewireScripts
</body>
</html>
