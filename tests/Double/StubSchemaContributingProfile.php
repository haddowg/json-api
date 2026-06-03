<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Double;

use haddowg\JsonApi\Schema\Profile\AbstractProfile;
use haddowg\JsonApi\Validation\SchemaContributingProfile;

/**
 * A {@see SchemaContributingProfile} test double: a fixed URI and a fixed
 * (possibly null) decoded JSON Schema fragment.
 */
final class StubSchemaContributingProfile extends AbstractProfile implements \haddowg\JsonApi\Validation\SchemaContributingProfileInterface
{
    public function __construct(
        private readonly string $uri,
        private readonly ?object $fragment,
    ) {}

    public function uri(): string
    {
        return $this->uri;
    }

    public function schemaFragment(): ?object
    {
        return $this->fragment;
    }
}
