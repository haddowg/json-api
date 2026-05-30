<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Hydrator;

use haddowg\JsonApi\Exception\JsonApiException;
use haddowg\JsonApi\Exception\RelationshipNotExists;
use haddowg\JsonApi\Exception\ResourceTypeMissing;
use haddowg\JsonApi\Request\JsonApiRequestInterface;

/**
 * Base hydrator for resources that support only the update (PATCH) operation,
 * as well as relationship updates.
 *
 * @see https://github.com/woohoolabs/yin — original work (MIT), from which this derives.
 */
abstract class AbstractUpdateHydrator implements HydratorInterface, UpdateRelationshipHydratorInterface
{
    use HydratorTrait;
    use UpdateHydratorTrait;

    /**
     * Alias for {@see UpdateHydratorTrait::hydrateForUpdate()}.
     *
     * @param mixed $domainObject
     * @return mixed
     *
     * @throws ResourceTypeMissing|JsonApiException
     */
    public function hydrate(JsonApiRequestInterface $request, mixed $domainObject): mixed
    {
        $domainObject = $this->hydrateForUpdate($request, $domainObject);

        $this->validateDomainObject($request, $domainObject);

        return $domainObject;
    }

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
    ): mixed {
        return $this->hydrateForRelationshipUpdate($relationship, $request, $domainObject);
    }

    /**
     * Post-hydration validation hook.
     *
     * Called after the domain object has been fully hydrated. Override to add
     * cross-field or business-rule validation; the default implementation is
     * a no-op.
     *
     * @param mixed $domainObject
     */
    protected function validateDomainObject(JsonApiRequestInterface $request, mixed $domainObject): void {}
}
