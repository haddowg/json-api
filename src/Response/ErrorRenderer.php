<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Response;

use haddowg\JsonApi\Document\TopLevelMembers;
use haddowg\JsonApi\Exception\JsonApiExceptionInterface;
use haddowg\JsonApi\Schema\Document\ErrorDocument;
use haddowg\JsonApi\Schema\Error\Error;
use haddowg\JsonApi\Schema\Error\ErrorMessageResolverInterface;
use haddowg\JsonApi\Schema\JsonApiObject;
use haddowg\JsonApi\Schema\Link\DocumentLinks;
use haddowg\JsonApi\Transformer\DocumentTransformer;

/**
 * Renders errors to their JSON:API arrays without a server or a request, for an error
 * body built outside the request lifecycle. The output is what {@see ErrorResponse}
 * renders for the same errors: each error's `title` and `detail` go through the
 * optional {@see ErrorMessageResolverInterface} by `code`, its `context` fills their
 * `{placeholder}` tokens, and its links bind to `$baseUri` unless they pinned their own.
 *
 * Inside the lifecycle, throw a {@see JsonApiExceptionInterface} or return an
 * {@see ErrorResponse} instead, so the server's resolver, base URI and `jsonapi`
 * member apply.
 */
final class ErrorRenderer
{
    private function __construct() {}

    /**
     * A single error object. An error with no members renders as `[]`, which encodes
     * as a JSON array; cast it to an object before encoding it on its own.
     *
     * @return array<string, mixed>
     */
    public static function error(Error $error, ?ErrorMessageResolverInterface $resolver = null, string $baseUri = ''): array
    {
        return self::resolve($error, $resolver, $baseUri)->transform();
    }

    /**
     * A whole error document, `errors` plus whichever of `jsonapi`, `meta` and `links`
     * are given, in the order a response sends them. A `null` `$jsonApi` leaves the `jsonapi` member out; pass
     * `new JsonApiObject()` for `{"version": "1.1"}`.
     *
     * @param JsonApiExceptionInterface|iterable<Error> $errors
     * @param array<string, mixed>                      $meta
     *
     * @return array<string, mixed>
     */
    public static function document(
        JsonApiExceptionInterface|iterable $errors,
        ?JsonApiObject $jsonApi = null,
        array $meta = [],
        ?DocumentLinks $links = null,
        ?ErrorMessageResolverInterface $resolver = null,
        string $baseUri = '',
    ): array {
        return TopLevelMembers::order((new DocumentTransformer())->renderErrorDocument(
            self::buildDocument(self::errorList($errors), $jsonApi, $meta, $links, $resolver, $baseUri),
        ));
    }

    /**
     * The HTTP status for `$errors`, as {@see ErrorResponse} would send it: an
     * exception's declared status, the shared status of errors that agree on one, or
     * the nearest status class for a mix.
     *
     * @param JsonApiExceptionInterface|iterable<Error> $errors
     */
    public static function status(JsonApiExceptionInterface|iterable $errors): int
    {
        if ($errors instanceof JsonApiExceptionInterface) {
            return $errors->getStatusCode();
        }

        return (new ErrorDocument(self::errorList($errors)))->getStatusCode(null);
    }

    /**
     * @internal Shared with {@see ErrorResponse}, which renders the same document
     *           with the server's resolver, base URI and `jsonapi` member.
     *
     * @param list<Error>          $errors
     * @param array<string, mixed> $meta
     */
    public static function buildDocument(
        array $errors,
        ?JsonApiObject $jsonApi,
        array $meta,
        ?DocumentLinks $links,
        ?ErrorMessageResolverInterface $resolver,
        string $baseUri,
    ): ErrorDocument {
        $document = new ErrorDocument(\array_map(
            static fn(Error $error): Error => self::resolve($error, $resolver, $baseUri),
            $errors,
        ));

        return $document
            ->setJsonApi($jsonApi)
            ->setMeta($meta)
            ->setLinks($links?->rebasedTo($baseUri));
    }

    /**
     * @param JsonApiExceptionInterface|iterable<Error> $errors
     *
     * @return list<Error>
     */
    private static function errorList(JsonApiExceptionInterface|iterable $errors): array
    {
        if ($errors instanceof JsonApiExceptionInterface) {
            return $errors->getErrors();
        }

        $list = [];
        foreach ($errors as $error) {
            $list[] = $error;
        }

        return $list;
    }

    private static function resolve(Error $error, ?ErrorMessageResolverInterface $resolver, string $baseUri): Error
    {
        $title = ($error->code !== '' ? $resolver?->title($error->code) : null) ?? $error->title;
        $detail = ($error->code !== '' ? $resolver?->detail($error->code) : null) ?? $error->detail;

        return new Error(
            id: $error->id,
            status: $error->status,
            code: $error->code,
            title: self::interpolate($title, $error->context),
            detail: self::interpolate($detail, $error->context),
            source: $error->source,
            links: $error->links?->rebasedTo($baseUri),
            meta: $error->meta,
            context: $error->context,
        );
    }

    /**
     * Fills `{placeholder}` tokens in `$template` from `$context`, PSR-3 style, and
     * leaves a token with no matching key as written.
     *
     * @param array<string, scalar|\Stringable> $context
     */
    private static function interpolate(string $template, array $context): string
    {
        if ($context === [] || $template === '') {
            return $template;
        }

        $replacements = [];
        foreach ($context as $key => $value) {
            $replacements['{' . $key . '}'] = (string) $value;
        }

        return \strtr($template, $replacements);
    }
}
