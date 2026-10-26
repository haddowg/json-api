<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Exception;

/**
 * The request needs credentials it did not carry, or carried ones the server rejected.
 *
 * Core never throws it: authentication belongs to the framework, so an adapter throws or
 * renders it when its security layer reports an unauthenticated caller (Symfony's
 * `AuthenticationException`, or an access denial for an anonymous token; Laravel's
 * `AuthenticationException`).
 *
 * The `detail` is omitted unless one is given. A framework's own message can name the
 * credential or the rule that refused the request, so an adapter passes it through only
 * when it is safe to, typically in debug mode.
 */
final class AuthenticationRequired extends AbstractJsonApiException implements DescribedErrorInterface
{
    /**
     * @param string          $detail   the error object's `detail`, or `''` to omit it
     * @param \Throwable|null $previous the framework exception this one translates
     */
    public function __construct(public readonly string $detail = '', ?\Throwable $previous = null)
    {
        parent::__construct($detail !== '' ? $detail : 'Authentication is required!', self::describe()->status, $previous);
    }

    public static function describe(): ErrorDescriptor
    {
        return new ErrorDescriptor(
            code: 'AUTHENTICATION_REQUIRED',
            status: 401,
            title: 'Unauthorized',
            exampleDetail: 'Authentication is required!',
            description: 'The request carried no credentials, or credentials the server rejected, such as an expired token. Authenticate, then send the request again with valid credentials.',
        );
    }

    public function getErrors(): array
    {
        return [
            self::describe()->toError(
                detail: $this->detail,
            ),
        ];
    }
}
