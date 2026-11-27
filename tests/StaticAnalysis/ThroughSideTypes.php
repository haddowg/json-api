<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\StaticAnalysis;

use haddowg\JsonApi\Resource\Field\BelongsToMany;
use haddowg\JsonApi\Resource\Field\Integer;

final class ThroughSideSeat {}

/**
 * Never executed. phpstan-hooks.neon analyses it with PHPStan's default settings, as a
 * consuming project would, so through() keeps taking a class-string and its named sides as
 * an author writes them.
 */
final class ThroughSideTypes
{
    public function sides(): BelongsToMany
    {
        return BelongsToMany::make('peers', 'owners')
            ->through(ThroughSideSeat::class, parent: 'owner', related: 'peer')
            ->fields(Integer::make('rank'))
            ->build();
    }

    public function oneSide(): BelongsToMany
    {
        return BelongsToMany::make('followers', 'owners')->through(ThroughSideSeat::class, related: 'owner')->build();
    }

    public function sidesRead(BelongsToMany $relation): string
    {
        return ($relation->pivotParentSide() ?? '') . ($relation->pivotRelatedSide() ?? '');
    }
}
