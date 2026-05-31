<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Server;

use haddowg\JsonApi\Schema\Profile\ProfileRegistry;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * The minimum the response value objects read to render a JSON:API response.
 *
 * The response layer is serializer-free: it builds a PHP array via the
 * transformer, then encodes it here using the server's PSR-17 factories and
 * encode options. A concrete {@see Server} (Phase 4.5) implements a superset of
 * this contract.
 *
 * @see https://jsonapi.org/format/1.1/
 */
interface ServerInterface
{
    /**
     * The base URI prepended to generated links. May be the empty string.
     */
    public function baseUri(): string;

    /**
     * The default value for the document's `jsonapi.version` member.
     */
    public function jsonApiVersion(): string;

    /**
     * The default value for the document's `jsonapi.meta` member.
     *
     * @return array<string, mixed>
     */
    public function defaultMeta(): array;

    /**
     * The default flags passed to {@see \json_encode()} when rendering the body.
     */
    public function encodeOptions(): int;

    /**
     * The profiles this server recognizes. The response layer applies the
     * registered profiles a request asks for (echoing them on the `Content-Type`
     * and in `links.profile`) and ignores any it does not recognize.
     */
    public function profiles(): ProfileRegistry;

    /**
     * The PSR-17 factory used to create the response message.
     */
    public function responseFactory(): ResponseFactoryInterface;

    /**
     * The PSR-17 factory used to create the response body stream.
     */
    public function streamFactory(): StreamFactoryInterface;
}
