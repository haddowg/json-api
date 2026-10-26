<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Field;

/**
 * A relation that can declare the id its linkage shows in generated examples, set by
 * {@see AbstractRelationBuilder::exampleId()}. Every core relation implements it; it is
 * separate from {@see RelationInterface} so a hand-written relation keeps compiling.
 */
interface ExemplifiedRelationInterface
{
    /**
     * The related resource id the relation's linkage shows in generated examples, or
     * `null` when it declares none.
     */
    public function exampleId(): ?string;
}
