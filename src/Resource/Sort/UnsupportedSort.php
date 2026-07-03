<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Sort;

use haddowg\JsonApi\Exception\AbstractJsonApiException;
use haddowg\JsonApi\Schema\Error\Error;

/**
 * Thrown when a {@see Sort} reaches a {@see SortHandler} that does not recognise
 * it. A **server configuration error**, rendered as a 500.
 *
 * The optional `$hint` is a **data-layer-agnostic** slot for the handler that raises
 * this to append remediation guidance specific to its own storage (e.g. a Doctrine
 * provider naming the arm-seam it accepts custom sorts through). Core never fills it.
 */
final class UnsupportedSort extends AbstractJsonApiException
{
    public function __construct(
        public readonly \haddowg\JsonApi\Resource\Sort\SortInterface $sort,
        public readonly ?string $hint = null,
    ) {
        $message = \sprintf('No handler is registered for sort "%s" (%s).', $sort->key(), $sort::class);
        if ($hint !== null && $hint !== '') {
            $message .= ' ' . $hint;
        }

        parent::__construct($message, 500);
    }

    public function getErrors(): array
    {
        return [new Error(
            status: '500',
            code: 'UNSUPPORTED_SORT',
            title: 'Unsupported sort',
            detail: $this->getMessage(),
        )];
    }
}
