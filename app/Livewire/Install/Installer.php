<?php

namespace App\Livewire\Install;

use App\Install\InstallationException;
use App\Install\InstalledState;
use App\Install\InstallerService;
use App\Install\InstallerSetupAuthority;
use App\Models\Series;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\File;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;
use PDOException;
use RuntimeException;
use Throwable;

#[Layout('components.layouts.installer')]
class Installer extends Component
{
    public string $setupToken = '';

    public ?string $setupError = null;

    public int $step = 0;

    public string $language = 'en';

    public string $dbConnection = 'sqlite';

    public string $dbHost = '127.0.0.1';

    public string $dbPort = '3306';

    public string $dbDatabase = '';

    public string $dbUsername = '';

    public string $dbPassword = '';

    public string $dbPrefix = '';

    public string $storageDriver = 'local';

    public string $siteTitle = 'My Manga Library';

    public string $appUrl = '';

    public string $adminName = 'admin';

    public string $adminEmail = '';

    public string $adminPassword = '';

    public string $adminPasswordConfirmation = '';

    public string $defaultDirection = 'rtl';

    public bool $matureGate = true;

    public bool $discourageIndexing = false;

    public bool $databaseOk = false;

    public ?string $databaseMessage = null;

    public ?string $installError = null;

    public bool $installed = false;

    public ?string $installedAdminEmail = null;

    public function mount(InstalledState $installed): void
    {
        if ($installed->installed()) {
            $this->redirectRoute('admin.dashboard');

            return;
        }

        $this->dbConnection = config('database.default', 'sqlite');
        $this->dbDatabase = config('database.connections.'.$this->dbConnection.'.database', database_path('database.sqlite'));
        $this->dbHost = config('database.connections.mysql.host', '127.0.0.1');
        $this->dbPort = (string) config('database.connections.mysql.port', '3306');
        $this->dbUsername = config('database.connections.mysql.username', '');
        $this->dbPrefix = config('database.connections.'.$this->dbConnection.'.prefix', '');
        $this->appUrl = request()->getSchemeAndHttpHost();
    }

    public function updated(string $property): void
    {
        if (str_starts_with($property, 'db')) {
            $this->databaseOk = false;
            $this->databaseMessage = null;
        }
    }

    public function unlock(InstallerSetupAuthority $authority): void
    {
        $this->setupError = null;

        try {
            if (! $authority->grant(request(), $this->setupToken)) {
                $this->setupError = 'That setup token is invalid. Generate the value from the server console.';

                return;
            }

            $this->setupToken = '';
        } catch (InstallationException $exception) {
            $this->setupError = $exception->publicMessage();
        }
    }

    public function chooseLanguage(string $language): void
    {
        if (! $this->authorizeAction()) {
            return;
        }

        if (array_key_exists($language, $this->languages())) {
            $this->language = $language;
        }
    }

    public function chooseStorage(string $driver): void
    {
        if (! $this->authorizeAction()) {
            return;
        }

        if ($driver === 'local') {
            $this->storageDriver = $driver;
        }
    }

    public function next(): void
    {
        if (! $this->authorizeAction()) {
            return;
        }

        if ($this->step === 1 && ! $this->requirementsPass()) {
            $this->addError('requirements', 'Fix the server requirements before continuing.');

            return;
        }

        if ($this->step === 2 && ! $this->databaseOk) {
            $this->testDatabase();

            if (! $this->databaseOk) {
                return;
            }
        }

        if ($this->step === 4) {
            $this->validateSite();
        }

        $this->step = min(5, $this->step + 1);
    }

    public function back(): void
    {
        if (! $this->authorizeAction()) {
            return;
        }

        $this->step = max(0, $this->step - 1);
    }

    public function testDatabase(): void
    {
        if (! $this->authorizeAction()) {
            return;
        }

        $this->validate($this->databaseRules());

        try {
            app(InstallerService::class)->testDatabase($this->payload());
            $this->databaseOk = true;
            $this->databaseMessage = 'Connection successful.';
        } catch (Throwable $e) {
            report($e);
            $this->databaseOk = false;
            $this->databaseMessage = $this->databaseFailureMessage($e);
        }
    }

    public function install(): void
    {
        if (! $this->authorizeAction()) {
            return;
        }

        $this->installError = null;
        $this->validate($this->rules());

        try {
            $user = app(InstallerService::class)->install($this->payload());

            Auth::login($user);
            session()->regenerate();
            app(InstallerSetupAuthority::class)->revoke(request());

            $this->installed = true;
            $this->installedAdminEmail = $user->email;
            $this->step = 5;
        } catch (InstallationException $e) {
            report($e);
            $this->installError = $e->publicMessage();
        } catch (Throwable $e) {
            report($e);
            $this->installError = '[INSTALL_FAILED] Installation did not complete. Review the application log, correct the problem, and retry.';
        }
    }

