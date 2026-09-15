<?php

namespace SocialDept\TenantDomains\Core;

use Random\RandomException;

/**
 * Mints the id that names a domain's ACME delegation target.
 *
 * The alphabet omits look-alike characters (0/o, 1/l/i, 5/s, 8/b) because these
 * get read over the phone and typed into a DNS panel by hand.
 *
 * **Never regenerate an id that has been issued.** The tenant may already have
 * `_acme-challenge` CNAMEd to it. A new one silently invalidates their DNS, so
 * no certificate ever issues and every hostname under the domain goes dark at
 * the next renewal.
 */
final class DelegationId
{
    public const DEFAULT_ALPHABET = '234567bdghjklmnpqrvwxyz';

    /**
     * @throws RandomException
     */
    public static function generate(int $length = 12, string $alphabet = self::DEFAULT_ALPHABET): string
    {
        $max = strlen($alphabet) - 1;
        $id = '';

        for ($i = 0; $i < $length; $i++) {
            $id .= $alphabet[random_int(0, $max)];
        }

        return $id;
    }

    /**
     * Whether a string is shaped like one of ours, so a malformed id from an
     * older implementation can be spotted rather than silently used.
     */
    public static function looksValid(string $id, string $alphabet = self::DEFAULT_ALPHABET): bool
    {
        if ($id === '') {
            return false;
        }

        return strspn($id, $alphabet) === strlen($id);
    }
}
