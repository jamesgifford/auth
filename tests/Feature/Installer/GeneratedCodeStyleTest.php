<?php

declare(strict_types=1);

namespace JamesGifford\Auth\Tests\Feature\Installer;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use JamesGifford\Auth\Installer\DatabaseSeederWiring;
use JamesGifford\Auth\Installer\ModelPublisher;
use JamesGifford\Auth\Installer\PackageMigrations;
use JamesGifford\Auth\Installer\UserModelModifier;
use JamesGifford\Auth\Tests\Support\StagesDatabaseSeeder;
use JamesGifford\Auth\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;

/**
 * Every file the installer writes into a consuming app must survive that
 * app's `pint --test` untouched. A fresh Laravel app's pint.json is the bare
 * "laravel" preset, so that is the style checked here — NOT this package's
 * own stricter pint.json.
 *
 * Each case runs the real installer code against a stock Laravel file, then
 * lets Pint (a dev dependency of this package) FIX a copy: any difference is
 * exactly the diff a consumer's lint check would fail on.
 */
class GeneratedCodeStyleTest extends TestCase
{
    use StagesDatabaseSeeder;

    /** Laravel 13's app/Models/User.php. */
    private const SKELETON_USER = <<<'PHP'
    <?php

    namespace App\Models;

    // use Illuminate\Contracts\Auth\MustVerifyEmail;
    use Database\Factories\UserFactory;
    use Illuminate\Database\Eloquent\Attributes\Fillable;
    use Illuminate\Database\Eloquent\Attributes\Hidden;
    use Illuminate\Database\Eloquent\Factories\HasFactory;
    use Illuminate\Foundation\Auth\User as Authenticatable;
    use Illuminate\Notifications\Notifiable;

    #[Fillable(['name', 'email', 'password'])]
    #[Hidden(['password', 'remember_token'])]
    class User extends Authenticatable
    {
        /** @use HasFactory<UserFactory> */
        use HasFactory, Notifiable;

        /**
         * Get the attributes that should be cast.
         *
         * @return array<string, string>
         */
        protected function casts(): array
        {
            return [
                'email_verified_at' => 'datetime',
                'password' => 'hashed',
            ];
        }
    }

    PHP;

    /** The Livewire starter kit's User: more imports and traits, a public method last. */
    private const STARTER_KIT_USER = <<<'PHP'
    <?php

    namespace App\Models;

    use Database\Factories\UserFactory;
    use Illuminate\Contracts\Auth\MustVerifyEmail;
    use Illuminate\Database\Eloquent\Attributes\Fillable;
    use Illuminate\Database\Eloquent\Attributes\Hidden;
    use Illuminate\Database\Eloquent\Factories\HasFactory;
    use Illuminate\Foundation\Auth\User as Authenticatable;
    use Illuminate\Notifications\Notifiable;
    use Illuminate\Support\Str;
    use Laravel\Fortify\Contracts\PasskeyUser;
    use Laravel\Fortify\PasskeyAuthenticatable;
    use Laravel\Fortify\TwoFactorAuthenticatable;

    #[Fillable(['name', 'email', 'password'])]
    #[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
    class User extends Authenticatable implements MustVerifyEmail, PasskeyUser
    {
        /** @use HasFactory<UserFactory> */
        use HasFactory, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

        /**
         * Get the attributes that should be cast.
         *
         * @return array<string, string>
         */
        protected function casts(): array
        {
            return [
                'email_verified_at' => 'datetime',
                'password' => 'hashed',
            ];
        }

        /**
         * Get the user's initials
         */
        public function initials(): string
        {
            $initials = Str::initials($this->name, true);

            return Str::length($initials) > 1
                ? Str::substr($initials, 0, 1).Str::substr($initials, -1)
                : $initials;
        }
    }

    PHP;

    /** A User with no trait use at all, so the package must start the trait block itself. */
    private const TRAITLESS_USER = <<<'PHP'
    <?php

    namespace App\Models;

    use Illuminate\Foundation\Auth\User as Authenticatable;

    class User extends Authenticatable
    {
        protected $fillable = [
            'name',
            'email',
            'password',
        ];
    }

    PHP;

    /** Laravel 11+'s database/seeders/DatabaseSeeder.php. */
    private const SKELETON_SEEDER = <<<'PHP'
    <?php

    namespace Database\Seeders;

    use App\Models\User;
    use Illuminate\Database\Console\Seeds\WithoutModelEvents;
    use Illuminate\Database\Seeder;

    class DatabaseSeeder extends Seeder
    {
        use WithoutModelEvents;

        /**
         * Seed the application's database.
         */
        public function run(): void
        {
            // User::factory(10)->create();

            User::factory()->create([
                'name' => 'Test User',
                'email' => 'test@example.com',
            ]);
        }
    }

