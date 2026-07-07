<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\OpenApi;

use haddowg\JsonApi\OpenApi\Metadata\Accepted;
use haddowg\JsonApi\OpenApi\Metadata\Created;
use haddowg\JsonApi\OpenApi\Metadata\MetaResult;
use haddowg\JsonApi\OpenApi\Metadata\NoContent;
use haddowg\JsonApi\OpenApi\Metadata\Ok;
use haddowg\JsonApi\OpenApi\Metadata\OperationResponseInterface;
use haddowg\JsonApi\OpenApi\Metadata\OperationType;
use haddowg\JsonApi\OpenApi\Metadata\SeeOther;
use haddowg\JsonApi\OpenApi\OpenApiProjector;
use haddowg\JsonApi\OpenApi\OperationProjector;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeServerMetadata;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeTypeMetadata;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Drives the per-operation success-response projection (ADR 0126): a type declaring
 * an {@see OperationResponseInterface} set per CRUD/read operation projects one
 * OpenAPI response per element (`202` async accepts, `204` create/update, `200`
 * meta-only delete, `303` fetch-one completion), while an undeclared operation
 * projects the single historic default byte-for-byte.
 */
#[CoversClass(OperationProjector::class)]
#[CoversClass(OpenApiProjector::class)]
#[Group('spec:document-structure')]
final class OperationResponseProjectionTest extends TestCase
{
    #[Test]
    public function anUndeclaredOperationProjectsTheHistoricDefaults(): void
    {
        $paths = $this->paths([]);

        // Create → 201 with Location + Document; no 202/204.
        $post = $this->arrAt($paths, '/videos', 'post');
        self::assertArrayHasKey('201', $this->arrAt($post, 'responses'));
        self::assertArrayNotHasKey('202', $this->arrAt($post, 'responses'));
        self::assertArrayNotHasKey('204', $this->arrAt($post, 'responses'));
        self::assertArrayHasKey('Location', $this->arrAt($post, 'responses', '201', 'headers'));
        self::assertSame(
            '#/components/schemas/VideosDocument',
            $this->strAt($post, 'responses', '201', 'content', 'application/vnd.api+json', 'schema', '$ref'),
        );

        // Update → 200; delete → 204; fetch-one → 200.
        self::assertArrayHasKey('200', $this->arrAt($paths, '/videos/{id}', 'patch', 'responses'));
        self::assertArrayHasKey('204', $this->arrAt($paths, '/videos/{id}', 'delete', 'responses'));
        self::assertArrayNotHasKey('303', $this->arrAt($paths, '/videos/{id}', 'get', 'responses'));
    }

    #[Test]
    public function createCanAdvertiseBoth201AndAn202AsyncAccept(): void
    {
        $post = $this->arrAt(
            $this->paths([OperationType::Create->value => [new Created(), new Accepted('jobs')]]),
            '/videos',
            'post',
        );

        self::assertArrayHasKey('201', $this->arrAt($post, 'responses'));
        self::assertArrayHasKey('202', $this->arrAt($post, 'responses'));

        // The 202 references the job type's document and carries the async headers.
        self::assertSame(
            '#/components/schemas/JobsDocument',
            $this->strAt($post, 'responses', '202', 'content', 'application/vnd.api+json', 'schema', '$ref'),
        );
        self::assertArrayHasKey('Content-Location', $this->arrAt($post, 'responses', '202', 'headers'));
        self::assertArrayHasKey('Retry-After', $this->arrAt($post, 'responses', '202', 'headers'));
    }

    #[Test]
    public function createCanAdvertiseA204NoContent(): void
    {
        $post = $this->arrAt(
            $this->paths([OperationType::Create->value => [new NoContent()]]),
            '/videos',
            'post',
        );

        self::assertArrayHasKey('204', $this->arrAt($post, 'responses'));
        self::assertArrayNotHasKey('201', $this->arrAt($post, 'responses'));
        self::assertArrayNotHasKey('content', $this->arrAt($post, 'responses', '204'));
    }

