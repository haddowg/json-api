<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Schema\Document;

use haddowg\JsonApi\Schema\Error\Error;

/**
 * A document whose top level carries one or more errors.
 *
 * @internal
 *
 */
interface ErrorDocumentInterface extends DocumentInterface
{
    /**
     * @return list<Error>
     */
    public function getErrors(): array;

    public function getStatusCode(?int $statusCode): int;
}
