<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Exception;

/**
 * The caller is known but is not allowed to do what the request asks.
 *
 * Core never throws it: authorization belongs to the framework, so an adapter throws or
 * renders it when its security layer refuses an authenticated caller (Symfony's
 * `AccessDeniedException`, Laravel's `AuthorizationException`). The other `403` codes
 * core catalogues refuse a request for what it says, such as a prohibited relationship
 * mutation; this one refuses it for who sent it.
 *
 * The `detail` is omitted unless one is given. A framework's own message can name the
 * credential or the rule that refused the request, so an adapter passes it through only
 * when it is safe to, typically in debug mode.
 */
final class AccessDenied extends AbstractJsonApiException implements DescribedErrorInterface
{
    /**
     * @param string          $detail   the error object's `detail`, or `''` to omit it
     * @param \Throwable|null $previous the framework exception this one translates
     */
    public function __construct(public readonly string $detail = '', ?\Throwable $previous = null)
    {
        parent::__construct($detail !== '' ? $detail : 'Access is denied!', self::describe()->status, $previous);
    }

    public static function describe(): ErrorDescriptor
    {
        return new ErrorDescriptor(
            code: 'ACCESS_DENIED',
            status: 403,
            title: 'Forbidden',
            description: 'The caller is authenticated but not permitted to do what the request asks. Retrying with the same credentials will fail the same way; act as a user who holds the permission, or don\'t make the request.',
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
