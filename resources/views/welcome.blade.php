<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ config('app.name', 'KomaEngine') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="h-full bg-gray-50 text-gray-900 antialiased dark:bg-gray-950 dark:text-gray-100">
    <main class="flex min-h-full flex-col items-center justify-center px-6 py-16">
        <div class="w-full max-w-xl text-center">
            <p class="text-sm font-semibold uppercase tracking-widest text-indigo-600 dark:text-indigo-400">
                Laravel {{ app()->version() }}
            </p>
            <h1 class="mt-3 text-4xl font-bold tracking-tight sm:text-5xl">KomaEngine</h1>
            <p class="mt-4 text-lg text-gray-600 dark:text-gray-400">
                A modern manga CMS. Fresh scaffold, ready to build on.
            </p>
            <div class="mt-8 flex items-center justify-center gap-4">
                <a href="https://laravel.com/docs"
                   class="rounded-lg bg-indigo-600 px-5 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-indigo-500">
                    Documentation
                </a>
                <a href="https://github.com/spydrful/KomaEngine"
                   class="rounded-lg px-5 py-2.5 text-sm font-semibold text-gray-700 ring-1 ring-inset ring-gray-300 transition hover:bg-gray-100 dark:text-gray-200 dark:ring-gray-700 dark:hover:bg-gray-900">
                    Repository
                </a>
            </div>
        </div>
    </main>
</body>
</html>
