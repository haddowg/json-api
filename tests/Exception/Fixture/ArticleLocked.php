<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Exception\Fixture;

use haddowg\JsonApi\Exception\AbstractJsonApiException;
use haddowg\JsonApi\Exception\DescribedErrorInterface;
use haddowg\JsonApi\Exception\ErrorDescriptor;

/**
 * An application's own described error that only some operations raise: an article being
 * edited elsewhere refuses an update or a delete, and nothing else.
 */
final class ArticleLocked extends AbstractJsonApiException implements DescribedErrorInterface
{
    public function __construct()
    {
        parent::__construct('The article is locked by another editor.', self::describe()->status);
    }

    public static function describe(): ErrorDescriptor
    {
        return new ErrorDescriptor(
            code: 'ARTICLE_LOCKED',
            status: 409,
            title: 'Article locked',
            description: 'Another editor holds the article. Wait for them to finish, then retry.',
            exampleDetail: 'The article is locked by another editor.',
        );
    }

    public function getErrors(): array
    {
        return [self::describe()->toError(detail: $this->getMessage())];
    }
}
