<?php

namespace SocialDept\TenantDomains\Console;

use Illuminate\Console\Command;
use SocialDept\TenantDomains\Core\PublicSuffixList;
use Throwable;

/**
 * Refresh the Public Suffix List.
 *
 * The list decides whether a domain is an apex, and so which routing record a
 * tenant is told to create. New suffixes appear regularly, and a stale list
 * quietly hands out the wrong instructions for them.
 */
class RefreshPublicSuffixListCommand extends Command
{
    protected $signature = 'domains:refresh-psl {--url=https://publicsuffix.org/list/public_suffix_list.dat}';

    protected $description = 'Download the latest Public Suffix List used for apex detection';

    public function handle(): int
    {
        $path = PublicSuffixList::path();
        $url = (string) $this->option('url');

        if (! is_writable(dirname($path))) {
            $this->error("Cannot write to {$path}.");
            $this->line('Copy the list somewhere writable and point tenant-domains.psl_path at it.');

            return self::FAILURE;
        }

        $this->line("Fetching {$url}");

        try {
            $contents = file_get_contents($url);
        } catch (Throwable $e) {
            $this->error("Download failed: {$e->getMessage()}");

            return self::FAILURE;
        }

        // A truncated or error-page response would break apex detection silently.
        if ($contents === false || ! str_contains($contents, '// ===BEGIN ICANN DOMAINS===')) {
            $this->error('That did not look like a Public Suffix List. Leaving the existing one in place.');

            return self::FAILURE;
        }

        file_put_contents($path, $contents);
        PublicSuffixList::flush();

        $this->info(sprintf('Wrote %s (%s lines).', $path, number_format(substr_count($contents, "\n"))));

        return self::SUCCESS;
    }
}
