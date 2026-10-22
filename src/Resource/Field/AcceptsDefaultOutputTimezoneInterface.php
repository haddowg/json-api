<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Field;

/**
 * A built field that takes part in the server's default output timezone
 * ({@see \haddowg\JsonApi\Server\Server::withOutputTimezone()}). Before rendering
 * attributes, {@see \haddowg\JsonApi\Resource\AbstractResource} passes each such
 * field the server's zone and renders the field it gets back.
 *
 * {@see DateTime} implements it to adopt the zone when it set no
 * `outputTimezone()` of its own. {@see Date} and {@see Time} return themselves
 * unchanged, because moving a calendar date or a bare time of day into another zone
 * can change its value. The composite fields ({@see Map}, {@see Obj},
 * {@see OneOf}) pass the zone on to their children.
 */
interface AcceptsDefaultOutputTimezoneInterface extends FieldInterface
{
    /**
     * This field with `$timezone` as its fallback output zone, or `$this` when the
     * zone changes nothing about how it renders.
     *
     * @return static
     */
    public function withDefaultOutputTimezone(string $timezone): static;
}
