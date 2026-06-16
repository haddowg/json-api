<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Exception;

use haddowg\JsonApi\Schema\Error\Error;
use haddowg\JsonApi\Schema\Error\ErrorSource;

/**
 * A client-supplied `filter[<key>]` value violates the value constraints declared
 * on the filter (see {@see \haddowg\JsonApi\Resource\Filter\HasValueConstraints}).
 *
 * This is a **`400`** — a bad query *parameter*, located by `source.parameter` on
 * `filter[<key>]` — deliberately **not** a `422` (which is reserved for document
 * *semantic* errors located by `source.pointer`). Validating the value here, before
 * it reaches the data layer, turns the provider's unhelpful default for a mistyped
 * value (a silent non-match in memory and on a loosely-typed database, or a Doctrine
 * PDO error — a `500` — on a strict driver) into a clean client error.
 *
 * Core declares the exception so any consumer (an in-memory handler, a core path)
 * can throw it; a framework adapter populates `$messages` from its translated
 * constraint violations. One {@see Error} is rendered per violation message.
 */
final class FilterValueInvalid extends AbstractJsonApiException
{
    /**
     * @param string       $filterKey the `filter[<key>]` key whose value is invalid
     * @param list<string> $messages  one human-readable message per constraint violation
     */
    public function __construct(
        public readonly string $filterKey,
        public readonly array $messages,
    ) {
        parent::__construct(
            \sprintf("Filtering value for 'filter[%s]' is invalid: %s", $filterKey, \implode('; ', $messages)),
            400,
        );
    }

    public function getErrors(): array
    {
        $source = ErrorSource::fromParameter("filter[$this->filterKey]");

        if ($this->messages === []) {
            return [new Error(
                status: '400',
                code: 'FILTER_VALUE_INVALID',
                title: 'Filter value is invalid',
                detail: "The value supplied for 'filter[$this->filterKey]' is invalid.",
                source: $source,
            )];
        }

        $errors = [];

        foreach ($this->messages as $message) {
            $errors[] = new Error(
                status: '400',
                code: 'FILTER_VALUE_INVALID',
                title: 'Filter value is invalid',
                detail: $message,
                source: $source,
            );
        }

        return $errors;
    }
}
