<div class="min-h-screen overflow-y-auto px-4 py-10 sm:px-6">
    <div class="mx-auto w-full max-w-[640px]">
        <div class="mb-8 flex flex-col items-center gap-3 text-center">
            <div class="flex h-14 w-14 items-center justify-center rounded-2xl bg-koma font-display text-xl font-extrabold text-white shadow-lg shadow-koma/30">KE</div>
            <div>
                <div class="font-display text-[22px] font-extrabold tracking-tight">Koma Engine</div>
                <div class="mt-0.5 text-[12.5px] text-faint">Community Edition installer</div>
            </div>
        </div>

        @if (! $setupAuthorized && ! $installed)
            <div class="rounded-[18px] border border-line bg-white p-6 shadow-sm sm:p-7">
                <h1 class="font-display text-[21px] font-extrabold">Authorize this installer</h1>
                <p class="mt-1.5 text-[13.5px] leading-6 text-muted">Run <code class="rounded bg-paper px-1.5 py-0.5 font-mono text-[12px]">php artisan koma:installer-token</code> on this server, then enter the token below. This prevents a visitor from claiming an unfinished site.</p>
                <div class="mt-5">
                    <label for="setup-token" class="mb-1.5 block text-[11.5px] font-bold uppercase tracking-wide text-muted">Setup token</label>
                    <input id="setup-token" type="password" wire:model="setupToken" autocomplete="off" class="h-[42px] w-full rounded-[10px] border border-line px-3 font-mono text-[13px] outline-none focus:border-koma">
                </div>
                @if ($setupError)
                    <div class="mt-4 rounded-[10px] bg-koma-soft px-4 py-3 text-[13px] font-semibold text-koma">{{ $setupError }}</div>
                @endif
                <button type="button" wire:click="unlock" class="mt-6 w-full rounded-[11px] bg-koma px-4 py-3 text-sm font-bold text-white hover:bg-koma-hover" wire:loading.attr="disabled" wire:target="unlock">
                    <span wire:loading.remove wire:target="unlock">Unlock setup</span>
                    <span wire:loading wire:target="unlock">Checking...</span>
                </button>
            </div>
        @else
        @if ($step < 5)
            @php
                $steps = ['Language', 'Requirements', 'Database', 'Storage', 'Site'];
            @endphp
            <div class="mb-7 flex items-start justify-center">
                @foreach ($steps as $i => $label)
                    @php
                        $done = $step > $i;
                        $active = $step === $i;
                    @endphp
                    <div class="flex items-start">
                        <div class="flex w-[74px] flex-col items-center gap-1.5">
                            <div @class([
                                'flex h-8 w-8 items-center justify-center rounded-full text-[12px] font-extrabold',
                                'bg-koma text-white' => $active,
                                'bg-[oklch(0.7_0.13_150_/_0.18)] text-[oklch(0.42_0.12_150)]' => $done,
                                'bg-[#ece7e1] text-faint' => ! $active && ! $done,
                            ])>{{ $done ? 'OK' : $i + 1 }}</div>
                            <div @class([
                                'text-center text-[10.5px] font-bold',
                                'text-[#1c1a18]' => $active,
                                'text-[oklch(0.42_0.12_150)]' => $done,
                                'text-faint' => ! $active && ! $done,
                            ])>{{ $label }}</div>
                        </div>
                        @if ($i < count($steps) - 1)
                            <div @class([
                                'mt-4 h-0.5 w-7',
                                'bg-[oklch(0.7_0.13_150_/_0.4)]' => $done,
                                'bg-[#e6e0d9]' => ! $done,
                            ])></div>
                        @endif
                    </div>
                @endforeach
            </div>
        @endif

        <div class="rounded-[18px] border border-line bg-white p-6 shadow-sm sm:p-7">
            @if ($step === 0)
                <h1 class="font-display text-[21px] font-extrabold">Choose your language</h1>
                <p class="mt-1.5 text-[13.5px] leading-6 text-muted">This sets the default language for the first site. More localization work can be added later.</p>

                <div class="mt-5 flex flex-col gap-2">
                    @foreach ($languages as $key => $name)
                        <button type="button" wire:click="chooseLanguage('{{ $key }}')" @class([
                            'flex w-full items-center justify-between rounded-[10px] border px-3.5 py-3 text-left text-[13.5px] transition',
                            'border-koma bg-koma-soft font-bold' => $language === $key,
                            'border-line bg-white font-medium hover:border-koma/50' => $language !== $key,
                        ])>
                            <span>{{ $name }}</span>
                            <span class="font-mono text-xs text-faint">{{ $key }}</span>
                        </button>
                    @endforeach
                </div>

                <button type="button" wire:click="next" class="mt-6 w-full rounded-[11px] bg-koma px-4 py-3 text-sm font-bold text-white hover:bg-koma-hover">Continue</button>
            @elseif ($step === 1)
                <h1 class="font-display text-[21px] font-extrabold">Welcome to the five-minute install</h1>
                <p class="mt-1.5 text-[13.5px] leading-6 text-[#57524c]">Before we begin, Koma checked this server. @if ($composeRuntime) Docker Compose already provides the database; you will only need its password from your Docker env file. @else You will also need your database details from your hosting provider, unless you are using SQLite. @endif</p>

                <div class="mt-5 overflow-hidden rounded-xl border border-line-soft">
                    @foreach ($requirements as $requirement)
                        <div class="flex items-center gap-3 border-b border-line-soft px-4 py-3 last:border-b-0">
                            <span @class([
                                'flex h-6 w-6 shrink-0 items-center justify-center rounded-full text-[10px] font-extrabold',
                                'bg-[oklch(0.7_0.13_150_/_0.15)] text-[oklch(0.42_0.12_150)]' => $requirement['ok'],
                                'bg-koma-soft text-koma' => ! $requirement['ok'],
                            ])>{{ $requirement['ok'] ? 'OK' : 'NO' }}</span>
                            <span class="flex-1 text-[13.5px] font-semibold">{{ $requirement['label'] }}</span>
                            <span class="font-mono text-[12px] text-faint">{{ $requirement['value'] }}</span>
                        </div>
                    @endforeach
                </div>
                @error('requirements') <p class="mt-3 text-[12.5px] font-semibold text-koma">{{ $message }}</p> @enderror

                <div class="mt-6 flex gap-2.5">
                    <button type="button" wire:click="back" class="rounded-[11px] bg-paper px-5 py-3 text-sm font-semibold text-[#57524c] hover:bg-line">Back</button>
                    <button type="button" wire:click="next" @class([
                        'flex-1 rounded-[11px] px-4 py-3 text-sm font-bold text-white',
                        'bg-koma hover:bg-koma-hover' => $requirementsPass,
                        'cursor-not-allowed bg-[#cabfb4]' => ! $requirementsPass,
                    ])>Continue</button>
                </div>
            @elseif ($step === 2)
                <h1 class="font-display text-[21px] font-extrabold">Database connection</h1>
                <p class="mt-1.5 text-[13.5px] leading-6 text-muted">Koma stores users, series, chapters, settings, and plugin state in the database.</p>

                <div class="mt-5 flex flex-col gap-3">
                    <div>
                        <label class="mb-1.5 block text-[11.5px] font-bold uppercase tracking-wide text-muted">Database type</label>
                        <select wire:model.live="dbConnection" class="h-[42px] w-full rounded-[10px] border border-line bg-white px-3 text-[13.5px] outline-none focus:border-koma">
                            <option value="sqlite" @disabled($composeRuntime)>SQLite{{ $composeRuntime ? ' (not used with Docker Compose)' : '' }}</option>
                            <option value="mysql">MySQL</option>
                            <option value="mariadb">MariaDB</option>
                        </select>
                        @error('dbConnection') <p class="mt-1 text-[12px] font-semibold text-koma">{{ $message }}</p> @enderror
                        @if ($composeRuntime)
                            <p class="mt-1.5 text-[12.5px] leading-5 text-muted">Docker Compose provides this MariaDB service. Keep the host <span class="font-mono">db</span>; the password is <span class="font-mono">KOMA_DB_PASSWORD</span> in the Docker env file you started Compose with.</p>
                        @endif
                    </div>

                    @if ($dbConnection === 'sqlite')
                        <div>
                            <label class="mb-1.5 block text-[11.5px] font-bold uppercase tracking-wide text-muted">SQLite database path</label>
                            <input wire:model.live="dbDatabase" class="h-[42px] w-full rounded-[10px] border border-line px-3 font-mono text-[13px] outline-none focus:border-koma">
                            @error('dbDatabase') <p class="mt-1 text-[12px] font-semibold text-koma">{{ $message }}</p> @enderror
                            @if ($sqliteProblem) <p class="mt-1 text-[12px] font-semibold text-koma">{{ $sqliteProblem }}</p> @endif
                        </div>
                    @else
                        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                            <div>
                                <label class="mb-1.5 block text-[11.5px] font-bold uppercase tracking-wide text-muted">Host</label>
                                <input wire:model.live="dbHost" class="h-[42px] w-full rounded-[10px] border border-line px-3 font-mono text-[13px] outline-none focus:border-koma">
                                @error('dbHost') <p class="mt-1 text-[12px] font-semibold text-koma">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label class="mb-1.5 block text-[11.5px] font-bold uppercase tracking-wide text-muted">Port</label>
                                <input wire:model.live="dbPort" class="h-[42px] w-full rounded-[10px] border border-line px-3 font-mono text-[13px] outline-none focus:border-koma">
                                @error('dbPort') <p class="mt-1 text-[12px] font-semibold text-koma">{{ $message }}</p> @enderror
                            </div>
                        </div>
                        <div>
                            <label class="mb-1.5 block text-[11.5px] font-bold uppercase tracking-wide text-muted">Database name</label>
                            <input wire:model.live="dbDatabase" class="h-[42px] w-full rounded-[10px] border border-line px-3 font-mono text-[13px] outline-none focus:border-koma">
                            @error('dbDatabase') <p class="mt-1 text-[12px] font-semibold text-koma">{{ $message }}</p> @enderror
                        </div>
                        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                            <div>
                                <label class="mb-1.5 block text-[11.5px] font-bold uppercase tracking-wide text-muted">Username</label>
                                <input wire:model.live="dbUsername" class="h-[42px] w-full rounded-[10px] border border-line px-3 font-mono text-[13px] outline-none focus:border-koma">
                                @error('dbUsername') <p class="mt-1 text-[12px] font-semibold text-koma">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label class="mb-1.5 block text-[11.5px] font-bold uppercase tracking-wide text-muted">Password</label>
                                <input type="password" wire:model.live="dbPassword" class="h-[42px] w-full rounded-[10px] border border-line px-3 font-mono text-[13px] outline-none focus:border-koma">
                            </div>
                        </div>
                    @endif

                    <div>
                        <label class="mb-1.5 block text-[11.5px] font-bold uppercase tracking-wide text-muted">Table prefix</label>
                        <input wire:model.live="dbPrefix" placeholder="Optional" class="h-[42px] w-full rounded-[10px] border border-line px-3 font-mono text-[13px] outline-none focus:border-koma">
                        @error('dbPrefix') <p class="mt-1 text-[12px] font-semibold text-koma">{{ $message }}</p> @enderror
                    </div>
                </div>

                @if ($databaseMessage)
                    <div @class([
                        'mt-4 rounded-[10px] px-4 py-3 text-[13px] font-semibold',
                        'bg-[oklch(0.7_0.13_150_/_0.14)] text-[oklch(0.42_0.12_150)]' => $databaseOk,
                        'bg-koma-soft text-koma' => ! $databaseOk,
                    ])>{{ $databaseMessage }}</div>
                @endif

                <div class="mt-6 flex gap-2.5">
                    <button type="button" wire:click="back" class="rounded-[11px] bg-paper px-5 py-3 text-sm font-semibold text-[#57524c] hover:bg-line">Back</button>
                    <button type="button" wire:click="testDatabase" class="flex-1 rounded-[11px] bg-ink px-4 py-3 text-sm font-bold text-white hover:bg-ink-panel" wire:loading.attr="disabled" wire:target="testDatabase">
                        <span wire:loading.remove wire:target="testDatabase">Test connection</span>
                        <span wire:loading wire:target="testDatabase">Testing...</span>
                    </button>
                    @if ($databaseOk)
                        <button type="button" wire:click="next" class="flex-1 rounded-[11px] bg-koma px-4 py-3 text-sm font-bold text-white hover:bg-koma-hover">Continue</button>
                    @endif
                </div>
            @elseif ($step === 3)
                <h1 class="font-display text-[21px] font-extrabold">Where should pages live?</h1>
                <p class="mt-1.5 text-[13.5px] leading-6 text-muted">Local disk is the Community Edition setup path today. Object-storage integrations are not included; they are deferred to separately distributed Pro modules or a future managed service.</p>

                <div class="mt-5 flex flex-col gap-3">
                    @foreach ($storageOptions as $option)
                        <button type="button" wire:click="chooseStorage('{{ $option['id'] }}')" @disabled(! $option['enabled']) @class([
                            'flex w-full items-center gap-3 rounded-xl border-2 px-4 py-3 text-left transition',
                            'border-koma bg-koma-soft' => $storageDriver === $option['id'],
                            'border-line-soft bg-white hover:border-koma/40' => $storageDriver !== $option['id'] && $option['enabled'],
                            'cursor-not-allowed border-line-soft bg-[#faf8f6] opacity-70' => ! $option['enabled'],
                        ])>
                            <span @class([
                                'flex h-5 w-5 shrink-0 items-center justify-center rounded-full border-2',
                                'border-koma' => $storageDriver === $option['id'],
                                'border-[#d6cfc7]' => $storageDriver !== $option['id'],
                            ])>
                                <span @class([
                                    'h-2.5 w-2.5 rounded-full',
                                    'bg-koma' => $storageDriver === $option['id'],
                                ])></span>
                            </span>
                            <span class="flex-1">
                                <span class="block text-sm font-bold">{{ $option['name'] }}</span>
                                <span class="mt-0.5 block text-[12.5px] text-faint">{{ $option['description'] }}</span>
                            </span>
                            @if ($option['badge'])
                                <span class="rounded-md bg-koma px-2 py-1 text-[10px] font-extrabold uppercase tracking-wide text-white">{{ $option['badge'] }}</span>
                            @endif
                        </button>
                    @endforeach
                </div>

                <div class="mt-6 flex gap-2.5">
                    <button type="button" wire:click="back" class="rounded-[11px] bg-paper px-5 py-3 text-sm font-semibold text-[#57524c] hover:bg-line">Back</button>
                    <button type="button" wire:click="next" class="flex-1 rounded-[11px] bg-koma px-4 py-3 text-sm font-bold text-white hover:bg-koma-hover">Continue</button>
                </div>
            @elseif ($step === 4)
                <h1 class="font-display text-[21px] font-extrabold">Set up your site</h1>
                <p class="mt-1.5 text-[13.5px] leading-6 text-muted">This creates your first administrator and writes the core site settings.</p>
                <p class="mt-2 text-[12.5px] leading-5 text-muted">Before publishing, confirm that you have permission to publish each work and add your own contact and policy information in Settings → Privacy &amp; Legal. Koma Engine does not verify rights or determine your legal obligations.</p>

                <div class="mt-5 flex flex-col gap-3">
                    <div>
                        <label class="mb-1.5 block text-[11.5px] font-bold uppercase tracking-wide text-muted">Site title</label>
                        <input wire:model.live="siteTitle" class="h-[42px] w-full rounded-[10px] border border-line px-3 text-sm font-semibold outline-none focus:border-koma">
                        @error('siteTitle') <p class="mt-1 text-[12px] font-semibold text-koma">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="mb-1.5 block text-[11.5px] font-bold uppercase tracking-wide text-muted">Site URL</label>
                        <input wire:model.live="appUrl" class="h-[42px] w-full rounded-[10px] border border-line px-3 font-mono text-[13px] outline-none focus:border-koma">
                        @error('appUrl') <p class="mt-1 text-[12px] font-semibold text-koma">{{ $message }}</p> @enderror
                    </div>
                    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                        <div>
                            <label class="mb-1.5 block text-[11.5px] font-bold uppercase tracking-wide text-muted">Admin name</label>
                            <input wire:model.live="adminName" class="h-[42px] w-full rounded-[10px] border border-line px-3 text-[13.5px] outline-none focus:border-koma">
                            @error('adminName') <p class="mt-1 text-[12px] font-semibold text-koma">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="mb-1.5 block text-[11.5px] font-bold uppercase tracking-wide text-muted">Admin email</label>
                            <input wire:model.live="adminEmail" class="h-[42px] w-full rounded-[10px] border border-line px-3 text-[13.5px] outline-none focus:border-koma">
                            @error('adminEmail') <p class="mt-1 text-[12px] font-semibold text-koma">{{ $message }}</p> @enderror
                        </div>
                    </div>
                    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                        <div>
                            <label class="mb-1.5 block text-[11.5px] font-bold uppercase tracking-wide text-muted">Password</label>
                            <input type="password" wire:model.live="adminPassword" class="h-[42px] w-full rounded-[10px] border border-line px-3 font-mono text-[13px] outline-none focus:border-koma">
                            @error('adminPassword') <p class="mt-1 text-[12px] font-semibold text-koma">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="mb-1.5 block text-[11.5px] font-bold uppercase tracking-wide text-muted">Confirm password</label>
                            <input type="password" wire:model.live="adminPasswordConfirmation" class="h-[42px] w-full rounded-[10px] border border-line px-3 font-mono text-[13px] outline-none focus:border-koma">
                        </div>
                    </div>
                    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                        <div>
                            <label class="mb-1.5 block text-[11.5px] font-bold uppercase tracking-wide text-muted">Default reader mode</label>
                            <select wire:model.live="defaultDirection" class="h-[42px] w-full rounded-[10px] border border-line bg-white px-3 text-[13.5px] outline-none focus:border-koma">
                                @foreach (\App\Models\Series::DIRECTIONS as $direction)
                                    <option value="{{ $direction }}">{{ ucfirst($direction) }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="flex items-end">
                            <label class="flex h-[42px] w-full items-center gap-2 rounded-[10px] border border-line px-3 text-[13px] font-semibold">
                                <input type="checkbox" wire:model.live="matureGate" class="h-4 w-4 accent-koma">
                                Mature-content gate
                            </label>
                        </div>
                    </div>
                    <label class="flex items-center gap-2 pt-1 text-[13px] font-semibold text-[#57524c]">
                        <input type="checkbox" wire:model.live="discourageIndexing" class="h-4 w-4 accent-koma">
                        Discourage search engines until the site is ready
                    </label>
                </div>

                @if ($installError)
                    <div class="mt-4 rounded-[10px] bg-koma-soft px-4 py-3 text-[13px] font-semibold text-koma">{{ $installError }}</div>
                @endif

                <div class="mt-6 flex gap-2.5">
                    <button type="button" wire:click="back" class="rounded-[11px] bg-paper px-5 py-3 text-sm font-semibold text-[#57524c] hover:bg-line">Back</button>
                    <button type="button" wire:click="install" class="flex-1 rounded-[11px] bg-koma px-4 py-3 text-sm font-bold text-white hover:bg-koma-hover" wire:loading.attr="disabled" wire:target="install">
                        <span wire:loading.remove wire:target="install">Install Koma Engine</span>
                        <span wire:loading wire:target="install">Installing...</span>
                    </button>
                </div>
            @else
                @if ($installed)
                    <div class="py-2 text-center">
                        <div class="mx-auto mb-4 flex h-16 w-16 items-center justify-center rounded-full bg-[oklch(0.7_0.13_150_/_0.15)] text-sm font-extrabold text-[oklch(0.42_0.12_150)]">OK</div>
                        <h1 class="font-display text-2xl font-extrabold">Koma Engine is installed</h1>
                        <p class="mx-auto mt-2 max-w-md text-sm leading-6 text-[#57524c]">Your site is ready. Sign in with {{ $installedAdminEmail }} and start building your manga library.</p>
                        <div class="mt-6 rounded-xl bg-[#faf8f6] p-4 text-left">
                            <div class="grid grid-cols-1 gap-3 text-sm sm:grid-cols-3">
                                <div><div class="text-[11px] font-bold uppercase text-faint">Theme</div><div class="mt-1 font-bold">Aozora</div></div>
                                <div><div class="text-[11px] font-bold uppercase text-faint">Storage</div><div class="mt-1 font-bold">Local disk</div></div>
                                <div><div class="text-[11px] font-bold uppercase text-faint">Role</div><div class="mt-1 font-bold">Administrator</div></div>
                            </div>
                        </div>
                        <a href="{{ route('admin.dashboard') }}" class="mt-6 block rounded-[11px] bg-koma px-4 py-3 text-sm font-bold text-white hover:bg-koma-hover">Open dashboard</a>
                    </div>
                @endif
            @endif
        </div>
        @endif

        <p class="mt-6 text-center text-xs text-faint">Need help? Koma Engine is open-source software for legitimate manga and webcomic publishing.</p>
    </div>
</div>
