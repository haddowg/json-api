<?php

declare(strict_types=1);

namespace haddowg\JsonApi\OpenApi\Metadata;

use haddowg\JsonApi\Resource\DocumentMeta;
use haddowg\JsonApi\Resource\Field\FieldBuilderInterface;
use haddowg\JsonApi\Resource\Field\FieldInterface;

/**
 * `201 Created` with the document of a named JSON:API resource type: a custom action that
 * creates the resource it answers with, such as `POST /orders/{id}/-actions/duplicate`.
 * The projection documents an optional `Location` header beside the document.
 *
 * {@see \haddowg\JsonApi\Server\Server::dispatch()} renders the action's
 * {@see \haddowg\JsonApi\Response\DataResponse} as `201` unless the action also declares
 * an {@see ActionResource} `200`, in which case the handler picks with `withStatus(201)`
 * ({@see ActionResponses::dataStatus()}).
 *
 * `$meta` declares the members of the document's top-level `meta`, with the field builders
 * an attribute takes. The declaration is closed: the projection narrows the document's `meta`
 * to these members, and an adapter in debug mode refuses a response that renders another. A
 * member declared `required()` is in every response; any other is optional. The handler sets
 * the values with `withMeta()`, and each member's declared example feeds the operation's
 * example.
 */
final readonly class ActionCreated implements ActionResponse
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
            throw new \InvalidArgumentException('An action created response requires a non-empty type.');
        }
        $this->meta = (new DocumentMeta($meta, owner: \sprintf('the action response of type "%s"', $type)))->fields;
    }

    /**
     * The same response with `$meta` as the members of its document's top-level `meta`.
     *
     * @param list<FieldInterface|FieldBuilderInterface> $meta
     */
    public function withMeta(array $meta): self
    {
        return new self($this->type, $meta);
    }

    public function status(): int
    {
        return 201;
    }

    public function jobType(): ?string
    {
        return null;
    }

    /**
     * The JSON:API type whose document schema is the action's `201` response body.
     */
    public function bodyType(): string
    {
        return $this->type;
    }
}
