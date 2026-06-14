<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Testing;

/**
 * The plain-data response envelope a {@see JsonApiDocument} / {@see JsonApiErrors}
 * may optionally carry alongside the decoded body: an HTTP status code and a
 * header map. Deliberately framework-agnostic — status and headers are plain
 * scalars, so a PSR-7 caller and an HttpFoundation caller (e.g. the Symfony
 * bundle's `JsonApiBrowser`) feed the wrappers the same way without either side
 * hard-depending on `psr/http-message`.
 *
 * Header names are matched case-insensitively (HTTP header names are not
 * case-sensitive), so a wrapper constructed from either source can assert on
 * `Content-Type` regardless of the casing the producer emitted.
 */
final class ResponseMeta
{
    /**
     * @var array<string, string> the original (preserved-case) header map
     */
    private readonly array $headers;

    /**
     * @var array<string, string> a lower-cased-key view for case-insensitive lookup
     */
    private readonly array $lowerHeaders;

    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        public readonly ?int $status = null,
        array $headers = [],
    ) {
        $this->headers = $headers;
        $lower = [];
        foreach ($headers as $name => $value) {
            $lower[\strtolower($name)] = $value;
        }
        $this->lowerHeaders = $lower;
    }

    /**
     * @return array<string, string>
     */
    public function headers(): array
    {
        return $this->headers;
    }

    public function hasHeader(string $name): bool
    {
        return \array_key_exists(\strtolower($name), $this->lowerHeaders);
    }

    public function header(string $name): ?string
    {
        return $this->lowerHeaders[\strtolower($name)] ?? null;
    }
}
