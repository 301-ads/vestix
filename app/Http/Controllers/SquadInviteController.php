<?php

namespace App\Http\Controllers;

use App\Models\SquadInvite;
use App\Models\User;
use App\Services\SquadManagementService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use InvalidArgumentException;

class SquadInviteController extends Controller
{
    public function show(string $token): View|RedirectResponse
    {
        $invite = SquadInvite::findByPlainToken($token)?->load(['squad', 'inviter']);

        if (! $invite instanceof SquadInvite) {
            return view('squad-invites.invalid', [
                'message' => 'Deze uitnodigingslink is ongeldig.',
            ]);
        }

        if ($invite->isAccepted()) {
            return view('squad-invites.invalid', [
                'message' => 'Deze uitnodiging is al geaccepteerd.',
            ]);
        }

        if ($invite->isExpired()) {
            return view('squad-invites.invalid', [
                'message' => 'Deze uitnodiging is verlopen. Vraag een nieuwe link aan.',
            ]);
        }

        $existingUser = User::query()
            ->where('email', $invite->email)
            ->first();

        return view('squad-invites.accept', [
            'token' => $token,
            'invite' => $invite,
            'existingUser' => $existingUser,
            'needsRegistration' => $existingUser === null,
            'authMismatch' => Auth::check() && strcasecmp((string) Auth::user()?->email, $invite->email) !== 0,
        ]);
    }

    public function accept(Request $request, string $token, SquadManagementService $management): RedirectResponse
    {
        $invite = SquadInvite::findByPlainToken($token)?->load('squad');

        if (! $invite instanceof SquadInvite) {
            return redirect()->route('squad-invites.show', ['token' => $token])
                ->withErrors(['invite' => 'Deze uitnodigingslink is ongeldig.']);
        }

        $existingUser = User::query()
            ->where('email', $invite->email)
            ->first();

        $validated = $request->validate([
            'name' => [$existingUser ? 'nullable' : 'required', 'string', 'max:255'],
            'password' => [$existingUser ? 'nullable' : 'required', 'string', 'min:8', 'confirmed'],
        ]);

        try {
            if ($existingUser instanceof User) {
                if (! Auth::check()) {
                    return redirect()->route('filament.admin.auth.login')
                        ->with('status', 'Log in met '.$invite->email.' om de uitnodiging te accepteren.');
                }

                $user = $management->acceptInvite($invite, Auth::user());
            } else {
                $user = $management->acceptInvite($invite, null, [
                    'name' => $validated['name'] ?? $invite->name,
                    'password' => $validated['password'] ?? '',
                ]);

                Auth::login($user);
            }
        } catch (InvalidArgumentException $exception) {
            return back()->withErrors(['invite' => $exception->getMessage()]);
        }

        return redirect('/admin')
            ->with('status', 'Welkom bij '.$invite->squad?->name.'.');
    }
}
