<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Internal;

use haddowg\JsonApi\Request\JsonApiRequestInterface;

/**
 * The callable of a field declared `notSparseField()`, which the transformer renders
 * whatever `fields[type]` lists.
 *
 * @internal
 *
 * @template TValue
 */
final readonly class NonSparseMember
{
    /**
     * @param \Closure(mixed, JsonApiRequestInterface, string): TValue $render
     */
    public function __construct(private \Closure $render) {}

    /**
     * @return TValue
     */
    public function __invoke(mixed $model, JsonApiRequestInterface $request, string $name): mixed
    {
        return ($this->render)($model, $request, $name);
    }
}
