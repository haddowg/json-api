<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Examples\MusicCatalog\Domain;

/**
 * A user's library: a polymorphic to-many (`MorphToMany`) of mixed members
 * (Track|Album|Artist). `$owner` is the related {@see User}; `$items` is the
 * mixed related list the `MorphToMany` relation reads straight off the object,
 * rendering each member through its own per-type serializer.
 */
final class Library
{
    /**
     * @param list<object> $items
     */
    public function __construct(
        public string $id = '',
        public ?User $owner = null,
        public array $items = [],
    ) {}
}
