<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Testing;

use haddowg\JsonApi\Response\AbstractResponse;
use haddowg\JsonApi\Server\ServerInterface;
use haddowg\JsonApi\Testing\Internal\Decode;
use haddowg\JsonApi\Testing\Internal\Diff;
use PHPUnit\Framework\Assert;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Fluent assertions over a JSON:API **error** document. Same input shapes as
 * {@see JsonApiDocument}. Every assertion returns `$this`; {@see errors()}
 * exposes the raw error list for ad-hoc checks.
 *
 * As with {@see JsonApiDocument}, when constructed from a PSR-7 response (or
 * given a {@see ResponseMeta}) the wrapper also carries the HTTP status code and
 * header map, so {@see assertStatus()} / {@see assertContentType()} /
 * {@see assertHeader()} assert the transport envelope alongside the error
 * payload — the envelope is plain scalars, no `psr/http-message` dependency.
 */
final class JsonApiErrors
{
    /**
     * @var list<array<string, mixed>>
     */
    private readonly array $errors;

    private readonly ResponseMeta $meta;

    /**
     * @param ResponseInterface|string|array<string, mixed>|AbstractResponse $document
     */
    public function __construct(
        ResponseInterface|string|array|AbstractResponse $document,
        ?ServerInterface $server = null,
        ?ServerRequestInterface $request = null,
        ?ResponseMeta $meta = null,
    ) {
        $this->meta = $meta ?? Decode::toResponseMeta($document, $server, $request) ?? new ResponseMeta();
        $document = Decode::toArray($document, $server, $request);
        $errors = $document['errors'] ?? [];
        $list = [];
        if (\is_array($errors)) {
            foreach ($errors as $error) {
                if (\is_array($error)) {
                    /** @var array<string, mixed> $error */
                    $list[] = $error;
                }
            }
        }
        $this->errors = $list;
    }

    /**
     * @param ResponseInterface|string|array<string, mixed>|AbstractResponse $document
     */
    public static function of(
        ResponseInterface|string|array|AbstractResponse $document,
        ?ServerInterface $server = null,
        ?ServerRequestInterface $request = null,
        ?ResponseMeta $meta = null,
    ): self {
        return new self($document, $server, $request, $meta);
    }

    // ---- response envelope (status + headers) ----

    public function assertStatus(int $status): self
    {
        Assert::assertSame(
            $status,
            $this->meta->status,
            "Expected response status {$status}, got " . ($this->meta->status ?? 'none (no response envelope)') . '.',
        );

        return $this;
    }

    public function assertContentType(string $expected = 'application/vnd.api+json'): self
    {
        $actual = $this->meta->header('Content-Type');
        Assert::assertNotNull($actual, 'The response carries no Content-Type header.');
        Assert::assertStringContainsString(
            $expected,
            (string) $actual,
            "Expected Content-Type to contain '{$expected}', got '{$actual}'.",
        );

        return $this;
    }

    public function assertHeader(string $name, ?string $expected = null): self
    {
        Assert::assertTrue($this->meta->hasHeader($name), "Response header '{$name}' is missing.");

        if ($expected !== null) {
            Assert::assertSame(
                $expected,
                $this->meta->header($name),
                "Response header '{$name}' does not match.",
            );
        }

        return $this;
    }

    // ---- error payload ----

    public function assertCount(int $count): self
    {
        Assert::assertCount($count, $this->errors, "Expected {$count} error(s).");

        return $this;
    }

    public function assertHasError(?string $status = null, ?string $pointer = null, ?string $code = null): self
    {
        $matched = false;
        foreach ($this->errors as $error) {
            if ($status !== null && ($error['status'] ?? null) !== $status) {
                continue;
            }
            if ($code !== null && ($error['code'] ?? null) !== $code) {
                continue;
            }
            if ($pointer !== null && $this->pointerOf($error) !== $pointer) {
                continue;
            }

            $matched = true;

            break;
        }

        Assert::assertTrue($matched, 'No error matched the given criteria.');

        return $this;
    }

    public function assertHasErrorAt(string $pointer): self
    {
        $pointers = \array_map(fn(array $error): ?string => $this->pointerOf($error), $this->errors);

        Assert::assertContains($pointer, $pointers, "No error has source.pointer '{$pointer}'.");

        return $this;
    }

    public function assertHasErrorWithCode(string $code): self
    {
        $codes = \array_map(static fn(array $error): mixed => $error['code'] ?? null, $this->errors);
        Assert::assertContains($code, $codes, "No error has code '{$code}'.");

        return $this;
    }

    /**
     * Asserts at least one error object **exactly** matches `$error` (whole-
     * member compare; both sides recursively key-sorted for a stable diff).
     *
     * @param array<string, mixed> $error
     */
    public function assertHasExactError(array $error): self
    {
        $needle = Diff::normalise($error);
        $haystack = \array_map(static fn(array $e): array => Diff::normalise($e), $this->errors);

        Assert::assertContains(
            $needle,
            $haystack,
            'No error object exactly matches the expected error.',
        );

        return $this;
    }

    /**
     * Asserts the error list is **exactly** `$errors`, order-sensitive — catches
     * a missing, extra, or reordered error object.
     *
     * @param list<array<string, mixed>> $errors
     */
    public function assertErrorsExact(array $errors): self
    {
        $expected = \array_map(static fn(array $e): array => Diff::normalise($e), $errors);
        $actual = \array_map(static fn(array $e): array => Diff::normalise($e), $this->errors);

        Assert::assertSame(
            $expected,
            $actual,
            'The error list does not exactly match the expected errors.',
        );

        return $this;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function errors(): array
    {
        return $this->errors;
    }

    /**
     * The plain-data response envelope (status + headers) carried alongside the
     * error payload, if any was supplied or extracted.
     */
    public function responseMeta(): ResponseMeta
    {
        return $this->meta;
    }

    /**
     * @param array<string, mixed> $error
     */
    private function pointerOf(array $error): ?string
    {
        $source = $error['source'] ?? null;
        if (\is_array($source)) {
            $pointer = $source['pointer'] ?? null;

            return \is_string($pointer) ? $pointer : null;
        }

        return null;
    }
}
