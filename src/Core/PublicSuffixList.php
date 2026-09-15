<?php

namespace SocialDept\TenantDomains\Core;

use Illuminate\Support\Facades\Cache;
use Pdp\CannotProcessHost;
use Pdp\Rules;
use Throwable;

/**
 * The Mozilla Public Suffix List, parsed.
 *
 * Parsing the raw list costs roughly 87ms, far too much to repeat. The cache
 * carries it between requests (~1ms to unserialize), and the static carries it
 * between resolutions inside one process, which is what keeps a test suite from
 * re-parsing on every test.
 *
 * A hand-maintained suffix table is the alternative, and it is wrong: one source
 * app carried 20 entries and misclassified every registrable domain outside
 * them, which decides whether a tenant is told to create a CNAME or an A record.
 */
class PublicSuffixList
{
    private static ?Rules $rules = null;

    public static function rules(): Rules
    {
        if (self::$rules instanceof Rules) {
            return self::$rules;
        }

        $path = self::path();

        // Keyed by mtime so a refreshed list invalidates the cache on its own.
        $key = 'tenant-domains:psl:'.@filemtime($path);

        try {
            return self::$rules = Cache::rememberForever($key, fn (): Rules => Rules::fromPath($path));
        } catch (Throwable) {
            // No usable cache store, which the core allows. Fall back to the static.
            return self::$rules = Rules::fromPath($path);
        }
    }

    /**
     * The part of a hostname below its registrable domain, or null when the
     * hostname *is* the registrable domain.
     *
     * `blog.example.com` yields `blog`, `example.com` yields null, and
     * `example.co.uk` yields null because the list knows `co.uk` is a suffix.
     */
    public static function subDomain(string $host): ?string
    {
        try {
            return self::rules()->resolve($host)->subDomain()->value();
        } catch (CannotProcessHost) {
            // Apex is the safe answer: an A record works everywhere, an apex CNAME does not.
            return null;
        }
    }

    /**
     * The registrable domain. `example.co.uk` for `blog.example.co.uk`.
     */
    public static function registrableDomain(string $host): ?string
    {
        try {
            return self::rules()->resolve($host)->registrableDomain()->value();
        } catch (CannotProcessHost) {
            return null;
        }
    }

    /**
     * Where the list file lives. Configurable so an app can point at a copy it
     * refreshes on its own schedule.
     */
    public static function path(): string
    {
        $configured = self::configuredPath();

        if ($configured !== null && is_file($configured)) {
            return $configured;
        }

        return dirname(__DIR__, 2).'/resources/data/public_suffix_list.dat';
    }

    /**
     * Drop the memoised rules. Needed when the list file changes mid-process.
     */
    public static function flush(): void
    {
        self::$rules = null;
    }

    private static function configuredPath(): ?string
    {
        if (! function_exists('config')) {
            return null;
        }

        try {
            $path = config('tenant-domains.psl_path');
        } catch (Throwable) {
            return null;
        }

        return is_string($path) && $path !== '' ? $path : null;
    }
}
