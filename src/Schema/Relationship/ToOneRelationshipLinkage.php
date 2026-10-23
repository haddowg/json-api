<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Schema\Relationship;

/**
 * The target a rendered to-one relation carries when the host supplies it
 * out-of-band, through {@see \haddowg\JsonApi\Serializer\ToOneRelationshipLinkageInterface},
 * instead of core reading it off the parent model.
 *
 * `$target` is the related domain object to render, or `null` for none. None renders
 * exactly as an empty to-one does: `data: null` in the linkage, nothing in `included`,
 * and a null hop for an `on()` attribute flattened through the relation. That holds
 * even when the parent's own property points at a related object, so a host can
 * withhold a target it does not want rendered. "No override" is the seam returning
 * `null`, not this value object.
 */
final readonly class ToOneRelationshipLinkage
{
    public function __construct(
        public mixed $target,
    ) {}

    /**
     * Renders `$target` as the relation's related resource.
     */
    public static function to(mixed $target): self
    {
        return new self($target);
    }

    /**
     * Renders the relation as empty, whatever the parent model holds.
     */
    public static function none(): self
    {
        return new self(null);
    }
}
