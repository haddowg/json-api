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

    /**
     * A `describedby` top-level link merged into the rendered `links` at render time
     * ({@see withDescribedby()}), symmetric with the by-convention `self`. Kept off the
     * `DocumentLinks` value object so a caller (e.g. the Symfony bundle pointing at the
     * served OpenAPI document) can contribute it without reconstructing whatever links
     * a handler already set.
     */
    protected ?\haddowg\JsonApi\Schema\Link\Link $describedby = null;

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
     * Profiles a **nested** render activated that the document must still advertise
     * — set by {@see withActivatedProfiles()}. The paginated responses advertise the
     * profile of their own primary/related page automatically
     * ({@see AppliesPaginationTrait::appliedPageProfiles()}); this carries a profile
     * activated somewhere the top-level page does not reach — e.g. a cursor-paginated
     * **included** relation whose per-parent {@see \haddowg\JsonApi\Pagination\CursorBasedPage}
     * renders inside the compound document while the primary collection is page-based.
     * Each is advertised only when the server has registered it (the same advisory
     * rule the page path applies).
     *
     * @var list<ProfileInterface>
     */
    protected array $activatedProfiles = [];

    /**
     * @param array<string, mixed> $meta
     */
    public function withMeta(array $meta): static
    {
        $self = clone $this;
        $self->meta = $meta;

        return $self;
    }

    /**
     * The document-level `meta` set on this response (via {@see withMeta()} /
     * {@see withAddedMeta()}), for a consumer that needs to read-modify-write it —
     * e.g. a `kernel.view` decorator contributing top-level meta alongside a member a
     * handler already set (`total` under `?withCount`).
     *
     * @return array<string, mixed>
     */
    public function meta(): array
    {
        return $this->meta;
    }

    /**
     * Merges the given members into the document-level `meta`, preserving whatever is
     * already set. {@see withMeta()} replaces the whole `meta`, so a decorator that
     * wants to *add* a member without clobbering an existing one (e.g. a handler-set
     * `total`) uses this instead. The given members win on a key collision.
     *
     * @param array<string, mixed> $meta
     */
    public function withAddedMeta(array $meta): static
    {
        $self = clone $this;
        $self->meta = [...$this->meta, ...$meta];

        return $self;
    }

    public function withLinks(?DocumentLinks $links): static
    {
        $self = clone $this;
        $self->links = $links;

        return $self;
    }

    /**
     * Sets the top-level `describedby` link — a link to a description document for the
     * document, e.g. the served OpenAPI spec (JSON:API 1.1). It is merged into the
     * rendered top-level `links` at render time (like the by-convention `self`), so it
     * coexists with a handler's `self`/pagination/custom links without the caller
     * reconstructing them. An explicit `describedby` already in {@see withLinks()} wins.
     */
    public function withDescribedby(?\haddowg\JsonApi\Schema\Link\Link $describedby): static
    {
        $self = clone $this;
        $self->describedby = $describedby;

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
     * Advertises one or more profiles a **nested** render activated — a profile the
     * document must carry that the response's own top-level page does not surface.
     * The motivating case: a cursor-paginated **included** relation renders a
     * {@see \haddowg\JsonApi\Pagination\CursorBasedPage} per parent inside a compound
     * document whose primary collection is page-based, so the cursor-pagination
     * profile is activated by the include, not the primary page. Each URI is advertised
     * only when the server has registered the profile (the advisory rule the page path
     * already applies); an unregistered one is silently dropped.
     *
     * @param ProfileInterface ...$profiles
     */
    public function withActivatedProfiles(ProfileInterface ...$profiles): static
    {
        $self = clone $this;
        $self->activatedProfiles = \array_values($profiles);

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
        $body = $this->applyExtensions($body);
        $body = $this->applyDescribedby($body);
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
     * request negotiated via the `Accept` `profile` media-type parameter. Profile
     * negotiation is Accept-based (final JSON:API 1.1); the RC-era `?profile=` query
     * parameter is tolerated but no longer negotiates or is advertised as applied — so
     * the advertised profiles now match the (Accept-only) behavioural gates. Unrecognized
     * profiles are ignored, never rejected. Subclasses extend this (e.g.
     * {@see DataResponse} adds a paginator's profile).
     *
     * @return list<ProfileInterface>
     */
    protected function appliedProfiles(ServerInterface $server, JsonApiRequestInterface $request): array
    {
        $profiles = [];
        $seen = [];

        foreach ($request->getRequestedProfiles() as $uri) {
            if (isset($seen[$uri])) {
                continue;
            }

            $profile = $server->profiles()->get($uri);
            if ($profile !== null) {
                $profiles[] = $profile;
                $seen[$uri] = true;
            }
        }

        // Fold in any profile a nested render activated (e.g. a cursor-paginated
        // included relation), each only when the server has registered it.
        foreach ($this->activatedProfiles as $activated) {
            $profiles = $this->mergeAppliedProfile($profiles, $server, $activated);
        }

        return $profiles;
    }

    /**
     * Merges one profile into the applied set, but only when the server
     * **recognises** it — the advisory rule shared by the page-profile path
     * ({@see AppliesPaginationTrait::appliedPageProfiles()}) and the activated-profile
     * fold above. The registered instance is used (not the caller's), so the server's
     * configuration of that profile wins; an already-present or unregistered profile
     * leaves the set unchanged. A recognised, new profile is prepended, matching how a
     * page profile is surfaced ahead of the request-requested set.
     *
     * @param list<ProfileInterface> $profiles
     *
     * @return list<ProfileInterface>
     */
    protected function mergeAppliedProfile(array $profiles, ServerInterface $server, ?ProfileInterface $profile): array
    {
        if ($profile === null) {
            return $profiles;
        }

        $registered = $server->profiles()->get($profile->uri());
        if ($registered === null) {
            return $profiles;
        }

        foreach ($profiles as $existing) {
            if ($existing->uri() === $registered->uri()) {
                return $profiles;
            }
        }

        \array_unshift($profiles, $registered);

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
     * The extension URIs applied to this response: the ones a subclass hard-codes in
     * {@see extensions()} merged (de-duplicated) with any set via {@see withExtensions()}.
     * Echoed in the `Content-Type` `ext` media-type parameter and advertised in the
     * top-level `jsonapi.ext` member.
     *
     * @return list<string>
     */
    private function resolvedExtensions(): array
    {
        return \array_values(\array_unique([...$this->extensions(), ...$this->appliedExtensions]));
    }

    /**
     * Records the applied extension URIs in the top-level `jsonapi.ext` member — the
     * location JSON:API 1.1 defines for advertising applied extensions on the `jsonapi`
     * object (symmetric with `jsonapi.profile`), so a document produced under an applied
     * extension (e.g. the Atomic Operations response) self-describes it. A no-op when no
     * extension is applied.
     *
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    private function applyExtensions(array $body): array
    {
        $extensions = $this->resolvedExtensions();
        if ($extensions === []) {
            return $body;
        }

        $jsonapi = $body['jsonapi'] ?? [];
        $jsonapi = \is_array($jsonapi) ? $jsonapi : [];

        $existing = $jsonapi['ext'] ?? [];
        $existing = \is_array($existing) ? \array_values(\array_filter($existing, '\is_string')) : [];

        $jsonapi['ext'] = \array_values(\array_unique([...$existing, ...$extensions]));
        $body['jsonapi'] = $jsonapi;

        return $body;
    }

    /**
     * Merges the {@see withDescribedby()} `describedby` link into the rendered top-level
     * `links` — symmetric with {@see applyTopLevelSelf()}. Any `describedby` already in
     * the body (an author's own, via {@see withLinks()}) wins and is left untouched;
     * otherwise it is added alongside `self`/pagination/custom links without disturbing
     * them. A bodiless response never reaches here.
     *
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    private function applyDescribedby(array $body): array
    {
        if ($this->describedby === null) {
            return $body;
        }

        /** @var array<string, mixed> $links */
        $links = $body['links'] ?? [];
        if (isset($links['describedby'])) {
            return $body;
        }

        // The caller supplies a complete href (the served spec URL), so no base is
        // prepended — mirroring how a profile link renders its own absolute URI.
        $links['describedby'] = $this->describedby->transform('');
        $body['links'] = $links;

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

        $extensions = $this->resolvedExtensions();
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
