<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource;

use haddowg\JsonApi\OpenApi\Metadata\Accepted;
use haddowg\JsonApi\OpenApi\Metadata\ActionCreated;
use haddowg\JsonApi\OpenApi\Metadata\ActionResource;
use haddowg\JsonApi\OpenApi\Metadata\DeclaresDocumentMeta;
use haddowg\JsonApi\OpenApi\Metadata\MetaResult;
use haddowg\JsonApi\OpenApi\Metadata\OperationResponseInterface;
use haddowg\JsonApi\OpenApi\Metadata\OperationType;
use haddowg\JsonApi\OpenApi\SchemaProjector;
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\Field\AdditionalProperties;
use haddowg\JsonApi\Resource\Field\FieldBuilderInterface;
use haddowg\JsonApi\Resource\Field\FieldInterface;
use haddowg\JsonApi\Response\DataResponse;
use haddowg\JsonApi\Response\MetaResponse;
use haddowg\JsonApi\Response\RelatedResponse;
use haddowg\JsonApi\Schema\Profile\CountableProfile;
use haddowg\JsonApi\Serializer\DeclaresDocumentMetaInterface;

/**
 * The declared members of one document's top-level `meta`: what a type's
 * {@see DeclaresDocumentMetaInterface::documentMeta()} returns for an operation, or what an
 * {@see ActionResource}, {@see ActionCreated} or {@see MetaResult} declares.
 *
 * Adapters and the OpenAPI projection share it. An adapter builds one per operation when it
 * registers the type, which refuses a misdeclaration ({@see DocumentMetaMisdeclared}) before
 * any request; fills the members their `extractUsing()` hooks supply with {@see fill()};
 * and, in debug only, checks the rendered `meta` with {@see assertSatisfiedBy()}.
 *
 * A collection document keeps the members core writes into it, `page` and `total`, beside
 * the declared ones. Declaring either of those on a collection is refused.
 */
