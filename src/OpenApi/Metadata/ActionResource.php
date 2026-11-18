<?php

declare(strict_types=1);

namespace haddowg\JsonApi\OpenApi\Metadata;

use haddowg\JsonApi\Resource\DocumentMeta;
use haddowg\JsonApi\Resource\Field\FieldBuilderInterface;
use haddowg\JsonApi\Resource\Field\FieldInterface;

/**
 * `200 OK` with the document of a named JSON:API resource type — a custom action whose
 * success body is that type's resource document. Replaces the former
 * {@see ActionMetadataInterface} `Document` output mode + `outputType` pair.
 *
 * `$meta` declares the members of the document's top-level `meta`, with the field builders
 * an attribute takes. The declaration is closed: the projection narrows the document's `meta`
 * to these members, and an adapter in debug mode refuses a response that renders another. A
 * member declared `required()` is in every response; any other is optional. The handler sets
 * the values with `withMeta()`, and each member's declared example feeds the operation's
 * example.
 */
final readonly class ActionResource implements ActionResponse
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
    public function __construct(
        private string $type,
        array $meta = [],
    ) {
        if ($type === '') {
            throw new \InvalidArgumentException('An action resource response requires a non-empty type.');
        }
        $this->meta = (new DocumentMeta($meta, owner: \sprintf('the action response of type "%s"', $type)))->fields;
    }

    public function status(): int
    {
        return 200;
    }

    public function jobType(): ?string
    {
        return null;
    }

    /**
     * The JSON:API type whose document schema is the action's `200` response body.
     */
    public function bodyType(): string
    {
        return $this->type;
    }
}
