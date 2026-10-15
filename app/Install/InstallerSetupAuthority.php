<?php

namespace App\Install;

use Illuminate\Http\Request;
use Illuminate\Session\Store;

class InstallerSetupAuthority
{
    private const SESSION_KEY = 'koma.installer.setup_grant';

    public function token(): string
    {
        return hash_hmac('sha256', 'koma-installer-setup-token-v1', $this->applicationSecret());
    }

    public function grant(Request $request, string $providedToken): bool
    {
        if (! hash_equals($this->token(), trim($providedToken))) {
            return false;
        }

        $session = $this->session($request);
        $session->migrate(true);
        $session->put(self::SESSION_KEY, $this->sessionGrant());

        return true;
    }

    public function authorized(Request $request): bool
    {
        $grant = $this->session($request)->get(self::SESSION_KEY);

        return is_string($grant) && hash_equals($this->sessionGrant(), $grant);
    }

    public function revoke(Request $request): void
    {
        $this->session($request)->forget(self::SESSION_KEY);
    }

    public function sessionGrant(): string
    {
        return hash_hmac('sha256', 'koma-installer-session-grant-v1', $this->applicationSecret());
    }

    private function applicationSecret(): string
    {
        $key = (string) config('app.key', '');

        if (str_starts_with($key, 'base64:')) {
            $decoded = base64_decode(substr($key, 7), true);
            if (is_string($decoded) && strlen($decoded) === 32) {
                return $decoded;
            }
        } elseif (strlen($key) === 32) {
            return $key;
        }

        throw new InstallationException(
            'INVALID_APP_KEY',
            'Generate a valid application key before opening the installer.',
        );
    }

    private function session(Request $request): Store
    {
        return $request->hasSession()
            ? $request->session()
            : app('session')->driver();
    }
}
