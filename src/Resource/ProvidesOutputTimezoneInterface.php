<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource;

/**
 * A {@see SerializerResolverInterface} that also carries the server's default
 * output timezone. {@see AbstractResource} reads it off its injected resolver when
 * rendering attributes and hands it to each
 * {@see \haddowg\JsonApi\Resource\Field\AcceptsDefaultOutputTimezoneInterface}
 * field. A resolver that does not implement it means no default zone.
 *
 * The {@see \haddowg\JsonApi\Server\Server} and its registry implement it. A
 * resolver that decorates the registry should implement it too and delegate, or the
 * resources it serves render without the server's default.
 */
interface ProvidesOutputTimezoneInterface
{
    /**
     * The timezone every `DateTime` attribute renders in unless the field sets its
     * own, or `null` to render each value in the zone it carries.
     */
    public function outputTimezone(): ?string;
}
