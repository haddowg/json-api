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
 * is warned only by the documents that contain it. There are twenty-five scoped
 * structures today: an action's non-JSON:API response bodies ({@see RAW_CONTENT}), the
 * request bodies it declares it accepts ({@see ACCEPTED_BODIES}), the worked example
 * on an operation's request and responses ({@see EXAMPLES}), the named links a
 * resource declares ({@see DECLARED_LINKS}), the per-method operation ids of an action
 * answering to several methods ({@see ACTION_METHOD_IDS}), an action's declared safety
 * ({@see ACTION_SAFETY}), its own vendor extensions ({@see ACTION_EXTENSIONS}), the
 * scopes an operation needs ({@see SCOPES}), a polymorphic relation with a single
 * related type ({@see SINGLE_TARGET_POLYMORPHISM}), an action answering with a URL
 * ({@see ACTION_LOCATION}), the `type`-discriminated unions of a polymorphic relation
 * or a typed `included` ({@see DISCRIMINATED_UNIONS}), a `sort` parameter accepting
 * a family of keys by prefix ({@see SORT_FAMILIES}), a list attribute whose items are
 * objects or a discriminated union ({@see STRUCTURED_LIST_ELEMENTS}), a deprecation
 * marker ({@see DEPRECATION}), a collection action's filter parameters
 * ({@see ACTION_FILTERS}), an action answering `201 Created` ({@see ACTION_CREATED}),
 * a related-only type ({@see RELATED_ONLY}), a `filter` parameter accepting a family of
 * keys by prefix ({@see FILTER_FAMILIES}), a filter taking its list as
 * `filter[<key>][]` ({@see LIST_FILTERS}), the declared members of a linkage
 * identifier's `meta` ({@see IDENTIFIER_META}), an object open to members it does
 * not declare ({@see OPEN_OBJECTS}), the queried link rows of a polymorphic to-many
 * identified by stored pairs ({@see IDENTIFIED_PIVOTS}), the error code of a relation
 * whose members are fixed ({@see FIXED_MEMBERSHIP}), a value of several primitive
 * types ({@see UNIONS}) and a field's shape declared as a named component
 * ({@see NAMED_COMPONENTS}).
 * A change that moves every
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
    public const CONTRACT = 36;

    /**
     * The contract a document carries when it uses none of the scoped structures below:
     * the shape every document shares. Bump it, with {@see CONTRACT}, when a change moves
     * that shape. Contract 13 did, giving every core error code a `detail` narrowing with
     * its example. Contract 18 did again, replacing each type's and relation's links
     * components with one shared component per link context. Contract 19 did too, since
     * the discriminated unions of {@see DISCRIMINATED_UNIONS} reach nearly every
     * document through its typed `included`, so every document now carries 19 and the
     * scoped tiers below name only the contract that first introduced each structure.
     * Contract 23 did again, closing every attributes component and declared nested
     * object with `additionalProperties: false`. Contract 33 did too, giving each
     * operation that raises scoped error codes an inline error response and an error
     * document of its own, named for the operation rather than for the codes. Contract 34
     * did again, writing every schema example as an `examples` list, giving the `sort`
     * parameter an example and its keys' descriptions under `x-sort-descriptions`, giving
     * filter and sort family entries a `description` and a filter family's an `example`,
     * and a relationship object an example. Contract 35 did again, moving the atomic
     * relationship operations' identifier union and its array into the `AtomicIdentifier`
     * and `AtomicIdentifiers` components they reference, and offering each error code
     * tied to an {@see \haddowg\JsonApi\Exception\ErrorFeature} only on the operations
     * that can raise it, so most writes and some reads gained error documents of their own.
     * Contract 36 did again, offering each of core's codes, feature-gated or not, only on the
     * operations whose runtime can raise it, so nearly every operation answers `400` and
     * `404` with an error document of its own, and codes that shared a shape by feature now
     * share one only with the codes following the same rule.
     */
    public const BASELINE = 36;

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
     * are a one-member union rather than a `$ref`, and its related and relationship
     * endpoints drop the related type's filter, sort and page parameters. A document
     * carries it only when such a relation exists.
     */
    public const SINGLE_TARGET_POLYMORPHISM = 16;

    /**
     * The contract that introduced an action answering with a URL: an action declaring
     * {@see Metadata\SeeOther} with a media type documents a `200` location document beside
     * its `303`, through the shared `LocationDocument` and `LocationLink` components. A
     * document carries it only when an action declares one.
     */
    public const ACTION_LOCATION = 17;

    /**
     * The contract that made the projection's resource unions discriminated: a
     * polymorphic relation's linkage identifier and related resource are a `oneOf` with a
     * `type` discriminator mapping each type to its `<Type>ResourceIdentifier` or
     * `<Type>Resource`, and a compound document's `included` is a `oneOf` over the
     * `<Type>Resource` of every type its `?include` paths reach, in place of an untyped
     * `{type, id}` item. The atomic operation is a `oneOf` over one component per kind,
     * each carrying a discriminated `data` union. A document carries it when it has a
     * polymorphic relation, a typed `included` or the atomic endpoint, which nearly
     * every document does, so {@see BASELINE} carries it too.
     */
    public const DISCRIMINATED_UNIONS = 19;

    /**
     * The contract that introduced sort families: a `sort` parameter whose items are an
     * `anyOf` of the fixed-key enum and one `pattern` per family of keys matched by
     * prefix, with the families listed under the array schema's `x-sort-families`. A
     * document carries it only when some `sort` parameter accepts a family.
     */
    public const SORT_FAMILIES = 20;

    /**
     * The contract that introduced structured list elements: an `array` attribute, or an
     * array member nested in one, whose `items` is an object schema built from child
     * fields or a `oneOf` with a `discriminator` over such objects, rather than a scalar.
     * A document carries it only when some attribute declares one.
     */
    public const STRUCTURED_LIST_ELEMENTS = 21;

    /**
     * The contract that introduced deprecation markers: `deprecated: true` on an operation,
     * a `filter[<key>]` parameter or an attribute or relationship property, the `sort`
     * schema's `x-deprecated-sorts` list and a `deprecated` member on an `x-sort-families`
     * entry. A document carries it only when something it describes is declared
     * deprecated.
     */
    public const DEPRECATION = 22;

    /**
     * The contract that introduced action filters: the `filter[<key>]` query parameters
     * of a collection-scope action that takes its mount type's filters, shaped as that
     * type's collection fetch shapes them. A document carries it only when such an action
     * takes at least one filter.
     */
    public const ACTION_FILTERS = 25;

    /**
     * The contract that introduced an action answering `201 Created`
     * ({@see Metadata\ActionCreated}): a `201` success response on an action's operation,
     * carrying the named type's resource document and an optional `Location` header. A
     * document carries it only when an action declares one.
     */
    public const ACTION_CREATED = 26;

    /**
     * The contract that introduced related-only types ({@see Metadata\DeclaresRelatedOnly}):
     * a `<Type>ResourceLinks` that lists no `self` and doesn't extend the shared
     * `ResourceLinks`. A document carries it only when a type is related-only.
     */
    public const RELATED_ONLY = 27;

    /**
     * The contract that introduced filter families: one `filter` parameter in `deepObject`
     * style whose object schema matches each family's `<prefix>.<suffix>` keys with
     * `patternProperties`, listing the families under `x-filter-families`. A document
     * carries it only when some endpoint accepts a filter family.
     */
    public const FILTER_FAMILIES = 27;

    /**
     * The contract that introduced list filters: a `filter[<key>][]` parameter carrying
     * the `x-filter-list` extension for a filter that takes its value as an array, beside
     * the `filter[<key>]` parameter only while the filter also takes a delimited string.
     * A document carries it only when some filter declares a list.
     */
    public const LIST_FILTERS = 27;

    /**
     * The contract that introduced declared identifier meta: a relation's
     * `<Type><Rel>IdentifierMeta` component, typing the members its `identifierMeta()`
     * resolver adds to each response linkage identifier's `meta`, beside any `pivot`. A
     * document carries it only when a relation declares such members.
     */
    public const IDENTIFIER_META = 28;

    /**
     * The contract that introduced open objects: an object schema built from declared
     * child fields whose `additionalProperties` is a schema describing every member it
     * does not declare, rather than `false`, optionally bounded by `minProperties` and
     * `maxProperties`. A document carries it only when some field declares one.
     */
    public const OPEN_OBJECTS = 29;

    /**
     * The contract that let a polymorphic to-many identified by stored pairs query its
     * link rows: its relationship endpoint takes the `filter[<key>]` and `sort` parameters
     * its related endpoint takes, and its linkage identifiers carry a typed `meta.pivot`
     * with the request and response shapes a monomorphic pivot relation has. A document
     * carries it only when such a relation declares pivot fields, filters or sorts.
     */
    public const IDENTIFIED_PIVOTS = 30;

    /**
     * The contract that introduced fixed membership: the `MEMBERSHIP_CHANGE_PROHIBITED`
     * code in the error catalogue's `403` shape, refusing a replacement that changes the
     * members of a relation declaring `cannotChangeMembership()`. A document carries it
     * only when such a relation exists.
     */
    public const FIXED_MEMBERSHIP = 30;

    /**
     * The contract that introduced primitive unions: a schema whose `type` lists several
     * JSON types, each member's type-scoped keywords beside it, or, when a member carries
     * a keyword not scoped to its type such as `enum`, a `oneOf` of one schema per member.
     * A document carries it only when some field declares a {@see \haddowg\JsonApi\Resource\Field\Union}.
     */
    public const UNIONS = 31;

    /**
     * The contract that introduced named shape components: a field's value shape declared
     * with `component()` projects once as `#/components/schemas/<Name>`, with
     * `<Name>Create` and `<Name>Update` where a write's shape differs, and each use is a
     * `$ref` to it carrying the use's own description, example, deprecation, non-empty
     * keywords and `null`. A document carries it only when it emits such a component.
     */
    public const NAMED_COMPONENTS = 32;

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
        bool $actionLocations = false,
        bool $discriminatedUnions = false,
        bool $sortFamilies = false,
        bool $structuredListElements = false,
        bool $deprecation = false,
        bool $actionFilters = false,
        bool $actionCreated = false,
        bool $relatedOnly = false,
        bool $filterFamilies = false,
        bool $listFilters = false,
        bool $identifierMeta = false,
        bool $openObjects = false,
        bool $identifiedPivots = false,
        bool $fixedMembership = false,
        bool $unions = false,
        bool $namedComponents = false,
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
            $actionLocations ? self::ACTION_LOCATION : self::BASELINE,
            $discriminatedUnions ? self::DISCRIMINATED_UNIONS : self::BASELINE,
            $sortFamilies ? self::SORT_FAMILIES : self::BASELINE,
            $structuredListElements ? self::STRUCTURED_LIST_ELEMENTS : self::BASELINE,
            $deprecation ? self::DEPRECATION : self::BASELINE,
            $actionFilters ? self::ACTION_FILTERS : self::BASELINE,
            $actionCreated ? self::ACTION_CREATED : self::BASELINE,
            $relatedOnly ? self::RELATED_ONLY : self::BASELINE,
            $filterFamilies ? self::FILTER_FAMILIES : self::BASELINE,
            $listFilters ? self::LIST_FILTERS : self::BASELINE,
            $identifierMeta ? self::IDENTIFIER_META : self::BASELINE,
            $openObjects ? self::OPEN_OBJECTS : self::BASELINE,
            $identifiedPivots ? self::IDENTIFIED_PIVOTS : self::BASELINE,
            $fixedMembership ? self::FIXED_MEMBERSHIP : self::BASELINE,
            $unions ? self::UNIONS : self::BASELINE,
            $namedComponents ? self::NAMED_COMPONENTS : self::BASELINE,
        )];
    }
}
