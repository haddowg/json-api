<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Schema\Document;

use haddowg\JsonApi\Schema\JsonApiObject;
use haddowg\JsonApi\Schema\Link\DocumentLinks;

/**
 * Common top-level members of a JSON:API document.
 *
 * @internal
 *
 * @see https://github.com/woohoolabs/yin — original work (MIT), from which this derives.
 */
interface DocumentInterface
{
    /**
     * Provides the "jsonapi" member of the document, or null to omit it.
     */
    public function getJsonApi(): ?JsonApiObject;

    /**
     * Provides the "meta" member of the document. An empty array omits the member.
     *
     * @return array<string, mixed>
     */
    public function getMeta(): array;

    /**
     * Provides the "links" member of the document, or null to omit it.
     */
    public function getLinks(): ?DocumentLinks;
}
