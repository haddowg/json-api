<?php

declare(strict_types=1);

/*
 * Fails a pull request that changes the projector's emitted structure without moving
 * `OpenApi\GeneratorContract::CONTRACT` past the value the last release shipped.
 *
 * The contract is **release-scoped**, like the package version: it names the document
 * shape a release emits, so it moves at most once per release cycle. That makes the last
 * release tag the comparison point, never the pull request's base. Unreleased commits
 * accumulate; the first one in a cycle to change the structure moves the contract, and
 * every later one leaves it exactly where that first one put it. Comparing against the
 * base instead bumps once per pull request, which is how a single unreleased cycle once
 * reached contract 5 for a document no consumer had seen more than once.
 *
 * Structure comes from the committed witness (tests/OpenApi/Fixture/contract-witness.json),
 * which tracks the projector because ContractWitnessTest fails otherwise — so "the witness
 * moved" and "the emitted document moved" are the same statement. The contract number
 * comes from the constant itself, read at the tag and in the working tree.
 *
 * Prose is not structure. `description` and `summary` string values are stripped before the
 * comparison, so rewording a generated sentence costs no bump — a generator is never too
 * old to read a changed description. Everything else counts: a member added, renamed or
 * removed, a rewired $ref, a changed const/enum/required set, a new status code, a new
 * vendor extension. The stripping is by key name, so a *user* schema property literally
 * named `description` is only exempted when its value is a string — which a schema object
 * never is.
 *
 * The four verdicts:
 *
 *   structure moved, contract still at the tag's value  → fail, naming the bump to make
 *   structure moved, contract already ahead             → pass, an earlier PR bumped
 *   structure unchanged, contract at the tag's value    → pass
 *   structure unchanged, contract moved anyway          → fail, the bump announces nothing
 *
 * Usage:  php bin/contract-guard.php [<head-witness.json>]
 *
 * Exit 0 = pass, 1 = fail, 2 = the guard could not run.
 *
 * Passing cases with no baseline: no release tag at all, or a tag that predates the
 * witness. v1.0.0 is exactly that — it shipped before both the witness and the constant
 * existed, which is why an absent `info.x-generator` denotes contract 1 rather than
 * "unknown".
 */

const WITNESS_PATH = 'tests/OpenApi/Fixture/contract-witness.json';
const CONTRACT_SOURCE = 'src/OpenApi/GeneratorContract.php';

/**
 * The contract a document carrying no `info.x-generator` was emitted under: v1.0.0 shipped
 * before the field existed, so its shape is contract 1 rather than an unknown.
 */
const ABSENT_CONTRACT = 1;

$repoRoot = \dirname(__DIR__);
$witnessPath = $argv[1] ?? $repoRoot . '/' . WITNESS_PATH;

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

function abort(string $message): never
{
    \fwrite(\STDERR, "[contract-guard] {$message}\n");
    exit(2);
}

/**
 * Runs a git command in the repository, or returns null when git exits non-zero (an
 * unknown revision, a path absent at that revision).
 */
function git(string $repoRoot, string ...$args): ?string
{
    $command = 'git -C ' . \escapeshellarg($repoRoot);
    foreach ($args as $arg) {
        $command .= ' ' . \escapeshellarg($arg);
    }

    $output = [];
    $status = 0;
    \exec($command . ' 2>/dev/null', $output, $status);

    return $status === 0 ? \implode("\n", $output) : null;
}

/**
 * The most recent release tag, or null when the repository has none.
 *
 * Only plain `X.Y.Z` / `vX.Y.Z` tags count: a prerelease is not a release, and git's
 * version sort orders prerelease suffixes by configuration rather than by semver.
 */
function latestReleaseTag(string $repoRoot): ?string
{
    $tags = git($repoRoot, 'tag', '--sort=-v:refname');
    if ($tags === null) {
        abort('git tag failed — is this a git checkout with its tags fetched?');
    }

    foreach (\explode("\n", $tags) as $tag) {
        $tag = \trim($tag);
        if (\preg_match('/^v?\d+\.\d+\.\d+$/', $tag) === 1) {
            return $tag;
        }
    }

    return null;
}

/**
 * The `CONTRACT` value declared in a copy of the constant's source file, or null when the
 * file does not declare one.
 */
