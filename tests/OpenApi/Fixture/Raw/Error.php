<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\OpenApi\Fixture\Raw;

use haddowg\JsonApi\OpenApi\Metadata\DescribesRawBody;

/**
 * A raw body schema class whose short name clashes with the shared `Error` component.
 */
final class Error implements DescribesRawBody
{
    public static function jsonSchema(): array
    {
        return ['type' => 'object'];
    }
}