    /**
     * @return array<string, string>
     */
    protected function languages(): array
    {
        return [
            'en' => 'English',
            'ja' => 'Japanese',
            'es' => 'Spanish',
            'fr' => 'French',
            'de' => 'German',
            'pt_BR' => 'Portuguese (Brazil)',
        ];
    }

    /**
     * @return array<int, array{label:string,value:string,ok:bool,required:bool}>
     */
    public function requirements(): array
    {
        if (! app(InstallerSetupAuthority::class)->authorized(request())) {
            return [];
        }

        $databaseExtensionAvailable = $this->databaseExtensionAvailable();
        $documentRoot = $this->documentRootRequirement();
        $runtimePaths = $this->runtimePathRequirement();
        $environment = $this->environmentRequirement();
        $validApplicationKey = $this->validApplicationKey();

        return [
            [
                'label' => 'PHP version',
                'value' => PHP_VERSION.' (requires 8.3–8.5)',
                'ok' => version_compare(PHP_VERSION, '8.3.0', '>=')
                    && version_compare(PHP_VERSION, '8.6.0', '<'),
                'required' => true,
            ],
            [
                'label' => 'Web document root',
                ...$documentRoot,
                'required' => true,
            ],
            [
                'label' => $this->dbConnection === 'sqlite' ? 'SQLite support' : 'MySQL/MariaDB support',
                'value' => $databaseExtensionAvailable ? 'available' : 'missing',
                'ok' => $databaseExtensionAvailable,
                'required' => true,
            ],
            [
                'label' => 'GD image processing',
                'value' => extension_loaded('gd') ? 'available' : 'missing',
                'ok' => extension_loaded('gd'),
                'required' => true,
            ],
            [
                'label' => 'ZIP archive support',
                'value' => extension_loaded('zip') ? 'available' : 'missing',
                'ok' => extension_loaded('zip'),
                'required' => true,
            ],
            [
                'label' => 'WebP optimization',
                'value' => function_exists('imagewebp') ? 'available' : 'source-format fallback',
                'ok' => function_exists('imagewebp'),
                'required' => false,
            ],
            [
                'label' => 'Storage directory',
                'value' => is_writable(storage_path()) ? 'writable' : 'not writable',
                'ok' => is_writable(storage_path()),
                'required' => true,
            ],
            [
                'label' => 'Bootstrap cache',
                'value' => is_writable(base_path('bootstrap/cache')) ? 'writable' : 'not writable',
                'ok' => is_writable(base_path('bootstrap/cache')),
                'required' => true,
            ],
            [
                'label' => 'Runtime paths',
                ...$runtimePaths,
                'required' => true,
            ],
            [
                'label' => 'Environment file',
                ...$environment,
                'required' => ! app()->environment('testing')
                    && config('installer.environment_mode') !== 'process',
            ],
            [
                'label' => 'Application key',
                'value' => $validApplicationKey ? 'valid' : 'missing or invalid',
                'ok' => $validApplicationKey,
                'required' => true,
            ],
            [
                'label' => 'Archive upload limit',
                'value' => sprintf(
                    'PHP %s / post %s; Koma %s MB',
                    ini_get('upload_max_filesize') ?: 'unknown',
                    ini_get('post_max_size') ?: 'unknown',
                    (int) ceil((int) config('koma.archives.imports.max_archive_kb') / 1024),
                ),
                'ok' => true,
                'required' => false,
            ],
            [
                'label' => 'Public storage link',
                ...$this->storageLinkRequirement(),
                'required' => true,
            ],
        ];
    }

    protected function requirementsPass(): bool
    {
        return collect($this->requirements())
            ->every(fn (array $requirement) => ! $requirement['required'] || $requirement['ok']);
    }

