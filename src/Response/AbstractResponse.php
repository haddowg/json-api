<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Response;

use haddowg\JsonApi\Document\TopLevelMembers;
use haddowg\JsonApi\Request\JsonApiRequest;
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Response\Internal\RenderedDocument;
use haddowg\JsonApi\Schema\JsonApiObject;
use haddowg\JsonApi\Schema\Link\DocumentLinks;
use haddowg\JsonApi\Schema\Profile\ProfileInterface;
use haddowg\JsonApi\Server\ServerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Base for the public response value objects.
 *
 * Holds the document-level members common to every response (meta, links, the
 * jsonapi object), the response headers and an optional per-response encode-flag
 * override, plus the fluent withers that set them and the render template that
 * turns a response into a PSR-7 message.
 *
 * Immutability follows the {@see \haddowg\JsonApi\Request\AbstractRequest}
 * convention: the class is NOT `readonly`, properties are `protected`, and each
 * wither does clone-then-assign (`$self = clone $this; $self->x = …; return
 * $self;`) — a `readonly` property cannot be reassigned on a clone under PHP 8.3.
 *
 * The render path is serializer-free until the very end: {@see render()} builds a
 * PHP array via the transformer and {@see toPsrResponse()} encodes it.
 */
abstract class AbstractResponse
{
    /**
     * @var array<string, mixed>
     */
    protected array $meta = [];

    protected ?DocumentLinks $links = null;

    protected ?JsonApiObject $jsonApi = null;

    /**
     * @var array<string, string>
     */
    protected array $headers = [];

    protected ?int $encodeOptions = null;

    protected ?int $status = null;

    /**
     * Extension URIs advertised in the response `ext` media-type parameter, set by
     * {@see withExtensions()} and merged with any a subclass hard-codes in
     * {@see extensions()}.
     *
     * @var list<string>
     */
    protected array $appliedExtensions = [];

    /**
     * @param array<string, mixed> $meta
     */
    public function withMeta(array $meta): static
    {
        $self = clone $this;
        $self->meta = $meta;

        return $self;
    }

    public function withLinks(?DocumentLinks $links): static
    {
        $self = clone $this;
        $self->links = $links;

        return $self;
    }

    public function withJsonApi(?JsonApiObject $jsonApi): static
    {
        $self = clone $this;
        $self->jsonApi = $jsonApi;

        return $self;
    }

    public function withHeader(string $name, string $value): static
    {
        $self = clone $this;
        $self->headers[$name] = $value;

        return $self;
    }

    /**
     * @param array<string, string> $headers
     */
    public function withHeaders(array $headers): static
    {
        $self = clone $this;
        $self->headers = $headers;

        return $self;
    }

    public function withEncodeOptions(int $encodeOptions): static
    {
        $self = clone $this;
        $self->encodeOptions = $encodeOptions;

        return $self;
    }

    /**
     * Advertises one or more JSON:API extensions on this response's `Content-Type`
     * `ext` media-type parameter. A document produced by applied extension
     * processing MUST declare those extensions — e.g. an error response rolled back
     * from an Atomic Operations batch carries the atomic extension URI. The given
     * URIs are merged (de-duplicated) with any a subclass hard-codes in
     * {@see extensions()}.
     *
     * @param list<string> $extensions
     */
    public function withExtensions(array $extensions): static
    {
        $self = clone $this;
        $self->appliedExtensions = $extensions;

        return $self;
    }

    /**
     * Overrides the HTTP status the response renders with. Each response type
     * renders a sensible default (a `DataResponse` is `200`); a write handler
     * sets `201` on a create, for example. Ignored by {@see NoContentResponse},
     * which is always `204`.
     */
    public function withStatus(int $status): static
    {
        $self = clone $this;
        $self->status = $status;

        return $self;
    }

