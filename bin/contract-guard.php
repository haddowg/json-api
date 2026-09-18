<?php

declare(strict_types=1);

/*
 * Fails a pull request that changes the projector's emitted structure without bumping
 * `OpenApi\GeneratorContract::CONTRACT`.
 *
 * It compares two revisions of the committed contract witness
 * (tests/OpenApi/Fixture/contract-witness.json) — the base branch's and the branch's —
 * and applies one rule: if the structure moved, the contract must have moved too. The
 * witness tracks the projector because ContractWitnessTest fails otherwise, so "the
 * witness moved" and "the emitted document moved" are the same statement.
 *
 * Prose is not structure. `description` and `summary` string values are stripped before
 * the comparison, so rewording a generated sentence costs no bump — a generator is never
 * too old to read a changed description. Everything else counts: a member added, renamed
 * or removed, a rewired $ref, a changed const/enum/required set, a new status code, a new
 * vendor extension. The stripping is by key name, so a *user* schema property literally
 * named `description` is only exempted when its value is a string — which a schema object
 * never is.
 *
 * Usage:  php bin/contract-guard.php <base-witness.json> [<head-witness.json>]
 *
 * Exit 0 = pass (no structural change, or the contract was bumped), 1 = fail.
 * A missing or empty base file passes: the witness did not exist on the base branch.
 */

$repoRoot = \dirname(__DIR__);
$basePath = $argv[1] ?? null;
$headPath = $argv[2] ?? $repoRoot . '/tests/OpenApi/Fixture/contract-witness.json';

if (!\is_string($basePath)) {
    \fwrite(\STDERR, "usage: php bin/contract-guard.php <base-witness.json> [<head-witness.json>]\n");
    exit(2);
}

function fail(string $message): never
{
    \fwrite(\STDERR, "\n[contract-guard] FAIL: {$message}\n");
    exit(1);
}

function pass(string $message): never
{
    \fwrite(\STDOUT, "[contract-guard] {$message}\n");
    exit(0);
}

/**
 * Decodes a witness document, or returns null when it is absent/empty.
 */
function readWitness(string $path): ?array
{
    if (!\is_file($path)) {
        return null;
    }

    $raw = \file_get_contents($path);
    if (!\is_string($raw) || \trim($raw) === '') {
        return null;
    }

    $decoded = \json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);
    if (!\is_array($decoded)) {
        fail("{$path} is not a JSON object.");
    }

    return $decoded;
}

/**
 * The contract the witness was generated under.
 */
function contractOf(array $witness, string $label): int
{
    $contract = $witness['info']['x-generator']['contract'] ?? null;
    if (!\is_int($contract)) {
        fail("the {$label} witness carries no integer info.x-generator.contract.");
    }

    return $contract;
}

/**
 * Drops the prose members and the generator stamp, so what remains is the structure a
 * code generator reads.
 */
function structure(mixed $value): mixed
{
    if (!\is_array($value)) {
        return $value;
    }

    $out = [];
    foreach ($value as $key => $item) {
        if (($key === 'description' || $key === 'summary') && \is_string($item)) {
            continue;
        }
        if ($key === 'x-generator') {
            continue;
        }
        $out[$key] = structure($item);
    }

    return $out;
}

$base = readWitness($basePath);
if ($base === null) {
    pass("no witness on the base revision ({$basePath}) — nothing to compare.");
}

$head = readWitness($headPath);
if ($head === null) {
    fail("the witness is missing or empty at {$headPath}; regenerate it with UPDATE_CONTRACT_WITNESS=1 composer test.");
}

$baseContract = contractOf($base, 'base');
$headContract = contractOf($head, 'head');

if (structure($base) === structure($head)) {
    pass("the emitted structure is unchanged (contract {$headContract}).");
}

if ($headContract > $baseContract) {
    pass("the emitted structure changed and the contract moved {$baseContract} → {$headContract}.");
}

fail(
    "the projected OpenAPI structure changed but GeneratorContract::CONTRACT is still {$headContract}.\n"
    . "  A code generator keys on that integer to decide whether it is too old to read this server's\n"
    . "  document. Leaving it unchanged means a generator built against contract {$headContract} will\n"
    . "  quietly skip whatever this change added, and emit a client missing it.\n"
    . "  Bump GeneratorContract::CONTRACT to " . ($baseContract + 1) . " and regenerate the witness\n"
    . '  (UPDATE_CONTRACT_WITNESS=1 composer test -- --filter ContractWitnessTest).',
);
