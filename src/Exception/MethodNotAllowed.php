<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Exception;

/**
 * The request's method is not one the addressed path accepts, such as a `PATCH` to a
 * read-only type's resource or a `POST` to a collection that takes no creates.
 *
 * Core never throws it: core routes nothing, so an adapter throws or renders it when its
 * router finds the path but no route for the method. The response carries the `Allow`
 * header listing the methods the path does accept, which HTTP requires of a `405`.
 */
final class MethodNotAllowed extends AbstractJsonApiException implements DescribedErrorInterface, CarriesResponseHeadersInterface
{
    /**
     * The methods the path accepts, upper-cased and without duplicates.
     *
     * @var list<string>
     */
    public readonly array $allowedMethods;

    /**
     * @param string          $method         the method the request used
     * @param list<string>    $allowedMethods the methods the path accepts
     * @param \Throwable|null $previous       the framework exception this one translates
     */
    public function __construct(
        public readonly string $method,
        array $allowedMethods,
        ?\Throwable $previous = null,
    ) {
        $this->allowedMethods = \array_values(\array_unique(\array_map(\strtoupper(...), $allowedMethods)));

        parent::__construct(self::detailFor(\strtoupper($method), $this->allowedMethods), self::describe()->status, $previous);
    }

    public static function describe(): ErrorDescriptor
    {
        return new ErrorDescriptor(
            code: 'METHOD_NOT_ALLOWED',
            status: 405,
            title: 'Method Not Allowed',
            feature: ErrorFeature::MethodNotAllowed,
            exampleDetail: 'The method PATCH is not allowed on this path, which accepts GET.',
            description: 'The path exists, but not for the method the request used: the type or relationship may be read-only, or the endpoint may take no such operation. The `Allow` response header lists the methods the path accepts.',
        );
    }

    public function getErrors(): array
    {
        return [
            self::describe()->toError(
                detail: $this->getMessage(),
            ),
        ];
    }

    public function responseHeaders(): array
    {
        return ['Allow' => \implode(', ', $this->allowedMethods)];
    }

    /**
     * @param list<string> $allowedMethods
     */
    private static function detailFor(string $method, array $allowedMethods): string
    {
        if ($allowedMethods === []) {
            return 'The method ' . $method . ' is not allowed on this path.';
        }

        return 'The method ' . $method . ' is not allowed on this path, which accepts ' . \implode(', ', $allowedMethods) . '.';
    }
}
