<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Masuk — Oleochemical Maintenance</title>
    @vite('resources/css/app.css')
    <style>
        body { font-family: 'Inter', system-ui, -apple-system, sans-serif; }
    </style>
</head>
<body class="bg-slate-50 min-h-screen flex items-center justify-center p-4">
    <div class="w-full max-w-md">
        <!-- Logo -->
        <div class="text-center mb-8">
            <h1 class="text-2xl font-bold text-slate-900">Oleochemical Maintenance</h1>
            <p class="text-sm text-slate-500 mt-1">Maintenance Management System</p>
        </div>

        <!-- Card Login -->
        <div class="bg-white rounded-xl border border-slate-200 shadow-sm p-6">
            <h2 class="text-base font-semibold text-slate-900 mb-6">Masuk ke Panel Admin</h2>

            @if (isset($errors) && $errors->any())
                <div class="mb-4 p-3 bg-red-50 border border-red-200 rounded-lg text-sm text-red-700">
                    {{ $errors->first('username') ?: $errors->first('email') }}
                </div>
            @endif

            @if (session('status'))
                <div class="mb-4 p-3 bg-green-50 border border-green-200 rounded-lg text-sm text-green-700">
                    {{ session('status') }}
                </div>
            @endif

            <form method="POST" action="{{ route('login') }}" class="space-y-4">
                @csrf

                <div class="space-y-1.5">
                    <label class="block text-sm font-medium text-slate-700">NIK / Email</label>
                    <input type="text" name="username" value="{{ old('username') }}" required autofocus
                           autocomplete="username"
                           class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                </div>

                <div class="space-y-1.5">
                    <label class="block text-sm font-medium text-slate-700">Password</label>
                    <input type="password" name="password" required autocomplete="current-password"
                           class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                </div>

                <div class="flex items-center justify-between">
                    <label class="flex items-center gap-2 text-sm text-slate-600">
                        <input type="checkbox" name="remember" class="rounded border-slate-300 text-blue-600 focus:ring-blue-500">
                        Ingat saya
                    </label>
                </div>

                <button type="submit"
                        class="w-full py-2.5 bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium rounded-lg transition-colors">
                    Masuk
                </button>
            </form>

            <p class="mt-4 text-xs text-slate-400 text-center">
                Gunakan NIK atau email terdaftar, lalu masukkan password akun Anda.
                Hubungi administrator bila lupa password.
            </p>
        </div>

        <p class="text-center text-xs text-slate-400 mt-6">
            &copy; {{ date('Y') }} Oleochemical Maintenance. All rights reserved.
        </p>
    </div>
</body>
</html>