    /**
     * @return array<int, array{id:string,name:string,description:string,enabled:bool,badge:string|null}>
     */
    protected function storageOptions(): array
    {
        return [
            [
                'id' => 'local',
                'name' => 'Local disk',
                'description' => 'Store pages on this server. Best for the first install.',
                'enabled' => true,
                'badge' => null,
            ],
            [
                'id' => 's3',
                'name' => 'Amazon S3 compatible',
                'description' => 'Object storage for larger libraries. Planned as a Pro setup screen.',
                'enabled' => false,
                'badge' => 'Pro later',
            ],
            [
                'id' => 'r2',
                'name' => 'Cloudflare R2',
                'description' => 'Zero-egress object storage. Planned as a Pro setup screen.',
                'enabled' => false,
                'badge' => 'Pro later',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function payload(): array
    {
        return [
            'language' => $this->language,
            'db_connection' => $this->dbConnection,
            'db_host' => $this->dbHost,
            'db_port' => $this->dbPort,
            'db_database' => $this->dbDatabase,
            'db_username' => $this->dbUsername,
            'db_password' => $this->dbPassword,
            'db_prefix' => $this->dbPrefix,
            'storage_driver' => $this->storageDriver,
            'site_title' => $this->siteTitle,
            'app_url' => $this->appUrl,
            'admin_name' => $this->adminName,
            'admin_email' => $this->adminEmail,
            'admin_password' => $this->adminPassword,
            'default_direction' => $this->defaultDirection,
            'mature_gate' => $this->matureGate,
            'discourage_indexing' => $this->discourageIndexing,
        ];
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    protected function databaseRules(): array
    {
        $connections = $this->composeRuntime() ? ['mysql', 'mariadb'] : ['sqlite', 'mysql', 'mariadb'];

        $rules = [
            'dbConnection' => ['required', Rule::in($connections)],
            'dbDatabase' => ['required', 'string', 'max:255'],
            'dbPrefix' => ['nullable', 'string', 'max:32', 'regex:/^[A-Za-z0-9_]*$/'],
        ];

        if ($this->dbConnection !== 'sqlite') {
            $rules += [
                'dbHost' => ['required', 'string', 'max:255'],
                'dbPort' => ['required', 'integer', 'min:1', 'max:65535'],
                'dbUsername' => ['required', 'string', 'max:255'],
                'dbPassword' => ['nullable', 'string', 'max:255'],
            ];
        }

        return $rules;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    protected function messages(): array
    {
        return [
            'dbConnection.in' => $this->composeRuntime()
                ? 'Docker Compose installs use the bundled MariaDB service. SQLite is not supported here.'
                : 'Choose SQLite, MySQL, or MariaDB.',
        ];
    }

    protected function composeRuntime(): bool
    {
        return config('koma.runtime') === 'docker-compose';
    }

    protected function sqliteProblem(): ?string
    {
        $database = InstallerService::sqliteDatabasePath($this->dbDatabase);

        if ($database === ':memory:') {
            return null;
        }

        $directory = dirname($database);

        return is_dir($directory) && is_writable($directory)
            ? null
            : "The web server cannot write to {$directory}. Choose a writable location or use MySQL or MariaDB.";
    }

    protected function databaseFailureMessage(Throwable $exception): string
    {
        if ($exception instanceof InstallationException) {
            return $exception->publicMessage();
        }

        $compose = $this->composeRuntime();

        for ($cause = $exception; $cause !== null; $cause = $cause->getPrevious()) {
            if ($cause instanceof RuntimeException && str_starts_with($cause->getMessage(), 'SQLite directory is not writable')) {
                return $this->sqliteProblem() ?? $cause->getMessage();
            }

            if (! $cause instanceof PDOException) {
                continue;
            }

            $code = is_int($cause->getCode()) && $cause->getCode() > 0
                ? $cause->getCode()
                : (int) ($cause->errorInfo[1] ?? 0);

            return match ($code) {
                1045 => 'The database server rejected the username or password.'
                    .($compose ? ' With Docker Compose, the password is the KOMA_DB_PASSWORD value in the Docker env file you started Compose with.' : ''),
                1044 => "That user is not allowed to use the database {$this->dbDatabase}.",
                1049 => "The database {$this->dbDatabase} does not exist on that server.",
                2002, 2005 => "Koma could not reach a database server at {$this->dbHost}:{$this->dbPort}."
                    .($compose ? ' Inside Docker Compose the database host is db.' : ''),
                default => 'Database connection failed. Verify the database details and server availability.',
            };
        }

        return 'Database connection failed. Verify the database details and server availability.';
    }

    protected function rules(): array
    {
        return $this->databaseRules() + [
            'language' => ['required', Rule::in(array_keys($this->languages()))],
            'storageDriver' => ['required', 'in:local'],
            'siteTitle' => ['required', 'string', 'max:120'],
            'appUrl' => ['required', 'url', 'max:255'],
            'adminName' => ['required', 'string', 'max:80'],
            'adminEmail' => ['required', 'email', 'max:255'],
            'adminPassword' => ['required', 'same:adminPasswordConfirmation', 'min:8', 'max:255'],
            'defaultDirection' => ['required', Rule::in(Series::DIRECTIONS)],
            'matureGate' => ['boolean'],
            'discourageIndexing' => ['boolean'],
        ];
    }

    protected function validateSite(): void
    {
        $this->validate([
            'siteTitle' => ['required', 'string', 'max:120'],
            'appUrl' => ['required', 'url', 'max:255'],
            'adminName' => ['required', 'string', 'max:80'],
            'adminEmail' => ['required', 'email', 'max:255'],
            'adminPassword' => ['required', 'same:adminPasswordConfirmation', 'min:8', 'max:255'],
            'defaultDirection' => ['required', Rule::in(Series::DIRECTIONS)],
        ]);
    }

    /**
     * @return array{value:string,ok:bool}
     */
    protected function documentRootRequirement(): array
    {
        $expected = realpath(public_path());
        $reported = request()->server('DOCUMENT_ROOT');
        $actual = is_string($reported) && $reported !== '' ? realpath($reported) : false;

        if (is_string($expected) && is_string($actual) && $actual === $expected) {
            return ['value' => 'public/ directory ready', 'ok' => true];
        }

        return [
            'value' => 'must point to the package public/ directory',
            'ok' => false,
        ];
    }

    /**
     * @return array{value:string,ok:bool}
     */
    protected function environmentRequirement(): array
    {
        if (config('installer.environment_mode') === 'process') {
            return ['value' => 'managed by process environment', 'ok' => true];
        }

        if (app()->environment('testing') && ! config('installer.verify_environment_in_tests')) {
            return ['value' => 'not checked in the test environment', 'ok' => true];
        }

        $path = (string) config('installer.environment_path', base_path('.env'));

        if (! file_exists($path)) {
            return is_writable(dirname($path))
                ? ['value' => 'will be created with mode 0600', 'ok' => true]
                : ['value' => 'directory is not writable', 'ok' => false];
        }

        if (! is_file($path)) {
            return ['value' => 'path is occupied by a non-file', 'ok' => false];
        }

        if (! is_readable($path) || ! is_writable($path)) {
            return ['value' => 'must be readable and writable by PHP', 'ok' => false];
        }

        $mode = fileperms($path) & 0777;
        if ($mode !== 0600) {
            return [
                'value' => sprintf('mode %04o is unsafe; run chmod 600 .env', $mode),
                'ok' => false,
            ];
        }

        return ['value' => 'read/write ready with mode 0600', 'ok' => true];
    }

    protected function databaseExtensionAvailable(): bool
    {
        return $this->dbConnection === 'sqlite'
            ? extension_loaded('pdo_sqlite')
            : extension_loaded('pdo_mysql');
    }

    protected function validApplicationKey(): bool
    {
        $key = (string) config('app.key', '');

        if (str_starts_with($key, 'base64:')) {
            $decoded = base64_decode(substr($key, 7), true);

            return $decoded !== false && strlen($decoded) === 32;
        }

        return strlen($key) === 32;
    }

    /**
     * @return array{value:string,ok:bool}
     */
    protected function runtimePathRequirement(): array
    {
        $paths = (array) config('installer.runtime_paths', []);

        foreach ($paths as $label => $path) {
            $probe = null;

            try {
                if (! is_string($path) || $path === '') {
                    throw new \RuntimeException('Invalid runtime path.');
                }
                File::ensureDirectoryExists($path);
                $probe = $path.'/.koma-write-probe-'.str()->uuid();

                if (File::put($probe, 'ready') === false
                    || File::get($probe) !== 'ready'
                    || ! File::delete($probe)) {
                    throw new \RuntimeException('Runtime path probe failed.');
                }
            } catch (Throwable) {
                return [
                    'value' => sprintf('%s is not create/read/delete ready', (string) $label),
                    'ok' => false,
                ];
            } finally {
                if (is_string($probe) && is_file($probe)) {
                    @unlink($probe);
                }
            }
        }

        return ['value' => 'all create/read/delete probes passed', 'ok' => true];
    }

    /**
     * @return array{value:string,ok:bool}
     */
    protected function storageLinkRequirement(): array
    {
        $link = public_path('storage');
        $target = storage_path('app/public');

        if (is_link($link)) {
            return realpath($link) === realpath($target)
                ? ['value' => 'ready', 'ok' => true]
                : ['value' => 'invalid target; installer will repair', 'ok' => true];
        }

        if (file_exists($link)) {
            return ['value' => 'occupied by a file or directory', 'ok' => false];
        }

        return ['value' => 'will create', 'ok' => is_writable(public_path())];
    }

    protected function authorizeAction(): bool
    {
        if (app(InstallerSetupAuthority::class)->authorized(request())) {
            return true;
        }

        $this->setupError = 'Enter the setup token generated on the server before continuing.';

        return false;
    }

    public function render()
    {
        $setupAuthorized = app(InstallerSetupAuthority::class)->authorized(request());

        return view('livewire.install.installer', [
            'setupAuthorized' => $setupAuthorized,
            'languages' => $setupAuthorized ? $this->languages() : [],
            'requirements' => $setupAuthorized ? $this->requirements() : [],
            'requirementsPass' => $setupAuthorized && $this->requirementsPass(),
            'storageOptions' => $setupAuthorized ? $this->storageOptions() : [],
            'composeRuntime' => $setupAuthorized && $this->composeRuntime(),
            'sqliteProblem' => $setupAuthorized && $this->dbConnection === 'sqlite' ? $this->sqliteProblem() : null,
        ]);
    }
}
