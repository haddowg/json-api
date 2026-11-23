<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Exception;

use haddowg\JsonApi\Resource\DateMessage;
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
 *
 * A message that is a {@see DateMessage}, for a value or bound that is not the date its
 * filter takes, renders with its `{example}` context and may be translated under
 * `FILTER_VALUE_INVALID.date_time`, `.date`, `.time` or `.formatted_date_time`.
 */
final class FilterValueInvalid extends AbstractJsonApiException implements DescribedErrorInterface
{
    /**
     * @param string                   $filterKey the `filter[<key>]` key whose value is invalid
     * @param list<string|DateMessage> $messages one human-readable message per constraint violation
     */
    public function __construct(
        public readonly string $filterKey,
        public readonly array $messages,
    ) {
        parent::__construct(
            \sprintf("Filtering value for 'filter[%s]' is invalid: %s", $filterKey, \implode('; ', \array_map('\strval', $messages))),
            self::describe()->status,
        );
    }

    public static function describe(): ErrorDescriptor
    {
        return new ErrorDescriptor(
            code: 'FILTER_VALUE_INVALID',
            status: 400,
            title: 'Filter value is invalid',
            exampleDetail: 'The value supplied for \'filter[rating]\' is invalid.',
            context: ['example' => ErrorContextType::Str],
            source: ErrorSourceShape::Parameter,
            exampleSource: 'filter[rating]',
            description: 'A `filter[...]` value fails the constraints that filter declares, for example text where a number is expected. Correct the value of the parameter named in `source.parameter`; each failed constraint is its own error.',
        );
    }

    public function getErrors(): array
    {
        $source = ErrorSource::fromParameter("filter[$this->filterKey]");

        if ($this->messages === []) {
            return [self::describe()->toError(
                detail: "The value supplied for 'filter[$this->filterKey]' is invalid.",
                source: $source,
            )];
        }

        $errors = [];

        foreach ($this->messages as $message) {
            $errors[] = $message instanceof DateMessage
                ? self::describe()->toError(
                    detail: $message->message(),
                    context: $message->context(),
                    source: $source,
                    detailVariant: $message->detailVariant(self::describe()->code),
                )
                : self::describe()->toError(
                    detail: $message,
                    source: $source,
                );
        }

        return $errors;
    }
}
