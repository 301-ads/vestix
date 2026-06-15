<?php

namespace Tests\Feature\Console;

use App\Models\ApiCredential;
use App\Models\Asset;
use App\Models\Position;
use App\Models\User;
use App\Support\VestixDataTransfer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\Concerns\InteractsWithSquads;
use Tests\TestCase;

class VestixDataTransferTest extends TestCase
{
    use InteractsWithSquads;
    use RefreshDatabase;

    private string $exportRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->exportRoot = storage_path('app/'.VestixDataTransfer::EXPORT_DIRECTORY.'/testing');

        if (File::isDirectory($this->exportRoot)) {
            File::deleteDirectory($this->exportRoot);
        }
    }

    protected function tearDown(): void
    {
        if (File::isDirectory($this->exportRoot)) {
            File::deleteDirectory($this->exportRoot);
        }

        parent::tearDown();
    }

    public function test_export_creates_json_files_and_manifest(): void
    {
        ['user' => $user, 'squad' => $squad] = $this->createUserWithSquad();
        Position::factory()->for($user)->create(['squad_id' => $squad->id]);

        $destination = $this->exportRoot.'/export-test';

        $this->artisan('vestix:export-data', ['--path' => $destination])
            ->assertSuccessful();

        $this->assertFileExists($destination.'/manifest.json');
        $this->assertFileExists($destination.'/users.json');
        $this->assertFileExists($destination.'/positions.json');

        $manifest = json_decode(File::get($destination.'/manifest.json'), true);
        $this->assertSame(1, $manifest['tables']['users']);
        $this->assertSame(1, $manifest['tables']['positions']);
    }

    public function test_roundtrip_preserves_users_positions_and_relationships(): void
    {
        ['user' => $user, 'squad' => $squad] = $this->createUserWithSquad();
        $asset = Asset::factory()->create();
        $position = Position::factory()->for($user)->create([
            'squad_id' => $squad->id,
            'asset_id' => $asset->id,
            'ticker' => $asset->ticker,
        ]);

        $userId = $user->id;
        $squadId = $squad->id;
        $positionId = $position->id;
        $assetId = $asset->id;
        $userEmail = $user->email;

        $destination = $this->exportRoot.'/roundtrip';

        $this->artisan('vestix:export-data', ['--path' => $destination])
            ->assertSuccessful();

        app(VestixDataTransfer::class)->wipeApplicationData();

        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('positions', 0);

        $this->artisan('vestix:import-data', ['path' => $destination])
            ->assertSuccessful();

        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('positions', 1);
        $this->assertDatabaseCount('squads', 1);
        $this->assertDatabaseCount('assets', 1);

        $this->assertDatabaseHas('users', [
            'id' => $userId,
            'email' => $userEmail,
        ]);

        $this->assertDatabaseHas('positions', [
            'id' => $positionId,
            'user_id' => $userId,
            'squad_id' => $squadId,
            'asset_id' => $assetId,
            'ticker' => $asset->ticker,
        ]);
    }

    public function test_import_refuses_non_empty_database_without_force(): void
    {
        $this->createUserWithSquad();

        $destination = $this->exportRoot.'/refuse-test';
        File::ensureDirectoryExists($destination);
        File::put($destination.'/manifest.json', json_encode(['tables' => []]));

        $this->artisan('vestix:import-data', ['path' => $destination])
            ->assertFailed();
    }

    public function test_import_dry_run_does_not_write_rows(): void
    {
        ['user' => $user, 'squad' => $squad] = $this->createUserWithSquad();
        Position::factory()->for($user)->create(['squad_id' => $squad->id]);

        $destination = $this->exportRoot.'/dry-run';
        $this->artisan('vestix:export-data', ['--path' => $destination])->assertSuccessful();

        app(VestixDataTransfer::class)->wipeApplicationData();

        $this->artisan('vestix:import-data', [
            'path' => $destination,
            '--dry-run' => true,
        ])->assertSuccessful();

        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('positions', 0);
    }

    public function test_api_credentials_remain_readable_after_roundtrip(): void
    {
        ['user' => $user] = $this->createUserWithSquad();

        ApiCredential::query()->create([
            'user_id' => $user->id,
            'provider' => 'polygon',
            'encrypted_credentials' => ['api_key' => 'secret-key-123'],
        ]);

        $destination = $this->exportRoot.'/credentials';

        $this->artisan('vestix:export-data', ['--path' => $destination])->assertSuccessful();
        app(VestixDataTransfer::class)->wipeApplicationData();
        $this->artisan('vestix:import-data', ['path' => $destination])->assertSuccessful();

        $credential = ApiCredential::query()->where('user_id', $user->id)->first();

        $this->assertNotNull($credential);
        $this->assertSame('secret-key-123', $credential->encrypted_credentials['api_key']);
    }

    public function test_export_without_notifications_skips_notifications_file(): void
    {
        $this->createUserWithSquad();

        $destination = $this->exportRoot.'/no-notifications';

        $this->artisan('vestix:export-data', [
            '--path' => $destination,
            '--no-notifications' => true,
        ])->assertSuccessful();

        $this->assertFileDoesNotExist($destination.'/notifications.json');
    }

    public function test_import_uses_latest_export_when_path_omitted(): void
    {
        ['user' => $user, 'squad' => $squad] = $this->createUserWithSquad();
        Position::factory()->for($user)->create(['squad_id' => $squad->id]);

        $exportRoot = storage_path('app/'.VestixDataTransfer::EXPORT_DIRECTORY);

        if (File::isDirectory($exportRoot)) {
            foreach (File::directories($exportRoot) as $directory) {
                File::deleteDirectory($directory);
            }
        }

        $older = $exportRoot.'/2026-01-01_000000';
        $newer = $exportRoot.'/2026-01-02_000000';

        Artisan::call('vestix:export-data', ['--path' => $older]);
        Artisan::call('vestix:export-data', ['--path' => $newer]);

        app(VestixDataTransfer::class)->wipeApplicationData();

        $transfer = app(VestixDataTransfer::class);
        $this->assertSame($newer, $transfer->findLatestExportPath());

        $this->artisan('vestix:import-data')->assertSuccessful();

        $this->assertDatabaseCount('users', 1);
    }
}
