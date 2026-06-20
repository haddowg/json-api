<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Request;

use haddowg\JsonApi\Exception\MediaTypeUnacceptable;
use haddowg\JsonApi\Exception\MediaTypeUnsupported;
use haddowg\JsonApi\Exception\QueryParamMalformed;
use haddowg\JsonApi\Exception\QueryParamUnrecognized;
use haddowg\JsonApi\Exception\RelationshipNotExists;
use haddowg\JsonApi\Exception\RelationshipTypeInappropriate;
use haddowg\JsonApi\Exception\RequiredTopLevelMembersMissing;
use haddowg\JsonApi\Exception\TopLevelMemberNotAllowed;
use haddowg\JsonApi\Exception\TopLevelMembersIncompatible;
use haddowg\JsonApi\Hydrator\Relationship\ToManyRelationship;
use haddowg\JsonApi\Hydrator\Relationship\ToOneRelationship;
use haddowg\JsonApi\Schema\Profile\CountableProfile;
use haddowg\JsonApi\Schema\Profile\RelationshipQueriesProfile;
use haddowg\JsonApi\Schema\ResourceIdentifier;
use Psr\Http\Message\ServerRequestInterface;

/**
 * JSON:API-aware server request.
 *
 * Wraps a PSR-7 ServerRequestInterface and adds JSON:API-specific parsing and
 * validation. Lazy-caches parsed query-parameter groups (fields, include, sort,
 * page, filter, profile) and invalidates each cache entry when the corresponding
 * header or query parameter is replaced via a PSR-7 `with*()` call.
 *
 * NOT `readonly`: PSR-7 wither methods require `clone $this` followed by property
 * mutation on the clone, and the lazy caches require nulling on invalidation.
 *
 * @see https://jsonapi.org/format/1.1/
 */
class JsonApiRequest extends AbstractRequest implements JsonApiRequestInterface
{
    /**
     * Parsed "fields" query param: keyed by resource type → set of field names.
     *
     * @var array<string, array<string, string>>|null
     */
    protected ?array $includedFields = null;

    /**
     * Parsed "include" query param: keyed by base path → set of relationship names.
     *
     * @var array<string, array<string, string>>|null
     */
    protected ?array $includedRelationships = null;

    /**
     * Parsed "withCount" query param: the flat set of requested relationship
     * names, as a name → name map for O(1) membership testing.
     *
     * @var array<string, string>|null
     */
    protected ?array $countedRelationships = null;

    /**
     * Parsed "sort" query param.
     *
     * @var list<string>|null
     */
    protected ?array $sorting = null;

    /**
     * Parsed "page" query param.
     *
     * @var array<string, mixed>|null
     */
    protected ?array $pagination = null;

    /**
     * Parsed "filter" query param.
     *
     * @var array<string, mixed>|null
     */
    protected ?array $filtering = null;

    /**
     * Parsed Relationship Queries profile family (`relatedQuery` / `rQ`), keyed
     * by relationship (include) path → the path's merged {@see RelatedQuery}
     * (canonical `relatedQuery` wins on a per-`[path][op]` conflict). `null`
     * until first read; an empty map when the profile was not negotiated or no
     * family params were supplied.
     *
     * @var array<string, RelatedQuery>|null
     */
    protected ?array $relatedQueries = null;

    /**
     * Parsed profile lists: keyed by "applied", "requested", "required".
     * Each entry is a map of profile-URL → profile-URL (for O(1) membership test).
     *
     * @var array<string, array<string, string>|null>
     */
    protected array $profiles = [];

    /**
     * Parsed extension lists: keyed by header name ("content-type", "accept").
     * Each entry is a map of extension-URL → extension-URL (for O(1) membership test).
     *
     * @var array<string, array<string, string>|null>
     */
    protected array $extensions = [];

    public function __construct(ServerRequestInterface $request)
    {
        parent::__construct($request);
    }

    /**
     * Validates if the current request's Content-Type header conforms to the JSON:API schema.
     *
     * @throws MediaTypeUnsupported
     */
    public function validateContentTypeHeader(): void
    {
        if ($this->isValidMediaTypeHeader('content-type') === false) {
            throw new MediaTypeUnsupported($this->getHeaderLine('content-type'));
        }
    }

