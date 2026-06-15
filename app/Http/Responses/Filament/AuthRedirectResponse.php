<?php

namespace App\Http\Responses\Filament;

use App\Filament\Pages\RegisterSquad;
use Filament\Facades\Filament;
use Illuminate\Http\RedirectResponse;
use Livewire\Features\SupportRedirects\Redirector;

class AuthRedirectResponse
{
    public function toResponse($request): RedirectResponse | Redirector
    {
        $user = Filament::auth()->user();

        if ($user !== null && ! $user->squads()->exists()) {
            return redirect()->to(RegisterSquad::getUrl());
        }

        return redirect()->intended(Filament::getUrl());
    }
}
