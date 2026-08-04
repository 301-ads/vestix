<?php

namespace App\Services;

use App\Enums\PositionVisibility;
use App\Enums\SquadRole;
use App\Enums\UserAccountCreatedSource;
use App\Events\UserAccountCreated;
use App\Mail\SquadInviteMail;
use App\Models\Position;
use App\Models\Squad;
use App\Models\SquadInvite;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use InvalidArgumentException;
use Spatie\Permission\PermissionRegistrar;

class SquadManagementService
{
    public function __construct(private SquadPermissionService $permissions) {}

    public function delete(Squad $squad): void
    {
        Position::query()
            ->where('squad_id', $squad->id)
            ->update([
                'squad_id' => null,
                'visibility' => PositionVisibility::Private->value,
            ]);

        $teamKey = config('permission.column_names.team_foreign_key');
        $rolesTable = config('permission.table_names.model_has_roles');

        DB::table($rolesTable)->where($teamKey, $squad->id)->delete();

        SquadInvite::query()->where('squad_id', $squad->id)->delete();

        $squad->users()->detach();
        $squad->delete();
    }

    public function removeMember(Squad $squad, User $member): void
    {
        if (! $squad->users()->whereKey($member->id)->exists()) {
            throw new InvalidArgumentException('Gebruiker is geen lid van deze squad.');
        }

        if ($squad->owner_id === $member->id) {
            throw new InvalidArgumentException('De squad-eigenaar kan niet worden verwijderd. Draag eerst het eigenaarschap over of verwijder de squad.');
        }

        if ($this->commanderCount($squad) === 1 && $this->hasRole($squad, $member, SquadRole::Commander)) {
            throw new InvalidArgumentException('De laatste Commander kan niet worden verwijderd.');
        }

        $this->ghostMemberSharedScouts($squad, $member);
        $this->revokeRoles($squad, $member);
        $squad->users()->detach($member->id);
    }

    public function leaveSquad(Squad $squad, User $member): void
    {
        if (! $squad->users()->whereKey($member->id)->exists()) {
            throw new InvalidArgumentException('Je bent geen lid van deze squad.');
        }

        if ($squad->owner_id === $member->id) {
            throw new InvalidArgumentException('Als eigenaar moet je eerst het eigenaarschap overdragen of de squad verwijderen.');
        }

        if ($this->commanderCount($squad) === 1 && $this->hasRole($squad, $member, SquadRole::Commander)) {
            throw new InvalidArgumentException('Je bent de laatste Commander. Draag eerst een andere Commander aan of verwijder de squad.');
        }

        $this->ghostMemberSharedScouts($squad, $member);
        $this->revokeRoles($squad, $member);
        $squad->users()->detach($member->id);
    }

    public function transferOwnership(Squad $squad, User $actor, User $newOwner): void
    {
        if ($squad->owner_id !== $actor->id && ! $actor->isSuperAdmin()) {
            throw new InvalidArgumentException('Alleen de squad-eigenaar kan het eigenaarschap overdragen.');
        }

        if (! $squad->users()->whereKey($newOwner->id)->exists()) {
            throw new InvalidArgumentException('De nieuwe eigenaar moet lid zijn van de squad.');
        }

        if ($newOwner->is($actor) && $squad->owner_id === $actor->id) {
            throw new InvalidArgumentException('Je bent al eigenaar van deze squad.');
        }

        $squad->update(['owner_id' => $newOwner->id]);
        $this->permissions->assignRole($newOwner, $squad, SquadRole::Commander);
    }

    /**
     * Attach an existing Vestix user to the squad.
     */
    public function addMember(Squad $squad, string $email, SquadRole $role): User
    {
        $email = strtolower(trim($email));

        $user = User::query()->where('email', $email)->first();

        if (! $user instanceof User) {
            throw new InvalidArgumentException('Geen bestaand account voor dit e-mailadres. Stuur een uitnodigingslink.');
        }

        if ($squad->users()->whereKey($user->id)->exists()) {
            throw new InvalidArgumentException('Deze gebruiker is al lid van deze squad.');
        }

        $squad->users()->attach($user->id);
        $this->permissions->assignRole($user, $squad, $role);

        return $user;
    }

    /**
     * Create (or refresh) a magic invite and email the accept link.
     *
     * @return array{invite: SquadInvite, plain_token: string}
     */
    public function inviteByEmail(
        Squad $squad,
        User $inviter,
        string $email,
        SquadRole $role,
        ?string $name = null,
    ): array {
        $email = strtolower(trim($email));

        $existing = User::query()->where('email', $email)->first();

        if ($existing instanceof User && $squad->users()->whereKey($existing->id)->exists()) {
            throw new InvalidArgumentException('Deze gebruiker is al lid van deze squad.');
        }

        if (! $existing instanceof User && blank($name)) {
            throw new InvalidArgumentException('Naam is verplicht voor een nieuwe uitnodiging.');
        }

        SquadInvite::query()
            ->where('squad_id', $squad->id)
            ->where('email', $email)
            ->whereNull('accepted_at')
            ->delete();

        $plainToken = SquadInvite::generatePlainToken();

        $invite = SquadInvite::query()->create([
            'squad_id' => $squad->id,
            'invited_by' => $inviter->id,
            'email' => $email,
            'name' => $existing?->name ?? $name,
            'role' => $role,
            'token_hash' => SquadInvite::hashToken($plainToken),
            'expires_at' => now()->addDays(7),
        ]);

        Mail::to($email)->send(new SquadInviteMail($invite, $plainToken));

        return [
            'invite' => $invite,
            'plain_token' => $plainToken,
        ];
    }

