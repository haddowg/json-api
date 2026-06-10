<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Double;

use haddowg\JsonApi\Resource\Field\RelationInterface;
use haddowg\JsonApi\Serializer\RelationshipLoadStateInterface;

/**
 * A {@see RelationshipLoadStateInterface} double that reports a fixed answer for
 * every relation, recording the relations it was asked about so tests can assert
 * the predicate was (or was not) consulted.
 */
final class FakeRelationshipLoadState implements RelationshipLoadStateInterface
{
    /**
     * @var list<RelationInterface>
     */
    public array $askedAbout = [];

    public function __construct(private readonly bool $loaded) {}

    public function isRelationshipLoaded(mixed $model, RelationInterface $relation): bool
    {
        $this->askedAbout[] = $relation;

        return $this->loaded;
    }
}
