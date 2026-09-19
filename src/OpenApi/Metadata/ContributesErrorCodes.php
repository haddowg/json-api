<?php

declare(strict_types=1);

namespace haddowg\JsonApi\OpenApi\Metadata;

use haddowg\JsonApi\Exception\ErrorCatalogSourceInterface;

/**
 * Server metadata that contributes error codes of its own to the projected catalogue.
 *
 * Core's codes are always catalogued; these join them, so an application's or a framework
 * integration's errors reach a generated client as named schema variants too. It is a
 * **separate**, opt-in contract rather than a method on {@see ServerMetadataInterface} so
 * an existing implementation keeps working untouched — the same shape as the
 * {@see \haddowg\JsonApi\Resource\Filter\DescribesQueryParameter} and
 * {@see \haddowg\JsonApi\Resource\Filter\TargetsColumn} seams.
 *
 * The contribution is **per server**: an error is documented only on the servers whose
 * metadata offers it, which is the registration-awareness the rest of the projection
 * already has ([ADR 0131](../../../docs/adr/0131-registration-aware-openapi-projection.md)).
 */
interface ContributesErrorCodes extends ServerMetadataInterface
{
    /**
     * The sources whose described errors join core's, in the order they should be
     * catalogued.
     *
     * @return iterable<ErrorCatalogSourceInterface>
     */
    public function errorSources(): iterable;
}