    /**
     * Validates if the current request's Accept header conforms to the JSON:API schema.
     *
     * @throws MediaTypeUnacceptable
     */
    public function validateAcceptHeader(): void
    {
        // The Accept rule differs from the Content-Type rule: a 406 is required only
        // when EVERY application/vnd.api+json instance is parametrized; a single
        // conforming instance is acceptable, and the q weight is not a parameter.
        if (MediaType::accepts($this->getHeaderLine('accept')) === false) {
            throw new MediaTypeUnacceptable($this->getHeaderLine('accept'));
        }
    }

    /**
     * Validates if the current request's query parameters conform to the JSON:API schema.
     *
     * According to the JSON:API specification "Implementation specific query parameters MUST
     * adhere to the same constraints as member names with the additional requirement that they
     * MUST contain at least one non a-z character (U+0061 to U+007A)".
     *
     * @throws QueryParamUnrecognized
     */
    public function validateQueryParams(): void
    {
        foreach ($this->getQueryParams() as $queryParamName => $queryParamValue) {
            if (
                \preg_match('/^([a-z]+)$/', (string) $queryParamName) === 1 &&
                \in_array($queryParamName, ['fields', 'include', 'sort', 'page', 'filter', 'profile'], true) === false
            ) {
                throw new QueryParamUnrecognized((string) $queryParamName);
            }
        }
    }

    /**
     * Validates if the current request's top-level members conform to the JSON:API schema.
     *
     * @throws RequiredTopLevelMembersMissing
     * @throws TopLevelMembersIncompatible
     * @throws TopLevelMemberNotAllowed
     */
    public function validateTopLevelMembers(): void
    {
        $body = $this->getParsedBody();
        if ($body === null) {
            return;
        }

        $body = (array) $body;

        if (isset($body['data']) === false && isset($body['errors']) === false && isset($body['meta']) === false) {
            throw new RequiredTopLevelMembersMissing();
        }

        if (isset($body['data']) && isset($body['errors'])) {
            throw new TopLevelMembersIncompatible();
        }

        if (isset($body['data']) === false && isset($body['included'])) {
            throw new TopLevelMemberNotAllowed();
        }
    }

    protected function isValidMediaTypeHeader(string $headerName): bool
    {
        return MediaType::isValid($this->getHeaderLine($headerName));
    }

    protected function parseHeaderProfiles(string $headerName): void
    {
        $this->profiles[$headerName] = $this->parseHeaderMediaTypeParameterList($headerName, 'profile');
    }

    protected function parseHeaderExtensions(string $headerName): void
    {
        $this->extensions[$headerName] = $this->parseHeaderMediaTypeParameterList($headerName, 'ext');
    }

    /**
     * Extracts the space-separated URI list of a JSON:API media-type parameter
     * (`profile` or `ext`) from the given header — across every JSON:API
     * media-type instance and regardless of parameter order — as a URL → URL map
     * for O(1) membership testing.
     *
     * @return array<string, string>
     */
    private function parseHeaderMediaTypeParameterList(string $headerName, string $parameter): array
    {
        $list = [];

        foreach (MediaType::split($this->getHeaderLine($headerName)) as $mediaType) {
            if (\stripos($mediaType, 'application/vnd.api+json') === false) {
                continue;
            }

            $matches = [];
            if (\preg_match('/;\s*' . $parameter . '\s*=\s*"?([^";]*)"?/i', $mediaType, $matches) !== 1) {
                continue;
            }

            foreach (\explode(' ', \trim($matches[1])) as $uri) {
                if ($uri !== '') {
                    $list[$uri] = $uri;
                }
            }
        }

        return $list;
    }

    protected function parseQueryParamProfiles(string $queryParamName): void
    {
        $queryParam = $this->getQueryParam($queryParamName, '');

        if (\is_string($queryParam) === false) {
            throw new QueryParamMalformed($queryParamName, $queryParam);
        }

        $queryParam = \trim($queryParam);
        if ($queryParam === '') {
            $this->profiles[$queryParamName] = [];

            return;
        }

        $profileList = \array_flip(\explode(' ', $queryParam));
        /** @var array<string, string> $profileList */
        $this->profiles[$queryParamName] = $profileList;
    }

    /**
     * @return list<string>
     */
    public function getRequestedProfiles(): array
    {
        if (isset($this->profiles['accept']) === false) {
            $this->parseHeaderProfiles('accept');
        }

        return \array_keys($this->profiles['accept'] ?? []);
    }

