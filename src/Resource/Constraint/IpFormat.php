<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Constraint;

/**
 * String must be a valid IP address. `$version` selects the accepted form:
 * `4` (JSON Schema `format: ipv4`), `6` (`format: ipv6`), or `null` for both.
 */
final readonly class IpFormat implements \haddowg\JsonApi\Resource\Constraint\ConstraintInterface
{
    public function __construct(
        public ?int $version = null,
        public Context $context = new Context(),
    ) {}

    public function context(): Context
    {
        return $this->context;
    }
}
