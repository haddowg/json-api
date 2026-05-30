<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Request;

use haddowg\JsonApi\Exception\MediaTypeUnacceptable;
use haddowg\JsonApi\Exception\MediaTypeUnsupported;
use haddowg\JsonApi\Exception\QueryParamUnrecognized;
use haddowg\JsonApi\Exception\RequiredTopLevelMembersMissing;
use haddowg\JsonApi\Exception\TopLevelMemberNotAllowed;
use haddowg\JsonApi\Exception\TopLevelMembersIncompatible;
use haddowg\JsonApi\Hydrator\Relationship\ToManyRelationship;
use haddowg\JsonApi\Hydrator\Relationship\ToOneRelationship;
use Psr\Http\Message\ServerRequestInterface;

interface JsonApiRequestInterface extends ServerRequestInterface
{
    /**
     * Validates if the current request's "Content-Type" header conforms to the JSON:API schema.
     *
     * @throws MediaTypeUnsupported
     */
    public function validateContentTypeHeader(): void;

    /**
     * Validates if the current request's "Accept" header conforms to the JSON:API schema.
     *
     * @throws MediaTypeUnacceptable
     */
    public function validateAcceptHeader(): void;

    /**
     * Validates if the current request's query parameters conform to the JSON:API schema.
     *
     * According to the JSON:API specification "Implementation specific query parameters MUST
     * adhere to the same constraints as member names with the additional requirement that they
     * MUST contain at least one non a-z character (U+0061 to U+007A)".
     *
     * @throws QueryParamUnrecognized
     */
    public function validateQueryParams(): void;

    /**
     * Validates if the current request's top-level members conform to the JSON:API schema.
     *
     * According to the JSON:API specification:
     * - A document MUST contain at least one of the following top-level members: "data", "errors", "meta".
     * - The members "data" and "errors" MUST NOT coexist in the same document.
     * - The document MAY contain any of these top-level members: "jsonapi", "links", "included"
     * - If a document does not contain a top-level "data" key, the "included" member MUST NOT be present either.
     *
     * @throws RequiredTopLevelMembersMissing
     * @throws TopLevelMembersIncompatible
     * @throws TopLevelMemberNotAllowed
     */
    public function validateTopLevelMembers(): void;

    /**
     * Returns a list of field names for the given resource type which should be present in the response.
     *
     * @return list<string>
     */
    public function getIncludedFields(string $resourceType): array;

    /**
     * Determines if a given field for a given resource type should be present in the response or not.
     */
    public function isIncludedField(string $resourceType, string $field): bool;

    /**
     * Determines if any relationship needs to be included.
     */
    public function hasIncludedRelationships(): bool;

    /**
     * Returns a list of relationship paths for a given parent path which should be included in the response.
     *
     * @return list<string>
     */
    public function getIncludedRelationships(string $baseRelationshipPath): array;

    /**
     * Determines if a given relationship name that is a child of the $baseRelationshipPath should be included
     * in the response.
     *
     * @param array<string, mixed> $defaultRelationships
     */
    public function isIncludedRelationship(string $baseRelationshipPath, string $relationshipName, array $defaultRelationships): bool;

    /**
     * Returns the "sort[]" query parameters.
     *
     * @return list<string>
     */
    public function getSorting(): array;

    /**
     * Returns the "page[]" query parameters.
     *
     * @return array<string, mixed>
     */
    public function getPagination(): array;

    /**
     * Returns the "filter[]" query parameters.
     *
     * @return array<string, mixed>
     */
    public function getFiltering(): array;

    /**
     * Returns the value of the "filter[$param]" query parameter if present or $default value otherwise.
     */
    public function getFilteringParam(string $param, mixed $default = null): mixed;

    /**
     * Returns the value of the "$name" query parameter if present or the $default value otherwise.
     */
    public function getQueryParam(string $name, mixed $default = null): mixed;

    /**
     * Returns a new request with the "$name" query parameter.
     *
     * @return static
     */
    public function withQueryParam(string $name, mixed $value): static;

    /**
     * @return list<string>
     */
    public function getAppliedProfiles(): array;

    public function isProfileApplied(string $profile): bool;

    /**
     * @return list<string>
     */
    public function getRequiredProfiles(): array;

    public function isProfileRequired(string $profile): bool;

    /**
     * @return list<string>
     */
    public function getRequestedProfiles(): array;

    public function isProfileRequested(string $profile): bool;

    /**
     * Returns the primary resource if it is present in the request body, or the $default value otherwise.
     *
     * @return array<string, mixed>|mixed
     */
    public function getResource(mixed $default = null): mixed;

    /**
     * Returns the "type" of the primary resource if it is present, or the $default value otherwise.
     */
    public function getResourceType(mixed $default = null): mixed;

    /**
     * Returns the "id" of the primary resource if it is present, or the $default value otherwise.
     */
    public function getResourceId(mixed $default = null): mixed;

    /**
     * Returns the "lid" (local id) of the primary resource if it is present, or the $default value otherwise.
     *
     * Per JSON:API 1.1 a resource being created MAY carry a `lid` instead of an `id`; it is a
     * document-local handle for a not-yet-created resource, not the resource's server-assigned id.
     */
    public function getResourceLid(mixed $default = null): mixed;

    /**
     * Returns the "attributes" of the primary resource.
     *
     * @return array<string, mixed>
     */
    public function getResourceAttributes(): array;

    /**
     * Returns the $attribute attribute of the primary resource if it is present, or the $default value otherwise.
     */
    public function getResourceAttribute(string $attribute, mixed $default = null): mixed;

    /**
     * Returns if the $relationship to-one relationship of the primary resource is present.
     */
    public function hasToOneRelationship(string $relationship): bool;

    /**
     * Returns the $relationship to-one relationship of the primary resource if it is present.
     */
    public function getToOneRelationship(string $relationship): ToOneRelationship;

    /**
     * Returns if the $relationship to-many relationship of the primary resource is present.
     */
    public function hasToManyRelationship(string $relationship): bool;

    /**
     * Returns the $relationship to-many relationship of the primary resource if it is present.
     */
    public function getToManyRelationship(string $relationship): ToManyRelationship;
}