    public function isProfileRequested(string $profile): bool
    {
        if (isset($this->profiles['accept']) === false) {
            $this->parseHeaderProfiles('accept');
        }

        return isset($this->profiles['accept'][$profile]);
    }

    /**
     * @return list<string>
     */
    public function getRequiredProfiles(): array
    {
        if (isset($this->profiles['profile']) === false) {
            $this->parseQueryParamProfiles('profile');
        }

        return \array_keys($this->profiles['profile'] ?? []);
    }

    public function isProfileRequired(string $profile): bool
    {
        if (isset($this->profiles['profile']) === false) {
            $this->parseQueryParamProfiles('profile');
        }

        return isset($this->profiles['profile'][$profile]);
    }

    /**
     * @return list<string>
     */
    public function getAppliedProfiles(): array
    {
        if (isset($this->profiles['content-type']) === false) {
            $this->parseHeaderProfiles('content-type');
        }

        return \array_keys($this->profiles['content-type'] ?? []);
    }

    public function isProfileApplied(string $profile): bool
    {
        if (isset($this->profiles['content-type']) === false) {
            $this->parseHeaderProfiles('content-type');
        }

        return isset($this->profiles['content-type'][$profile]);
    }

    /**
     * The extension URIs the client requested via the `Accept` header's `ext`
     * media-type parameter. Parsed and exposed but not wired downstream — a
     * hook an extension implementation can plug into.
     *
     * @return list<string>
     */
    public function getRequestedExtensions(): array
    {
        if (isset($this->extensions['accept']) === false) {
            $this->parseHeaderExtensions('accept');
        }

        return \array_keys($this->extensions['accept'] ?? []);
    }

    /**
     * The extension URIs asserted on the request body via the `Content-Type`
     * header's `ext` media-type parameter.
     *
     * @return list<string>
     */
    public function getAppliedExtensions(): array
    {
        if (isset($this->extensions['content-type']) === false) {
            $this->parseHeaderExtensions('content-type');
        }

        return \array_keys($this->extensions['content-type'] ?? []);
    }

    /**
     * @return array<string, array<string, string>>
     */
    protected function parseIncludedFields(): array
    {
        $includedFields = [];
        $fields = $this->getQueryParam('fields', []);
        if (\is_array($fields) === false) {
            throw new QueryParamMalformed('fields', $fields);
        }

        foreach ($fields as $resourceType => $resourceFields) {
            if (\is_string($resourceFields) === false) {
                throw new QueryParamMalformed('fields', $fields);
            }

            $fieldMap = \array_flip(\explode(',', $resourceFields));
            /** @var array<string, string> $fieldMap */
            $includedFields[(string) $resourceType] = $fieldMap;
        }

        return $includedFields;
    }

    /**
     * Returns a list of field names for the given resource type which should be present in the response.
     *
     * @return list<string>
     */
    public function getIncludedFields(string $resourceType): array
    {
        if ($this->includedFields === null) {
            $this->includedFields = $this->parseIncludedFields();
        }

        return isset($this->includedFields[$resourceType]) ? \array_keys($this->includedFields[$resourceType]) : [];
    }

    public function requestedFieldsetTypes(): array
    {
        if ($this->includedFields === null) {
            $this->includedFields = $this->parseIncludedFields();
        }

        return \array_keys($this->includedFields);
    }

    /**
     * Determines if a given field for a given resource type should be present in the response or not.
     */
    public function isIncludedField(string $resourceType, string $field): bool
    {
        if ($this->includedFields === null) {
            $this->includedFields = $this->parseIncludedFields();
        }

        if (\array_key_exists($resourceType, $this->includedFields) === false) {
            return true;
        }

        if (isset($this->includedFields[$resourceType][''])) {
            return false;
        }

        return isset($this->includedFields[$resourceType][$field]);
    }

    protected function parseIncludedRelationships(): void
    {
        $this->includedRelationships = [];

        $includeQueryParam = $this->getQueryParam('include', '');

        if (\is_string($includeQueryParam) === false) {
            throw new QueryParamMalformed('include', $includeQueryParam);
        }

        if ($includeQueryParam === '') {
            return;
        }

        $relationshipNames = \explode(',', $includeQueryParam);
        foreach ($relationshipNames as $relationship) {
            $relationship = '.' . $relationship . '.';
            $length = \strlen($relationship);
            $dot1 = 0;

            while ($dot1 < $length - 1) {
                $pos = \strpos($relationship, '.', $dot1 + 1);
                $dot2 = $pos !== false ? $pos : 0;
                $path = \substr($relationship, 1, $dot1 > 0 ? $dot1 - 1 : 0);
                $name = \substr($relationship, $dot1 + 1, $dot2 - $dot1 - 1);

                if (isset($this->includedRelationships[$path]) === false) {
                    $this->includedRelationships[$path] = [];
                }
                $this->includedRelationships[$path][$name] = $name;

                $dot1 = $dot2;
            }
        }
    }

