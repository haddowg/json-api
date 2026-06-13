<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Examples\MusicCatalog\Exception;

use haddowg\JsonApi\Exception\AbstractJsonApiException;
use haddowg\JsonApi\Schema\Error\Error;

/**
 * A custom, application-defined JSON:API error: HTTP 402 Payment Required.
 *
 * The escape-hatch witness for the exception model — an application extends
 * {@see AbstractJsonApiException}, supplies a message + status through the parent
 * constructor and implements {@see getErrors()} to expose its JSON:API error data,
 * and the thrown instance flows through the same
 * {@see \haddowg\JsonApi\Middleware\ErrorHandlerMiddleware} as the library's own
 * typed exceptions — rendered as a spec-compliant `402` error document with no
 * special-casing.
 *
 * The handler throws it when a write demands a capability the caller lacks (e.g.
 * creating a playlist without the `premium` flag), so a real referent exists for
 * the docs' "define your own exception" claim.
 */
final class PaymentRequired extends AbstractJsonApiException
{
    public function __construct(string $detail = 'This operation requires an active premium subscription.')
    {
        parent::__construct($detail, 402);
    }

    public function getErrors(): array
    {
        return [
            new Error(
                status: '402',
                code: 'PAYMENT_REQUIRED',
                title: 'Payment required',
                detail: $this->getMessage(),
            ),
        ];
    }
}
