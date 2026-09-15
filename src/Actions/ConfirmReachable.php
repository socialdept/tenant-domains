<?php

namespace SocialDept\TenantDomains\Actions;

use Illuminate\Support\Facades\Http;
use SocialDept\TenantDomains\Contracts\ReachabilityProbe;
use SocialDept\TenantDomains\Data\ReachabilityResult;
use Throwable;

/**
 * Prove that a request to a domain actually arrives at us.
 *
 * DNS verification says where a name points. It cannot say what answers there,
 * and behind a proxy it cannot even say that: a proxied record resolves to the
 * proxy, and whatever happens next is invisible from outside. It may forward to
 * us, redirect elsewhere, or serve a cached page.
 *
 * The failure this exists to catch: a tenant keeps a redirect rule from a
 * previous host, passes every DNS check, and then the domain never works. The
 * rule was answering before anything reached us.
 *
 * Redirects are deliberately **not followed**. A redirect is the failure being
 * hunted, and following one hides it behind a destination that may well answer
 * 200.
 */
class ConfirmReachable implements ReachabilityProbe
{
    public function __construct(
        private readonly int $timeout = 8,
        /** Optional extra check on the body, for an app that can recognise itself. */
        private readonly ?\Closure $expectation = null,
    ) {
        //
    }

    /**
     * @param  array<int, string>  $urls
     */
    public function probe(array $urls): ReachabilityResult
    {
        $last = null;

        foreach ($urls as $url) {
            $last = $this->ask($url);

            if (! $last->reachable) {
                return $last;
            }
        }

        // An empty list is a domain that serves nothing, so there is nothing to reach.
        return $last ?? ReachabilityResult::ok();
    }

    private function ask(string $url): ReachabilityResult
    {
        $host = (string) parse_url($url, PHP_URL_HOST);

        try {
            $response = Http::timeout($this->timeout)
                ->withoutRedirecting()
                ->get($url);
        } catch (Throwable $e) {
            return ReachabilityResult::failed(
                "Nothing answered at {$host}. ".$e->getMessage(),
                url: $url,
            );
        }

        if ($response->redirect()) {
            return ReachabilityResult::failed(
                sprintf(
                    'Requests to %s are being redirected to %s before they reach us. A redirect or page rule at your DNS provider is intercepting this domain.',
                    $host,
                    $response->header('Location') ?: 'somewhere else',
                ),
                status: $response->status(),
                url: $url,
            );
        }

        if (! $response->successful()) {
            return ReachabilityResult::failed(
                sprintf(
                    'Requests to %s reach something that answered %d. Check for a redirect, page rule or worker on this domain.',
                    $host,
                    $response->status(),
                ),
                status: $response->status(),
                url: $url,
            );
        }

        if ($this->expectation !== null && ! ($this->expectation)($response)) {
            return ReachabilityResult::failed(
                "Requests to {$host} reach something other than us. Check for a redirect, page rule or worker on this domain.",
                status: $response->status(),
                url: $url,
            );
        }

        return ReachabilityResult::ok($response->status(), $url);
    }
}
