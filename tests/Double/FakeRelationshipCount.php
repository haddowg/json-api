<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Double;

use haddowg\JsonApi\Resource\Field\RelationInterface;
use haddowg\JsonApi\Serializer\RelationshipCountInterface;

/**
 * A {@see RelationshipCountInterface} double returning a fixed count for every
 * relation, recording the relations it was asked about so tests can assert the
 * seam was (or was not) consulted. A `null` count models "no count available".
 */
final class FakeRelationshipCount implements RelationshipCountInterface
{
    /**
     * @var list<RelationInterface>
     */
    public array $askedAbout = [];

    public function __construct(private readonly ?int $count) {}

    public function countRelationship(mixed $model, RelationInterface $relation): ?int
    {
        $this->askedAbout[] = $relation;

        return $this->count;
    }
}
