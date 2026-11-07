<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Exception;

use haddowg\JsonApi\Resource\Field\FieldBuilderInterface;
use haddowg\JsonApi\Resource\Field\FieldInterface;
use haddowg\JsonApi\Schema\Error\Error;
use haddowg\JsonApi\Schema\Error\ErrorSource;

/**
 * What an exception can say about its error *before* anything goes wrong: the stable
 * `code`, the HTTP status, the default `title`, the shape of the interpolation context,
 * and the `source` member it always fills.
 *
 * The three wire members a client matches on (`code`, `status`, `title`) live here and
 * nowhere else — {@see toError()} is how the exception builds its {@see Error}, so a
 * descriptor cannot drift from what the exception actually renders. That is what makes
 * the catalogue safe to publish: {@see ErrorCatalog} reads descriptors off the classes
 * without constructing a single exception, and the OpenAPI projection catalogues each code
 * as one branch of the error shape its status, `source` and `meta` select.
 *
 * `$context` is the *shape* of {@see Error::$context} — the `{placeholder}` tokens core
 * fills into the `title` / `detail` templates for this code, which is what an
 * {@see \haddowg\JsonApi\Schema\Error\ErrorMessageResolverInterface} must support in a
 * replacement template. Context is interpolation input, resolved before the response is
 * sent and never a wire member, so this is where the placeholder shape is published: the
 * OpenAPI projection leaves it out entirely, because a template author writes PHP and a
 * generated document describes only what a client receives.
 *
 * `$description`, `$exampleDetail` and `$exampleSource` are the opposite: they exist only
 * for the document. The description tells an API consumer what the code means and what to
 * do about it, the example detail shows one `detail` a client might read, and the example
 * source one value of the `source` member `$source` names. None of them reaches an error
 * object; the projection publishes the description on the code's branch and the examples in
 * the code's entry of each error response that can carry it.
 *
 * `$meta` declares the members the exception puts in the error object's `meta`, with the
 * same field builders a resource's attributes use. The declaration is closed: a member it
 * does not name never appears. A member declared `required()` is in every error object of
 * the code; any other member is optional. Each member's declared example feeds the code's
 * example, and a required member without one drops it.
 */
final readonly class ErrorDescriptor
{
    /**
     * The members the exception puts in `meta`, built.
     *
     * @var list<FieldInterface>
     */
    public array $meta;

    /**
     * @param string                          $code          the stable machine-readable error code
     * @param int                             $status        the HTTP status this error responds with
     * @param string                          $title         the default (pre-localization) short title
     * @param array<string, ErrorContextType> $context       the interpolation placeholders, by name
     * @param ErrorSourceShape|null           $source        the `source` member always filled, if any
     * @param ErrorFeature|null               $feature       the capability the error depends on, if any
     * @param string|null                     $description   what the code means to an API consumer and what a client should do about it
     * @param string|null                     $exampleDetail an example `detail` member, for a code whose detail names the request's own context
     * @param string|null                     $exampleSource an example value of the `source` member `$source` names, such as `/data/attributes/title` or `include`
     * @param list<FieldInterface|FieldBuilderInterface> $meta the members the exception puts in `meta`
     */
    public function __construct(
        public string $code,
        public int $status,
        public string $title,
        public array $context = [],
        public ?ErrorSourceShape $source = null,
        public ?ErrorFeature $feature = null,
        public ?string $description = null,
        public ?string $exampleDetail = null,
        public ?string $exampleSource = null,
        array $meta = [],
    ) {
        $this->meta = \array_map(
            static fn(FieldInterface|FieldBuilderInterface $member): FieldInterface => $member instanceof FieldBuilderInterface ? $member->build() : $member,
            $meta,
        );
    }

    /**
     * Builds the error object for one occurrence: the descriptor supplies `code`,
     * `status` and `title`; the caller supplies what only the throw site knows.
     *
     * @param array<string, scalar|\Stringable> $context the interpolation values, keyed as {@see $context} declares
     * @param array<string, mixed>              $meta
     */
    public function toError(string $detail, array $context = [], ?ErrorSource $source = null, array $meta = []): Error
    {
        return new Error(
            status: (string) $this->status,
            code: $this->code,
            title: $this->title,
            detail: $detail,
            source: $source,
            meta: $meta,
            context: $context,
        );
    }
}
