<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Hydrator;

use haddowg\JsonApi\Exception\JsonApiException;
use haddowg\JsonApi\Exception\RelationshipNotExists;
use haddowg\JsonApi\Request\JsonApiRequestInterface;

/**
 * @see https://github.com/woohoolabs/yin — original work (MIT), from which this derives.
 */
interface UpdateRelationshipHydratorInterface
{
    /**
     * @param mixed $domainObject
     * @return mixed
     *
     * @throws RelationshipNotExists|JsonApiException
     */
    public function hydrateRelationship(
        string $relationship,
        JsonApiRequestInterface $request,
        mixed $domainObject,
    ): mixed;
}
