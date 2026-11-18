<?php

declare(strict_types=1);

namespace Ranetrace\Laravel\Support;

use Illuminate\Support\Facades\Auth;
use Ranetrace\Php\Config;
use Throwable;

/**
 * This application's Ranetrace configuration, as the shared core reads it.
 *
 * `ranetrace/ranetrace-php`'s `Config` is the seam the shared payload builders
 * ask about the host: the key set deliberately mirrors `config/ranetrace.php`,
 * so the published config array goes straight in. What is added here is what a
 * framework-agnostic SDK has to be told and Laravel can simply answer: the
 * environment, the project root, the framework identity, and how to resolve the
 * current user.
 *
 * Built fresh per capture rather than bound as a singleton: `config()` is
 * mutable at runtime (and every other test changes it), and a snapshot taken at
 * boot would ship a payload describing a configuration the application no longer
 * has.
 */
final class CoreConfig
{
    /**
     * The `framework` half of the pair the error and log payloads carry.
     */
    public const string FRAMEWORK = 'laravel';

    public static function make(): Config
    {
        $values = config('ranetrace');

        return new Config([
            ...(is_array($values) ? $values : []),
            'key' => self::ingestKey(),
            'environment' => config('app.env'),
            'project_root' => base_path(),
            'framework' => self::FRAMEWORK,
            'framework_version' => app()->version(),
            'user_resolver' => self::resolveUser(...),
        ]);
    }

    /**
     * The ingest key as it would be sent, trimmed, or `''` when there is none.
     *
     * A value that is not a string is no key: `RANETRACE_KEY=true` arrives
     * from `env()` as a boolean, and a number typed into the published config
     * as an integer. The shared core throws on such a key, so it is read as
     * missing here. Every reader of the key asks here, so capture, the
     * commands, the dashboard and the client agree on one answer.
     */
    public static function ingestKey(): string
    {
        $key = config('ranetrace.key');

        return is_string($key) ? mb_trim($key) : '';
    }

    public static function hasIngestKey(): bool
    {
        return self::ingestKey() !== '';
    }

    /**
     * The authenticated user's id as an event or a browser error ships it: an
     * int or a string, else null.
     *
     * The lookup is host code, and an auth guard whose session store or user
     * provider is down throws from it. That throw costs the item its user and
     * leaves one warning in the internal log, the same warning the shared core
     * writes when {@see resolveUser()} throws on the error path, and the item is
     * still captured.
     */
    public static function authenticatedUserId(): int|string|null
    {
        try {
            $id = Auth::user()?->getAuthIdentifier();
        } catch (Throwable $e) {
            InternalLogger::warning('Ranetrace user resolver failed', [
                'exception' => $e->getMessage(),
            ]);

            return null;
        }

        return is_int($id) || is_string($id) ? $id : null;
    }

    /**
     * The authenticated user as the shared builders want them: an id, and an
     * email the builder is free to drop.
     *
     * - `getAuthIdentifier()` is on the `Authenticatable` contract, so it is
     *   safe across every implementation, Eloquent or not.
     * - The email is only READ when `errors.capture_user_email` is on, so a host
     *   whose accessor is expensive or side-effecting is not made to run it for
     *   a value that would be discarded. The builder gates it again on the same
     *   config value; the two agree by construction.
     * - `getAttribute()` returns null when the column is missing, so a host app
     *   whose User model has no `email` does not break error capture. The
     *   `method_exists` guard covers non-Eloquent custom Authenticatables;
     *   PHPStan doesn't know the host app may not use Eloquent.
     *
     * @return array{id: mixed, email: mixed}|null
     */
    private static function resolveUser(): ?array
    {
        $user = Auth::user();

        if ($user === null) {
            return null;
        }

        return [
            'id' => $user->getAuthIdentifier(),
            // @phpstan-ignore function.alreadyNarrowedType
            'email' => config('ranetrace.errors.capture_user_email', false) && method_exists($user, 'getAttribute')
                ? $user->getAttribute('email')
                : null,
        ];
    }
}