    #[Test]
    public function updateCanAdvertiseAsyncAndNoContent(): void
    {
        $async = $this->arrAt(
            $this->paths([OperationType::Update->value => [new Ok(), new Accepted('jobs')]]),
            '/videos/{id}',
            'patch',
        );
        self::assertArrayHasKey('200', $this->arrAt($async, 'responses'));
        self::assertArrayHasKey('202', $this->arrAt($async, 'responses'));
        self::assertSame(
            '#/components/schemas/JobsDocument',
            $this->strAt($async, 'responses', '202', 'content', 'application/vnd.api+json', 'schema', '$ref'),
        );

        $noContent = $this->arrAt(
            $this->paths([OperationType::Update->value => [new NoContent()]]),
            '/videos/{id}',
            'patch',
        );
        self::assertArrayHasKey('204', $this->arrAt($noContent, 'responses'));
        self::assertArrayNotHasKey('200', $this->arrAt($noContent, 'responses'));
    }

    #[Test]
    public function deleteCanAdvertiseA200MetaDocument(): void
    {
        $paths = $this->paths([OperationType::Delete->value => [new MetaResult()]]);
        $delete = $this->arrAt($paths, '/videos/{id}', 'delete');

        self::assertArrayHasKey('200', $this->arrAt($delete, 'responses'));
        self::assertArrayNotHasKey('204', $this->arrAt($delete, 'responses'));
        self::assertSame(
            '#/components/schemas/MetaDocument',
            $this->strAt($delete, 'responses', '200', 'content', 'application/vnd.api+json', 'schema', '$ref'),
        );

        // The MetaDocument component must be emitted (else the $ref dangles).
        $document = (new OpenApiProjector())->project($this->server([OperationType::Delete->value => [new MetaResult()]]))->toArray();
        self::assertArrayHasKey('MetaDocument', $this->arrAt($document, 'components', 'schemas'));
    }

    #[Test]
    public function fetchOneCanAdvertiseA303CompletionRedirect(): void
    {
        $get = $this->arrAt(
            $this->paths([OperationType::FetchOne->value => [new Ok(), new SeeOther()]]),
            '/videos/{id}',
            'get',
        );

        self::assertArrayHasKey('200', $this->arrAt($get, 'responses'));
        self::assertArrayHasKey('303', $this->arrAt($get, 'responses'));
        // A 303 is a headers-only redirect: a Location header and no body.
        self::assertArrayHasKey('Location', $this->arrAt($get, 'responses', '303', 'headers'));
        self::assertArrayNotHasKey('content', $this->arrAt($get, 'responses', '303'));
    }

    /**
     * The projected `paths` for a `videos` type carrying the given response overrides,
     * plus a `jobs` type so the async 202's `JobsDocument` ref resolves.
     *
     * @param array<string, non-empty-list<OperationResponseInterface>> $responses
     * @return array<string, mixed>
     */
    private function paths(array $responses): array
    {
        return $this->arrAt((new OpenApiProjector())->project($this->server($responses))->toArray(), 'paths');
    }

    /**
     * @param array<string, non-empty-list<OperationResponseInterface>> $responses
     */
    private function server(array $responses): FakeServerMetadata
    {
        $videos = FakeTypeMetadata::resource(
            type: 'videos',
            fields: [Id::make(), Str::make('title')->required()],
            tags: ['Videos'],
            responses: $responses,
        );
        $jobs = FakeTypeMetadata::resource(
            type: 'jobs',
            fields: [Id::make(), Str::make('status')->required()],
            tags: ['Jobs'],
        );

        return new FakeServerMetadata(title: 'Media API', version: '1.0.0', types: [$videos, $jobs]);
    }

    /**
     * @param array<array-key, mixed> $data
     */
    private function at(array $data, string ...$keys): mixed
    {
        $cursor = $data;
        foreach ($keys as $key) {
            self::assertIsArray($cursor);
            self::assertArrayHasKey($key, $cursor);
            $cursor = $cursor[$key];
        }

        return $cursor;
    }

    /**
     * @param array<array-key, mixed> $data
     * @return array<array-key, mixed>
     */
    private function arrAt(array $data, string ...$keys): array
    {
        $value = $this->at($data, ...$keys);
        self::assertIsArray($value);

        return $value;
    }

    /**
     * @param array<array-key, mixed> $data
     */
    private function strAt(array $data, string ...$keys): string
    {
        $value = $this->at($data, ...$keys);
        self::assertIsString($value);

        return $value;
    }
}