final readonly class DocumentMeta
{
    /**
     * The top-level `meta` members core owns on a collection document: the page's members,
     * and the total `withCount=_self_` asks for.
     */
    public const array COLLECTION_MEMBERS = ['page', CountableProfile::META_MEMBER];

    /**
     * The declared members, built, without the {@see AdditionalProperties} entry.
     *
     * @var list<FieldInterface>
     */
    public array $fields;

    /**
     * The entry that opens the object, if any.
     */
    public ?AdditionalProperties $additional;

    /**
     * @param iterable<FieldInterface|FieldBuilderInterface> $fields     the declared members, at most one of them an {@see AdditionalProperties} entry
     * @param bool                                           $collection whether the document is a collection, which keeps core's own members
     * @param string                                         $owner      what declares them, for the messages, such as `the "articles" FetchCollection document`
     *
     * @throws DocumentMetaMisdeclared when two members share a name, a collection declares a member core owns, or two entries are declared
     */
    public function __construct(iterable $fields = [], public bool $collection = false, public string $owner = 'the document')
    {
        $shape = MetaShape::of($fields, $owner, DocumentMetaMisdeclared::class);
        foreach ($shape->fields as $field) {
            if ($collection && \in_array($field->name(), self::COLLECTION_MEMBERS, true)) {
                throw new DocumentMetaMisdeclared(\sprintf(
                    'The top-level meta of %s declares the member "%s", which core writes into every collection document. Rename the member.',
                    $owner,
                    $field->name(),
                ));
            }
        }
        $this->fields = $shape->fields;
        $this->additional = $shape->additional;
    }

    /**
     * The declaration as a {@see MetaShape}.
     */
    public function shape(): MetaShape
    {
        return MetaShape::of($this->additional === null ? $this->fields : [...$this->fields, $this->additional], $this->owner);
    }

    /**
     * Every declared entry, the members and the {@see AdditionalProperties} entry, as a
     * response's metadata keeps them.
     *
     * @return list<FieldInterface>
     */
    public function declaration(): array
    {
        return $this->additional === null ? $this->fields : [...$this->fields, $this->additional];
    }

    /**
     * The members `$declarer` declares for the document `$operation` answers with. A type
     * that declares nothing has none. A `FetchCollection` document is a collection. A
     * delete's members apply only to the meta-only `200` it may answer with; see
     * {@see forDelete()}. A relationship write's case, such as
     * {@see OperationType::AddRelationship}, gives the members of the meta-only `200` it
     * answers with in place of its linkage, which none means it does not; a `202`'s are read
     * through {@see forAccepted()}. `$relation` names the relation a relationship write
     * case is for, and is ignored for any other case.
     *
     * @throws DocumentMetaMisdeclared
     */
    public static function forOperation(object $declarer, OperationType $operation, string $type = '', ?string $relation = null): self
    {
        $relation = $operation->isRelationshipWrite() ? $relation : null;
        $owner = $relation === null
            ? \sprintf('the "%s" %s document', $type, $operation->value)
            : \sprintf('the "%s" %s document of "%s"', $type, $operation->value, $relation);

        return new self(self::declared($declarer, $operation, $relation), $operation === OperationType::FetchCollection, $owner);
    }

    /**
     * What `$declarer` returns from `documentMeta()` for `$operation`, or nothing when it
     * declares no document meta.
     *
     * @return iterable<FieldInterface|FieldBuilderInterface>
     */
    private static function declared(object $declarer, OperationType $operation, ?string $relation = null): iterable
    {
        if (!$declarer instanceof DeclaresDocumentMetaInterface && !$declarer instanceof DeclaresDocumentMeta) {
            return [];
        }

        return $declarer->documentMeta($operation, $relation);
    }

    /**
     * The members of a related endpoint's document, which serves `$declarer`, the related
     * type: its `FetchCollection` members for a to-many and its `FetchOne` members for a
     * to-one.
     *
     * @throws DocumentMetaMisdeclared
     */
    public static function forRelated(object $declarer, bool $toMany, string $relatedType = ''): self
    {
        return self::forOperation($declarer, $toMany ? OperationType::FetchCollection : OperationType::FetchOne, $relatedType);
    }

    /**
     * The members of the job document the `202` of `$operation`, a create or an update,
     * answers with: the ones its declared {@see Accepted} carries, else the ones `$declarer`
     * declares for {@see OperationType::CreateAccepted} or {@see OperationType::UpdateAccepted}.
     *
     * @param iterable<object> $responses the write's declared success responses
     *
     * @throws DocumentMetaMisdeclared when both declare members
     */
    public static function forAccepted(object $declarer, OperationType $operation, iterable $responses = [], string $type = ''): self
    {
        $accepted = $operation->accepted() ?? throw new \InvalidArgumentException(\sprintf('Only a create or an update answers a 202; %s does not.', $operation->value));
        $owner = \sprintf('the "%s" %s 202 document', $type, $operation->value);
        $own = [];
        foreach ($responses as $response) {
            if ($response instanceof Accepted) {
                $own = $response->meta;
            }
        }
        $declared = new self(self::declared($declarer, $accepted), false, $owner);
        if ($own !== [] && !$declared->isEmpty()) {
            throw new DocumentMetaMisdeclared(\sprintf('The top-level meta of %s is declared both in documentMeta(OperationType::%s) and on its Accepted response; declare it in one place.', $owner, $accepted->name));
        }

        return $own !== [] ? new self($own, false, $owner) : $declared;
    }

    /**
     * The members a declared response's document carries: an {@see ActionResource},
     * {@see ActionCreated} or {@see MetaResult}'s own, and none for anything else.
     */
    public static function forResponse(object $response, string $owner = 'the action response'): self
    {
        return match (true) {
            $response instanceof ActionResource, $response instanceof ActionCreated, $response instanceof MetaResult, $response instanceof Accepted => new self($response->meta, false, $owner),
            default => new self([], false, $owner),
        };
    }

    /**
     * The members of the meta-only `200` a delete answers with: the type's `Delete` members,
     * or the ones its declared {@see MetaResult} carries. None when the delete declares no
     * `200`, as a `204` has no document.
     *
     * @param object                               $declarer  the type's declarer, or its `Delete` members already read
     * @param iterable<OperationResponseInterface> $responses the delete's declared success responses
     *
     * @throws DocumentMetaMisdeclared when the type and the {@see MetaResult} both declare members
     */
    public static function forDelete(object $declarer, iterable $responses, string $type = ''): self
    {
        $owner = \sprintf('the "%s" Delete document', $type);
        $result = null;
        foreach ($responses as $response) {
            if ($response->status() === 200) {
                $result = $response;
            }
        }
        if ($result === null) {
            return new self([], false, $owner);
        }

        $declared = $declarer instanceof self ? $declarer : self::forOperation($declarer, OperationType::Delete, $type);
        $own = $result instanceof MetaResult ? $result->meta : [];
        if (!$declared->isEmpty() && $own !== []) {
            throw new DocumentMetaMisdeclared(\sprintf('The top-level meta of %s is declared both in documentMeta() and on its MetaResult; declare it in one place.', $owner));
        }

        return $own !== [] ? new self($own, false, $owner) : $declared;
    }

    /**
     * The members of the document an action answers `$status` with, among its declared
     * responses: its {@see ActionCreated} for a `201`, its {@see Accepted} for a `202`, else
     * its {@see ActionResource} or {@see MetaResult}.
     *
     * @param iterable<object> $responds the action's declared responses
     */
    public static function forActionStatus(iterable $responds, int $status, string $owner = 'the action response'): self
    {
        foreach ($responds as $response) {
            $matches = match ($status) {
                201 => $response instanceof ActionCreated,
                202 => $response instanceof Accepted,
                default => $response instanceof ActionResource || $response instanceof MetaResult,
            };
            if ($matches) {
                return self::forResponse($response, $owner);
            }
        }

        return new self([], false, $owner);
    }

    public function isEmpty(): bool
    {
        return $this->fields === [] && $this->additional === null;
    }

    /**
     * Whether some member is declared `required()`, which makes `meta` required.
     */
    public function hasRequired(): bool
    {
        $schemas = new SchemaProjector();
        foreach ($this->fields as $field) {
            if ($schemas->isRequired($field, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The values of the members an `extractUsing()` hook supplies, each hook called with the
     * document's primary data (one resource, or the page of resources) and the request, and
     * rendered through the member's declared shape. A member with no hook is left out.
     *
     * @return array<string, mixed>
     */
    public function extract(mixed $data, JsonApiRequestInterface $request): array
    {
        return $this->shape()->extract($data, $request, DocumentMetaMismatch::class);
    }

    /**
     * `$meta` with each member an `extractUsing()` hook supplies filled in, unless the
     * handler or a hook already set it: a value set through `withMeta()` wins.
     *
     * @param array<string, mixed> $meta the `meta` already set on the response
     *
     * @return array<string, mixed>
     */
    public function fill(array $meta, mixed $data, JsonApiRequestInterface $request): array
    {
        if ($this->isEmpty()) {
            return $meta;
        }

        return [...$this->extract($data, $request), ...$meta];
    }

    /**
     * `$response` with each member an `extractUsing()` hook supplies filled into its `meta`,
     * as {@see fill()} does. The hooks receive the response's primary data: its page of
     * resources as a list for a collection, else its one resource or `null`. A meta-only
     * response has none, so its hooks receive `$subject`, such as the resource a delete
     * removed. A collection answered with one resource, as a singular filter is, hands its
     * hooks that resource. An adapter calls it once the handler and its After hooks have
     * built the response.
     *
     * @template T of DataResponse|RelatedResponse|MetaResponse
     *
     * @param T $response
     *
     * @return T
     */
    public function applyTo(DataResponse|RelatedResponse|MetaResponse $response, JsonApiRequestInterface $request, mixed $subject = null): DataResponse|RelatedResponse|MetaResponse
    {
        if (!$this->extractsAny()) {
            return $response;
        }

        if ($response instanceof MetaResponse) {
            $data = $subject;
        } else {
            $resources = $response->primaryResources();
            $data = $response->isCollection() ? $resources : ($resources[0] ?? null);
        }

        return $response->withMeta($this->fill($response->meta(), $data, $request));
    }

    private function extractsAny(): bool
    {
        return $this->shape()->extractsAny();
    }

    /**
     * What is wrong with a rendered top-level `meta` against the declaration: a required
     * member missing, a member it does not declare, or a member of the wrong JSON type. A
     * collection's core members are always allowed. Empty when it conforms, and always for a
     * declaration with no members, whose document stays open.
     *
     * @param array<array-key, mixed> $meta
     *
     * @return list<string>
     */
    public function violations(array $meta): array
    {
        return $this->shape()->violations($meta, $this->collection ? self::COLLECTION_MEMBERS : []);
    }

    /**
     * Refuses a rendered top-level `meta` that does not match the declaration. An adapter
     * calls it in debug only.
     *
     * @param array<array-key, mixed> $meta
     *
     * @throws DocumentMetaMismatch
     */
    public function assertSatisfiedBy(array $meta): void
    {
        $violations = $this->violations($meta);
        if ($violations !== []) {
            throw new DocumentMetaMismatch(\sprintf(
                'The top-level meta of %s does not match its declaration: %s.',
                $this->owner,
                \implode('; ', $violations),
            ));
        }
    }
}