    /**
     * Determines if any relationship needs to be included.
     */
    public function hasIncludedRelationships(): bool
    {
        if ($this->includedRelationships === null) {
            $this->parseIncludedRelationships();
        }

        return $this->includedRelationships !== [];
    }

    /**
     * Returns a list of relationship paths for a given parent path which should be included in the response.
     *
     * @return list<string>
     */
    public function getIncludedRelationships(string $baseRelationshipPath): array
    {
        if ($this->includedRelationships === null) {
            $this->parseIncludedRelationships();
        }

        if (isset($this->includedRelationships[$baseRelationshipPath])) {
            return \array_values($this->includedRelationships[$baseRelationshipPath]);
        }

        return [];
    }

    /**
     * Returns every requested full dotted include path, reconstructed from the
     * parsed include tree. A request for `?include=a.b.c` yields the full chain
     * `a`, `a.b`, `a.b.c` (the intermediate paths are themselves requested per
     * JSON:API semantics), so depth and allow-list checks can be evaluated against
     * the complete set.
     *
     * @return list<string>
     */
    public function getIncludePaths(): array
    {
        if ($this->includedRelationships === null) {
            $this->parseIncludedRelationships();
        }

        $paths = [];
        foreach ($this->includedRelationships ?? [] as $parentPath => $names) {
            foreach ($names as $name) {
                $paths[] = $parentPath === '' ? $name : $parentPath . '.' . $name;
            }
        }

        return \array_values(\array_unique($paths));
    }

    /**
     * Determines if a given relationship name that is a child of the $baseRelationshipPath should be included
     * in the response.
     *
     * @param array<string, mixed> $defaultRelationships
     */
    public function isIncludedRelationship(
        string $baseRelationshipPath,
        string $relationshipName,
        array $defaultRelationships,
    ): bool {
        if ($this->includedRelationships === null) {
            $this->parseIncludedRelationships();
        }

        if ($this->getQueryParam('include') === '') {
            return false;
        }

        if ($this->includedRelationships === [] && \array_key_exists($relationshipName, $defaultRelationships)) {
            return true;
        }

        return isset($this->includedRelationships[$baseRelationshipPath][$relationshipName]);
    }

    /**
     * Parses the flat, comma-separated `?withCount` query parameter into a
     * name → name map (deduplicated, empty names dropped). A blank or absent
     * parameter yields an empty map.
     *
     * Each member is either a relationship name (its `meta.total` on the relationship
     * object) or the reserved {@see CountableProfile::SELF_TOKEN} `_self_`, which
     * names the primary collection/resource (its `meta.total` top-level). The map
     * carries `_self_` through verbatim — no special parsing — and the countability
     * gate is enforced downstream.
     *
     * Opt-in: an empty map unless the client negotiated the Countable profile (its
     * URI in the `Accept` `profile` parameter), so `?withCount` carries no special
     * meaning — and a relationship literally named after the family never collides —
     * outside the profile.
     *
     * @return array<string, string>
     */
    protected function parseCountedRelationships(): array
    {
        if ($this->isProfileRequested(CountableProfile::URI) === false) {
            return [];
        }

        $withCount = $this->getQueryParam('withCount', '');

        if (\is_string($withCount) === false) {
            throw new QueryParamMalformed('withCount', $withCount);
        }

        $withCount = \trim($withCount);
        if ($withCount === '') {
            return [];
        }

        $names = [];
        foreach (\explode(',', $withCount) as $name) {
            $name = \trim($name);
            if ($name !== '') {
                $names[$name] = $name;
            }
        }

        return $names;
    }

    /**
     * @return list<string>
     */
    public function getCountedRelationships(): array
    {
        if ($this->countedRelationships === null) {
            $this->countedRelationships = $this->parseCountedRelationships();
        }

        return \array_values($this->countedRelationships);
    }

