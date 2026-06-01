<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Schema\Document;

/**
 * Base for resource documents. Stateless: the per-pass request, primary object
 * and additional meta are carried on the {@see \haddowg\JsonApi\Transformer\ResourceDocumentTransformation}
 * and passed to {@see getData()} / {@see getRelationshipData()} directly.
 *
 * @internal
 */
abstract class AbstractResourceDocument implements ResourceDocumentInterface {}
