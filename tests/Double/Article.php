<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Double;

/**
 * Minimal domain object used by the composition-contract proof test.
 *
 * A plain final class with no framework coupling; demonstrates that hydrators
 * and resource serializers can operate on any PHP value type.
 */
final class Article
{
    public function __construct(
        public string $id,
        public string $title,
        public ?string $authorId = null,
    ) {}
}
