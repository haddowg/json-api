<?php

declare(strict_types=1);

namespace haddowg\JsonApi\OpenApi;

/**
 * An OpenAPI 3.1 Responses Object — a map of HTTP status code (or the wildcard
 * `default`) to a {@see Response} (or a {@see Reference} to one).
 *
 * The OAS meta-schema requires status-code keys to match `^[1-5](?:[0-9]{2}|XX)$`;
 * the caller supplies valid codes. An operation's `responses` is a required object
 * member, so even an empty Responses serializes as `{}`.
 */
final readonly class Responses implements \JsonSerializable
{
    /**
     * @param array<string, Response|Reference> $responses status code (or `default`) → {@see Response}
     */
    public function __construct(
        public array $responses = [],
    ) {}

    /**
     * Returns a copy with one status-code (or `default`) entry added/replaced.
     */
    public function with(string $status, Response|Reference $response): self
    {
        $responses = $this->responses;
        $responses[$status] = $response;

        return new self($responses);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $out = [];
        foreach ($this->responses as $status => $response) {
            $out[$status] = $response->toArray();
        }

        return $out;
    }

    public function toJson(): \stdClass
    {
        $object = new \stdClass();
        foreach ($this->responses as $status => $response) {
            $object->{$status} = $response->toJson();
        }

        return $object;
    }

    public function jsonSerialize(): \stdClass
    {
        return $this->toJson();
    }
}
