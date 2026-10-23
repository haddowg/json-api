<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Constraint;

use haddowg\JsonApi\OpenApi\Schema;

/**
 * String must be a valid IP address. `$version` selects the accepted form:
 * `4` (JSON Schema `format: ipv4`), `6` (`format: ipv6`), or `null` for both, which
 * projects no `format`.
 */
final readonly class IpFormat implements ProvidesJsonSchema
{
    public function __construct(
        public ?int $version = null,
        public Context $context = new Context(),
    ) {}

    public function context(): Context
    {
        return $this->context;
    }

    public function contribute(Schema $schema): Schema
    {
        // JSON Schema has no single format for "either family", and a lone `ipv4` would
        // reject the IPv6 addresses this constraint accepts.
        return match ($this->version) {
            4 => $schema->withFormat('ipv4'),
            6 => $schema->withFormat('ipv6'),
            default => $schema,
        };
    }
}
