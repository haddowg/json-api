<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Filter;

use haddowg\JsonApi\Exception\AbstractJsonApiException;
use haddowg\JsonApi\Schema\Error\Error;

/**
 * Thrown when a {@see Filter} reaches a {@see FilterHandler} that does not
 * recognise it (e.g. a custom filter with no registered handler). This is a
 * **server configuration error**, not a client error, so it renders as a 500.
 */
final class UnsupportedFilter extends AbstractJsonApiException
{
    public function __construct(public readonly Filter $filter)
    {
        parent::__construct(
            \sprintf('No handler is registered for filter "%s" (%s).', $filter->key(), $filter::class),
            500,
        );
    }

    public function getErrors(): array
    {
        return [new Error(
            status: '500',
            code: 'UNSUPPORTED_FILTER',
            title: 'Unsupported filter',
            detail: $this->getMessage(),
        )];
    }
}
