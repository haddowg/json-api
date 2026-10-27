<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Response;

use haddowg\JsonApi\Negotiation\AcceptNegotiator;
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\ProvidesOutputTimezoneInterface;
use haddowg\JsonApi\Response\Internal\RenderedDocument;
use haddowg\JsonApi\Schema\Document\MetaDocument;
use haddowg\JsonApi\Server\ServerInterface;
use haddowg\JsonApi\Transformer\DocumentTransformer;
use haddowg\JsonApi\Transformer\ResourceDocumentTransformation;

/**
 * A `303 See Other` response whose `Location` header points elsewhere.
 *
 * From a fetch it is the completion half of the async lifecycle (JSON:API 1.1
 * §"Asynchronous Processing"): once the job an {@see AcceptedResponse} advertised has
 * finished, a `GET` on that job resource redirects to the resource the operation
 * produced. It is always the bodiless `303` there; the document-level members do not
 * apply, but {@see withHeader()} does.
 *
 * From a custom action it is the URL the action produced, such as a short-lived
 * download link, and {@see \haddowg\JsonApi\Server\Server::dispatch()} marks it so.
 * A request whose `Accept` prefers JSON:API ({@see AcceptNegotiator::prefersJsonApi()})
 * then gets a `200` document instead, because a browser's `fetch()` cannot read a
 * `303`'s `Location`:
 *
 * ```json
 * {
 *   "links": {
 *     "self": "https://api.example.com/files/1/-actions/download",
 *     "location": {"href": "https://storage.example.com/1.csv?signature=…", "type": "text/csv", "meta": {"expiresAt": "2026-10-27T12:00:00+00:00"}}
 *   },
 *   "meta": {"expiresAt": "2026-10-27T12:00:00+00:00"},
 *   "jsonapi": {"version": "1.1"}
 * }
 * ```
 *
 * `location.type` appears only when known and `location.meta` only with an expiry; the
 * top-level `meta.expiresAt` is always there, `null` for a URL that does not expire.
 * Any other request gets the `303`. Both shapes carry `Cache-Control: no-store` and
 * `Vary: Accept` unless the handler set its own.
 */
final class SeeOtherResponse extends AbstractResponse
{
    private ?\DateTimeInterface $expiresAt = null;

    private ?string $type = null;

    private bool $fromAction = false;

    private function __construct(private readonly string $location) {}

    /**
     * A `303` whose `Location` header is the given URL. `$expiresAt` is when that URL
     * stops working, rendered only by a custom action's location document.
     */
    public static function to(string $location, ?\DateTimeInterface $expiresAt = null): self
    {
        $self = new self($location);
        $self->headers['Location'] = $location;
        $self->expiresAt = $expiresAt;

        return $self;
    }

    public function location(): string
    {
        return $this->location;
    }

    public function expiresAt(): ?\DateTimeInterface
    {
        return $this->expiresAt;
    }

    /**
     * The media type the URL serves, such as `text/csv`, rendered as the location link's
     * `type`. It wins over the media type the action's {@see \haddowg\JsonApi\OpenApi\Metadata\SeeOther}
     * declares.
     */
    public function withType(string $mediaType): self
    {
        $self = clone $this;
        $self->type = $mediaType;

        return $self;
    }

    public function type(): ?string
    {
        return $this->type;
    }

    /**
     * Marks this response as a custom action's result, so it negotiates between the
     * location document and the `303` and is never cached. `$mediaType` is the declared
     * one, used when the handler set none.
     *
     * @internal called by {@see \haddowg\JsonApi\Server\Server::dispatch()}
     */
    public function fromAction(?string $mediaType = null): self
    {
        $self = clone $this;
        $self->fromAction = true;
        $self->type ??= $mediaType;
        foreach (['Cache-Control' => 'no-store', 'Vary' => 'Accept'] as $name => $value) {
            if (!$self->hasHeader($name)) {
                $self->headers[$name] = $value;
            }
        }

        return $self;
    }

    public function isFromAction(): bool
    {
        return $this->fromAction;
    }

    protected function render(ServerInterface $server, JsonApiRequestInterface $request): RenderedDocument
    {
        if (!$this->fromAction || !AcceptNegotiator::prefersJsonApi($request->getHeaderLine('accept'))) {
            return new RenderedDocument([], 303, hasBody: false);
        }

        $expiresAt = $this->expiresAt === null ? null : self::formatExpiry($this->expiresAt, $server);
        $document = new MetaDocument($this->resolveJsonApi($server), ['expiresAt' => $expiresAt, ...$this->meta], $this->links);

        $transformation = new ResourceDocumentTransformation(
            $document,
            null,
            $request,
            '',
            '',
            [],
            \haddowg\JsonApi\Server\RequestBaseUri::resolve($server->baseUri(), $request->getUri()),
            $server->maxIncludeDepth(),
            linkRendering: \haddowg\JsonApi\Schema\Link\Internal\LinkRendering::forServer($server),
            scopes: \haddowg\JsonApi\Scope\ScopeEnforcer::forServer($server),
        );

        $result = (new DocumentTransformer())->transformMetaDocument($transformation)->result;
        $result = $this->applyTopLevelSelf($result, $server, $request);

        $location = ['href' => $this->location];
        if ($this->type !== null) {
            $location['type'] = $this->type;
        }
        if ($expiresAt !== null) {
            $location['meta'] = ['expiresAt' => $expiresAt];
        }
        /** @var array<string, mixed> $links */
        $links = $result['links'] ?? [];
        $links['location'] = $location;
        $result['links'] = $links;

        return new RenderedDocument($result, 200);
    }

    /**
     * The location document carries the URL as its `location` link, so it drops the
     * `Location` header the `303` needs.
     *
     * @return array<string, string>
     */
    protected function headersFor(int $status): array
    {
        if ($status === 303) {
            return $this->headers;
        }

        return \array_filter($this->headers, static fn(string $name): bool => \strcasecmp($name, 'Location') !== 0, \ARRAY_FILTER_USE_KEY);
    }

    /**
     * The expiry as RFC 3339, in the server's output timezone when it has one, the way
     * a `DateTime` attribute is written.
     */
    private static function formatExpiry(\DateTimeInterface $expiresAt, ServerInterface $server): string
    {
        $timezone = $server instanceof ProvidesOutputTimezoneInterface ? $server->outputTimezone() : null;
        $value = \DateTimeImmutable::createFromInterface($expiresAt);
        if ($timezone !== null) {
            $value = $value->setTimezone(new \DateTimeZone($timezone));
        }

        return $value->format(\DateTimeInterface::ATOM);
    }

    private function hasHeader(string $name): bool
    {
        foreach (\array_keys($this->headers) as $header) {
            if (\strcasecmp($header, $name) === 0) {
                return true;
            }
        }

        return false;
    }
}
