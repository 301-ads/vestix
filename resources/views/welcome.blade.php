<!DOCTYPE html>
<html lang="nl" class="scroll-smooth">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Vestix | Multiplayer Swingtrading</title>

    <link rel="icon" href="{{ asset('images/favicon.svg') }}" type="image/svg+xml">
    <link rel="icon" href="{{ asset('images/favicon.png') }}" type="image/png">
    <link rel="apple-touch-icon" sizes="180x180" href="{{ asset('images/favicon-180x180.png') }}">
    <link rel="manifest" href="{{ asset('manifest.json') }}">

    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: {
                        sans: ['Inter', 'sans-serif'],
                    },
                    colors: {
                        vestix: {
                            green: '#00D492',
                            dark: '#0a0a0a',
                            card: '#181818',
                            border: '#2a2a2a',
                            textMuted: '#9ca3af'
                        }
                    },
                    backgroundImage: {
                        'grid-pattern': "linear-gradient(to right, #ffffff05 1px, transparent 1px), linear-gradient(to bottom, #ffffff05 1px, transparent 1px)",
                    }
                }
            }
        }
    </script>
    <style>
        .glow-text { text-shadow: 0 0 20px rgba(0, 212, 146, 0.4); }
        .bg-grid { background-size: 40px 40px; }
    </style>
