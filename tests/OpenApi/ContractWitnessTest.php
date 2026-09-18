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
     * @throws \JsonException
     */
    private function project(): string
    {
        return (new OpenApiProjector())->project(ContractWitnessServer::build())->toJsonString(pretty: true) . "\n";
    }
}
