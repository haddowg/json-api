<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Operation;

use haddowg\JsonApi\Response\DataResponse;
use haddowg\JsonApi\Response\ErrorResponse;
use haddowg\JsonApi\Response\IdentifierResponse;
use haddowg\JsonApi\Response\MetaResponse;
use haddowg\JsonApi\Response\NoContentResponse;
use haddowg\JsonApi\Response\RelatedResponse;

/**
 * The consumer extension point for the operations layer: given any
 * {@see JsonApiOperationInterface}, produce one of the public response value objects.
 *
 * Handlers receive a PSR-7-decoupled operation (the optional originating HTTP
 * message is still reachable via {@see OperationContext::httpRequest()}) and
 * return a response value object; the adapter encodes it to PSR-7. Dispatching on
 * the concrete operation type (e.g. `match (true) { $op instanceof
 * CreateResourceOperation => … }`) keeps handler branches type-safe.
 */
interface OperationHandlerInterface
{
    public function handle(
        \haddowg\JsonApi\Operation\JsonApiOperationInterface $operation,
    ): DataResponse|MetaResponse|RelatedResponse|IdentifierResponse|NoContentResponse|ErrorResponse;
}
