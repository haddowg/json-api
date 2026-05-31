<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Transformer;

use haddowg\JsonApi\Schema\Document\ErrorDocumentInterface;

/**
 * Document-transformation state for error documents.
 *
 * @internal
 *
 * @extends AbstractDocumentTransformation<ErrorDocumentInterface>
 *
 */
class ErrorDocumentTransformation extends AbstractDocumentTransformation {}
