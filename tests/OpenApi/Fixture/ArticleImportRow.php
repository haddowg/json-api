<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\OpenApi\Fixture;

use haddowg\JsonApi\OpenApi\Metadata\DescribesRawBody;

/**
 * A plain-JSON import body described by a schema class, accepted as a request body.
 */
final class ArticleImportRow implements DescribesRawBody
{
    public static function jsonSchema(): array
    {
        return [
            'type' => 'array',
            'items' => [
                'type' => 'object',
                'properties' => ['title' => ['type' => 'string']],
                'required' => ['title'],
            ],
        ];
    }
}
