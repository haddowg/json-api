<?php

declare(strict_types=1);

namespace haddowg\JsonApi\OpenApi;

/**
 * A scope some operations require that neither the scope catalogue nor the scope
 * scheme's configured flows declare, so the document cannot describe what it grants.
 */
final readonly class UndeclaredScope implements \Stringable
{
    /**
     * @param list<string> $operations the ids of the operations that require it
     */
    public function __construct(
        public string $scope,
        public array $operations,
    ) {}

    public function message(): string
    {
        return \sprintf('The scope "%s" is required by %s but is not declared in the scope catalogue or the scope scheme\'s flows.', $this->scope, \implode(', ', $this->operations));
    }

    public function __toString(): string
    {
        return $this->message();
    }
}
