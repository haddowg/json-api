<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\OpenApi;

use haddowg\JsonApi\OpenApi\GeneratorContract;
use haddowg\JsonApi\OpenApi\OpenApiProjector;
use haddowg\JsonApi\Tests\OpenApi\Fixture\ContractWitness;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\ContractWitnessServer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The witness documents {@see ContractWitness} exports for the contract guard and for
 * `@haddowg/json-api-witness`.
 *
 * Nothing is committed: CI exports the witness on each pull request, compares its structure
 * against the last release's published package (`bin/contract-guard.php`), and posts the
 * diff against the base branch for review. These tests pin what the export carries.
 */
#[CoversClass(GeneratorContract::class)]
#[CoversClass(OpenApiProjector::class)]
final class ContractWitnessTest extends TestCase
{
    #[Test]
    public function theExportWritesBothDocuments(): void
    {
        $directory = \sys_get_temp_dir() . '/contract-witness-' . \bin2hex(\random_bytes(6));
        ContractWitness::export($directory);

        try {
            foreach ([ContractWitness::WITNESS => GeneratorContract::CONTRACT, ContractWitness::BASELINE => GeneratorContract::BASELINE] as $file => $contract) {
                $document = \json_decode((string) \file_get_contents($directory . '/' . $file), true, flags: \JSON_THROW_ON_ERROR);
                self::assertIsArray($document);
                self::assertIsArray($document['info'] ?? null);
                self::assertSame(['contract' => $contract], $document['info']['x-generator'] ?? null, $file);
            }
        } finally {
            foreach ([ContractWitness::WITNESS, ContractWitness::BASELINE] as $file) {
                @\unlink($directory . '/' . $file);
            }
            @\rmdir($directory);
        }
    }

    #[Test]
    public function theProjectionIsStable(): void
    {
        self::assertSame(ContractWitness::project(), ContractWitness::project());
        self::assertSame(ContractWitness::project(scoped: false), ContractWitness::project(scoped: false));
    }

    /**
     * The witness carries the contract it was generated under, which is what lets the CI
     * guard compare two revisions of the file and nothing else.
     */
    #[Test]
    public function theWitnessCarriesTheCurrentContract(): void
    {
        $document = (new OpenApiProjector())->project(ContractWitnessServer::build())->toArray();

        self::assertIsArray($document['info']);
        self::assertSame(['contract' => GeneratorContract::CONTRACT], $document['info']['x-generator']);
    }

    #[Test]
    public function aDocumentWithoutRawContentCarriesTheBaselineContract(): void
    {
        $document = (new OpenApiProjector())->project(ContractWitnessServer::build(rawContent: false, examples: false, declaredLinks: false, actionDeclarations: false, scopes: false, singleTargetPolymorphism: false, actionLocations: false, sortFamilies: false, structuredLists: false, deprecations: false, actionFilters: false, actionCreated: false, relatedOnly: false, filterFamilies: false, listFilters: false, identifierMeta: false, openObjects: false))->toArray();

        self::assertIsArray($document['info']);
        self::assertSame(['contract' => GeneratorContract::BASELINE], $document['info']['x-generator']);
    }

    #[Test]
    public function theContractIsTheHighestAnyDocumentCarries(): void
    {
        self::assertSame(['contract' => GeneratorContract::CONTRACT], GeneratorContract::toArray(rawContent: true, acceptedBodies: true, examples: true, declaredLinks: true, actionMethodIds: true, actionSafety: true, actionExtensions: true, scopes: true, singleTargetPolymorphism: true, actionLocations: true, discriminatedUnions: true, sortFamilies: true, structuredListElements: true, deprecation: true, actionFilters: true, actionCreated: true, relatedOnly: true, filterFamilies: true, listFilters: true, identifierMeta: true, openObjects: true));
        self::assertSame(['contract' => \max(GeneratorContract::BASELINE, GeneratorContract::RELATED_ONLY)], GeneratorContract::toArray(relatedOnly: true));
        self::assertSame(['contract' => \max(GeneratorContract::BASELINE, GeneratorContract::FILTER_FAMILIES)], GeneratorContract::toArray(filterFamilies: true));
        self::assertSame(['contract' => \max(GeneratorContract::BASELINE, GeneratorContract::LIST_FILTERS)], GeneratorContract::toArray(listFilters: true));
        self::assertSame(['contract' => \max(GeneratorContract::BASELINE, GeneratorContract::IDENTIFIER_META)], GeneratorContract::toArray(identifierMeta: true));
        self::assertSame(['contract' => \max(GeneratorContract::BASELINE, GeneratorContract::OPEN_OBJECTS)], GeneratorContract::toArray(openObjects: true));
        self::assertSame(['contract' => \max(GeneratorContract::BASELINE, GeneratorContract::ACTION_CREATED)], GeneratorContract::toArray(actionCreated: true));
        self::assertSame(['contract' => \max(GeneratorContract::BASELINE, GeneratorContract::ACTION_FILTERS)], GeneratorContract::toArray(actionFilters: true));
        self::assertSame(['contract' => \max(GeneratorContract::BASELINE, GeneratorContract::DEPRECATION)], GeneratorContract::toArray(deprecation: true));
        self::assertSame(['contract' => \max(GeneratorContract::BASELINE, GeneratorContract::STRUCTURED_LIST_ELEMENTS)], GeneratorContract::toArray(structuredListElements: true));
        self::assertSame(['contract' => \max(GeneratorContract::BASELINE, GeneratorContract::SORT_FAMILIES)], GeneratorContract::toArray(sortFamilies: true));
        self::assertSame(['contract' => \max(GeneratorContract::BASELINE, GeneratorContract::DISCRIMINATED_UNIONS)], GeneratorContract::toArray(discriminatedUnions: true));
        self::assertSame(['contract' => \max(GeneratorContract::BASELINE, GeneratorContract::ACTION_LOCATION)], GeneratorContract::toArray(actionLocations: true));
        self::assertSame(['contract' => \max(GeneratorContract::BASELINE, GeneratorContract::SINGLE_TARGET_POLYMORPHISM)], GeneratorContract::toArray(singleTargetPolymorphism: true));
        self::assertSame(['contract' => \max(GeneratorContract::BASELINE, GeneratorContract::SCOPES)], GeneratorContract::toArray(scopes: true));
        self::assertSame(['contract' => \max(GeneratorContract::BASELINE, GeneratorContract::DECLARED_LINKS)], GeneratorContract::toArray(declaredLinks: true));
        self::assertSame(['contract' => \max(GeneratorContract::BASELINE, GeneratorContract::ACTION_METHOD_IDS)], GeneratorContract::toArray(actionMethodIds: true));
        self::assertSame(['contract' => \max(GeneratorContract::BASELINE, GeneratorContract::ACTION_SAFETY)], GeneratorContract::toArray(actionSafety: true));
        self::assertSame(['contract' => \max(GeneratorContract::BASELINE, GeneratorContract::ACTION_EXTENSIONS)], GeneratorContract::toArray(actionExtensions: true));
        // A scoped tier never stamps below the baseline, which may since have overtaken it.
        self::assertSame(['contract' => \max(GeneratorContract::BASELINE, GeneratorContract::EXAMPLES)], GeneratorContract::toArray(examples: true));
        self::assertSame(['contract' => \max(GeneratorContract::BASELINE, GeneratorContract::RAW_CONTENT)], GeneratorContract::toArray(rawContent: true));
    }
}
