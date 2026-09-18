<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Exception;

use haddowg\JsonApi\Schema\Error\ErrorSource;

/**
 * Raised (400) when a requested `?include` path is deeper than the effective
 * maximum include depth (the per-resource override, else the server default).
 * Depth is the number of relationship hops from the primary resource:
 * `?include=a` is depth 1, `?include=a.b.c` is depth 3.
 */
final class InclusionDepthExceeded extends AbstractJsonApiException implements DescribedErrorInterface
{
    /**
     * @param list<string> $paths
     */
    public function __construct(public readonly array $paths, public readonly int $maxDepth)
    {
        parent::__construct(
            "Included paths '" . \implode(', ', $paths) . "' exceed the maximum include depth of " . $maxDepth . '!',
            self::describe()->status,
        );
    }

    public static function describe(): ErrorDescriptor
    {
        return new ErrorDescriptor(
            code: 'INCLUSION_DEPTH_EXCEEDED',
            status: 400,
            title: 'Inclusion depth exceeded',
            context: ['paths' => ErrorContextType::Str, 'maxDepth' => ErrorContextType::Integer],
            source: ErrorSourceShape::Parameter,
        );
    }

    public function getErrors(): array
    {
        return [
            self::describe()->toError(
                detail: "Included paths '" . \implode(', ', $this->paths) . "' exceed the maximum include depth of " . $this->maxDepth . ' permitted by the endpoint!',
                context: ['paths' => \implode(', ', $this->paths), 'maxDepth' => $this->maxDepth],
                source: ErrorSource::fromParameter('include'),
            ),
        ];
    }
}
