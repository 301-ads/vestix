@props([
    'user' => filament()->auth()->user(),
])

@php
    use Filament\Models\Contracts\HasAvatar;

    $src = filament()->getUserAvatarUrl($user);
    $alt = __('filament-panels::layout.avatar.alt', ['name' => filament()->getUserName($user)]);

    $hasUploadedAvatar = filled($user->getAttributeValue('avatar_url'));

    if (! $hasUploadedAvatar && $user instanceof HasAvatar) {
        $hasUploadedAvatar = filled($user->getFilamentAvatarUrl());
    }
@endphp

@if ($hasUploadedAvatar)
    <x-filament::avatar
        :src="$src"
        :alt="$alt"
        :attributes="
            \Filament\Support\prepare_inherited_attributes($attributes)
                ->class(['fi-user-avatar'])
        "
    />
@else
    @php
        $letter = str(filament()->getNameForDefaultAvatar($user))
            ->trim()
            ->substr(0, 1)
            ->upper();
    @endphp

    <span
        {{
            \Filament\Support\prepare_inherited_attributes($attributes)
                ->class(['fi-avatar', 'fi-circular', 'fi-user-avatar', 'vestix-user-avatar'])
        }}
        aria-label="{{ $alt }}"
        role="img"
    >
        {{ $letter }}
    </span>
@endif
