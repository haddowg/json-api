<?php

declare(strict_types=1);

namespace haddowg\JsonApi\OpenApi;

use haddowg\JsonApi\Exception\ErrorCatalog;
use haddowg\JsonApi\Exception\ErrorDescriptor;
use haddowg\JsonApi\Exception\ErrorFeature;
use haddowg\JsonApi\OpenApi\Metadata\OperationType;
use haddowg\JsonApi\OpenApi\Metadata\ServerMetadataInterface;
use haddowg\JsonApi\Pagination\CursorPaginationProfile;
use haddowg\JsonApi\Pagination\MultiPaginator;
use haddowg\JsonApi\Schema\Profile\CountableProfile;

/**
 * Projects core's {@see ErrorCatalog} into one named schema component per error code,
 * and the `anyOf` that offers them from `ErrorDocument.errors.items`.
 *
 * A variant narrows the shared `Error` rather than restating it: `allOf: [$ref Error,
 * {code: const, status: const, …}]`. So a generator reads the code off
 * `allOf[1].properties.code.const` and emits a typed exception subclass, while the
 * member vocabulary stays in one place and a variant cannot drift from it.
 *
 * **The `anyOf` is a catalogue, not a constraint.** Its first branch is the open generic
 * `Error`, which every error object satisfies — including one carrying an application's
 * own code that core has never heard of. That is deliberate: a closed `oneOf` would
 * make a server's own error documents fail its own published schema the first time the
 * application threw something of its own ([ADR 0136](../../docs/adr/0136-open-error-code-catalogue-in-the-projected-document.md)).
 *
 * The projection is registration-aware, like the rest of the document (ADR 0131): a
 * code whose descriptor names an {@see ErrorFeature} the server does not offer is left
 * out entirely. A read-only server's client has no use for a typed
 * `RESOURCE_TYPE_UNACCEPTABLE` it can never receive.
 *
 * @internal
 */
final class ErrorCatalogProjector
{
    /**
     * The per-code variant components, keyed by component name, in catalogue order —
     * restricted to the codes this server can actually raise.
     *
     * @return array<string, Schema>
     */
    public function components(ServerMetadataInterface $server): array
    {
        $components = [];
        foreach (ErrorCatalog::descriptors() as $descriptor) {
            if ($descriptor->feature !== null && !$this->offers($descriptor->feature, $server)) {
                continue;
            }

            $components[self::componentName($descriptor->code)] = $this->variant($descriptor);
        }

        return $components;
    }

    /**
     * The schema for one entry of `ErrorDocument.errors`: the open generic `Error`
     * first, then every catalogued variant.
     *
     * @param list<string> $components the variant component names, in emit order
     */
    public function errorsItemSchema(array $components): Schema
    {
        $branches = [Schema::ref(ComponentNaming::schemaRef('Error'))];
        foreach ($components as $component) {
            $branches[] = Schema::ref(ComponentNaming::schemaRef($component));
        }

        return Schema::create()
            ->withDescription(
                'A JSON:API error object. The named branches catalogue the codes this server '
                . 'documents; the leading generic `Error` keeps the list open, so an error '
                . 'carrying an undocumented `code` is still a valid error object.',
            )
            ->withAnyOf($branches);
    }

    /**
     * The component name for a code: `FILTERING_UNRECOGNIZED` → `FilteringUnrecognizedError`.
     * The suffix keeps a code clear of the shared `Error` / `ErrorSource` / `ErrorDocument`
     * components and of a resource type's component set, and is not doubled on a code that
     * already ends in it (`APPLICATION_ERROR` → `ApplicationError`).
     */
    public static function componentName(string $code): string
    {
        $base = ComponentNaming::base(\strtolower($code));

        return \str_ends_with($base, 'Error') ? $base : $base . 'Error';
    }

