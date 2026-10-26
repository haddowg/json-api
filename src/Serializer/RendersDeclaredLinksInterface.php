<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Serializer;

use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Schema\Link\Link;

/**
 * Optional capability: a serializer whose resource objects carry declared links
 * ({@see \haddowg\JsonApi\Resource\Link\DeclaredLink}). The transformer merges them into
 * the resource's `links` beside {@see SerializerInterface::getLinks()}, the contributed
 * links and the convention `self`: a name `getLinks()` already set wins over a declared
 * link, and a declared link wins over a contributed one.
 *
 * {@see \haddowg\JsonApi\Resource\AbstractResource} implements it from its
 * {@see \haddowg\JsonApi\Resource\AbstractResource::links()}; a serializer that does not
 * implement it renders no declared links.
 */
interface RendersDeclaredLinksInterface
{
    /**
     * The declared links one resource renders, keyed by name. A link whose href resolves
     * to `null` is absent.
     *
     * @return array<string, Link>
     */
    public function renderDeclaredLinks(mixed $object, JsonApiRequestInterface $request): array;
}
