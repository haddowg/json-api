<?php

declare(strict_types=1);

namespace haddowg\JsonApi\OpenApi\Metadata;

use haddowg\JsonApi\Resource\DocumentMeta;
use haddowg\JsonApi\Resource\Field\FieldBuilderInterface;
use haddowg\JsonApi\Resource\Field\FieldInterface;

/**
 * `200 OK` with a meta-only document (no primary `data`). Valid on delete — a delete
 * that answers with top-level `meta` rather than `204 No Content`.
 *
 * `$meta` declares the members of that `meta`, with the field builders an attribute takes.
 * The declaration is closed: the projection narrows the meta document to these members, and
 * an adapter in debug mode refuses a response that renders another. A member declared
 * `required()` is in every response; any other is optional. With none declared the document
 * stays the shared, open `MetaDocument`.
 */
final readonly class MetaResult implements DeleteResponse, ActionResponse
{
    /**
     * The members of the document's top-level `meta`, built.
     *
     * @var list<FieldInterface>
     */
    public array $meta;

    /**
     * @param list<FieldInterface|FieldBuilderInterface> $meta the members of the document's top-level `meta`
     */
    public function __construct(array $meta = [])
    {
        $this->meta = (new DocumentMeta($meta, owner: 'the meta-only response'))->fields;
    }

    public function status(): int
    {
        return 200;
    }

    public function jobType(): ?string
    {
        return null;
    }
}
