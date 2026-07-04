<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Constraint;

use haddowg\JsonApi\OpenApi\Schema;

/**
 * String must be a valid IP address. `$version` selects the accepted form:
 * `4` (JSON Schema `format: ipv4`), `6` (`format: ipv6`), or `null` for both.
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
        return $schema->withFormat($this->version === 6 ? 'ipv6' : 'ipv4');
    }
}
