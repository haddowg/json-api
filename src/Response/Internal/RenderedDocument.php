<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Response\Internal;

/**
 * The product of rendering a response value object: the transformed JSON:API
 * document body and the HTTP status it should be returned with. The
 * {@see \haddowg\JsonApi\Response\AbstractResponse::toPsrResponse()} template
 * encodes the body and applies the status.
 *
 * `$hasBody` is `false` for a bodiless response (a `204 No Content`): the
 * template then omits the body and the `Content-Type` header entirely.
 *
 * @internal
 */
final readonly class RenderedDocument
{
    /**
     * @param array<string, mixed> $body
     */
    public function __construct(
        public array $body,
        public int $status,
        public bool $hasBody = true,
    ) {}
}
