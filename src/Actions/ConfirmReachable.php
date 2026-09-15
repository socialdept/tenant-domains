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
 *
 * Not every redirect is hostile, though. A same-host redirect proves the request
 * arrived, and many apps legitimately send an unverified hostname somewhere else
 * of their own. Supply `$acceptRedirectTo` to claim those, or a tenant that
 * redirects non-primary domains to its primary can never finish setup.
 */
class ConfirmReachable implements ReachabilityProbe
{
    public function __construct(
        private readonly int $timeout = 8,
        /** Optional extra check on the body, for an app that can recognise itself. */
        private readonly ?\Closure $expectation = null,
        /**
         * Given the redirect's target host and the host being probed, whether
         * that redirect is the app's own. Same-host redirects always count.
         *
         * @var (\Closure(string, string): bool)|null
         */
        private readonly ?\Closure $acceptRedirectTo = null,
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

    /**
     * Whether a redirect landed somewhere we own.
     *
     * A redirect back to the same host is always ours: it cannot have happened
     * unless the request reached us, which is the only thing being proven here.
     */
    private function redirectIsOurs(string $target, string $host): bool
    {
        if (strcasecmp($target, $host) === 0) {
            return true;
        }

        return $this->acceptRedirectTo !== null
            && ($this->acceptRedirectTo)($target, $host);
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
            $location = (string) $response->header('Location');
            $target = (string) (parse_url($location, PHP_URL_HOST) ?: $host);

            if ($this->redirectIsOurs($target, $host)) {
                return ReachabilityResult::ok($response->status(), $url);
            }

            return ReachabilityResult::failed(
                sprintf(
                    'Requests to %s are being redirected to %s before they reach us. A redirect or page rule at your DNS provider is intercepting this domain.',
                    $host,
                    $location !== '' ? $location : 'somewhere else',
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
