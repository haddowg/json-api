<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Exception;

use haddowg\JsonApi\Schema\Error\Error;
use haddowg\JsonApi\Schema\Error\ErrorSource;

/**
 * A `page[kind]` discriminator named a pagination strategy the resource's
 * {@see \haddowg\JsonApi\Pagination\MultiPaginator} menu does not offer. Surfaced
 * as a `400` with `source.parameter` naming `page[kind]` and a `detail` listing the
 * kinds the menu does accept, so a client can correct the selection. This is the
 * one hard error in an otherwise clamp-don't-`400` pagination surface: an explicit,
 * unrecognised strategy request is a client mistake worth signalling (an ambiguous
 * or garbage `page[…]` value without a `kind` still falls back to the default).
 */
final class PaginationKindUnknown extends AbstractJsonApiException implements DescribedErrorInterface
{
    /**
     * @param string       $kind       the requested `page[kind]` value that no child declares
     * @param list<string> $validKinds the kinds the menu offers, in declaration order
     */
    public function __construct(
        public readonly string $kind,
        public readonly array $validKinds,
    ) {
        parent::__construct("Pagination kind '$kind' is not supported!", self::describe()->status);
    }

    public static function describe(): ErrorDescriptor
    {
        return new ErrorDescriptor(
            code: 'PAGINATION_KIND_UNKNOWN',
            status: 400,
            title: 'Pagination kind is not supported',
            context: ['kind' => ErrorContextType::Str, 'kinds' => ErrorContextType::Str],
            source: ErrorSourceShape::Parameter,
            feature: ErrorFeature::PaginationMenu,
        );
    }

    public function getErrors(): array
    {
        $valid = \implode(', ', $this->validKinds);

        return [
            self::describe()->toError(
                detail: "The pagination strategy 'page[kind]=$this->kind' is not supported; use one of: $valid.",
                context: ['kind' => $this->kind, 'kinds' => $valid],
                source: ErrorSource::fromParameter('page[kind]'),
            ),
        ];
    }
}