</head>
<body class="bg-vestix-dark text-white font-sans antialiased overflow-x-hidden selection:bg-vestix-green selection:text-black">

    <nav class="fixed w-full z-50 top-0 border-b border-vestix-border/50 bg-vestix-dark/80 backdrop-blur-md">
        <div class="max-w-7xl mx-auto px-6 h-20 flex items-center justify-between">
            <div class="flex items-center gap-3 text-white">
                <x-vestix-wordmark size="md" for-dark-background />
            </div>

            <div class="hidden md:flex items-center gap-8 text-sm font-medium text-vestix-textMuted">
                <a href="#features" class="hover:text-white transition-colors">Hoe het werkt</a>
                <a href="#squads" class="hover:text-white transition-colors">Voor Squads</a>
            </div>

            <div class="flex items-center gap-4 text-sm">
                <a href="{{ route('filament.admin.auth.login') }}" class="hidden md:block font-medium text-vestix-textMuted hover:text-white transition-colors">Inloggen</a>
                <a href="{{ route('filament.admin.auth.register') }}" class="bg-white text-black px-5 py-2.5 rounded-full font-semibold hover:bg-gray-200 transition-colors">
                    Start een Squad
                </a>
            </div>
        </div>
    </nav>

    <section class="relative pt-32 pb-20 md:pt-48 md:pb-32 overflow-hidden">
        <div class="absolute inset-0 bg-grid-pattern bg-grid opacity-20" style="-webkit-mask-image: linear-gradient(to bottom, white, transparent); mask-image: linear-gradient(to bottom, white, transparent);"></div>

        <div class="absolute top-1/4 left-1/2 -translate-x-1/2 w-[600px] h-[600px] bg-vestix-green/10 rounded-full blur-[120px] pointer-events-none"></div>

        <div class="relative max-w-7xl mx-auto px-6 text-center z-10">
            <div class="inline-flex items-center gap-2 px-3 py-1.5 rounded-full bg-vestix-green/10 border border-vestix-green/20 text-vestix-green text-xs font-semibold mb-8 uppercase tracking-widest">
                <span class="relative flex h-2 w-2">
                  <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-vestix-green opacity-75"></span>
                  <span class="relative inline-flex rounded-full h-2 w-2 bg-vestix-green"></span>
                </span>
                Nu in besloten bèta
            </div>

            <h1 class="text-5xl md:text-7xl font-bold tracking-tight mb-6 leading-tight">
                Swingtraden is geen <br class="hidden md:block" />
                <span class="text-transparent bg-clip-text bg-gradient-to-r from-white to-gray-500">solospel meer.</span>
            </h1>

            <p class="mt-4 text-lg md:text-xl text-vestix-textMuted max-w-2xl mx-auto leading-relaxed mb-10">
                Vestix combineert kille, wiskundige stop-losses met de intelligentie van jouw eigen trading squad. Ontdek setups, kloon targets en bescherm je inleg zonder emotie.
            </p>

            <div class="flex flex-col sm:flex-row items-center justify-center gap-4">
                <a href="{{ route('filament.admin.auth.register') }}" class="w-full sm:w-auto px-8 py-4 bg-vestix-green text-black rounded-lg font-semibold text-lg hover:bg-[#00e69d] hover:shadow-[0_0_20px_rgba(0,212,146,0.3)] transition-all transform hover:-translate-y-0.5">
                    Start je platform
                </a>
                <a href="#features" class="w-full sm:w-auto px-8 py-4 bg-vestix-card border border-vestix-border text-white rounded-lg font-medium text-lg hover:bg-gray-800 transition-all">
                    Bekijk de radar ↓
                </a>
            </div>

            <div class="mt-20 relative mx-auto max-w-5xl">
                <div class="absolute -inset-1 bg-gradient-to-b from-vestix-green/20 to-transparent rounded-2xl blur-xl opacity-50"></div>
                <div class="relative bg-vestix-card border border-vestix-border rounded-xl shadow-2xl overflow-hidden text-left flex flex-col">
                    <div class="h-10 border-b border-vestix-border bg-vestix-dark flex items-center px-4 gap-2">
                        <div class="w-3 h-3 rounded-full bg-gray-700"></div>
                        <div class="w-3 h-3 rounded-full bg-gray-700"></div>
                        <div class="w-3 h-3 rounded-full bg-gray-700"></div>
                    </div>
                    <div class="p-8 overflow-x-auto">
                        @php
                            $tickerLogoBase = rtrim(config('vestix.tradingview.logo_cdn_url', 'https://s3-symbol-logo.tradingview.com'), '/');
                        @endphp
                        <h3 class="text-lg font-semibold mb-6 flex items-center gap-2">
                            <svg class="w-5 h-5 text-vestix-textMuted" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"></path></svg>
                            Squad Radar — Alpha Team
                        </h3>
                        <div class="w-full min-w-[800px] bg-vestix-dark rounded-lg border border-vestix-border overflow-hidden">
                            <div class="grid grid-cols-5 text-xs text-vestix-textMuted uppercase font-medium p-4 border-b border-vestix-border">
                                <div>Ticker</div>
                                <div>Geplande Entry</div>
                                <div>Berekende SL</div>
                                <div>Scout</div>
                                <div class="text-right">Actie</div>
                            </div>
                            <div class="grid grid-cols-5 items-center p-4 border-b border-vestix-border/50 bg-white/[0.02]">
                                <div class="font-bold text-white flex items-center gap-2">
                                    <span class="w-6 h-6 rounded-full overflow-hidden shrink-0 ring-1 ring-vestix-border bg-white/5">
                                        <img src="{{ $tickerLogoBase }}/asml.svg" alt="" class="w-full h-full object-cover" width="24" height="24" loading="lazy" />
                                    </span>
                                    ASML
                                </div>
                                <div class="text-gray-300">$920.45</div>
                                <div class="text-vestix-green">$890.12</div>
                                <div class="text-gray-400 flex items-center gap-2">
                                    <div class="w-5 h-5 rounded-full bg-gray-600 flex items-center justify-center text-[10px] text-white">D</div> Davy
                                </div>
                                <div class="text-right flex justify-end">
                                    <div class="px-3 py-1.5 bg-vestix-green/10 text-vestix-green border border-vestix-green/30 rounded text-sm cursor-pointer hover:bg-vestix-green hover:text-black transition-colors flex items-center gap-1">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"></path></svg>
                                        Kloon Target
                                    </div>
                                </div>
                            </div>
                            <div class="grid grid-cols-5 items-center p-4">
                                <div class="font-bold text-white flex items-center gap-2">
                                    <span class="w-6 h-6 rounded-full overflow-hidden shrink-0 ring-1 ring-vestix-border bg-white/5">
                                        <img src="{{ $tickerLogoBase }}/automatic-data-processing.svg" alt="" class="w-full h-full object-cover" width="24" height="24" loading="lazy" />
                                    </span>
                                    ADP
                                </div>
                                <div class="text-gray-300">$231.48</div>
                                <div class="text-vestix-green">$221.08</div>
                                <div class="text-gray-400 flex items-center gap-2">
                                    <div class="w-5 h-5 rounded-full bg-gray-600 flex items-center justify-center text-[10px] text-white">B</div> Bas
                                </div>
                                <div class="text-right flex justify-end">
                                    <div class="px-3 py-1.5 bg-vestix-green/10 text-vestix-green border border-vestix-green/30 rounded text-sm cursor-pointer hover:bg-vestix-green hover:text-black transition-colors flex items-center gap-1">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"></path></svg>
                                        Kloon Target
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <section id="features" class="py-24 bg-vestix-dark border-t border-vestix-border">
        <div class="max-w-7xl mx-auto px-6">
            <div class="text-center mb-16">
                <h2 class="text-3xl md:text-4xl font-bold mb-4">Drie pijlers voor structurele winst</h2>
                <p class="text-vestix-textMuted max-w-2xl mx-auto">Verwijder de emotie. Gebruik de data. Handel als een eenheid.</p>
            </div>

            <div id="squads" class="grid grid-cols-1 md:grid-cols-3 gap-8">
                <div class="bg-vestix-card p-8 rounded-2xl border border-vestix-border hover:border-vestix-green/50 transition-colors group">
                    <div class="w-14 h-14 bg-vestix-dark border border-vestix-border rounded-xl flex items-center justify-center mb-6 group-hover:bg-vestix-green/10 transition-colors">
                        <svg class="w-6 h-6 text-vestix-green" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path></svg>
                    </div>
                    <h3 class="text-xl font-bold mb-3">Gedeelde Radar</h3>
                    <p class="text-vestix-textMuted leading-relaxed">Waarom de markt in je eentje scannen? Vorm een Squad met bevriende traders. Iedere A+ setup verschijnt direct op de gezamenlijke radar.</p>
                </div>

                <div class="bg-vestix-card p-8 rounded-2xl border border-vestix-border hover:border-vestix-green/50 transition-colors group relative overflow-hidden">
                    <div class="absolute top-0 right-0 p-4 opacity-5 pointer-events-none">
                        <svg class="w-24 h-24 text-white" fill="currentColor" viewBox="0 0 24 24"><path d="M16 1H4C2.9 1 2 1.9 2 3v14h2V3h12V1zm3 4H8C6.9 5 6 5.9 6 7v14c0 1.1.9 2 2 2h11c1.1 0 2-.9 2-2V7c0-1.1-.9-2-2-2zm0 16H8V7h11v14z"></path></svg>
                    </div>
                    <div class="w-14 h-14 bg-vestix-dark border border-vestix-border rounded-xl flex items-center justify-center mb-6 group-hover:bg-vestix-green/10 transition-colors relative z-10">
                        <svg class="w-6 h-6 text-vestix-green" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"></path></svg>
                    </div>
                    <h3 class="text-xl font-bold mb-3 relative z-10">Clone & Execute</h3>
                    <p class="text-vestix-textMuted leading-relaxed relative z-10">Zie je een geniale setup van een Squad-lid? Met de 'Kloon Target' knop kopieer je de calculaties naar je eigen portfolio. Vul je inleg in en vuur.</p>
                </div>

                <div class="bg-vestix-card p-8 rounded-2xl border border-vestix-border hover:border-vestix-green/50 transition-colors group">
                    <div class="w-14 h-14 bg-vestix-dark border border-vestix-border rounded-xl flex items-center justify-center mb-6 group-hover:bg-vestix-green/10 transition-colors">
                        <svg class="w-6 h-6 text-vestix-green" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"></path></svg>
                    </div>
                    <h3 class="text-xl font-bold mb-3">Het Wiskundige Schild</h3>
                    <p class="text-vestix-textMuted leading-relaxed">Vestix berekent genadeloos je Stop-Loss op basis van ATR en SMA. Regel 1: het schild schuift alleen omhoog, nooit omlaag. Jouw winst wordt gelockt.</p>
                </div>
            </div>
        </div>
    </section>

    <section class="py-24 bg-vestix-dark border-t border-vestix-border overflow-hidden">
        <div class="max-w-7xl mx-auto px-6 flex flex-col lg:flex-row items-center gap-16">
            <div class="flex-1">
                <div class="inline-flex items-center gap-2 px-3 py-1.5 rounded-full bg-gray-800 border border-gray-700 text-gray-300 text-xs font-semibold mb-6 uppercase tracking-widest">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path></svg>
                    Ghost Mode
                </div>
                <h2 class="text-3xl md:text-4xl font-bold mb-6">Liever als <span class="text-transparent bg-clip-text bg-gradient-to-r from-gray-300 to-gray-600">lone wolf</span> opereren? Geen probleem.</h2>
                <p class="text-vestix-textMuted text-lg leading-relaxed mb-8">
                    Hoewel de Squad-radar krachtig is, ben je nergens toe verplicht. Vestix is in de basis jouw ultieme, persoonlijke trading terminal.
                </p>
                <ul class="space-y-4">
                    <li class="flex items-start gap-3 text-gray-300">
                        <svg class="w-6 h-6 text-vestix-green shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
                        <span><strong class="text-white">100% Privé:</strong> Jouw inleg, P&L en broker-keys zijn altijd strikt afgeschermd, zelfs áls je in een Squad zit.</span>
                    </li>
                    <li class="flex items-start gap-3 text-gray-300">
                        <svg class="w-6 h-6 text-vestix-green shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
                        <span><strong class="text-white">Verborgen Setups:</strong> Vink 'Privé' aan bij een nieuwe setup en hij blijft onzichtbaar voor de rest van je team.</span>
                    </li>
                    <li class="flex items-start gap-3 text-gray-300">
                        <svg class="w-6 h-6 text-vestix-green shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
                        <span><strong class="text-white">Solo Executie:</strong> Gebruik de app puur als jouw eigen wiskundige waakhond om emoties tijdens het traden uit te bannen.</span>
                    </li>
                </ul>
            </div>

            <div class="flex-1 w-full max-w-lg relative lg:ml-auto">
                <div class="absolute -inset-1 bg-gradient-to-r from-gray-700 to-vestix-border rounded-2xl blur-xl opacity-30"></div>
                <div class="relative bg-vestix-card border border-vestix-border rounded-2xl p-8 shadow-2xl">
                    <h4 class="text-sm font-semibold text-white mb-6 uppercase tracking-wider">Nieuwe Setup Toevoegen</h4>

                    <div class="space-y-5">
                        <div>
                            <label class="block text-xs text-vestix-textMuted mb-2">Ticker</label>
                            <div class="w-full h-10 bg-vestix-dark border border-vestix-border rounded-lg flex items-center px-3 text-white font-mono text-sm">NVDA</div>
                        </div>

                        <div>
                            <label class="block text-xs text-vestix-textMuted mb-2">Zichtbaarheid Setup</label>
                            <div class="flex gap-3">
                                <div class="flex-1 h-12 rounded-lg border border-vestix-green bg-vestix-green/10 flex items-center justify-center gap-2 cursor-pointer transition-colors shadow-[0_0_15px_rgba(0,212,146,0.1)]">
                                    <svg class="w-4 h-4 text-vestix-green" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path></svg>
                                    <span class="text-vestix-green text-sm font-medium">Privé</span>
                                </div>
                                <div class="flex-1 h-12 rounded-lg border border-vestix-border bg-vestix-dark flex items-center justify-center gap-2 text-vestix-textMuted cursor-not-allowed opacity-50">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"></path></svg>
                                    <span class="text-sm font-medium">Squad Radar</span>
                                </div>
                            </div>
                            <p class="text-[11px] text-gray-500 mt-2 flex items-center gap-1">
                                <svg class="w-3 h-3 text-vestix-green" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                                Alleen jij ziet deze trade. Geen team-notificaties.
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <section class="py-24 relative overflow-hidden border-t border-vestix-border">
        <div class="absolute inset-0 bg-vestix-green/5"></div>
        <div class="relative max-w-4xl mx-auto px-6 text-center">
            <h2 class="text-3xl font-bold mb-6">Klaar om emotie uit te schakelen?</h2>
            <p class="text-vestix-textMuted mb-8 text-lg">Maak vandaag nog je account aan, nodig je squad uit en begin met systematisch traden.</p>
            <a href="{{ route('filament.admin.auth.register') }}" class="inline-flex px-8 py-4 bg-white text-black rounded-lg font-bold text-lg hover:bg-gray-200 transition-colors shadow-lg">
                Start met Vestix
            </a>
        </div>
    </section>

    <footer class="bg-vestix-dark border-t border-vestix-border py-8 text-center text-sm text-gray-500">
        <div class="max-w-7xl mx-auto px-6 flex flex-col md:flex-row justify-between items-center gap-4">
            <div class="flex items-center gap-2">
                <x-vestix-wordmark size="sm" muted for-dark-background />
            </div>
            <p>&copy; {{ date('Y') }} Vestix Trading Software. Alle rechten voorbehouden.</p>
            <div class="flex gap-4">
                <a href="#" class="hover:text-white transition-colors">Privacy</a>
                <a href="#" class="hover:text-white transition-colors">Voorwaarden</a>
            </div>
        </div>
    </footer>

</body>
</html>