    /**
     * Renders the response value object into a PSR-7 response: builds the body
     * array via {@see render()}, JSON-encodes it with the resolved flags and the
     * fixed `application/vnd.api+json` content type, then applies any configured
     * headers. The status is the one {@see withStatus()} set, falling back to the
     * rendered default. A bodiless render (a `204`) omits the body and the
     * `Content-Type` header.
     *
     * @throws \JsonException when the body cannot be encoded
     */
    final public function toPsrResponse(ServerInterface $server, ServerRequestInterface $request): ResponseInterface
    {
        $jsonApiRequest = $request instanceof JsonApiRequestInterface ? $request : new JsonApiRequest($request);

        $rendered = $this->render($server, $jsonApiRequest);
        $status = $this->status ?? $rendered->status;

        if (!$rendered->hasBody) {
            return $this->applyHeaders($server->responseFactory()->createResponse($status));
        }

        $profiles = $this->appliedProfiles($server, $jsonApiRequest);
        $body = $this->applyProfiles($rendered->body, $profiles, $jsonApiRequest);
        $body = $this->orderTopLevelMembers($body);

        // JSON_THROW_ON_ERROR is passed inline (not via a variable) so PHPStan narrows
        // json_encode()'s return to string; an unencodable document throws \JsonException.
        $json = \json_encode(
            $body,
            \JSON_THROW_ON_ERROR | ($this->encodeOptions ?? $server->encodeOptions()),
        );

        $response = $server->responseFactory()
            ->createResponse($status)
            ->withHeader('Content-Type', $this->contentType($profiles))
            ->withBody($server->streamFactory()->createStream($json));

        if ($profiles !== []) {
            // Servers supporting the profile media-type parameter SHOULD vary on Accept.
            $response = $response->withHeader('Vary', 'Accept');
        }

        return $this->applyHeaders($response);
    }

    /**
     * Applies the configured response headers ({@see withHeader()}) onto a PSR-7
     * response.
     */
    private function applyHeaders(ResponseInterface $response): ResponseInterface
    {
        foreach ($this->headers as $name => $value) {
            $response = $response->withHeader($name, $value);
        }

        return $response;
    }

    /**
     * The profiles applied to this response: the server-registered profiles the
     * request asked for (via the `Accept` `profile` parameter or the `profile`
     * query parameter). Unrecognized profiles are ignored, never rejected.
     * Subclasses extend this (e.g. {@see DataResponse} adds a paginator's
     * profile).
     *
     * @return list<ProfileInterface>
     */
    protected function appliedProfiles(ServerInterface $server, JsonApiRequestInterface $request): array
    {
        $profiles = [];
        $seen = [];

        foreach ([...$request->getRequestedProfiles(), ...$request->getRequiredProfiles()] as $uri) {
            if (isset($seen[$uri])) {
                continue;
            }

            $profile = $server->profiles()->get($uri);
            if ($profile !== null) {
                $profiles[] = $profile;
                $seen[$uri] = true;
            }
        }

        return $profiles;
    }

    /**
     * Normalises a document's top-level members into the canonical
     * {@see TopLevelMembers::ORDER} — `data` (or `errors`) first, `jsonapi` last —
     * so the serialized shape is identical regardless of how the document was
     * assembled. `array_key_exists` keeps a present-but-null member (e.g. an empty
     * to-one's `data: null`); any unexpected member is preserved after the known set.
     *
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    private function orderTopLevelMembers(array $body): array
    {
        $ordered = [];
        foreach (TopLevelMembers::ORDER as $member) {
            if (\array_key_exists($member, $body)) {
                $ordered[$member] = $body[$member];
                unset($body[$member]);
            }
        }

        return [...$ordered, ...$body];
    }

    /**
     * Runs each applied profile's finalisation hook over the body and records the
     * applied profile URIs in the top-level `jsonapi.profile` member — the location
     * JSON:API 1.1 defines for advertising applied profiles (an array of URIs on the
     * `jsonapi` object), not a `links.profile` member.
     *
     * @param array<string, mixed>   $body
     * @param list<ProfileInterface> $profiles
     *
     * @return array<string, mixed>
     */
    private function applyProfiles(array $body, array $profiles, JsonApiRequestInterface $request): array
    {
        if ($profiles === []) {
            return $body;
        }

        foreach ($profiles as $profile) {
            $body = $profile->finalizeDocument($body, $request);
        }

        $jsonapi = $body['jsonapi'] ?? [];
        $jsonapi = \is_array($jsonapi) ? $jsonapi : [];

        $existing = $jsonapi['profile'] ?? [];
        $existing = \is_array($existing) ? \array_values(\array_filter($existing, '\is_string')) : [];

        $uris = \array_map(static fn(ProfileInterface $profile): string => $profile->uri(), $profiles);

        $jsonapi['profile'] = \array_values(\array_unique([...$existing, ...$uris]));
        $body['jsonapi'] = $jsonapi;

        return $body;
    }

