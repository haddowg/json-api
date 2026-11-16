<?php

declare(strict_types=1);

namespace haddowg\JsonApi\OpenApi;

use haddowg\JsonApi\OpenApi\Metadata\ActionMetadataInterface;
use haddowg\JsonApi\OpenApi\Metadata\OperationKey;
use haddowg\JsonApi\OpenApi\Metadata\RelationMetadataInterface;

/**
 * One operation a type exposes, as {@see OperationEnumerator} lists it.
 *
 * @internal
 */
final readonly class EnumeratedOperation
{
    /**
     * @param string       $path   the path template the document keys the operation under
     * @param string       $method the lower-case HTTP method, as a path item keys it
     * @param bool         $singleton whether the operation belongs to a singleton type, so its path has no `{id}`
     * @param list<string> $tags
     */
    public function __construct(
        public OperationKey $key,
        public string $path,
        public string $method,
        public string $operationId,
        public array $tags,
        public ?RelationMetadataInterface $relation = null,
        public ?ActionMetadataInterface $action = null,
        public bool $singleton = false,
    ) {}

    public function reference(): OperationReference
    {
        return new OperationReference($this->path, $this->method);
    }
}
