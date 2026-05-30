<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Hydrator;

use haddowg\JsonApi\Exception\JsonApiException;
use haddowg\JsonApi\Exception\RelationshipNotExists;
use haddowg\JsonApi\Exception\ResourceTypeMissing;
use haddowg\JsonApi\Request\JsonApiRequestInterface;

/**
 * Base hydrator for resources that support both create (POST) and update (PATCH),
 * as well as relationship updates.
 *
 * Dispatches to {@see CreateHydratorTrait::hydrateForCreate()} or
 * {@see UpdateHydratorTrait::hydrateForUpdate()} based on the HTTP method.
 *
 * @see https://github.com/woohoolabs/yin — original work (MIT), from which this derives.
 */
abstract class AbstractHydrator implements HydratorInterface, UpdateRelationshipHydratorInterface
{
    use HydratorTrait;
    use CreateHydratorTrait;
    use UpdateHydratorTrait;

    /**
     * Hydrates `$domainObject` based on the HTTP method of the request.
     *
     * POST → create, PATCH → update. Any other method is a no-op.
     *
     * @param mixed $domainObject
     * @return mixed
     *
     * @throws ResourceTypeMissing|JsonApiException
     */
    public function hydrate(JsonApiRequestInterface $request, mixed $domainObject): mixed
    {
        if ($request->getMethod() === 'POST') {
            $domainObject = $this->hydrateForCreate($request, $domainObject);
        } elseif ($request->getMethod() === 'PATCH') {
            $domainObject = $this->hydrateForUpdate($request, $domainObject);
        }

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
