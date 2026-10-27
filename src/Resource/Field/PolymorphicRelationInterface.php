<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Field;

/**
 * Marks a relation as polymorphic by declaration: its related resource may be any of
 * the types it lists, however many that is. {@see MorphTo} and {@see MorphToMany}
 * implement it, so a `MorphTo` over one type is still polymorphic while a `BelongsTo`
 * never is. An OpenAPI metadata adapter reads it to answer
 * {@see \haddowg\JsonApi\OpenApi\Metadata\DeclaresPolymorphicRelation::isPolymorphic()}.
 */
interface PolymorphicRelationInterface extends RelationInterface {}
