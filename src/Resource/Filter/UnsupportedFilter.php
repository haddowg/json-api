<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Filter;

use haddowg\JsonApi\Exception\AbstractJsonApiException;
use haddowg\JsonApi\Schema\Error\Error;

/**
 * Thrown when a {@see Filter} reaches a {@see FilterHandler} that does not
 * recognise it (e.g. a custom filter with no registered handler). This is a
 * **server configuration error**, not a client error, so it renders as a 500.
 *
 * The optional `$hint` is a **data-layer-agnostic** slot for the handler that raises
 * this to append remediation guidance specific to its own storage — e.g. a Doctrine
 * provider can name the arm-seam it accepts custom filters through. Core never fills it
 * (it holds no knowledge of any concrete data layer); the raising handler does.
 */
final class UnsupportedFilter extends AbstractJsonApiException
{
    public function __construct(
        public readonly \haddowg\JsonApi\Resource\Filter\FilterInterface $filter,
        public readonly ?string $hint = null,
    ) {
        $message = \sprintf('No handler is registered for filter "%s" (%s).', $filter->key(), $filter::class);
        if ($hint !== null && $hint !== '') {
            $message .= ' ' . $hint;
        }

        parent::__construct($message, 500);
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
