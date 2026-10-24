<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'Admin') | Reelspay</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @stack('styles')
</head>
<body class="min-h-screen bg-slate-100 font-sans text-slate-900">
    <div class="min-h-screen md:grid md:grid-cols-[15rem_minmax(0,1fr)]">
        <aside class="bg-slate-950 px-5 py-6 text-white">
            <a href="{{ route('admin.home') }}" class="text-xl font-bold tracking-wide">Reelspay Admin</a>
            <nav class="mt-8 flex flex-col gap-2" aria-label="Admin navigation">
                <a href="{{ route('admin.home') }}"
                    @class(['rounded-lg px-4 py-3 text-sm font-medium hover:bg-slate-800', 'bg-slate-800' => request()->routeIs('admin.home')])>
                    Home
                </a>
                <a href="{{ route('admin.notifications.index') }}"
                    @class(['rounded-lg px-4 py-3 text-sm font-medium hover:bg-slate-800', 'bg-slate-800' => request()->routeIs('admin.notifications.*')])>
                    Send notification
                </a>
                <a href="{{ route('tiktok-library.index') }}"
                    @class(['rounded-lg px-4 py-3 text-sm font-medium hover:bg-slate-800', 'bg-slate-800' => request()->routeIs('tiktok-library.*')])>
                    Videos
                </a>
            </nav>
        </aside>

        <div class="min-w-0">
            <header class="flex items-center justify-between border-b border-slate-200 bg-white px-5 py-4 md:px-8">
                <h1 class="text-lg font-semibold">@yield('title', 'Admin')</h1>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" class="rounded-lg bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-700">
                        Log out
                    </button>
                </form>
            </header>

            <main class="p-5 md:p-8">
                @yield('content')
            </main>
        </div>
    </div>
</body>
</html>
