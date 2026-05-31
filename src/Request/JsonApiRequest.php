<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Request;

use haddowg\JsonApi\Exception\MediaTypeUnacceptable;
use haddowg\JsonApi\Exception\MediaTypeUnsupported;
use haddowg\JsonApi\Exception\QueryParamMalformed;
use haddowg\JsonApi\Exception\QueryParamUnrecognized;
use haddowg\JsonApi\Exception\RelationshipNotExists;
use haddowg\JsonApi\Exception\RequiredTopLevelMembersMissing;
use haddowg\JsonApi\Exception\TopLevelMemberNotAllowed;
use haddowg\JsonApi\Exception\TopLevelMembersIncompatible;
use haddowg\JsonApi\Hydrator\Relationship\ToManyRelationship;
use haddowg\JsonApi\Hydrator\Relationship\ToOneRelationship;
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
 * @see https://github.com/woohoolabs/yin — original work (MIT), from which this derives.
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
     * Parsed profile lists: keyed by "applied", "requested", "required".
     * Each entry is a map of profile-URL → profile-URL (for O(1) membership test).
     *
     * @var array<string, array<string, string>|null>
     */
    protected array $profiles = [];

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
        if ($this->isValidMediaTypeHeader('accept') === false) {
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
        $header = $this->getHeaderLine($headerName);
        $matches = [];
        \preg_match('/^.*application\/vnd\.api\+json\s*;\s*profile\s*=\s*[\"]*([^\";,]*).*$/i', $header, $matches);

        if (isset($matches[1]) === false) {
            return;
        }

        $profileList = \array_flip(\explode(' ', $matches[1]));
        /** @var array<string, string> $profileList */
        $this->profiles[$headerName] = $profileList;
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
            unset($this->profiles['content-type']);
        }

        if ($name === 'accept') {
            unset($this->profiles['accept']);
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

        if ($name === 'sort') {
            $this->sorting = null;
        }

        if ($name === 'page') {
            $this->pagination = null;
        }

        if ($name === 'filter') {
            $this->filtering = null;
        }

        if ($name === 'profile') {
            unset($this->profiles['profile']);
        }
    }
}
