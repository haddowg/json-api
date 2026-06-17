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
 * Fluent assertions over a JSON:API **document** (a `data`/`meta`/`links`
 * response), for use in consumer test suites. Accepts a PSR-7 response, a raw
 * JSON string, an already-parsed array, or a response value object (with a
 * server to render it). Every assertion returns `$this` for chaining; lower-
 * level accessors (`data()`, `included()`, …) expose the raw structure for
 * ad-hoc checks.
 *
 * When constructed from a PSR-7 response (or when a {@see ResponseMeta} is
 * supplied explicitly), the wrapper also carries the HTTP status code and a
 * header map, so {@see assertStatus()} / {@see assertContentType()} /
 * {@see assertHeader()} work alongside the body assertions. The envelope is
 * plain scalars (no `psr/http-message` dependency in the assertion path), so a
 * framework caller — e.g. the Symfony bundle's `JsonApiBrowser` over an
 * HttpFoundation `Response` — can feed status + headers the same way.
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
        $this->document = Decode::toArray($document, $server, $request);
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

    // ---- single-resource assertions ----

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

    /**
     * Whole-member exact compare of the single primary resource object against
     * `$expected` — catches leaked or extra attributes / relationships that a
     * presence-only assertion would pass silently. Both sides are recursively
     * key-sorted first, so a failure prints a stable, readable diff (#64).
     *
     * @param array<string, mixed> $expected
     */
    public function assertFetchedOneExact(array $expected): self
    {
        $actual = $this->primaryData();
        Assert::assertSame(
            Diff::normalise($expected),
            Diff::normalise($actual),
            'The primary resource object does not exactly match the expected resource object.',
        );

        return $this;
    }

    // ---- collection assertions ----

    /**
     * Asserts the primary `data` is a list of resource objects (a fetched-many
     * document), each with a `type`. An empty collection passes.
     */
    public function assertFetchedMany(): self
    {
        $data = $this->document['data'] ?? null;
        Assert::assertIsArray($data, 'The document has no primary `data`.');
        Assert::assertTrue(\array_is_list($data), 'The primary `data` is not a collection (list of resources).');
        foreach ($data as $member) {
            Assert::assertIsArray($member, 'A collection member is not a resource object.');
            Assert::assertArrayHasKey('type', $member, 'A collection member has no `type`.');
        }

        return $this;
    }

    /**
     * The `?sort` witness: asserts the primary collection carries exactly the
     * given ids **in that order** (order matters). Optionally constrains every
     * member to `$type`.
     *
     * @param list<string> $idsInOrder
     */
    public function assertFetchedManyInOrder(array $idsInOrder, ?string $type = null): self
    {
        $this->assertFetchedMany();
        $members = $this->collection();

        $actualIds = \array_map(
            static fn(array $member): mixed => $member['id'] ?? null,
            $members,
        );
        Assert::assertSame($idsInOrder, $actualIds, 'The collection ids are not in the expected order.');

        if ($type !== null) {
            foreach ($members as $member) {
                Assert::assertSame($type, $member['type'] ?? null, "A collection member is not of type '{$type}'.");
            }
        }

        return $this;
    }

    public function assertCollectionCount(int $count): self
    {
        Assert::assertCount($count, $this->collection(), "Expected {$count} resource(s) in the collection.");

        return $this;
    }

    public function assertCollectionContains(string $type, string $id): self
    {
        $found = false;
        foreach ($this->collection() as $member) {
            if (($member['type'] ?? null) === $type && ($member['id'] ?? null) === $id) {
                $found = true;

                break;
            }
        }

        Assert::assertTrue($found, "The collection does not contain a '{$type}' resource with id '{$id}'.");

        return $this;
    }

    /**
     * Exact, order-sensitive compare of the collection's `{type, id}` (and, when
     * `attributes` is given in an expected member, its attributes) against
     * `$expected`. Catches a missing/extra/reordered member.
     *
     * @param list<array<string, mixed>> $expected each at least `{type, id}`, optionally `attributes`
     */
    public function assertFetchedManyExact(array $expected): self
    {
        $this->assertFetchedMany();
        $members = $this->collection();

        Assert::assertCount(
            \count($expected),
            $members,
            'The collection size does not match the expected member count.',
        );

        // Project each actual member down to the keys its matching expected member declares
        // (order-sensitive), so a caller can assert ids-only or ids+attributes per expectation.
        $reducedActual = [];
        $reducedExpected = [];
        foreach ($expected as $index => $expectedMember) {
            $actualMember = $members[$index] ?? [];
            $projected = [];
            foreach (\array_keys($expectedMember) as $key) {
                $projected[$key] = $actualMember[$key] ?? null;
            }
            $reducedActual[] = Diff::normalise($projected);
            $reducedExpected[] = Diff::normalise($expectedMember);
        }

        Assert::assertSame(
            $reducedExpected,
            $reducedActual,
            'The collection does not exactly match the expected members (order-sensitive).',
        );

        return $this;
    }

    // ---- included assertions ----

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

    /**
     * Membership: asserts a specific `{type, id}` resource is present in
     * `included` (beyond the count-only {@see assertHasIncluded()}).
     */
    public function assertHasIncludedResource(string $type, string $id): self
    {
        $found = false;
        foreach ($this->included() as $resource) {
            if (\is_array($resource) && ($resource['type'] ?? null) === $type && ($resource['id'] ?? null) === $id) {
                $found = true;

                break;
            }
        }

        Assert::assertTrue($found, "No included '{$type}' resource with id '{$id}' found.");

        return $this;
    }

    /**
     * Asserts `included` carries exactly the given `{type, id}` set (order-
     * insensitive — `included` ordering is not significant).
     *
     * @param list<array{type: string, id: string}> $expected
     */
    public function assertIncludedExactly(array $expected): self
    {
        $actual = [];
        foreach ($this->included() as $resource) {
            if (\is_array($resource)) {
                $actual[] = ['type' => $resource['type'] ?? null, 'id' => $resource['id'] ?? null];
            }
        }

        $sort = static function (array $a, array $b): int {
            return [$a['type'], $a['id']] <=> [$b['type'], $b['id']];
        };
        $expectedSorted = $expected;
        \usort($expectedSorted, $sort);
        \usort($actual, $sort);

        Assert::assertSame(
            Diff::normalise($expectedSorted),
            Diff::normalise($actual),
            'The included resources do not exactly match the expected set.',
        );

        return $this;
    }

    // ---- meta / links assertions ----

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

    /**
     * Whole-`meta` exact compare (recursively key-sorted for a stable diff).
     *
     * @param array<string, mixed> $expected
     */
    public function assertExactMeta(array $expected): self
    {
        Assert::assertSame(
            Diff::normalise($expected),
            Diff::normalise($this->meta()),
            'The document `meta` does not exactly match the expected meta.',
        );

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

    /**
     * Whole-`links` exact compare (recursively key-sorted for a stable diff).
     *
     * @param array<string, mixed> $expected
     */
    public function assertExactLinks(array $expected): self
    {
        Assert::assertSame(
            Diff::normalise($expected),
            Diff::normalise($this->links()),
            'The document `links` do not exactly match the expected links.',
        );

        return $this;
    }

    public function assertProfileApplied(string $uri): self
    {
        $profile = $this->jsonapi()['profile'] ?? [];
        $applied = \is_array($profile) ? $profile : [$profile];
        Assert::assertContains($uri, $applied, "Profile '{$uri}' is not applied.");

        return $this;
    }

    // ---- absence assertions ----

    /**
     * Asserts the document carries no primary `data` (absent, or explicitly
     * `null` — e.g. an empty to-one relationship endpoint).
     */
    public function assertNoData(): self
    {
        Assert::assertNull($this->document['data'] ?? null, 'The document unexpectedly carries primary `data`.');

        return $this;
    }

    /**
     * Asserts the document carries no `meta` member (or an empty one).
     */
    public function assertNoMeta(): self
    {
        Assert::assertEmpty($this->meta(), 'The document unexpectedly carries `meta`.');

        return $this;
    }

    /**
     * Asserts the document carries no `links` (with no `$rel`) — a witness for
     * `withoutLinks()` — or, with a `$rel`, that that specific link is absent.
     */
    public function assertNoLink(?string $rel = null): self
    {
        if ($rel === null) {
            Assert::assertEmpty($this->links(), 'The document unexpectedly carries `links`.');

            return $this;
        }

        Assert::assertArrayNotHasKey($rel, $this->links(), "The document unexpectedly carries link '{$rel}'.");

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
     * The top-level `jsonapi` member (where applied profiles are advertised under
     * its `profile` array).
     *
     * @return array<string, mixed>
     */
    public function jsonapi(): array
    {
        $jsonapi = $this->document['jsonapi'] ?? [];

        return \is_array($jsonapi) ? $jsonapi : [];
    }

    /**
     * The plain-data response envelope (status + headers) carried alongside the
     * body, if any was supplied or extracted.
     */
    public function responseMeta(): ResponseMeta
    {
        return $this->meta;
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

    /**
     * The primary collection as a list of resource-object arrays.
     *
     * @return list<array<string, mixed>>
     */
    private function collection(): array
    {
        $data = $this->document['data'] ?? null;
        Assert::assertIsArray($data, 'The document has no primary `data`.');
        Assert::assertTrue(\array_is_list($data), 'The primary `data` is not a collection (list of resources).');

        $members = [];
        foreach ($data as $member) {
            Assert::assertIsArray($member, 'A collection member is not a resource object.');
            /** @var array<string, mixed> $member */
            $members[] = $member;
        }

        return $members;
    }
}
