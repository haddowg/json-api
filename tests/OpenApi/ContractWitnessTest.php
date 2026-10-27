<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\OpenApi;

use haddowg\JsonApi\OpenApi\GeneratorContract;
use haddowg\JsonApi\OpenApi\OpenApiProjector;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\ContractWitnessServer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Pins the projector's emitted structure to a committed artifact — `Fixture/contract-witness.json`,
 * the document {@see ContractWitnessServer} projects to.
 *
 * It exists so the {@see GeneratorContract::CONTRACT} bump cannot be silently forgotten. The
 * guard is a chain: a projector change moves this document, so the witness must be
 * regenerated; CI then compares the regenerated witness against the one on the base branch
 * and fails the pull request unless the contract moved too (see `bin/contract-guard.php`).
 * Neither half is sufficient alone — this test cannot tell a deliberate re-record from a
 * forgetful one, and the CI guard has nothing to compare unless the witness tracks the
 * projector.
 *
 * Regenerate after an intended change:
 *
 * ```
 * UPDATE_CONTRACT_WITNESS=1 composer test -- --filter ContractWitnessTest
 * ```
 *
 * then read the diff and decide whether it warrants a contract bump.
 */
#[CoversClass(GeneratorContract::class)]
#[CoversClass(OpenApiProjector::class)]
final class ContractWitnessTest extends TestCase
{
    private const WITNESS = __DIR__ . '/Fixture/contract-witness.json';

    private const BASELINE_WITNESS = __DIR__ . '/Fixture/contract-witness-baseline.json';

    #[Test]
    public function theProjectedDocumentMatchesTheCommittedWitness(): void
    {
        $projected = $this->project();

        if (\getenv('UPDATE_CONTRACT_WITNESS') !== false) {
            \file_put_contents(self::WITNESS, $projected);
        }

        $committed = \file_get_contents(self::WITNESS);
        self::assertIsString($committed, 'The contract witness fixture is missing.');

        self::assertSame(
            $committed,
            $projected,
            'The projected OpenAPI document no longer matches tests/OpenApi/Fixture/contract-witness.json. '
            . 'Regenerate it with UPDATE_CONTRACT_WITNESS=1 composer test -- --filter ContractWitnessTest, '
            . 'read the diff, and bump GeneratorContract::CONTRACT if the structure moved.',
        );
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

    /**
     * The same server without its raw action responses, accepted bodies, examples, declared links, action declarations, scopes and single-target polymorphic relations: the
     * shape every document shares, stamped with {@see GeneratorContract::BASELINE}. The CI guard holds the baseline to
     * this file, so a change that moves every document cannot hide behind a scoped bump.
     */
    #[Test]
    public function theBaselineDocumentMatchesTheCommittedBaselineWitness(): void
    {
        $projected = $this->project(scoped: false);

        if (\getenv('UPDATE_CONTRACT_WITNESS') !== false) {
            \file_put_contents(self::BASELINE_WITNESS, $projected);
        }

        self::assertSame(
            \file_get_contents(self::BASELINE_WITNESS),
            $projected,
            'The projected baseline document no longer matches tests/OpenApi/Fixture/contract-witness-baseline.json. '
            . 'Regenerate it with UPDATE_CONTRACT_WITNESS=1 composer test -- --filter ContractWitnessTest, '
            . 'read the diff, and bump GeneratorContract::BASELINE (and CONTRACT) if the structure moved.',
        );
    }

    #[Test]
    public function aDocumentWithoutRawContentCarriesTheBaselineContract(): void
    {
        $document = (new OpenApiProjector())->project(ContractWitnessServer::build(rawContent: false, examples: false, declaredLinks: false, actionDeclarations: false, scopes: false, singleTargetPolymorphism: false))->toArray();

        self::assertIsArray($document['info']);
        self::assertSame(['contract' => GeneratorContract::BASELINE], $document['info']['x-generator']);
    }

    #[Test]
    public function theContractIsTheHighestAnyDocumentCarries(): void
    {
        self::assertSame(['contract' => GeneratorContract::CONTRACT], GeneratorContract::toArray(rawContent: true, acceptedBodies: true, examples: true, declaredLinks: true, actionMethodIds: true, actionSafety: true, actionExtensions: true, scopes: true, singleTargetPolymorphism: true));
        self::assertSame(['contract' => GeneratorContract::SINGLE_TARGET_POLYMORPHISM], GeneratorContract::toArray(singleTargetPolymorphism: true));
        self::assertSame(['contract' => GeneratorContract::SCOPES], GeneratorContract::toArray(scopes: true));
        self::assertSame(['contract' => GeneratorContract::DECLARED_LINKS], GeneratorContract::toArray(declaredLinks: true));
        self::assertSame(['contract' => GeneratorContract::ACTION_METHOD_IDS], GeneratorContract::toArray(actionMethodIds: true));
        self::assertSame(['contract' => GeneratorContract::ACTION_SAFETY], GeneratorContract::toArray(actionSafety: true));
        self::assertSame(['contract' => GeneratorContract::ACTION_EXTENSIONS], GeneratorContract::toArray(actionExtensions: true));
        // A scoped tier never stamps below the baseline, which may since have overtaken it.
        self::assertSame(['contract' => \max(GeneratorContract::BASELINE, GeneratorContract::EXAMPLES)], GeneratorContract::toArray(examples: true));
        self::assertSame(['contract' => \max(GeneratorContract::BASELINE, GeneratorContract::RAW_CONTENT)], GeneratorContract::toArray(rawContent: true));
    }

    /**
     * @throws \JsonException
     */
    private function project(bool $scoped = true): string
    {
        return (new OpenApiProjector())->project(ContractWitnessServer::build($scoped, $scoped, $scoped, $scoped, $scoped, $scoped))->toJsonString(pretty: true) . "\n";
    }
}