    PHP;

    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'jamesgifford-style-'.uniqid('', true);
        mkdir($this->tmpDir, 0777, true);
        file_put_contents($this->pintConfigPath(), (string) json_encode(['preset' => 'laravel']));
    }

    protected function tearDown(): void
    {
        $this->removeDatabaseSeeder();

        $this->app->make(PackageMigrations::class)->deletePublishedFiles(static function (): void {});

        $publisher = $this->app->make(ModelPublisher::class);
        foreach ($publisher->candidatePaths() as $candidate) {
            @unlink($candidate['path']);
        }
        @unlink($publisher->publishedConfigPath());

        (new Filesystem)->deleteDirectory($this->tmpDir);

        parent::tearDown();
    }

    public function test_published_migrations_pass_pint(): void
    {
        $migrations = $this->app->make(PackageMigrations::class);
        $migrations->publish(static function (): void {});

        $files = [];
        foreach ($migrations->publishedFiles() as $path) {
            $files['database/migrations/'.basename($path)] = (string) file_get_contents($path);
        }

        $this->assertCount(count($migrations->stems()), $files);
        $this->assertPassesPint($files);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function userModels(): array
    {
        return [
            'laravel skeleton' => [self::SKELETON_USER],
            'starter kit' => [self::STARTER_KIT_USER],
            'no traits' => [self::TRAITLESS_USER],
        ];
    }

    #[DataProvider('userModels')]
    public function test_user_model_install_edit_passes_pint_and_uninstall_restores_it(string $stock): void
    {
        $modifier = $this->app->make(UserModelModifier::class);
        $path = $this->tmpDir.DIRECTORY_SEPARATOR.'User.php';
        file_put_contents($path, $stock);

        $installed = $modifier->modify($path, $modifier->analyze($path))->modifiedCode;

        $this->assertPassesPint([
            'app/Models/User.php (stock)' => $stock,
            'app/Models/User.php (installed)' => $installed,
        ]);

        file_put_contents($path, $installed);
        $uninstalled = $modifier->reverseModify($path, $modifier->analyze($path))->modifiedCode;

        $this->assertSame($stock, $uninstalled, 'Uninstall should return the model to its stock text.');
    }

    public function test_database_seeder_wiring_passes_pint_and_unwiring_restores_it(): void
    {
        $this->stageDatabaseSeeder(self::SKELETON_SEEDER);
        $wiring = $this->app->make(DatabaseSeederWiring::class);

        $change = $wiring->wire($wiring->analyze(), DatabaseSeederWiring::CANONICAL_ORDER);

        $this->assertSame(DatabaseSeederWiring::CANONICAL_ORDER, $change->addedSeeders);
        $this->assertPassesPint([
            'database/seeders/DatabaseSeeder.php (stock)' => self::SKELETON_SEEDER,
            'database/seeders/DatabaseSeeder.php (wired)' => $change->modifiedCode,
        ]);

        $wiring->commit($change);
        $unwired = $wiring->unwire($wiring->analyze())->modifiedCode;

        $this->assertSame(self::SKELETON_SEEDER, $unwired, 'Unwiring should return the seeder to its stock text.');
    }

    public function test_generated_database_seeder_passes_pint(): void
    {
        $wiring = $this->app->make(DatabaseSeederWiring::class);

        $this->assertPassesPint([
            'database/seeders/DatabaseSeeder.php' => $wiring->stub(DatabaseSeederWiring::CANONICAL_ORDER),
        ]);
    }

    public function test_published_config_and_models_pass_pint(): void
    {
        Artisan::call('jamesgifford:auth:publish-models');
        $publisher = $this->app->make(ModelPublisher::class);

        $files = ['config/jamesgifford/auth.php' => (string) file_get_contents($publisher->publishedConfigPath())];
        foreach ($publisher->candidatePaths() as $candidate) {
            $files['app/Models/'.$candidate['name'].'.php'] = (string) file_get_contents($candidate['path']);
        }

        $this->assertStringContainsString("'account' => App\\Models\\Account::class,", $files['config/jamesgifford/auth.php']);
        $this->assertPassesPint($files);
    }

    /**
     * Write each file under its app-relative path (Pint's rules can depend on
     * the filename), let Pint fix them all in one run, and assert every file
     * came back unchanged. assertSame's diff shows exactly what Pint rewrote.
     *
     * @param  array<string, string>  $files  label (app-relative path, optional note) => contents
     */
    private function assertPassesPint(array $files): void
    {
        $root = $this->tmpDir.DIRECTORY_SEPARATOR.'lint-'.uniqid();
        $paths = [];

        foreach (array_keys($files) as $index => $label) {
            $relative = strtok($label, ' ');
            $path = $root.DIRECTORY_SEPARATOR.$index.DIRECTORY_SEPARATOR.$relative;
            @mkdir(dirname($path), 0777, true);
            file_put_contents($path, $files[$label]);
            $paths[$label] = $path;
        }

        $pint = dirname(__DIR__, 3).DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR.'bin'.DIRECTORY_SEPARATOR.'pint';
        $process = new Process([PHP_BINARY, $pint, '--config='.$this->pintConfigPath(), $root]);
        $process->run();

        $this->assertTrue(
            $process->isSuccessful(),
            "Pint failed to run:\n".$process->getOutput().$process->getErrorOutput(),
        );

        foreach ($paths as $label => $path) {
            $this->assertSame(
                $files[$label],
                (string) file_get_contents($path),
                "Pint's laravel preset would reformat {$label}.",
            );
        }
    }

    private function pintConfigPath(): string
    {
        return $this->tmpDir.DIRECTORY_SEPARATOR.'pint.json';
    }
}
