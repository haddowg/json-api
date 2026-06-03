<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Schema\Error;

/**
 * Maps an arbitrary throwable to the single generic-500 JSON:API {@see Error}
 * object — the spec-faithful home for an unexpected failure.
 *
 * This is the public, stateless seam behind the 500 mapping that
 * {@see \haddowg\JsonApi\Middleware\ErrorHandlerMiddleware} (and any framework
 * integration) applies to a throwable that escapes the chain. It owns *only* the
 * throwable → {@see Error} translation: it never logs, never derives an HTTP
 * status, and never builds an {@see \haddowg\JsonApi\Response\ErrorResponse} —
 * each caller keeps owning those.
 *
 * The mapping mirrors `laravel-json-api/exceptions`: with `$debug` on, the
 * throwable's message becomes the error `detail` and its
 * `{exception, file, line, trace}` go into the error object's `meta` (the
 * spec-faithful home — `source` locates request parts and there is no standard
 * trace member); with `$debug` off, neither leaks and the error is the redacted
 * generic form.
 */
final class InternalServerError
{
    /**
     * Map an arbitrary throwable to the single generic-500 JSON:API error object.
     *
     * With `$debug` off (the default), returns the redacted generic-500 form
     * carrying only `status` and `title`. With `$debug` on, attaches the
     * throwable's `code`, `detail` (its message) and diagnostic `meta`.
     */
    public static function for(\Throwable $throwable, bool $debug = false): Error
    {
        if ($debug === false) {
            return new Error(status: '500', title: 'Internal Server Error');
        }

        return new Error(
            status: '500',
            code: $throwable->getCode() === 0 ? '' : (string) $throwable->getCode(),
            title: 'Internal Server Error',
            detail: $throwable->getMessage(),
            meta: [
                'exception' => $throwable::class,
                'file' => $throwable->getFile(),
                'line' => $throwable->getLine(),
                'trace' => self::formatTrace($throwable),
            ],
        );
    }

    /**
     * The throwable's stack trace as frames with call arguments stripped, the
     * same shape Laravel's exception handler emits in debug mode.
     *
     * @return list<array<string, mixed>>
     */
    private static function formatTrace(\Throwable $throwable): array
    {
        $frames = [];

        foreach ($throwable->getTrace() as $frame) {
            unset($frame['args']);
            $frames[] = $frame;
        }

        return $frames;
    }
}
