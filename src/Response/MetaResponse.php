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
        );

        $result = (new DocumentTransformer())->transformMetaDocument($transformation)->result;

        return new RenderedDocument($result, 200);
    }
}
