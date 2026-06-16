<x-mail::message>
# Nieuw account aangemaakt

Er is een nieuw Vestix-account aangemaakt.

**Naam:** {{ $userName }}

**E-mail:** {{ $userEmail }}

**Bron:** {{ $sourceLabel }}

**Aangemaakt op:** {{ $createdAt }}

<x-mail::button :url="$adminUrl">
Bekijk gebruikers
</x-mail::button>

</x-mail::message>
