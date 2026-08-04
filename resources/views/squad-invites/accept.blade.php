<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Squad-uitnodiging · Vestix</title>
    <link rel="icon" href="{{ asset('images/favicon.svg') }}" type="image/svg+xml">
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="min-h-screen bg-zinc-950 text-zinc-100 antialiased">
    <main class="mx-auto flex min-h-screen max-w-lg flex-col justify-center px-6 py-12">
        <p class="text-sm uppercase tracking-[0.2em] text-emerald-400">Vestix</p>
        <h1 class="mt-3 text-3xl font-semibold tracking-tight">Squad-uitnodiging</h1>
        <p class="mt-3 text-zinc-400">
            <strong class="text-zinc-200">{{ $invite->inviter?->name ?? 'Iemand' }}</strong>
            nodigt je uit voor
            <strong class="text-zinc-200">{{ $invite->squad?->name }}</strong>
            als {{ $invite->role?->label() ?? $invite->role?->value }}.
        </p>

        @if ($errors->any())
            <div class="mt-6 rounded-lg border border-rose-500/40 bg-rose-500/10 px-4 py-3 text-sm text-rose-200">
                {{ $errors->first() }}
            </div>
        @endif

        @if ($authMismatch)
            <div class="mt-6 rounded-lg border border-amber-500/40 bg-amber-500/10 px-4 py-3 text-sm text-amber-100">
                Je bent ingelogd als {{ auth()->user()?->email }}. Log uit en gebruik
                <strong>{{ $invite->email }}</strong> om te accepteren.
            </div>
        @elseif ($needsRegistration)
            <form method="post" action="{{ route('squad-invites.accept', ['token' => $token]) }}" class="mt-8 space-y-4">
                @csrf
                <div>
                    <label class="mb-1 block text-sm text-zinc-400" for="name">Naam</label>
                    <input id="name" name="name" value="{{ old('name', $invite->name) }}" required
                           class="w-full rounded-lg border border-zinc-700 bg-zinc-900 px-3 py-2 outline-none focus:border-emerald-400">
                </div>
                <div>
                    <label class="mb-1 block text-sm text-zinc-400" for="password">Wachtwoord</label>
                    <input id="password" type="password" name="password" required minlength="8"
                           class="w-full rounded-lg border border-zinc-700 bg-zinc-900 px-3 py-2 outline-none focus:border-emerald-400">
                </div>
                <div>
                    <label class="mb-1 block text-sm text-zinc-400" for="password_confirmation">Bevestig wachtwoord</label>
                    <input id="password_confirmation" type="password" name="password_confirmation" required minlength="8"
                           class="w-full rounded-lg border border-zinc-700 bg-zinc-900 px-3 py-2 outline-none focus:border-emerald-400">
                </div>
                <button type="submit" class="w-full rounded-lg bg-emerald-500 px-4 py-2.5 font-medium text-zinc-950 hover:bg-emerald-400">
                    Account aanmaken &amp; joinen
                </button>
            </form>
        @else
            <form method="post" action="{{ route('squad-invites.accept', ['token' => $token]) }}" class="mt-8 space-y-4">
                @csrf
                @auth
                    <button type="submit" class="w-full rounded-lg bg-emerald-500 px-4 py-2.5 font-medium text-zinc-950 hover:bg-emerald-400">
                        Uitnodiging accepteren
                    </button>
                @else
                    <p class="text-sm text-zinc-400">
                        Er bestaat al een account voor <strong class="text-zinc-200">{{ $invite->email }}</strong>.
                        Log eerst in om te joinen.
                    </p>
                    <a href="{{ route('filament.admin.auth.login') }}"
                       class="block w-full rounded-lg bg-emerald-500 px-4 py-2.5 text-center font-medium text-zinc-950 hover:bg-emerald-400">
                        Inloggen
                    </a>
                @endauth
            </form>
        @endif
    </main>
</body>
</html>