    /**
     * One code's variant: the shared `Error`, narrowed by the two members a client may
     * dispatch on (`code` and `status` are the machine contract and are never
     * resolver-overridable) plus the `source` member the exception always fills.
     *
     * `title` stays a plain string — a bound
     * {@see \haddowg\JsonApi\Schema\Error\ErrorMessageResolverInterface} replaces it per
     * locale — so core's default is carried as the schema's `title` annotation instead
     * of a `const` the server would then violate.
     */
    private function variant(ErrorDescriptor $descriptor): Schema
    {
        $required = ['code', 'status'];
        $narrowing = Schema::ofType('object')
            ->withProperty('code', Schema::ofType('string')->withConst($descriptor->code))
            ->withProperty('status', Schema::ofType('string')->withConst((string) $descriptor->status));

        if ($descriptor->source !== null) {
            $narrowing = $narrowing->withProperty(
                'source',
                Schema::ofType('object')->withRequired([$descriptor->source->value]),
            );
            $required[] = 'source';
        }

        $variant = Schema::create()
            ->withTitle($descriptor->title)
            ->withAllOf([
                Schema::ref(ComponentNaming::schemaRef('Error')),
                $narrowing->withRequired($required),
            ]);

        if ($descriptor->context === []) {
            return $variant;
        }

        // Not a wire member: `Error::$context` is the interpolation input core fills into
        // the `title` / `detail` templates, so it is published as an extension naming the
        // `{placeholder}` tokens a replacement template may use (ADR 0128), not as a
        // property the response will carry.
        $context = [];
        foreach ($descriptor->context as $name => $type) {
            $context[$name] = Schema::ofType($type->value);
        }

        return $variant->withExtension('error-context', $context);
    }

    /**
     * Whether the server offers the capability an error depends on. Each arm answers the
     * question the {@see ErrorFeature} case states.
     */
    private function offers(ErrorFeature $feature, ServerMetadataInterface $server): bool
    {
        return match ($feature) {
            ErrorFeature::AtomicOperations => $server->atomicOperations() !== null,
            ErrorFeature::CursorPagination => $this->anyPageSchema(
                $server,
                static fn(Schema $page): bool => self::carriesCursorProfile($page),
            ),
            ErrorFeature::PaginationMenu => $this->anyPageSchema(
                $server,
                static fn(Schema $page): bool => \is_array($page->get('oneOf')),
            ),
            ErrorFeature::RelationshipCounts => \in_array(CountableProfile::URI, $server->profiles(), true),
            ErrorFeature::ClientGeneratedIds => $this->anyType(
                $server,
                static fn($type): bool => $type->allowsClientId(),
            ),
            ErrorFeature::Writes => $this->exposesAWrite($server),
        };
    }

    /**
     * Whether any collection — a type's or a relationship's — has a page schema
     * satisfying `$predicate`.
     *
     * @param \Closure(Schema): bool $predicate
     */
    private function anyPageSchema(ServerMetadataInterface $server, \Closure $predicate): bool
    {
        foreach ($server->types() as $type) {
            $page = $type->pageSchema();
            if ($page !== null && $predicate($page)) {
                return true;
            }

            foreach ($type->relations() as $relation) {
                $relationPage = $relation->pageSchema();
                if ($relationPage !== null && $predicate($relationPage)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Whether a page schema paginates by cursor — the strategy marks itself with the
     * cursor profile URI, bare or as one arm of a {@see MultiPaginator} menu.
     */
    private static function carriesCursorProfile(Schema $page): bool
    {
        foreach (self::branches($page) as $branch) {
            if ($branch->extension('profile') === CursorPaginationProfile::URI) {
                return true;
            }
        }

        return false;
    }

    /**
     * A page schema and, when it is a menu, each of its `oneOf` arms.
     *
     * @return \Generator<int, Schema>
     */
    private static function branches(Schema $page): \Generator
    {
        yield $page;

        $oneOf = $page->get('oneOf');
        if (!\is_array($oneOf)) {
            return;
        }

        foreach ($oneOf as $branch) {
            if ($branch instanceof Schema) {
                yield $branch;
            }
        }
    }

    /**
     * Whether any registered type satisfies `$predicate`.
     *
     * @param \Closure(\haddowg\JsonApi\OpenApi\Metadata\TypeMetadataInterface): bool $predicate
     */
    private function anyType(ServerMetadataInterface $server, \Closure $predicate): bool
    {
        foreach ($server->types() as $type) {
            if ($predicate($type)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the server accepts a request document anywhere: a CRUD write on some type,
     * or the atomic batch endpoint. Everything that parses or hydrates a body hangs off
     * this.
     *
     * A relationship mutation needs no branch of its own: it rides on its type's
     * {@see OperationType::Update}, so a type permissive enough to project one is already
     * caught by the CRUD loop.
     */
    private function exposesAWrite(ServerMetadataInterface $server): bool
    {
        if ($server->atomicOperations() !== null) {
            return true;
        }

        foreach ($server->types() as $type) {
            foreach ([OperationType::Create, OperationType::Update, OperationType::Delete] as $operation) {
                if (\in_array($operation, $type->operations(), true)) {
                    return true;
                }
            }
        }

        return false;
    }
}
