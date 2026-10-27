<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\OpenApi;

use haddowg\JsonApi\OpenApi\GeneratorContract;
use haddowg\JsonApi\OpenApi\Metadata\ActionDeclarations;
use haddowg\JsonApi\OpenApi\Metadata\ActionMetadataInterface;
use haddowg\JsonApi\OpenApi\Metadata\ActionScope;
use haddowg\JsonApi\OpenApi\Metadata\TypeMetadataInterface;
use haddowg\JsonApi\OpenApi\OpenApiProjector;
use haddowg\JsonApi\OpenApi\OperationProjector;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\ExtendedTypeMetadata;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeActionMetadata;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeRelationMetadata;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeServerMetadata;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeTypeMetadata;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * An action's operation ids stay unique, even once a consumer flattens them, and its
 * declared safety and own extensions reach its operation.
 */
#[CoversClass(ActionDeclarations::class)]
#[CoversClass(OperationProjector::class)]
#[CoversClass(GeneratorContract::class)]
final class ActionIdentityAndSafetyTest extends TestCase
{
    #[Test]
    public function oneActionPathAtBothScopesIsRejected(): void
    {
        $type = $this->files([
            new FakeActionMetadata('export', scope: ActionScope::Collection),
            new FakeActionMetadata('export', scope: ActionScope::Resource),
        ]);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Type "files" declares the "export" action at both the resource and the collection scope, and both would get the operation id "action.files.export". Give one of them a different name.');

        $this->project([$type]);
    }

    #[Test]
    public function anIntegrationCanRejectTheScopePairWithoutProjecting(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('"export" action at both the resource and the collection scope');

        ActionDeclarations::validate('files', [
            new FakeActionMetadata('export', scope: ActionScope::Resource),
            new FakeActionMetadata('export', scope: ActionScope::Collection),
        ]);
    }

    #[Test]
    public function eachMethodOfAMultiMethodActionGetsItsOwnOperationId(): void
    {
        $paths = $this->paths([$this->files([
            new FakeActionMetadata('export', ['GET', 'POST'], ActionScope::Collection),
            new FakeActionMetadata('archive', ['POST']),
        ])]);

        self::assertSame('action.files.export.get', $paths['/files/-actions/export']['get']['operationId']);
        self::assertSame('action.files.export.post', $paths['/files/-actions/export']['post']['operationId']);
        self::assertSame('action.files.archive', $paths['/files/{id}/-actions/archive']['post']['operationId']);
    }

