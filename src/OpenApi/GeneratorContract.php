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
 * **A bump can be scoped to a structure only some documents use.** {@see CONTRACT} is the
 * highest contract the projector emits, and a document using none of the scoped
 * structures carries {@see BASELINE} instead, so a generator that cannot read a structure
 * is warned only by the documents that contain it. There are ten scoped
 * structures today: an action's non-JSON:API response bodies ({@see RAW_CONTENT}), the
 * request bodies it declares it accepts ({@see ACCEPTED_BODIES}), the worked example
 * on an operation's request and responses ({@see EXAMPLES}), the named links a
 * resource declares ({@see DECLARED_LINKS}), the per-method operation ids of an action
 * answering to several methods ({@see ACTION_METHOD_IDS}), an action's declared safety
 * ({@see ACTION_SAFETY}), its own vendor extensions ({@see ACTION_EXTENSIONS}), the
 * scopes an operation needs ({@see SCOPES}) and a polymorphic relation with a single
 * related type ({@see SINGLE_TARGET_POLYMORPHISM}). A change that moves every
 * document raises `BASELINE` and `CONTRACT` together; the second witness,
 * `contract-witness-baseline.json`, and the CI guard hold `BASELINE` to that.
 *
 * @see \haddowg\JsonApi\Tests\OpenApi\ContractWitnessTest the test that fails when the
 *      emitted structure moves, so the bump cannot be forgotten
 */
final class GeneratorContract
{
    /**
     * The highest contract a document can carry. Bump by exactly one, and at most once
     * per release; see the class docblock for what warrants it and `docs/openapi.md`
     * for the discipline around it.
     */
    public const CONTRACT = 16;

    /**
     * The contract a document carries when it uses none of the scoped structures below:
     * the shape every document shares. Bump it, with {@see CONTRACT}, when a change moves
     * that shape. Contract 13 did, giving every core error code a `detail` narrowing with
     * its example, so every document now carries at least 13 and the scoped tiers below
     * name only the contract that first introduced each structure.
     */
    public const BASELINE = 13;

    /**
     * The contract that introduced raw action responses: media types other than JSON:API
     * under an action's success `content`, and the schema components they reference. A
     * document carries it only when an action declares one.
     */
    public const RAW_CONTENT = 10;

    /**
     * The contract that introduced accepted request bodies: one `requestBody.content`
     * entry per media type an action declares it accepts, wildcards included, and the
     * schema components they reference. A document carries it only when an action
     * declares accepted bodies.
     */
    public const ACCEPTED_BODIES = 11;

    /**
     * The contract that introduced operation examples: an `example` on the JSON:API media
     * type of an operation's request body and success responses, and of each shared error
     * response, built from the examples the server declares. A document carries it only
     * when at least one such example was emitted.
     */
    public const EXAMPLES = 12;

    /**
     * The contract that introduced declared resource links: a named property of a type's
     * `<Type>ResourceLinks` per link its resources declare, a link object carrying a typed
     * `meta` where the link has meta members. A document carries it only when a type
     * declares a link.
     */
    public const DECLARED_LINKS = 14;

    /**
     * The contract that gave each method of an action answering to several its own
     * operation id, `action.<type>.<path>.<method>`, where every method had shared
     * `action.<type>.<path>`. A document carries it only when such an action exists, so
     * every other document keeps its ids and its stamp.
     */
    public const ACTION_METHOD_IDS = 15;

    /**
     * The contract that introduced an action's declared safety: the `x-safety` extension
     * on its operation, an object holding whichever of `readOnly`, `destructive` and
     * `idempotent` the action declares. A document carries it only when an action
     * declares one.
     */
    public const ACTION_SAFETY = 15;

    /**
     * The contract that introduced an action's own vendor extensions, merged over the
     * type-wide ones on its operation. A document carries it only when an action declares
     * one.
     */
    public const ACTION_EXTENSIONS = 15;

    /**
     * The contract that introduced operation scopes: each operation's `security` names the
     * OAuth scopes it needs, and the error catalogue offers `INSUFFICIENT_SCOPE`. A
     * document carries it only when some operation needs a scope.
     */
    public const SCOPES = 16;

    /**
     * The contract that projected a polymorphic relation with a single related type as a
     * union, the shape it has with several: its linkage identifier and related resource
     * are a one-member `anyOf` rather than a `$ref`, and its related and relationship
     * endpoints drop the related type's filter, sort and page parameters. A document
     * carries it only when such a relation exists.
     */
    public const SINGLE_TARGET_POLYMORPHISM = 16;

    /**
     * The `info.x-generator` value for a document, given the scoped structures it uses.
     *
     * @return array{contract: int}
     */
    public static function toArray(
        bool $rawContent = false,
        bool $acceptedBodies = false,
        bool $examples = false,
        bool $declaredLinks = false,
        bool $actionMethodIds = false,
        bool $actionSafety = false,
        bool $actionExtensions = false,
        bool $scopes = false,
        bool $singleTargetPolymorphism = false,
    ): array {
        return ['contract' => \max(
            self::BASELINE,
            $rawContent ? self::RAW_CONTENT : self::BASELINE,
            $acceptedBodies ? self::ACCEPTED_BODIES : self::BASELINE,
            $examples ? self::EXAMPLES : self::BASELINE,
            $declaredLinks ? self::DECLARED_LINKS : self::BASELINE,
            $actionMethodIds ? self::ACTION_METHOD_IDS : self::BASELINE,
            $actionSafety ? self::ACTION_SAFETY : self::BASELINE,
            $actionExtensions ? self::ACTION_EXTENSIONS : self::BASELINE,
            $scopes ? self::SCOPES : self::BASELINE,
            $singleTargetPolymorphism ? self::SINGLE_TARGET_POLYMORPHISM : self::BASELINE,
        )];
    }
}