    /**
     * The response `Content-Type`, echoing the applied profile URIs in the
     * `profile` media-type parameter when any profiles are applied, and the
     * applied extension URIs in the `ext` media-type parameter when a response
     * type advertises any (see {@see extensions()}).
     *
     * @param list<ProfileInterface> $profiles
     */
    private function contentType(array $profiles): string
    {
        $type = 'application/vnd.api+json';

        $extensions = \array_values(\array_unique([...$this->extensions(), ...$this->appliedExtensions]));
        if ($extensions !== []) {
            $type .= '; ext="' . \implode(' ', $extensions) . '"';
        }

        if ($profiles !== []) {
            $uris = \array_map(static fn(ProfileInterface $profile): string => $profile->uri(), $profiles);
            $type .= '; profile="' . \implode(' ', $uris) . '"';
        }

        return $type;
    }

    /**
     * The JSON:API extension URIs this response advertises in the `ext` media-type
     * parameter of its `Content-Type`. The base advertises none; a response that
     * applies an extension (e.g. the Atomic Operations response) overrides this to
     * return that extension's URI.
     *
     * @return list<string>
     */
    protected function extensions(): array
    {
        return [];
    }

    /**
     * Resolves the document's `jsonapi` object: an explicitly set one, otherwise
     * one built from the server defaults.
     */
    protected function resolveJsonApi(ServerInterface $server): JsonApiObject
    {
        return $this->jsonApi ?? new JsonApiObject($server->jsonApiVersion(), $server->defaultMeta());
    }

    /**
     * Merges the spec-recommended top-level `links.self` — the URI that produced
     * the document (`{resolvedBase}{request.path}` plus the query string when
     * present, where `{resolvedBase}` is the configured base URI or, when none is
     * configured, the request origin — see {@see \haddowg\JsonApi\Server\RequestBaseUri})
     * — into the rendered body, for the data/resource documents that
     * call it (single, collection, related, relationship, meta). Error documents
     * do not call it. The URI derivation mirrors {@see AppliesPaginationTrait}
     * exactly. An existing top-level `self` (hand-set via {@see withLinks()}, or a
     * paginator's) wins; the merge preserves the pagination links alongside it.
     *
     * @param array<string, mixed> $result
     *
     * @return array<string, mixed>
     */
    protected function applyTopLevelSelf(array $result, ServerInterface $server, JsonApiRequestInterface $request): array
    {
        /** @var array<string, mixed> $links */
        $links = $result['links'] ?? [];
        if (isset($links['self'])) {
            return $result;
        }

        $self = \haddowg\JsonApi\Server\RequestBaseUri::resolve($server->baseUri(), $request->getUri()) . $request->getUri()->getPath();
        $queryString = $request->getUri()->getQuery();
        if ($queryString !== '') {
            $self .= '?' . $queryString;
        }

        $links['self'] = $self;
        $result['links'] = $links;

        return $result;
    }

    /**
     * Builds the JSON:API document body and the HTTP status for this response.
     */
    abstract protected function render(ServerInterface $server, JsonApiRequestInterface $request): RenderedDocument;
}
