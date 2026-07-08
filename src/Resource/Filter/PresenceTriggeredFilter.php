<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Filter;

/**
 * A filter whose `filter[<key>]` parameter is a **presence trigger**: its mere
 * presence applies a fixed, server-composed condition and the request value is
 * **ignored**, not read as a client input.
 *
 * A {@see Where} pinned with {@see Where::fixed()} reports this, and so does a
 * {@see WhereAll} / {@see WhereAny} group whose children are all themselves
 * presence-triggered (a canned toggle rather than a fanned-value search). The
 * OpenAPI projector reads this to document the parameter honestly — a
 * server-applied presence parameter whose value is ignored — instead of a
 * client value-input schema. A fanning group (whose value *is* passed to a
 * value-carrying child) reports `false` and projects its value schema normally.
 */
interface PresenceTriggeredFilter extends FilterInterface
{
    /**
     * Whether this filter is presence-triggered: its request value is ignored,
     * so `filter[<key>]` present with any value applies the fixed condition and
     * omitting the key does not apply it.
     */
    public function isPresenceTriggered(): bool;
}
