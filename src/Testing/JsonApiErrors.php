<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Testing;

use haddowg\JsonApi\Response\AbstractResponse;
use haddowg\JsonApi\Server\ServerInterface;
use haddowg\JsonApi\Testing\Internal\Decode;
use PHPUnit\Framework\Assert;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Fluent assertions over a JSON:API **error** document. Same input shapes as
 * {@see JsonApiDocument}. Every assertion returns `$this`; {@see errors()}
 * exposes the raw error list for ad-hoc checks.
 */
final class JsonApiErrors
{
    /**
     * @var list<array<string, mixed>>
     */
    private readonly array $errors;

    /**
     * @param ResponseInterface|string|array<string, mixed>|AbstractResponse $document
     */
    public function __construct(
        ResponseInterface|string|array|AbstractResponse $document,
        ?ServerInterface $server = null,
        ?ServerRequestInterface $request = null,
    ) {
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
    ): self {
        return new self($document, $server, $request);
    }

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
     * @return list<array<string, mixed>>
     */
    public function errors(): array
    {
        return $this->errors;
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
