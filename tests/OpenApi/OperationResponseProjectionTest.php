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

    #[Test]
    #[Group('spec:inclusion-of-related-resources')]
    #[Group('spec:sparse-fieldsets')]
    public function aWriteAdvertisesIncludeAndFieldsOnlyWhereItAnswersWithAResourceDocument(): void
    {
        // The default create (`201`) and update (`200`) answer with the type's own
        // document, which `?include` / `fields[]` shape exactly as they shape a read's.
        $default = $this->paths([]);
        self::assertSame(['fields[videos]'], $this->parameterNames($this->arrAt($default, '/videos', 'post')));
        self::assertSame(['fields[videos]'], $this->parameterNames($this->arrAt($default, '/videos/{id}', 'patch')));

        // A `204` create is bodyless and a `202` update answers with the JOB resource, so
        // neither renders a `videos` document there is anything to shape.
        $bodyless = $this->paths([
            OperationType::Create->value => [new NoContent()],
            OperationType::Update->value => [new Accepted('jobs')],
        ]);
        self::assertArrayNotHasKey('parameters', $this->arrAt($bodyless, '/videos', 'post'));
        self::assertArrayNotHasKey('parameters', $this->arrAt($bodyless, '/videos/{id}', 'patch'));

        // A mixed set keeps the pair — one arm still answers with the resource document.
        $mixed = $this->paths([OperationType::Create->value => [new Created(), new Accepted('jobs')]]);
        self::assertSame(['fields[videos]'], $this->parameterNames($this->arrAt($mixed, '/videos', 'post')));

        // A delete answers `204` or a meta-only `200` — never a resource document.
        self::assertArrayNotHasKey('parameters', $this->arrAt($default, '/videos/{id}', 'delete'));
        self::assertArrayNotHasKey(
            'parameters',
            $this->arrAt($this->paths([OperationType::Delete->value => [new MetaResult()]]), '/videos/{id}', 'delete'),
        );
    }

    /**
     * The `name`s of an operation's parameters.
     *
     * @param array<array-key, mixed> $operation
     * @return list<string>
     */
    private function parameterNames(array $operation): array
    {
        $names = [];
        foreach ($this->arrAt($operation, 'parameters') as $parameter) {
            self::assertIsArray($parameter);
            self::assertArrayHasKey('name', $parameter);
            self::assertIsString($parameter['name']);
            $names[] = $parameter['name'];
        }

        return $names;
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
            fields: [Id::make()->build(), Str::make('title')->required()->build()],
            tags: ['Videos'],
            responses: $responses,
        );
        $jobs = FakeTypeMetadata::resource(
            type: 'jobs',
            fields: [Id::make()->build(), Str::make('status')->required()->build()],
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
