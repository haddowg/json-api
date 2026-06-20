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
     * The resource types named in the request's `fields[...]` sparse-fieldset
     * parameter — the keys of the parsed fields map (e.g. `['articles', 'people']`
     * for `?fields[articles]=...&fields[people]=...`). Empty when no `fields`
     * parameter is present. Pair with {@see getIncludedFields()} to enumerate, per
     * type, the members the client requested — the input strict sparse-fieldset
     * member validation iterates.
     *
     * @return list<string>
     */
    public function requestedFieldsetTypes(): array;

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
     * Returns every requested full dotted include path (e.g. `a`, `a.b`, `a.b.c`
     * for `?include=a.b.c`), so include depth and allowed-path checks can be
     * evaluated against the complete requested set.
     *
     * @return list<string>
     */
    public function getIncludePaths(): array;

    /**
     * Determines if a given relationship name that is a child of the $baseRelationshipPath should be included
     * in the response.
     *
     * @param array<string, mixed> $defaultRelationships
     */
    public function isIncludedRelationship(string $baseRelationshipPath, string $relationshipName, array $defaultRelationships): bool;

    /**
     * The targets requested for counting via the `?withCount` query parameter — a
     * flat, comma-separated list (e.g. `?withCount=_self_,comments,tags`), like
     * `?include` but never dotted. Each entry is either a relationship name (whose
     * cardinality the client wants exposed as `meta.total` on the relationship
     * object) or the reserved token `_self_`, which names the **primary
     * collection/resource** (its `meta.total` top-level). The list is the raw
     * requested set; whether a given target is actually countable is validated
     * against the resource — a relation must be
     * {@see \haddowg\JsonApi\Resource\Field\AbstractRelation::countable()} (and
     * to-many), `_self_` requires the resource be
     * {@see \haddowg\JsonApi\Resource\AbstractResource::countable()} — and a count is
     * only emitted when a {@see \haddowg\JsonApi\Serializer\RelationshipCountInterface}
     * (relation) or the handler (`_self_`) supplies one.
     *
     * @return list<string>
     */
    public function getCountedRelationships(): array;

    /**
     * Whether the request named `$relationship` in `?withCount` — the flat,
     * position-independent membership test the serializer (for a relationship's
     * `meta.total`) and the handler (for `_self_`, the primary collection's total)
     * consult. Pass the reserved token `_self_` to test the primary collection.
     */
    public function countsRelationship(string $relationship): bool;

    /**
     * The Relationship Queries profile's per-relationship sort + filter for the
     * relationship at `$path` (its include path, e.g. `comments` or
     * `albums.tracks`), parsed from the `relatedQuery` / `rQ` family of the
     * primary request.
     *
     * Opt-in: an empty {@see RelatedQuery} unless the client negotiated
     * {@see \haddowg\JsonApi\Schema\Profile\RelationshipQueriesProfile::URI}. On a
     * per-`[path][op]` conflict between the families the canonical `relatedQuery`
     * wins. The returned values are raw client input — the host validates the
     * sort/filter keys against the relationship's vocabulary (the related-
     * collection endpoint's vocabulary), rejecting an unknown key with a `400`.
     */
    public function getRelatedQuery(string $path): RelatedQuery;

    /**
     * Whether any `relatedQuery` / `rQ` params target the relationship at `$path`
     * (and the profile was negotiated). `false` for an empty default path.
     */
    public function hasRelatedQuery(string $path): bool;

    /**
     * The relationship (include) paths the `relatedQuery` / `rQ` family targets,
     * for up-front validation against the resource's relationships. Empty unless
     * the profile was negotiated.
     *
     * @return list<string>
     */
    public function getRelatedQueryPaths(): array;

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
     * The extension URIs requested via the `Accept` header's `ext` parameter.
     *
     * @return list<string>
     */
    public function getRequestedExtensions(): array;

    /**
     * The extension URIs asserted via the `Content-Type` header's `ext` parameter.
     *
     * @return list<string>
     */
    public function getAppliedExtensions(): array;

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

    /**
     * Parses the **top-level** `{data: <linkage>}` of a relationship-endpoint body
     * (`/{type}/{id}/relationships/{name}`) into a to-one linkage value object —
     * here `data` is the relationship's linkage (its resource identifier), not the
     * `self`/`related` links — distinct from {@see getToOneRelationship()}, which
     * reads from the whole-resource `data.relationships.{name}.data` path.
     * `data: null` yields an empty (clearing) relationship.
     *
     * @throws \haddowg\JsonApi\Exception\RelationshipNotExists when the body carries no `data` member
     * @throws \haddowg\JsonApi\Exception\RelationshipTypeInappropriate when the body's `data` is a list (to-many linkage on a to-one endpoint)
     */
    public function getRelationshipDataToOne(string $relationship): ToOneRelationship;

    /**
     * Parses the **top-level** `{data: <linkage>}` of a relationship-endpoint body
     * (`/{type}/{id}/relationships/{name}`) into a to-many linkage value object —
     * here `data` is the relationship's linkage (its resource identifiers), not the
     * `self`/`related` links — distinct from {@see getToManyRelationship()}, which
     * reads from the whole-resource `data.relationships.{name}.data` path.
     * `data: []` yields an empty (clearing) relationship.
     *
     * @throws \haddowg\JsonApi\Exception\RelationshipNotExists when the body carries no `data` member
     * @throws \haddowg\JsonApi\Exception\RelationshipTypeInappropriate when the body's `data` is a single object or `null` (to-one linkage on a to-many endpoint)
     */
    public function getRelationshipDataToMany(string $relationship): ToManyRelationship;
}