    /**
     * @param  array{name?: string, password?: string}  $registration
     */
    public function acceptInvite(SquadInvite $invite, ?User $actor = null, array $registration = []): User
    {
        if ($invite->isAccepted()) {
            throw new InvalidArgumentException('Deze uitnodiging is al geaccepteerd.');
        }

        if ($invite->isExpired()) {
            throw new InvalidArgumentException('Deze uitnodiging is verlopen.');
        }

        $email = strtolower($invite->email);
        $user = $actor ?? User::query()->where('email', $email)->first();

        if ($user instanceof User) {
            if (strcasecmp($user->email, $email) !== 0) {
                throw new InvalidArgumentException('Log in met het uitgenodigde e-mailadres om te accepteren.');
            }
        } else {
            $name = trim((string) ($registration['name'] ?? $invite->name ?? ''));
            $password = (string) ($registration['password'] ?? '');

            if (blank($name) || strlen($password) < 8) {
                throw new InvalidArgumentException('Kies een naam en een wachtwoord van minimaal 8 tekens.');
            }

            $user = User::query()->create([
                'name' => $name,
                'email' => $email,
                'password' => $password,
            ]);

            UserAccountCreated::dispatch($user, UserAccountCreatedSource::SquadInvite);
        }

        if (! $invite->squad->users()->whereKey($user->id)->exists()) {
            $invite->squad->users()->attach($user->id);
        }

        $this->permissions->assignRole($user, $invite->squad, $invite->role);

        $invite->update(['accepted_at' => now()]);

        return $user;
    }

    public function changeMemberRole(Squad $squad, User $member, SquadRole $role): void
    {
        if (! $squad->users()->whereKey($member->id)->exists()) {
            throw new InvalidArgumentException('Gebruiker is geen lid van deze squad.');
        }

        if (
            $this->hasRole($squad, $member, SquadRole::Commander)
            && $role !== SquadRole::Commander
            && $this->commanderCount($squad) === 1
        ) {
            throw new InvalidArgumentException('Er moet minstens één Commander in de squad blijven.');
        }

        $this->permissions->assignRole($member, $squad, $role);
    }

    public function canDelete(Squad $squad, User $user): bool
    {
        return app(SquadContext::class)->userCanInSquad($user, $squad, 'squad.manage')
            && $squad->owner_id === $user->id
            && $user->squads()->whereKey($squad)->exists();
    }

    public function canManageMembers(Squad $squad, User $user): bool
    {
        return app(SquadContext::class)->userCanInSquad($user, $squad, 'squad.manage')
            && $user->squads()->whereKey($squad)->exists();
    }

    public function canRemoveMember(Squad $squad, User $actor, User $member): bool
    {
        if (! $this->canManageMembers($squad, $actor)) {
            return false;
        }

        if ($squad->owner_id === $member->id) {
            return false;
        }

        if ($actor->is($member) && $this->commanderCount($squad) === 1 && $this->hasRole($squad, $member, SquadRole::Commander)) {
            return false;
        }

        return true;
    }

    public function canTransferOwnership(Squad $squad, User $actor): bool
    {
        return $squad->owner_id === $actor->id
            && $actor->squads()->whereKey($squad->id)->exists();
    }

    public function canLeave(Squad $squad, User $member): bool
    {
        if (! $member->squads()->whereKey($squad->id)->exists()) {
            return false;
        }

        return $squad->owner_id !== $member->id;
    }

    public function commanderCount(Squad $squad): int
    {
        return $squad->users()
            ->get()
            ->filter(fn (User $user): bool => $this->hasRole($squad, $user, SquadRole::Commander))
            ->count();
    }

    public function ghostMemberSharedScouts(Squad $squad, User $member): void
    {
        Position::query()
            ->where('user_id', $member->id)
            ->where('squad_id', $squad->id)
            ->update([
                'squad_id' => null,
                'visibility' => PositionVisibility::Private->value,
            ]);
    }

    private function hasRole(Squad $squad, User $user, SquadRole $role): bool
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId($squad->id);
        $hasRole = $user->hasRole($role->value);
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);

        return $hasRole;
    }

    private function revokeRoles(Squad $squad, User $user): void
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId($squad->id);
        $user->syncRoles([]);
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    }
}