function contractIn(string $source): ?int
{
    $matches = [];
    if (\preg_match('/const\s+CONTRACT\s*=\s*(\d+)/', $source, $matches) !== 1) {
        return null;
    }

    return (int) $matches[1];
}

/**
 * @return array<mixed>
 */
function decodeWitness(string $raw, string $label): array
{
    $decoded = \json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);
    if (!\is_array($decoded)) {
        fail("the {$label} witness is not a JSON object.");
    }

    return $decoded;
}

/**
 * Drops the prose members and the generator stamp, so what remains is the structure a code
 * generator reads.
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

$tag = latestReleaseTag($repoRoot);
if ($tag === null) {
    pass('no release tag yet — the first release will set the baseline this guard compares against.');
}

$releasedWitness = git($repoRoot, 'show', $tag . ':' . WITNESS_PATH);
if ($releasedWitness === null || \trim($releasedWitness) === '') {
    pass("{$tag} predates the contract witness, so there is no released structure to compare against.");
}

$headWitness = \is_file($witnessPath) ? \file_get_contents($witnessPath) : false;
if (!\is_string($headWitness) || \trim($headWitness) === '') {
    fail("the witness is missing or empty at {$witnessPath}; regenerate it with UPDATE_CONTRACT_WITNESS=1 composer test.");
}

$headSource = \file_get_contents($repoRoot . '/' . CONTRACT_SOURCE);
if (!\is_string($headSource)) {
    abort('cannot read ' . CONTRACT_SOURCE . '.');
}

$headContract = contractIn($headSource);
if ($headContract === null) {
    fail(CONTRACT_SOURCE . ' declares no integer CONTRACT constant.');
}

// A tag from before the constant existed emitted documents with no `x-generator` at all,
// and an absent stamp denotes contract 1.
$releasedSource = git($repoRoot, 'show', $tag . ':' . CONTRACT_SOURCE);
$releasedContract = \is_string($releasedSource) ? contractIn($releasedSource) : null;
$releasedContract ??= ABSENT_CONTRACT;

$moved = structure(decodeWitness($releasedWitness, $tag)) !== structure(decodeWitness($headWitness, 'working tree'));

if ($headContract < $releasedContract) {
    fail(
        "GeneratorContract::CONTRACT is {$headContract}, behind the {$releasedContract} that {$tag} shipped.\n"
        . '  The contract only ever moves forward — a generator that read a document from '
        . "{$tag} would take this\n  server for the older one.",
    );
}

if (!$moved && $headContract === $releasedContract) {
    pass("the emitted structure is unchanged since {$tag} (contract {$headContract}).");
}

if (!$moved) {
    fail(
        "GeneratorContract::CONTRACT moved {$releasedContract} → {$headContract}, but the emitted structure is\n"
        . "  unchanged since {$tag}.\n"
        . "  The contract is release-scoped: it names the document shape a release emits, so a cycle that\n"
        . "  changes nothing a generator reads ships the integer the last release shipped. A bump here\n"
        . "  announces a difference that is not there, and every generator that supports {$releasedContract} warns for\n"
        . "  nothing.\n"
        . "  Reset GeneratorContract::CONTRACT to {$releasedContract} and regenerate the witness, or land the\n"
        . '  structural change the bump was meant to announce. (Reworded description/summary prose is not '
        . "structure.)",
    );
}

if ($headContract > $releasedContract) {
    pass(
        "the emitted structure changed since {$tag} and the contract is already at {$headContract} "
        . "(that release shipped {$releasedContract}).",
    );
}

fail(
    "the projected OpenAPI structure has changed since {$tag}, but GeneratorContract::CONTRACT is still\n"
    . "  {$headContract} — the value that release shipped.\n"
    . "  A code generator keys on that integer to decide whether it is too old to read this server's\n"
    . "  document. Leaving it where the last release left it means a generator built against contract\n"
    . "  {$headContract} quietly skips whatever this cycle added, and emits a client missing it.\n"
    . '  Bump GeneratorContract::CONTRACT to ' . ($releasedContract + 1) . " and regenerate the witness\n"
    . "  (UPDATE_CONTRACT_WITNESS=1 composer test -- --filter ContractWitnessTest).\n"
    . '  Only the first pull request of a release cycle does this; once the contract is ahead of '
    . "{$releasedContract},\n  later ones leave it alone.",
);
