<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\OpenApi\Fixture;

use haddowg\JsonApi\OpenApi\OpenApiProjector;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\ContractWitnessServer;

/**
 * The two documents the contract guard compares and `@haddowg/json-api-witness` publishes:
 * {@see ContractWitnessServer} projected whole, and projected without the structures a
 * scoped bump covers.
 */
final class ContractWitness
{
    public const WITNESS = 'contract-witness.json';

    public const BASELINE = 'contract-witness-baseline.json';

    /**
     * The witness, pretty-printed, or the baseline witness when `$scoped` is false.
     *
     * @throws \JsonException
     */
    public static function project(bool $scoped = true): string
    {
        return (new OpenApiProjector())
            ->project(ContractWitnessServer::build($scoped, $scoped, $scoped, $scoped, $scoped, $scoped, $scoped, $scoped, $scoped, $scoped, $scoped, $scoped, $scoped))
            ->toJsonString(pretty: true) . "\n";
    }

    /**
     * Writes both documents into `$directory`, creating it when absent.
     *
     * @throws \JsonException
     */
    public static function export(string $directory): void
    {
        if (!\is_dir($directory) && !\mkdir($directory, 0o777, true) && !\is_dir($directory)) {
            throw new \RuntimeException("Cannot create {$directory}.");
        }

        \file_put_contents($directory . '/' . self::WITNESS, self::project());
        \file_put_contents($directory . '/' . self::BASELINE, self::project(scoped: false));
    }
}
