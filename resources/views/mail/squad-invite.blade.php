<x-mail::message>
# Uitnodiging voor {{ $squadName }}

{{ $inviterName }} nodigt je uit voor de Vestix-squad **{{ $squadName }}** als **{{ $roleLabel }}**.

Klik op de knop om te accepteren. Nieuwe accounts kiezen zelf een wachtwoord — niemand anders stelt die voor je in.

<x-mail::button :url="$acceptUrl">
Uitnodiging accepteren
</x-mail::button>

Deze link verloopt op {{ $expiresAt }}.

Bedankt,<br>
{{ config('app.name') }}
</x-mail::message>
