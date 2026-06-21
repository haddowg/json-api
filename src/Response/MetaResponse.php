<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Response;

use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Response\Internal\RenderedDocument;
use haddowg\JsonApi\Schema\Document\MetaDocument;
use haddowg\JsonApi\Server\ServerInterface;
use haddowg\JsonApi\Transformer\DocumentTransformer;
use haddowg\JsonApi\Transformer\ResourceDocumentTransformation;

/**
 * A meta-only response: a top-level document carrying `meta` (and optionally
 * `jsonapi`/`links`) but no primary `data`.
 */
final class MetaResponse extends AbstractResponse
{
    private function __construct() {}

    /**
     * Seeds the document `meta` member.
     *
     * @param array<string, mixed> $meta
     */
    public static function fromMeta(array $meta): self
    {
        $self = new self();
        $self->meta = $meta;

        return $self;
    }

    protected function render(ServerInterface $server, JsonApiRequestInterface $request): RenderedDocument
    {
        $document = new MetaDocument($this->resolveJsonApi($server), $this->meta, $this->links);

        $transformation = new ResourceDocumentTransformation(
            $document,
            null,
            $request,
            '',
            '',
            [],
            \haddowg\JsonApi\Server\RequestBaseUri::resolve($server->baseUri(), $request->getUri()),
            $server->maxIncludeDepth(),
        );

        $result = (new DocumentTransformer())->transformMetaDocument($transformation)->result;

        $result = $this->applyTopLevelSelf($result, $server, $request);

        return new RenderedDocument($result, 200);
    }
}