    #[Test]
    public function onlyADocumentWithAMultiMethodActionMovesItsContract(): void
    {
        $multi = $this->project([$this->files([new FakeActionMetadata('export', ['GET', 'POST'])])]);
        $single = $this->project([$this->files([new FakeActionMetadata('export', ['POST'])])]);

        self::assertSame(GeneratorContract::toArray(actionMethodIds: true), self::generator($multi));
        self::assertSame(['contract' => GeneratorContract::BASELINE], self::generator($single));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function clashingNames(): iterable
    {
        foreach (ActionDeclarations::RESERVED_NAMES as $name) {
            yield $name => [$name, 'the "' . $name . '" operation'];
        }
        yield 'an operation name in another case' => ['Delete', 'the "delete" operation'];
        yield 'a hyphenated operation name' => ['fetch-one', 'the "fetchOne" operation'];
        yield 'a snake-cased operation name' => ['add_relationship', 'the "addRelationship" operation'];
    }

    #[Test]
    #[DataProvider('clashingNames')]
    public function anActionNamedLikeAnOperationIsRejected(string $path, string $clash): void
    {
        $type = $this->files([new FakeActionMetadata($path)]);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage(\sprintf('The "%s" action on type "files" is named like %s, so a consumer that flattens operation ids could not tell them apart. Give the action a different name.', $path, $clash));

        $this->project([$type]);
    }

    #[Test]
    public function anActionMayShareItsNameWithARelation(): void
    {
        $paths = $this->paths([$this->files([new FakeActionMetadata('owner', ['GET'])])]);

        self::assertSame('action.files.owner', $paths['/files/{id}/-actions/owner']['get']['operationId']);
        self::assertSame('fetchRelated.files.owner', $paths['/files/{id}/owner']['get']['operationId']);
    }

    #[Test]
    public function actionIdsThatFlattenToTheSameNameAreRejected(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Type "files" declares actions whose operation ids "action.files.export-all" and "action.files.exportAll" differ only in case or punctuation.');

        ActionDeclarations::validate('files', [
            new FakeActionMetadata('export-all'),
            new FakeActionMetadata('exportAll'),
        ]);
    }

    #[Test]
    public function declaredSafetyIsProjectedWithOnlyTheDeclaredKeys(): void
    {
        $paths = $this->paths([$this->files([
            new FakeActionMetadata('preview', ['GET'], readOnly: true, idempotent: true),
            new FakeActionMetadata('shred', ['POST'], destructive: true),
            new FakeActionMetadata('touch', ['POST'], readOnly: false, destructive: false, idempotent: false),
            new FakeActionMetadata('archive', ['POST']),
        ])]);

        self::assertSame(['readOnly' => true, 'idempotent' => true], $paths['/files/{id}/-actions/preview']['get']['x-safety']);
        self::assertSame(['destructive' => true], $paths['/files/{id}/-actions/shred']['post']['x-safety']);
        self::assertSame(['readOnly' => false, 'destructive' => false, 'idempotent' => false], $paths['/files/{id}/-actions/touch']['post']['x-safety']);
        self::assertArrayNotHasKey('x-safety', $paths['/files/{id}/-actions/archive']['post']);
    }

    #[Test]
    public function aCrudOperationIsNeverStampedWithSafety(): void
    {
        $paths = $this->paths([$this->files([new FakeActionMetadata('preview', ['GET'], readOnly: true)])]);

        foreach (['/files' => ['get', 'post'], '/files/{id}' => ['get', 'patch', 'delete']] as $path => $methods) {
            foreach ($methods as $method) {
                self::assertArrayNotHasKey('x-safety', $paths[$path][$method], \sprintf('%s %s', $method, $path));
            }
        }
    }

    #[Test]
    public function onlyADocumentWithDeclaredSafetyMovesItsContract(): void
    {
        $declared = $this->project([$this->files([new FakeActionMetadata('preview', ['GET'], idempotent: true)])]);
        $undeclared = $this->project([$this->files([new FakeActionMetadata('preview', ['GET'])])]);

        self::assertSame(GeneratorContract::toArray(actionSafety: true), self::generator($declared));
        self::assertSame(['contract' => GeneratorContract::BASELINE], self::generator($undeclared));
    }

    #[Test]
    public function aReadOnlyDestructiveActionIsRejected(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('The "shred" action on type "files" is declared both read-only and destructive.');

        ActionDeclarations::validate('files', [new FakeActionMetadata('shred', readOnly: true, destructive: true)]);
    }

    #[Test]
    public function theProjectionRejectsAReadOnlyDestructiveActionToo(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('declared both read-only and destructive');

        $this->project([$this->files([new FakeActionMetadata('shred', readOnly: true, destructive: true)])]);
    }

    #[Test]
    public function anActionsOwnExtensionsReachOnlyItsOperation(): void
    {
        $paths = $this->paths([$this->files([
            new FakeActionMetadata('archive', extensions: ['workflows' => ['workflowId' => 'archiveFile'], 'x-audit' => 'high']),
            new FakeActionMetadata('restore'),
        ])]);

        $archive = $paths['/files/{id}/-actions/archive']['post'];
        self::assertSame(['workflowId' => 'archiveFile'], $archive['x-workflows']);
        self::assertSame('high', $archive['x-audit']);
        self::assertArrayNotHasKey('x-workflows', $paths['/files/{id}/-actions/restore']['post']);
        self::assertArrayNotHasKey('x-workflows', $paths['/files/{id}']['get']);
    }

    #[Test]
    public function anActionsOwnExtensionsMergeOverTheTypeWideOnes(): void
    {
        $type = new ExtendedTypeMetadata(
            $this->files([
                new FakeActionMetadata('archive', extensions: ['feature' => 'archiving']),
                new FakeActionMetadata('restore'),
            ]),
            extensions: ['feature' => 'files', 'x-team' => 'storage'],
        );

        $paths = $this->paths([$type]);

        self::assertSame('archiving', $paths['/files/{id}/-actions/archive']['post']['x-feature']);
        self::assertSame('storage', $paths['/files/{id}/-actions/archive']['post']['x-team']);
        self::assertSame('files', $paths['/files/{id}/-actions/restore']['post']['x-feature']);
    }

    #[Test]
    public function declaredSafetyWinsOverAnExtensionOfTheSameName(): void
    {
        $paths = $this->paths([$this->files([
            new FakeActionMetadata('preview', ['GET'], readOnly: true, extensions: ['safety' => 'unknown']),
        ])]);

        self::assertSame(['readOnly' => true], $paths['/files/{id}/-actions/preview']['get']['x-safety']);
    }

    #[Test]
    public function onlyADocumentWithAnActionsOwnExtensionsMovesItsContract(): void
    {
        $declared = $this->project([$this->files([new FakeActionMetadata('archive', extensions: ['workflows' => []])])]);

        self::assertSame(GeneratorContract::toArray(actionExtensions: true), self::generator($declared));
    }

    #[Test]
    public function anInvalidActionExtensionNameIsRejectedWhenValidating(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        ActionDeclarations::validate('files', [new FakeActionMetadata('archive', extensions: ['x-oai-workflows' => []])]);
    }

    /**
     * @param array<string, mixed> $document
     */
    private static function generator(array $document): mixed
    {
        self::assertIsArray($document['info']);

        return $document['info']['x-generator'];
    }

    /**
     * @param list<ActionMetadataInterface> $actions
     */
    private function files(array $actions): FakeTypeMetadata
    {
        return FakeTypeMetadata::resource(
            type: 'files',
            fields: [Id::make()->build(), Str::make('name')->build()],
            relations: [
                FakeRelationMetadata::toOne('owner', ['people']),
                FakeRelationMetadata::toMany('sharedWith', ['people']),
            ],
            actions: $actions,
        );
    }

    /**
     * @param list<TypeMetadataInterface> $types
     *
     * @return array<string, mixed>
     */
    private function project(array $types): array
    {
        $people = FakeTypeMetadata::resource(type: 'people', fields: [Id::make()->build()]);

        return (new OpenApiProjector())->project(new FakeServerMetadata(
            title: 'Files API',
            version: '1.0.0',
            types: [...$types, $people],
            generatesExamples: false,
        ))->toArray();
    }

    /**
     * @param list<TypeMetadataInterface> $types
     *
     * @return array<string, array<string, array<string, mixed>>>
     */
    private function paths(array $types): array
    {
        /** @var array<string, array<string, array<string, mixed>>> */
        return $this->project($types)['paths'];
    }
}
