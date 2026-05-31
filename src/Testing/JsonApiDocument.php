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
 * Fluent assertions over a JSON:API **document** (a `data`/`meta`/`links`
 * response), for use in consumer test suites. Accepts a PSR-7 response, a raw
 * JSON string, an already-parsed array, or a response value object (with a
 * server to render it). Every assertion returns `$this` for chaining; lower-
 * level accessors (`data()`, `included()`, …) expose the raw structure for
 * ad-hoc checks.
 *
 * Assertions delegate to PHPUnit's {@see Assert}, so a failure is a normal test
 * failure with an informative message.
 */
final class JsonApiDocument
{
    /**
     * @var array<string, mixed>
     */
    private readonly array $document;

    /**
     * @param ResponseInterface|string|array<string, mixed>|AbstractResponse $document
     */
    public function __construct(
        ResponseInterface|string|array|AbstractResponse $document,
        ?ServerInterface $server = null,
        ?ServerRequestInterface $request = null,
    ) {
        $this->document = Decode::toArray($document, $server, $request);
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

    public function assertHasType(string $type): self
    {
        Assert::assertSame($type, $this->primaryData()['type'] ?? null, "The primary data type is not '{$type}'.");

        return $this;
    }

    public function assertHasId(string $id): self
    {
        Assert::assertSame($id, $this->primaryData()['id'] ?? null, "The primary data id is not '{$id}'.");

        return $this;
    }

    public function assertHasAttribute(string $name, mixed $expected = null): self
    {
        $attributes = $this->primaryData()['attributes'] ?? [];
        Assert::assertIsArray($attributes);
        Assert::assertArrayHasKey($name, $attributes, "Attribute '{$name}' is missing.");

        if (\func_num_args() > 1) {
            Assert::assertSame($expected, $attributes[$name], "Attribute '{$name}' does not match.");
        }

        return $this;
    }

    public function assertHasRelationship(string $name, ?string $expectedType = null, ?string $expectedId = null): self
    {
        $relationships = $this->primaryData()['relationships'] ?? [];
        Assert::assertIsArray($relationships);
        Assert::assertArrayHasKey($name, $relationships, "Relationship '{$name}' is missing.");

        if ($expectedType !== null || $expectedId !== null) {
            $relationship = $relationships[$name];
            Assert::assertIsArray($relationship);
            $linkage = $relationship['data'] ?? null;
            Assert::assertIsArray($linkage, "Relationship '{$name}' has no linkage data.");

            if ($expectedType !== null) {
                Assert::assertSame($expectedType, $linkage['type'] ?? null, "Relationship '{$name}' type does not match.");
            }
            if ($expectedId !== null) {
                Assert::assertSame($expectedId, $linkage['id'] ?? null, "Relationship '{$name}' id does not match.");
            }
        }

        return $this;
    }

    public function assertHasIncluded(string $type, ?int $count = null): self
    {
        $matching = \array_values(\array_filter(
            $this->included(),
            static fn(mixed $resource): bool => \is_array($resource) && ($resource['type'] ?? null) === $type,
        ));

        if ($count !== null) {
            Assert::assertCount($count, $matching, "Expected {$count} included '{$type}' resources.");
        } else {
            Assert::assertNotEmpty($matching, "No included '{$type}' resources found.");
        }

        return $this;
    }

    public function assertNotHasIncluded(string $type): self
    {
        $matching = \array_filter(
            $this->included(),
            static fn(mixed $resource): bool => \is_array($resource) && ($resource['type'] ?? null) === $type,
        );

        Assert::assertCount(0, $matching, "Unexpected included '{$type}' resource found.");

        return $this;
    }

    public function assertHasMetaKey(string $key): self
    {
        Assert::assertArrayHasKey($key, $this->meta(), "Meta key '{$key}' is missing.");

        return $this;
    }

    public function assertMetaValue(string $key, mixed $expected): self
    {
        Assert::assertArrayHasKey($key, $this->meta(), "Meta key '{$key}' is missing.");
        Assert::assertSame($expected, $this->meta()[$key], "Meta value for '{$key}' does not match.");

        return $this;
    }

    public function assertHasLink(string $rel, ?string $expectedHref = null): self
    {
        $links = $this->links();
        Assert::assertArrayHasKey($rel, $links, "Link '{$rel}' is missing.");

        if ($expectedHref !== null) {
            $link = $links[$rel];
            $href = \is_array($link) ? ($link['href'] ?? null) : $link;
            Assert::assertSame($expectedHref, $href, "Link '{$rel}' href does not match.");
        }

        return $this;
    }

    public function assertProfileApplied(string $uri): self
    {
        $profile = $this->links()['profile'] ?? [];
        $applied = \is_array($profile) ? $profile : [$profile];
        Assert::assertContains($uri, $applied, "Profile '{$uri}' is not applied.");

        return $this;
    }

    /**
     * The primary `data` member as-is (object map, list of resources, or null).
     */
    public function data(): mixed
    {
        return $this->document['data'] ?? null;
    }

    /**
     * @return list<mixed>
     */
    public function included(): array
    {
        $included = $this->document['included'] ?? [];

        return \is_array($included) ? \array_values($included) : [];
    }

    /**
     * @return array<string, mixed>
     */
    public function meta(): array
    {
        $meta = $this->document['meta'] ?? [];

        return \is_array($meta) ? $meta : [];
    }

    /**
     * @return array<string, mixed>
     */
    public function links(): array
    {
        $links = $this->document['links'] ?? [];

        return \is_array($links) ? $links : [];
    }

    /**
     * The raw parsed document.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->document;
    }

    /**
     * The single primary resource object (for a single-resource document).
     *
     * @return array<string, mixed>
     */
    private function primaryData(): array
    {
        $data = $this->document['data'] ?? null;
        Assert::assertIsArray($data, 'The document has no primary `data`.');

        /** @var array<string, mixed> $data */
        return $data;
    }
}
