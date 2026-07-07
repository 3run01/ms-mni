<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ config('app.name', 'SIM-MNI') }} - @yield('title', 'Sistema de Integração MNI')</title>

    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />

    <!-- Scripts -->
    @vite(['resources/css/app.css', 'resources/js/app.js'])

    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>

    <!-- Alpine.js -->
    <script defer src="https://unpkg.com/alpinejs@3.x.x/dist/cdn.min.js"></script>
</head>

<body class="font-sans antialiased bg-gray-100">
    <div class="min-h-screen">
        @auth
            <!-- Navigation -->
            <nav class="bg-white border-b border-gray-200 shadow-sm">
                <div class="px-4 mx-auto max-w-7xl sm:px-6 lg:px-8">
                    <div class="flex justify-between h-16">
                        <div class="flex items-center">
                            <div class="flex-shrink-0">
                                <h1 class="text-xl font-bold text-gray-900">SIM-MNI</h1>
                            </div>
                            <div class="hidden sm:ml-6 sm:flex sm:space-x-8">
                                <a href="/dashboard"
                                    class="px-1 py-2 text-sm font-medium text-gray-500 border-b-2 border-transparent hover:text-gray-700 hover:border-gray-300 whitespace-nowrap">
                                    Dashboard
                                </a>

                                <!-- Monitoramento Dropdown -->
                                <div class="relative" x-data="{ open: false }">
                                    <button @click="open = !open"
                                        class="flex items-center px-1 py-2 text-sm font-medium text-gray-500 border-b-2 border-transparent hover:text-gray-700 hover:border-gray-300 whitespace-nowrap">
                                        Monitoramento
                                        <svg class="w-4 h-4 ml-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                d="M19 9l-7 7-7-7"></path>
                                        </svg>
                                    </button>

                                    <div x-show="open" @click.away="open = false" x-transition
                                        class="absolute left-0 z-50 w-48 py-1 mt-2 bg-white border border-gray-200 rounded-md shadow-lg">
                                        <a href="/pulse/" target="_blank"
                                            class="flex items-center block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">
                                            <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor"
                                                viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                    d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z">
                                                </path>
                                            </svg>
                                            Laravel Pulse
                                        </a>
                                        <a href="/horizon/" target="_blank"
                                            class="flex items-center block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">
                                            <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor"
                                                viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                    d="M4 6h16M4 10h16M4 14h16M4 18h16">
                                                </path>
                                            </svg>
                                            Horizon
                                        </a>
                                        <a href="/logs/" target="_blank"
                                            class="flex items-center block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">
                                            <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor"
                                                viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                    d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z">
                                                </path>
                                            </svg>
                                            Log Viewer
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="flex items-center">
                            <!-- Mobile menu button -->
                            <div class="sm:hidden">
                                <button type="button"
                                    class="inline-flex items-center justify-center p-2 text-gray-400 rounded-md hover:text-gray-500 hover:bg-gray-100 focus:outline-none focus:ring-2 focus:ring-inset focus:ring-indigo-500"
                                    x-data="{ open: false }" @click="open = !open">
                                    <span class="sr-only">Abrir menu principal</span>
                                    <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                            d="M4 6h16M4 12h16M4 18h16" />
                                    </svg>
                                </button>
                            </div>

                            <div class="hidden sm:flex sm:items-center">
                                <div class="flex-shrink-0">
                                    <span class="text-sm text-gray-700">{{ Auth::user()->name }}</span>
                                </div>
                                <div class="ml-3">
                                    <form method="POST" action="{{ route('logout') }}" class="inline">
                                        @csrf
                                        <button type="submit" class="text-sm text-gray-500 hover:text-gray-700">
                                            Sair
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Mobile menu -->
                    <div class="sm:hidden" x-data="{ open: false }" x-show="open" x-transition>
                        <div class="pt-2 pb-3 space-y-1 bg-white border-t border-gray-200">
                            <a href="/dashboard"
                                class="block py-2 pl-3 pr-4 text-base font-medium text-gray-500 border-l-4 border-transparent hover:text-gray-700 hover:bg-gray-50 hover:border-gray-300">
                                Dashboard
                            </a>
                            <div class="py-2 pl-3 pr-4">
                                <div class="mb-2 text-base font-medium text-gray-500">Monitoramento</div>
                                <div class="pl-4 space-y-1">
                                    <a href="/pulse/" target="_blank"
                                        class="block px-2 py-2 text-sm text-gray-600 rounded-md hover:text-gray-800 hover:bg-gray-50">
                                        Laravel Pulse
                                    </a>
                                    <a href="/horizon/" target="_blank"
                                        class="block px-2 py-2 text-sm text-gray-600 rounded-md hover:text-gray-800 hover:bg-gray-50">
                                        Horizon
                                    </a>
                                    <a href="/log-viewer/" target="_blank"
                                        class="block px-2 py-2 text-sm text-gray-600 rounded-md hover:text-gray-800 hover:bg-gray-50">
                                        Log Viewer
                                    </a>
                                </div>
                            </div>
                        </div>
                        <div class="pt-4 pb-3 border-t border-gray-200">
                            <div class="flex items-center px-4">
                                <div class="flex-shrink-0">
                                    <div class="flex items-center justify-center w-10 h-10 bg-gray-300 rounded-full">
                                        <span
                                            class="text-sm font-medium text-gray-700">{{ substr(Auth::user()->name, 0, 1) }}</span>
                                    </div>
                                </div>
                                <div class="ml-3">
                                    <div class="text-base font-medium text-gray-800">{{ Auth::user()->name }}</div>
                                    <div class="text-sm font-medium text-gray-500">{{ Auth::user()->email }}</div>
                                </div>
                            </div>
                            <div class="px-2 mt-3">
                                <form method="POST" action="{{ route('logout') }}">
                                    @csrf
                                    <button type="submit"
                                        class="block w-full px-3 py-2 text-base font-medium text-left text-gray-500 rounded-md hover:text-gray-800 hover:bg-gray-50">
                                        Sair
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            </nav>
        @endauth

        <!-- Page Content -->
        <main>
            @yield('content')
        </main>
    </div>

    @stack('scripts')
</body>

</html>