    public function countsRelationship(string $relationship): bool
    {
        if ($this->countedRelationships === null) {
            $this->countedRelationships = $this->parseCountedRelationships();
        }

        return isset($this->countedRelationships[$relationship]);
    }

    public function getRelatedQuery(string $path): RelatedQuery
    {
        if ($this->relatedQueries === null) {
            $this->relatedQueries = $this->parseRelatedQueries();
        }

        return $this->relatedQueries[$path] ?? new RelatedQuery();
    }

    public function hasRelatedQuery(string $path): bool
    {
        if ($this->relatedQueries === null) {
            $this->relatedQueries = $this->parseRelatedQueries();
        }

        return isset($this->relatedQueries[$path]);
    }

    public function getRelatedQueryPaths(): array
    {
        if ($this->relatedQueries === null) {
            $this->relatedQueries = $this->parseRelatedQueries();
        }

        return \array_keys($this->relatedQueries);
    }

    /**
     * Parses the Relationship Queries profile family (`relatedQuery` / `rQ`) into
     * a path → {@see RelatedQuery} map. Opt-in: an empty map unless the client
     * negotiated {@see RelationshipQueriesProfile::URI} (via the `Accept`
     * `profile` media-type parameter) — otherwise the family is ignored entirely.
     *
     * The canonical {@see RelationshipQueriesProfile::FAMILY} is merged after the
     * shorthand {@see RelationshipQueriesProfile::FAMILY_SHORTHAND}, so on a
     * conflict targeting the same `[path][op]` the canonical value wins. Each
     * family value must be an array keyed by path → an array keyed by op
     * (`sort` / `filter`); a non-conforming shape is a `400`
     * ({@see QueryParamMalformed}).
     *
     * @return array<string, RelatedQuery>
     */
    protected function parseRelatedQueries(): array
    {
        if ($this->isProfileRequested(RelationshipQueriesProfile::URI) === false) {
            return [];
        }

        // Shorthand first, then canonical — canonical overrides per (path, op).
        $sorts = [];
        $filters = [];

        foreach ([RelationshipQueriesProfile::FAMILY_SHORTHAND, RelationshipQueriesProfile::FAMILY] as $family) {
            $value = $this->getQueryParam($family);
            if ($value === null) {
                continue;
            }

            if (\is_array($value) === false) {
                throw new QueryParamMalformed($family, $value);
            }

            foreach ($value as $path => $ops) {
                $path = (string) $path;
                if (\is_array($ops) === false) {
                    throw new QueryParamMalformed($family . '[' . $path . ']', $ops);
                }

                if (\array_key_exists('sort', $ops)) {
                    $sort = $ops['sort'];
                    if (\is_string($sort) === false) {
                        throw new QueryParamMalformed($family . '[' . $path . '][sort]', $sort);
                    }
                    $sorts[$path] = $sort;
                }

                if (\array_key_exists('filter', $ops)) {
                    $filter = $ops['filter'];
                    if (\is_array($filter) === false) {
                        throw new QueryParamMalformed($family . '[' . $path . '][filter]', $filter);
                    }
                    /** @var array<string, mixed> $filter */
                    $filters[$path] = $filter;
                }
            }
        }

        $queries = [];
        foreach ([...\array_keys($sorts), ...\array_keys($filters)] as $path) {
            if (isset($queries[$path])) {
                continue;
            }
            $queries[$path] = new RelatedQuery($sorts[$path] ?? null, $filters[$path] ?? []);
        }

        return $queries;
    }

    /**
     * Returns the "sort[]" query parameters.
     *
     * @return list<string>
     */
    public function getSorting(): array
    {
        if ($this->sorting === null) {
            $this->sorting = $this->parseSorting();
        }

        return $this->sorting;
    }

    /** @return list<string> */
    protected function parseSorting(): array
    {
        $sortingQueryParam = $this->getQueryParam('sort', '');
        if (\is_string($sortingQueryParam) === false) {
            throw new QueryParamMalformed('sort', $sortingQueryParam);
        }

        if ($sortingQueryParam === '') {
            return [];
        }

        /** @var list<string> $result */
        $result = \explode(',', $sortingQueryParam);

        return $result;
    }

    /**
     * Returns the "page[]" query parameters.
     *
     * @return array<string, mixed>
     */
    public function getPagination(): array
    {
        if ($this->pagination === null) {
            $this->pagination = $this->parsePagination();
        }

        return $this->pagination;
    }

