<?php

declare(strict_types=1);

namespace haddowg\JsonApi\OpenApi\Metadata;

/**
 * `201 Created` with the document of a named JSON:API resource type: a custom action that
 * creates the resource it answers with, such as `POST /orders/{id}/-actions/duplicate`.
 * The projection documents an optional `Location` header beside the document.
 *
 * {@see \haddowg\JsonApi\Server\Server::dispatch()} renders the action's
 * {@see \haddowg\JsonApi\Response\DataResponse} as `201` unless the action also declares
 * an {@see ActionResource} `200`, in which case the handler picks with `withStatus(201)`
 * ({@see ActionResponses::dataStatus()}).
 */
final readonly class ActionCreated implements ActionResponse
{
    public function __construct(
        private string $type,
    ) {
        if ($type === '') {
            throw new \InvalidArgumentException('An action created response requires a non-empty type.');
        }
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
