<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Exception;

use haddowg\JsonApi\Schema\Error\ErrorSource;

/**
 * The request's access token does not grant every scope the request needs.
 *
 * `meta.requiredScopes` lists every scope the request needs and `meta.missingScopes` the
 * ones the token lacks. When an `?include` path is what needs them, `meta.include` names
 * the path up to the hop that failed and `source.parameter` is `include`. A batch entry
 * that fails is located by its `source.pointer`. The response carries the RFC 6750
 * `WWW-Authenticate: Bearer error="insufficient_scope"` challenge naming the required
 * scopes.
 */
final class InsufficientScope extends AbstractJsonApiException implements DescribedErrorInterface, CarriesResponseHeadersInterface
{
    /**
     * @param list<string> $requiredScopes every scope the request needs
     * @param list<string> $missingScopes  the required scopes the token does not grant
     * @param string|null  $include        the include path whose hop needs the scopes
     * @param string|null  $pointer        the JSON Pointer to the batch entry that needs them
     */
    public function __construct(
        public readonly array $requiredScopes,
        public readonly array $missingScopes,
        public readonly ?string $include = null,
        public readonly ?string $pointer = null,
    ) {
        parent::__construct(
            'The access token does not grant the scopes ' . \implode(', ', $missingScopes) . '.',
            self::describe()->status,
        );
    }

    public static function describe(): ErrorDescriptor
    {
        return new ErrorDescriptor(
            code: 'INSUFFICIENT_SCOPE',
            status: 403,
            title: 'Insufficient scope',
            feature: ErrorFeature::OperationScopes,
            exampleDetail: 'The access token does not grant every scope this request needs.',
            description: 'The access token is valid but does not grant every scope the request needs. `meta.missingScopes` names the ones it lacks; request a token that grants them, or drop the `include` path named in `meta.include`.',
        );
    }

    public function getErrors(): array
    {
        $meta = ['requiredScopes' => $this->requiredScopes, 'missingScopes' => $this->missingScopes];
        if ($this->include !== null) {
            $meta['include'] = $this->include;
        }

        $source = match (true) {
            $this->include !== null => ErrorSource::fromParameter('include'),
            $this->pointer !== null => ErrorSource::fromPointer($this->pointer),
            default => null,
        };

        return [
            self::describe()->toError(
                detail: $this->include !== null
                    ? 'The access token does not grant every scope the include path \'' . $this->include . '\' needs.'
                    : 'The access token does not grant every scope this request needs.',
                source: $source,
                meta: $meta,
            ),
        ];
    }

    public function responseHeaders(): array
    {
        $challenge = 'Bearer error="insufficient_scope"';
        if ($this->requiredScopes !== []) {
            $challenge .= ', scope="' . \implode(' ', $this->requiredScopes) . '"';
        }

        return ['WWW-Authenticate' => $challenge];
    }
}
