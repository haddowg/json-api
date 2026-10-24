<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\OpenApi;

use haddowg\JsonApi\OpenApi\Metadata\ActionScope;
use haddowg\JsonApi\OpenApi\OpenApiProjector;
use haddowg\JsonApi\OpenApi\OperationProjector;
use haddowg\JsonApi\OpenApi\TypeNoun;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeActionMetadata;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeAtomicOperationsMetadata;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeRelationMetadata;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeServerMetadata;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeTypeMetadata;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\SingularNameTypeMetadata;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Generated prose that means one resource names it in the singular, with the right
 * article, and a declared singular name replaces the inflection.
 */
#[CoversClass(OperationProjector::class)]
#[CoversClass(OpenApiProjector::class)]
#[CoversClass(TypeNoun::class)]
final class SingularProseProjectionTest extends TestCase
{
    #[Test]
    public function aHyphenatedTypeReadsAsSingularWordsInEveryOperation(): void
    {
        $document = $this->project();

        self::assertSame('List export jobs', $this->at($document, 'paths', '/export-jobs', 'get', 'summary'));
        self::assertSame('Returns a paginated collection of export jobs.', $this->at($document, 'paths', '/export-jobs', 'get', 'description'));
        self::assertSame('A collection of export jobs.', $this->at($document, 'paths', '/export-jobs', 'get', 'responses', '200', 'description'));
        self::assertSame('Create an export job', $this->at($document, 'paths', '/export-jobs', 'post', 'summary'));
        self::assertSame('The created export job.', $this->at($document, 'paths', '/export-jobs', 'post', 'responses', '201', 'description'));
        self::assertSame('Fetch an export job', $this->at($document, 'paths', '/export-jobs/{id}', 'get', 'summary'));
        self::assertSame('Returns a single export job by its `id`.', $this->at($document, 'paths', '/export-jobs/{id}', 'get', 'description'));
        self::assertSame('The `id` of the export job.', $this->at($document, 'paths', '/export-jobs/{id}', 'parameters', '0', 'description'));
        self::assertSame('Update an export job', $this->at($document, 'paths', '/export-jobs/{id}', 'patch', 'summary'));
        self::assertSame('Delete an export job', $this->at($document, 'paths', '/export-jobs/{id}', 'delete', 'summary'));
        self::assertSame('Deletes the export job identified by its `id`.', $this->at($document, 'paths', '/export-jobs/{id}', 'delete', 'description'));

        self::assertSame('Fetch the related agencies of an export job', $this->at($document, 'paths', '/export-jobs/{id}/agencies', 'get', 'summary'));
        self::assertSame('Fetch the agencies relationship of an export job', $this->at($document, 'paths', '/export-jobs/{id}/relationships/agencies', 'get', 'summary'));
        self::assertSame('Replace the agencies relationship of an export job', $this->at($document, 'paths', '/export-jobs/{id}/relationships/agencies', 'patch', 'summary'));
        self::assertSame('Add to the agencies relationship of an export job', $this->at($document, 'paths', '/export-jobs/{id}/relationships/agencies', 'post', 'summary'));
        self::assertSame('Remove from the agencies relationship of an export job', $this->at($document, 'paths', '/export-jobs/{id}/relationships/agencies', 'delete', 'summary'));

        self::assertSame('Invokes the `retry` custom action on an export job.', $this->at($document, 'paths', '/export-jobs/{id}/-actions/retry', 'post', 'description'));
        self::assertSame('Invokes the `purge` custom action on the export jobs collection.', $this->at($document, 'paths', '/export-jobs/-actions/purge', 'post', 'description'));

        self::assertSame('An export job resource object.', $this->at($document, 'components', 'schemas', 'ExportJobsResource', 'description'));
        self::assertSame('The export job resource object an `add` operation creates.', $this->at($document, 'components', 'schemas', 'ExportJobsAtomicAdd', 'description'));
        self::assertSame('The export job resource object an `update` operation writes.', $this->at($document, 'components', 'schemas', 'ExportJobsAtomicUpdate', 'description'));
    }

    #[Test]
    public function aRegularPluralIsSingularised(): void
    {
        $document = $this->project();

        self::assertSame('Fetch an agency', $this->at($document, 'paths', '/agencies/{id}', 'get', 'summary'));
        self::assertSame('An agency resource object.', $this->at($document, 'components', 'schemas', 'AgenciesResource', 'description'));
    }

    #[Test]
    public function aDeclaredSingularNameReplacesTheInflection(): void
    {
        $document = $this->project();

        self::assertSame('Fetch a media item', $this->at($document, 'paths', '/media/{id}', 'get', 'summary'));
        self::assertSame('List media', $this->at($document, 'paths', '/media', 'get', 'summary'));
        self::assertSame('A media item resource object.', $this->at($document, 'components', 'schemas', 'MediaResource', 'description'));
    }

    /**
     * @return array<array-key, mixed>
     */
    private function project(): array
    {
        $exportJobs = FakeTypeMetadata::resource(
            type: 'export-jobs',
            fields: [Id::make()->build(), Str::make('state')->build()],
            relations: [FakeRelationMetadata::toMany('agencies', ['agencies'])],
            actions: [
                new FakeActionMetadata('retry'),
                new FakeActionMetadata('purge', scope: ActionScope::Collection),
            ],
        );
        $agencies = FakeTypeMetadata::resource(type: 'agencies', fields: [Id::make()->build(), Str::make('name')->build()]);
        $media = new SingularNameTypeMetadata(
            FakeTypeMetadata::resource(type: 'media', fields: [Id::make()->build(), Str::make('url')->build()]),
            'media item',
        );

        return (new OpenApiProjector())->project(new FakeServerMetadata(
            title: 'API',
            version: '1.0.0',
            types: [$exportJobs, $agencies, $media],
            atomicOperations: new FakeAtomicOperationsMetadata('/operations', 'Atomic Operations', []),
        ))->toArray();
    }

    /**
     * @param array<array-key, mixed> $document
     */
    private function at(array $document, string ...$keys): mixed
    {
        $cursor = $document;
        foreach ($keys as $key) {
            self::assertIsArray($cursor);
            self::assertArrayHasKey($key, $cursor, 'Missing ' . $key);
            $cursor = $cursor[$key];
        }

        return $cursor;
    }
}
