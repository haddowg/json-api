<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Double;

use haddowg\JsonApi\Operation\JsonApiOperation;
use haddowg\JsonApi\Operation\OperationHandler;
use haddowg\JsonApi\Response\DataResponse;
use haddowg\JsonApi\Response\ErrorResponse;
use haddowg\JsonApi\Response\IdentifierResponse;
use haddowg\JsonApi\Response\MetaResponse;
use haddowg\JsonApi\Response\RelatedResponse;

/**
 * {@see OperationHandler} test double that records the operation it last received
 * and returns a pre-configured response value object.
 */
final class RecordingOperationHandler implements OperationHandler
{
    public ?JsonApiOperation $received = null;

    public function __construct(
        private readonly DataResponse|MetaResponse|RelatedResponse|IdentifierResponse|ErrorResponse $response,
    ) {}

    public function handle(
        JsonApiOperation $operation,
    ): DataResponse|MetaResponse|RelatedResponse|IdentifierResponse|ErrorResponse {
        $this->received = $operation;

        return $this->response;
    }
}
