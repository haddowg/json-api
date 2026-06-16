<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Testing\Internal;

use haddowg\JsonApi\Response\AbstractResponse;
use haddowg\JsonApi\Server\ServerInterface;
use haddowg\JsonApi\Testing\ResponseMeta;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Normalises the assorted document inputs the {@see \haddowg\JsonApi\Testing\JsonApiDocument}
 * / {@see \haddowg\JsonApi\Testing\JsonApiErrors} wrappers accept — a PSR-7
 * response, a raw JSON string, an already-parsed array, or (with a server +
 * request to render it) a response value object — into a parsed
 * `array<string, mixed>`.
 *
 * @internal
 */
final class Decode
{
    /**
     * @param ResponseInterface|string|array<string, mixed>|AbstractResponse $document
     *
     * @return array<string, mixed>
     */
    public static function toArray(
        ResponseInterface|string|array|AbstractResponse $document,
        ?ServerInterface $server = null,
        ?ServerRequestInterface $request = null,
    ): array {
        if (\is_array($document)) {
            return $document;
        }

        if ($document instanceof AbstractResponse) {
            if ($server === null) {
                throw new \InvalidArgumentException(
                    'Rendering a response value object requires a ServerInterface; pass one to the wrapper, '
                    . 'or call toPsrResponse() yourself and pass the resulting response.',
                );
            }
            $document = $document->toPsrResponse($server, $request ?? RequestStub::get());
        }

        if ($document instanceof ResponseInterface) {
            $document = (string) $document->getBody();
        }

        if ($document === '') {
            return [];
        }

        $decoded = \json_decode($document, true, 512, \JSON_THROW_ON_ERROR);
        if (!\is_array($decoded)) {
            throw new \InvalidArgumentException('The document did not decode to a JSON object.');
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * Extracts the plain-data response envelope (status + flattened header map)
     * from a PSR-7 response, so the existing PSR-7 callers of the document
     * wrappers gain status/header assertions for free. Any other input shape
     * carries no envelope.
     *
     * @param ResponseInterface|string|array<string, mixed>|AbstractResponse $document
     */
    public static function toResponseMeta(
        ResponseInterface|string|array|AbstractResponse $document,
        ?ServerInterface $server = null,
        ?ServerRequestInterface $request = null,
    ): ?ResponseMeta {
        if ($document instanceof AbstractResponse) {
            if ($server === null) {
                return null;
            }
            $document = $document->toPsrResponse($server, $request ?? RequestStub::get());
        }

        if (!$document instanceof ResponseInterface) {
            return null;
        }

        $headers = [];
        foreach ($document->getHeaders() as $name => $values) {
            $headers[$name] = \implode(', ', $values);
        }

        return new ResponseMeta($document->getStatusCode(), $headers);
    }
}
