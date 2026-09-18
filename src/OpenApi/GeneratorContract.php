<?php

declare(strict_types=1);

namespace haddowg\JsonApi\OpenApi;

/**
 * The compatibility signal the projector stamps onto every document it emits, as
 * `info.x-generator`:
 *
 * ```json
 * "info": { "x-generator": { "contract": 1 } }
 * ```
 *
 * A single monotonic integer, bumped by hand whenever the **emitted structure**
 * changes in a way a document consumer could care about — a new member, a renamed or
 * removed one, a rewired `$ref`, a new vendor extension. It is not the package
 * version: `1.4.2 → 1.4.3` may emit exactly the same structure while a minor release
 * changes it, so a code generator keying on semver would accept or reject for reasons
 * unrelated to what it reads.
 *
 * It is nonetheless **release-scoped**, like the package version. The contract names the
 * shape a *release* emits, so it moves at most once per release cycle however many
 * commits that cycle takes: the first change to move the structure moves the contract,
 * and every later change in the same cycle leaves it alone. Only a released document is
 * a document a generator has ever seen.
 *
 * **An absent `info.x-generator` means contract 1.** v1.0.0 shipped before this field
 * existed, so contract 1 denotes the shape it emitted rather than "no signal" — every
 * published document is readable, and a generator supporting `[1, 2]` handles both v1.0.0
 * and the release that added the stamp.
 *
 * A code generator declares the contract range it understands and compares:
 *
 * - **below its minimum** — error. The document predates a structure the generator
 *   requires.
 * - **above its maximum** — warn. The server describes capabilities this generator
 *   does not know how to read, so the client it emits is silently missing them. That
 *   silent under-generation is the failure this field exists to catch; the opposite
 *   direction already fails loudly when a required member turns out to be absent.
 *
 * The field carries compatibility signalling and nothing else. The JSON:API version is
 * `#/components/schemas/JsonApi`'s `version` const, and the supported profiles and
 * extensions are that component's `profile` / `ext` enums; none of it is repeated here.
 * There is deliberately no feature-token list either — it would name what changed at
 * the cost of two hand-maintained lists (the server's and every generator's known-set)
 * that must agree forever.
 *
 * @see \haddowg\JsonApi\Tests\OpenApi\ContractWitnessTest the test that fails when the
 *      emitted structure moves, so the bump cannot be forgotten
 */
final class GeneratorContract
{
    /**
     * The current contract. Bump by exactly one, and at most once per release; see the
     * class docblock for what warrants it and `docs/openapi.md` for the discipline
     * around it.
     */
    public const CONTRACT = 2;

    /**
     * The `info.x-generator` value.
     *
     * @return array{contract: int}
     */
    public static function toArray(): array
    {
        return ['contract' => self::CONTRACT];
    }
}
