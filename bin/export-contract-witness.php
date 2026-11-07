<?php

declare(strict_types=1);

/*
 * Writes the contract witness and its baseline into a directory, by default
 * build/contract-witness. CI exports them to compare against the last release's
 * `@haddowg/json-api-witness`, to diff a pull request's output against its base, and to
 * publish the package.
 *
 * Usage:  php bin/export-contract-witness.php [<directory>]
 */

use haddowg\JsonApi\Tests\OpenApi\Fixture\ContractWitness;

require \dirname(__DIR__) . '/vendor/autoload.php';

$directory = $argv[1] ?? \dirname(__DIR__) . '/build/contract-witness';
ContractWitness::export($directory);

\fwrite(\STDOUT, "Wrote the contract witness to {$directory}.\n");