    /** @return array<string, mixed> */
    protected function parsePagination(): array
    {
        $pagination = $this->getQueryParam('page', []);

        if (\is_array($pagination) === false) {
            throw new QueryParamMalformed('page', $pagination);
        }

        /** @var array<string, mixed> $pagination */
        return $pagination;
    }

    /**
     * Returns the "filter[]" query parameters.
     *
     * @return array<string, mixed>
     */
    public function getFiltering(): array
    {
        if ($this->filtering === null) {
            $this->filtering = $this->parseFiltering();
        }

        return $this->filtering;
    }

    /** @return array<string, mixed> */
    protected function parseFiltering(): array
    {
        $filtering = $this->getQueryParam('filter', []);

        if (\is_array($filtering) === false) {
            throw new QueryParamMalformed('filter', $filtering);
        }

        /** @var array<string, mixed> $filtering */
        return $filtering;
    }

    public function getFilteringParam(string $param, mixed $default = null): mixed
    {
        $filtering = $this->getFiltering();

        return $filtering[$param] ?? $default;
    }

    /**
     * Returns the primary resource if it is present in the request body, or the $default value otherwise.
     *
     * @return array<string, mixed>|mixed
     */
    public function getResource(mixed $default = null): mixed
    {
        /** @var array<string, mixed> $body */
        $body = (array) $this->getParsedBody();

        return $body['data'] ?? $default;
    }

    public function getResourceType(mixed $default = null): mixed
    {
        /** @var array<string, mixed>|mixed $data */
        $data = $this->getResource();

        if (\is_array($data) === false) {
            return $default;
        }

        return $data['type'] ?? $default;
    }

    public function getResourceId(mixed $default = null): mixed
    {
        /** @var array<string, mixed>|mixed $data */
        $data = $this->getResource();

        if (\is_array($data) === false) {
            return $default;
        }

        return $data['id'] ?? $default;
    }

    public function getResourceLid(mixed $default = null): mixed
    {
        /** @var array<string, mixed>|mixed $data */
        $data = $this->getResource();

        if (\is_array($data) === false) {
            return $default;
        }

        return $data['lid'] ?? $default;
    }

    /**
     * Returns the "attributes" of the primary resource.
     *
     * @return array<string, mixed>
     */
    public function getResourceAttributes(): array
    {
        /** @var array<string, mixed>|mixed $data */
        $data = $this->getResource();

        if (\is_array($data) === false) {
            return [];
        }

        /** @var array<string, mixed> $attributes */
        $attributes = $data['attributes'] ?? [];

        return $attributes;
    }

    public function getResourceAttribute(string $attribute, mixed $default = null): mixed
    {
        $attributes = $this->getResourceAttributes();

        return $attributes[$attribute] ?? $default;
    }

    public function hasToOneRelationship(string $relationship): bool
    {
        $relationships = $this->getResourceRelationships();

        return isset($relationships[$relationship]) &&
            \array_key_exists('data', (array) $relationships[$relationship]);
    }

    /**
     * Returns the $relationship to-one relationship of the primary resource if it is present.
     *
     * @throws RelationshipNotExists
     */
    public function getToOneRelationship(string $relationship): ToOneRelationship
    {
        $relationships = $this->getResourceRelationships();

        if (
            isset($relationships[$relationship]) &&
            \array_key_exists('data', (array) $relationships[$relationship])
        ) {
            /** @var array<string, mixed> $rel */
            $rel = (array) $relationships[$relationship];

            // If data is null, the request clears the relationship
            if ($rel['data'] === null) {
                return new ToOneRelationship();
            }

            /** @var array<string, mixed> $relData */
            $relData = (array) $rel['data'];

            return new ToOneRelationship(ResourceIdentifier::fromArray($relData));
        }

        throw new RelationshipNotExists($relationship);
    }

    public function hasToManyRelationship(string $relationship): bool
    {
        $relationships = $this->getResourceRelationships();

        if (isset($relationships[$relationship]) === false) {
            return false;
        }

        /** @var array<string, mixed> $rel */
        $rel = (array) $relationships[$relationship];

        return isset($rel['data']);
    }

