<?php

declare(strict_types=1);

namespace haddowg\JsonApi\OpenApi;

/**
 * Projects the standard error responses every operation advertises into the shared
 * `components.responses` set, and points each one at the narrowest error document the
 * catalogue supports.
 *
 * An operation's error responses are identical across the whole document — the same
 * description, the same media type, the same schema — so the Response Object is written
 * once per status and every operation `$ref`s it. A status the OAS reason phrase names
 * (`400` → `BadRequest`) names the component, because that is the only thing a shared
 * error response is: the HTTP status, described.
 *
 * A component is emitted only for a status the projected paths actually reference
 * ({@see referencedStatuses()}), the same way every other component is emitted only when
 * something points at it.
 *
 * **Narrowing.** Each catalogued error code pins its `status` to a `const`, so the codes
 * reachable at a given status are derivable from the catalogue alone. Where the catalogue
 * claims a status the response carries `ErrorDocument<status>`, whose `errors.items.anyOf`
 * offers only those codes; where it claims none (an application's own status, or one
 * whose codes the server's feature set gated out) the response falls back to the generic
 * `ErrorDocument`. The narrowed
 * variants keep the open generic `Error` branch first, exactly as the generic document
 * does: narrowing sharpens the vocabulary a status publishes, it does not close it
 * ([ADR 0053](../../docs/adr/0053-the-projected-error-code-catalogue-is-open.md)).
 *
 * @internal
 */
final class ErrorResponseProjector
{
    /**
     * A `$ref` to the shared response component for `$status`.
     *
     * `$description` is carried as an OAS 3.1 Reference Object override, and only when it
     * differs from the component's own — so an endpoint that phrases a status its own way
     * (the atomic batch: "an operation targets a resource that does not exist") keeps its
     * wording without a component of its own, and one that agrees adds no noise.
     */
    public static function reference(string $status, ?string $description = null): Reference
    {
        $reference = Reference::to('responses', self::name($status));

        return $description === null || $description === self::description($status)
            ? $reference
            : new Reference($reference->ref, description: $description);
    }

    /**
     * The shared response components for `$statuses`, keyed by component name.
     *
     * `$byStatus` is the catalogue grouped by status ({@see ErrorCatalogProjector::componentsByStatus()});
     * a status it claims gets the narrowed document, a status it does not gets the generic one.
     *
     * @param list<string>             $statuses
     * @param array<int, list<string>> $byStatus
     *
     * @return array<string, Response>
     */
    public static function components(array $statuses, array $byStatus): array
    {
        $components = [];
        foreach ($statuses as $status) {
            $components[self::name($status)] = Response::ofSchema(
                self::description($status),
                Schema::ref(ComponentNaming::schemaRef(self::documentComponent($status, $byStatus))),
            );
        }

        return $components;
    }

    /**
     * The error statuses whose shared response the projected paths reference, ascending.
     * An operation pointed at a response narrowed to its scoped codes does not count. Read off the built
     * document rather than re-derived from metadata, so the component set cannot drift
     * from the per-operation status lists that produced it.
     *
     * @return list<string>
     */
    public static function referencedStatuses(Paths $paths): array
    {
        $seen = [];
        foreach ($paths->items as $item) {
            foreach ($item->operations as $operation) {
                foreach ($operation->responses->responses as $status => $response) {
                    if ($response instanceof Reference && $response->ref === Reference::to('responses', self::name((string) $status))->ref) {
                        $seen[$status] = true;
                    }
                }
            }
        }
        \ksort($seen);

        return \array_map(\strval(...), \array_keys($seen));
    }

    /**
     * The error-document schema component a `$status` response carries: the narrowed
     * `ErrorDocument<status>` when the catalogue claims that status, else the generic
     * `ErrorDocument`.
     *
     * @param array<int, list<string>> $byStatus
     */
    public static function documentComponent(string $status, array $byStatus): string
    {
        return isset($byStatus[(int) $status]) ? 'ErrorDocument' . $status : 'ErrorDocument';
    }

    /**
     * The response component name for a status — its HTTP reason phrase in PascalCase.
     * An unlisted status degrades to `Status<code>` rather than colliding, so the naming
     * and the descriptions below stay in step even if one gains a status the other has not.
     */
    public static function name(string $status): string
    {
        return self::NAMES[$status] ?? 'Status' . $status;
    }

    /**
     * The component's own description — the wording an operation inherits unless it
     * overrides it on the `$ref`.
     */
    public static function description(string $status): string
    {
        return self::DESCRIPTIONS[$status] ?? 'Error';
    }

    private const NAMES = [
        '400' => 'BadRequest',
        '401' => 'Unauthorized',
        '402' => 'PaymentRequired',
        '403' => 'Forbidden',
        '404' => 'NotFound',
        '405' => 'MethodNotAllowed',
        '406' => 'NotAcceptable',
        '408' => 'RequestTimeout',
        '409' => 'Conflict',
        '410' => 'Gone',
        '412' => 'PreconditionFailed',
        '413' => 'ContentTooLarge',
        '415' => 'UnsupportedMediaType',
        '422' => 'UnprocessableEntity',
        '423' => 'Locked',
        '428' => 'PreconditionRequired',
        '429' => 'TooManyRequests',
        '500' => 'InternalServerError',
        '502' => 'BadGateway',
        '503' => 'ServiceUnavailable',
        '504' => 'GatewayTimeout',
    ];

    /**
     * Human-readable descriptions for the enumerated error statuses. Required by
     * the OAS meta-schema (a Response Object's `description` is mandatory).
     */
    private const DESCRIPTIONS = [
        '400' => 'Bad Request — the request was malformed (e.g. an invalid query parameter).',
        '401' => 'Unauthorized — authentication is required and was missing or invalid.',
        '402' => 'Payment Required — the request needs a payment or plan the account lacks.',
        '403' => 'Forbidden — the request is not authorised.',
        '404' => 'Not Found — the resource does not exist.',
        '405' => 'Method Not Allowed — the endpoint does not accept this HTTP method.',
        '406' => 'Not Acceptable — the `Accept` header could not be satisfied.',
        '408' => 'Request Timeout — the server gave up waiting for the request.',
        '409' => 'Conflict — the request conflicts with the resource state (e.g. a type or id mismatch).',
        '410' => 'Gone — the resource existed and has been removed for good.',
        '412' => 'Precondition Failed — a conditional request header did not match.',
        '413' => 'Content Too Large — the request body is larger than the server accepts.',
        '415' => 'Unsupported Media Type — the `Content-Type` header is not `application/vnd.api+json`.',
        '422' => 'Unprocessable Entity — the document failed validation.',
        '423' => 'Locked — the resource is locked against this change.',
        '428' => 'Precondition Required — the request must be conditional.',
        '429' => 'Too Many Requests — the caller has sent too many requests; retry later.',
        '500' => 'Internal Server Error.',
        '502' => 'Bad Gateway — an upstream server returned an invalid response.',
        '503' => 'Service Unavailable — the server cannot handle the request right now; retry later.',
        '504' => 'Gateway Timeout — an upstream server did not answer in time.',
    ];
}
