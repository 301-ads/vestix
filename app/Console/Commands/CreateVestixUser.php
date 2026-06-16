<?php

namespace App\Console\Commands;

use App\Enums\SquadRole;
use App\Enums\UserAccountCreatedSource;
use App\Events\UserAccountCreated;
use App\Models\Squad;
use App\Models\User;
use App\Services\SquadManagementService;
use App\Services\SquadPermissionService;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use InvalidArgumentException;

class CreateVestixUser extends Command
{
    protected $signature = 'vestix:create-user
                            {email : Het e-mailadres van de gebruiker}
                            {--name= : Weergavenaam (standaard: deel voor @)}
                            {--password= : Wachtwoord (standaard: password)}
                            {--squad=Alpha Squad : Squad naam om te maken of te joinen}
                            {--super-admin : Maak een platform-beheerder zonder squad}';

    protected $description = 'Maak een Vestix-gebruiker met squad-toegang (herstel na DB-reset)';

    public function handle(SquadManagementService $management, SquadPermissionService $permissions): int
    {
        $email = strtolower(trim((string) $this->argument('email')));
        $name = (string) ($this->option('name') ?: Str::before($email, '@'));
        $password = (string) ($this->option('password') ?: 'password');
        $isSuperAdmin = (bool) $this->option('super-admin');

        if ($isSuperAdmin) {
            return $this->createSuperAdmin($email, $name, $password);
        }

        $squadName = trim((string) $this->option('squad'));

        $existingUser = User::query()->where('email', $email)->first();

        if ($existingUser instanceof User) {
            $this->warn('Bestaande gebruiker gekoppeld (wachtwoord niet gewijzigd).');
            $user = $existingUser;
        } else {
            $user = User::query()->create([
                'name' => $name,
                'email' => $email,
                'password' => $password,
            ]);

            UserAccountCreated::dispatch($user, UserAccountCreatedSource::Artisan);
        }

        $squad = Squad::query()->firstOrCreate(
            ['slug' => Squad::uniqueSlug($squadName)],
            [
                'name' => $squadName,
                'owner_id' => $user->id,
            ],
        );

        try {
            if (! $squad->users()->whereKey($user->id)->exists()) {
                $management->addMember($squad, $email, SquadRole::Commander);
            } else {
                $permissions->assignRole($user, $squad, SquadRole::Commander);
            }
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info("Gebruiker: {$user->email}");
        $this->info("Wachtwoord: {$password}");
        $this->info("Squad: {$squad->name} ({$squad->slug})");
        $this->info('Login: '.config('app.url').'/admin/login');

        return self::SUCCESS;
    }

    private function createSuperAdmin(string $email, string $name, string $password): int
    {
        $existingUser = User::query()->where('email', $email)->first();

        if ($existingUser instanceof User) {
            $existingUser->forceFill([
                'is_super_admin' => true,
            ])->save();

            $this->warn('Bestaande gebruiker bijgewerkt naar super admin (wachtwoord niet gewijzigd).');
            $user = $existingUser;
        } else {
            $user = User::query()->create([
                'name' => $name,
                'email' => $email,
                'password' => $password,
                'is_super_admin' => true,
            ]);

            UserAccountCreated::dispatch($user, UserAccountCreatedSource::Artisan);
        }

        $this->info("Super admin: {$user->email}");
        $this->info("Wachtwoord: {$password}");
        $this->info('Login: '.config('app.url').'/admin/login');

        return self::SUCCESS;
    }
}
