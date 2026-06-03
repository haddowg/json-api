<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Hydrator;

use haddowg\JsonApi\Exception\RelationshipNotExists;
use haddowg\JsonApi\Request\JsonApiRequestInterface;

/**
 */
interface UpdateRelationshipHydratorInterface
{
    /**
     * @param mixed $domainObject
     * @return mixed
     *
     * @throws RelationshipNotExists|\haddowg\JsonApi\Exception\JsonApiExceptionInterface
     */
    public function hydrateRelationship(
        string $relationship,
        JsonApiRequestInterface $request,
        mixed $domainObject,
    ): mixed;
}
