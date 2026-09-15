<?php

namespace SocialDept\TenantDomains\Enums;

enum ReconcileOutcome: string
{
    /** Moved forward a step, but is not finished. */
    case Advanced = 'advanced';

    /** Reached Verified on this pass. */
    case Verified = 'verified';

    /**
     * Checked, and the tenant's records are not ready yet.
     *
     * The ordinary state during setup, and explicitly **not** a failure. DNS takes
     * minutes to propagate, and a tenant who has done nothing wrong should not
     * be told they have.
     */
    case Waiting = 'waiting';

    /**
     * DNS could not be checked at all.
     *
     * Our problem, not the tenant's. Kept separate from Waiting so a resolver
     * outage cannot mark a whole table as failing.
     */
    case Unavailable = 'unavailable';

    /** Not looked at, because it was throttled or serves nothing. */
    case Skipped = 'skipped';
}
