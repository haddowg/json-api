<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Serializer;

use haddowg\JsonApi\Resource\Link\ActionLink;

/**
 * Optional capability: a serializer whose resource objects carry declared action links
 * ({@see ActionLink}). An adapter that renders custom actions as resource links reads them
 * here to decide which actions link and under what condition.
 *
 * {@see \haddowg\JsonApi\Resource\AbstractResource} implements it from its
 * {@see \haddowg\JsonApi\Resource\AbstractResource::links()}; a serializer that wraps a
 * resource forwards it.
 */
interface DeclaresActionLinksInterface
{
    /**
     * The declared action links, in declaration order, at most one per action path.
     *
     * @return list<ActionLink>
     */
    public function allActionLinks(): array;
}