    /**
     * Returns the $relationship to-many relationship of the primary resource if it is present.
     *
     * @throws RelationshipNotExists
     */
    public function getToManyRelationship(string $relationship): ToManyRelationship
    {
        $relationships = $this->getResourceRelationships();

        if (isset($relationships[$relationship]) === false) {
            throw new RelationshipNotExists($relationship);
        }

        /** @var array<string, mixed> $rel */
        $rel = (array) $relationships[$relationship];

        if (isset($rel['data']) === false) {
            throw new RelationshipNotExists($relationship);
        }

        $resourceIdentifiers = [];
        /** @var list<array<string, mixed>> $relDataList */
        $relDataList = (array) $rel['data'];
        foreach ($relDataList as $item) {
            $resourceIdentifiers[] = ResourceIdentifier::fromArray((array) $item);
        }

        return new ToManyRelationship($resourceIdentifiers);
    }

    public function getRelationshipDataToOne(string $relationship): ToOneRelationship
    {
        /** @var array<string, mixed> $body */
        $body = (array) $this->getParsedBody();

        if (\array_key_exists('data', $body) === false) {
            throw new RelationshipNotExists($relationship);
        }

        $data = $body['data'];

        if ($data === null) {
            return new ToOneRelationship();
        }

        // A relationship-endpoint to-one body MUST carry `data` as a single
        // resource-identifier object (or `null`, handled above). A list — including
        // `[]` — is a cardinality mismatch: the client sent to-many linkage to a
        // to-one relationship endpoint.
        if (\is_array($data) === false || \array_is_list($data)) {
            throw new RelationshipTypeInappropriate($relationship, 'to-many', 'to-one');
        }

        /** @var array<string, mixed> $data */
        return new ToOneRelationship(ResourceIdentifier::fromArray($data));
    }

    public function getRelationshipDataToMany(string $relationship): ToManyRelationship
    {
        /** @var array<string, mixed> $body */
        $body = (array) $this->getParsedBody();

        if (\array_key_exists('data', $body) === false) {
            throw new RelationshipNotExists($relationship);
        }

        $data = $body['data'];

        // A relationship-endpoint to-many body MUST carry `data` as an array of
        // resource-identifier objects (possibly empty — the clear/replace-with-none
        // signal). A single object (or `null`) is a cardinality mismatch — the
        // client sent to-one linkage to a to-many relationship endpoint.
        if (\is_array($data) === false || ($data !== [] && \array_is_list($data) === false)) {
            throw new RelationshipTypeInappropriate($relationship, 'to-one', 'to-many');
        }

        $resourceIdentifiers = [];
        /** @var list<mixed> $data */
        foreach ($data as $item) {
            /** @var array<string, mixed> $itemArray */
            $itemArray = (array) $item;
            $resourceIdentifiers[] = ResourceIdentifier::fromArray($itemArray);
        }

        return new ToManyRelationship($resourceIdentifiers);
    }

    /**
     * Returns the relationships map from the primary resource data, or [] if absent.
     *
     * @return array<string, mixed>
     */
    protected function getResourceRelationships(): array
    {
        /** @var array<string, mixed>|mixed $data */
        $data = $this->getResource();

        if (\is_array($data) === false) {
            return [];
        }

        if (isset($data['relationships']) === false || \is_array($data['relationships']) === false) {
            return [];
        }

        /** @var array<string, mixed> $relationships */
        $relationships = $data['relationships'];

        return $relationships;
    }

    protected function headerChanged(string $name): void
    {
        $name = \strtolower($name);

        if ($name === 'content-type') {
            unset($this->profiles['content-type'], $this->extensions['content-type']);
        }

        if ($name === 'accept') {
            unset($this->profiles['accept'], $this->extensions['accept']);
            // Negotiation gates the relatedQuery/rQ and withCount parses, so a changed
            // Accept (which carries the negotiated profiles) invalidates both caches.
            $this->relatedQueries = null;
            $this->countedRelationships = null;
        }
    }

    protected function queryParamChanged(string $name): void
    {
        if ($name === 'fields') {
            $this->includedFields = null;
        }

        if ($name === 'include') {
            $this->includedRelationships = null;
        }

        if ($name === 'withCount') {
            $this->countedRelationships = null;
        }

        if ($name === 'sort') {
            $this->sorting = null;
        }

        if ($name === 'page') {
            $this->pagination = null;
        }

        if ($name === 'filter') {
            $this->filtering = null;
        }

        if ($name === RelationshipQueriesProfile::FAMILY || $name === RelationshipQueriesProfile::FAMILY_SHORTHAND) {
            $this->relatedQueries = null;
        }

        if ($name === 'profile') {
            unset($this->profiles['profile']);
        }
    }
}
