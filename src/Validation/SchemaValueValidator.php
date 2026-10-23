<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Validation;

use haddowg\JsonApi\OpenApi\Schema;
use haddowg\JsonApi\Schema\Error\Error;
use haddowg\JsonApi\Schema\Error\ErrorSource;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Helper;
use Opis\JsonSchema\Validator;

/**
 * Validates a single value against a {@see Schema} (a JSON Schema 2020-12 node) with
 * opis, returning one `422` {@see Error} per leaf violation — the framework-agnostic
 * validator for constraint metadata whose meaning is a *raw JSON Schema* rather than a
 * translatable rule.
 *
 * The motivating case is the {@see \haddowg\JsonApi\Resource\Constraint\Shape}
 * composite-schema constraint (`oneOf`/`anyOf`/`allOf` of raw member schemas): a
 * framework validator (Symfony `AtLeastOneOf`, Laravel rules) cannot translate a raw
 * `Schema`, but opis validates it completely. Because the execution is opis — not the
 * host validator — the logic belongs in core, so the Symfony bundle and the Laravel
 * package share one implementation instead of each re-deriving it.
 *
 * opis is a `suggest` dependency: constructing this class requires it (a caller wires
 * it only when present, exactly as {@see DocumentValidator} is). Each opis instance
 * pointer is appended to the caller-supplied `$pointerPrefix` (e.g.
 * `/data/attributes/<field>`), so a nested-member violation reads
 * `/data/attributes/<field>/<child>` and a whole-value one reads
 * `/data/attributes/<field>` — consistent with every other attribute error.
 */
final class SchemaValueValidator
{
    private readonly Validator $validator;

    public function __construct()
    {
        $validator = new Validator();
        // Surface several violations at once rather than only the first (matching
        // DocumentValidator); setMaxErrors exists across all supported opis 2.x.
        $validator->setMaxErrors(20);
        $this->validator = $validator;
    }

    /**
     * Validates `$value` against `$schema`, returning one `422` {@see Error} per opis
     * leaf error (empty when it validates). Pointers are `$pointerPrefix` plus the opis
     * instance pointer, so a whole-value violation reads exactly `$pointerPrefix` (with an
     * empty prefix, the RFC 6901 whole-document pointer `''`).
     *
     * @return list<Error>
     */
    public function validate(Schema $schema, mixed $value, string $pointerPrefix): array
    {
        // toJson(), not toArray(): flattening to arrays turns an empty schema node
        // into `[]`, which opis does not accept as a schema.
        $result = $this->validator->validate(Helper::toJSON($value), $schema->toJson());

        $error = $result->error();
        if ($error === null) {
            return [];
        }

        $errors = [];
        foreach ((new ErrorFormatter())->formatKeyed($error) as $pointer => $messages) {
            if (!\is_array($messages)) {
                continue;
            }
            // opis keys a root-level error `/`; appending that would leave a trailing slash.
            $pointer = (string) $pointer === '/' ? '' : (string) $pointer;
            foreach ($messages as $message) {
                if (!\is_string($message)) {
                    continue;
                }
                $errors[] = new Error(
                    status: '422',
                    code: 'VALIDATION_FAILED',
                    title: 'Unprocessable Entity',
                    detail: $message,
                    source: ErrorSource::fromPointer($pointerPrefix . $pointer),
                );
            }
        }

        return $errors;
    }
}
