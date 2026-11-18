<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource;

use haddowg\JsonApi\OpenApi\Metadata\ActionCreated;
use haddowg\JsonApi\OpenApi\Metadata\ActionResource;
use haddowg\JsonApi\OpenApi\Metadata\DeclaresDocumentMeta;
use haddowg\JsonApi\OpenApi\Metadata\MetaResult;
use haddowg\JsonApi\OpenApi\Metadata\OperationResponseInterface;
use haddowg\JsonApi\OpenApi\Metadata\OperationType;
use haddowg\JsonApi\OpenApi\SchemaProjector;
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\Field\AbstractFieldValue;
use haddowg\JsonApi\Resource\Field\FieldBuilderInterface;
use haddowg\JsonApi\Resource\Field\FieldInterface;
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
     * The declared members, built.
     *
     * @var list<FieldInterface>
     */
    public array $fields;

    /**
     * @param iterable<FieldInterface|FieldBuilderInterface> $fields     the declared members
     * @param bool                                           $collection whether the document is a collection, which keeps core's own members
     * @param string                                         $owner      what declares them, for the messages, such as `the "articles" FetchCollection document`
     *
     * @throws DocumentMetaMisdeclared when two members share a name, or a collection declares a member core owns
     */
    public function __construct(iterable $fields = [], public bool $collection = false, public string $owner = 'the document')
    {
        $built = [];
        foreach ($fields as $field) {
            $field = $field instanceof FieldBuilderInterface ? $field->build() : $field;
            $name = $field->name();
            if (isset($built[$name])) {
                throw new DocumentMetaMisdeclared(\sprintf('The top-level meta of %s declares the member "%s" twice.', $owner, $name));
            }
            if ($collection && \in_array($name, self::COLLECTION_MEMBERS, true)) {
                throw new DocumentMetaMisdeclared(\sprintf(
                    'The top-level meta of %s declares the member "%s", which core writes into every collection document. Rename the member.',
                    $owner,
                    $name,
                ));
            }
            $built[$name] = $field;
        }
        $this->fields = \array_values($built);
    }

    /**
     * The members `$declarer` declares for the document `$operation` answers with. A type
     * that declares nothing, and a delete, have none. A `FetchCollection` document is a
     * collection.
     *
     * @throws DocumentMetaMisdeclared
     */
    public static function forOperation(object $declarer, OperationType $operation, string $type = ''): self
    {
        $owner = \sprintf('the "%s" %s document', $type, $operation->value);
        if ($operation === OperationType::Delete
            || !($declarer instanceof DeclaresDocumentMetaInterface || $declarer instanceof DeclaresDocumentMeta)) {
            return new self([], $operation === OperationType::FetchCollection, $owner);
        }

        return new self($declarer->documentMeta($operation), $operation === OperationType::FetchCollection, $owner);
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
     * The members a declared response's document carries: an {@see ActionResource},
     * {@see ActionCreated} or {@see MetaResult}'s own, and none for anything else.
     */
    public static function forResponse(object $response, string $owner = 'the action response'): self
    {
        return match (true) {
            $response instanceof ActionResource, $response instanceof ActionCreated, $response instanceof MetaResult => new self($response->meta, false, $owner),
            default => new self([], false, $owner),
        };
    }

    /**
     * The members of the document a delete's declared responses answer `200` with: its
     * {@see MetaResult}'s, or none.
     *
     * @param iterable<OperationResponseInterface> $responses
     */
    public static function forDeleteResponses(iterable $responses, string $type = ''): self
    {
        $owner = \sprintf('the "%s" Delete document', $type);
        foreach ($responses as $response) {
            if ($response instanceof MetaResult) {
                return new self($response->meta, false, $owner);
            }
        }

        return new self([], false, $owner);
    }

    public function isEmpty(): bool
    {
        return $this->fields === [];
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
        $values = [];
        foreach ($this->fields as $field) {
            if ($field instanceof AbstractFieldValue && $field->extractsThroughHook()) {
                $values[$field->name()] = $field->serialize($data, $request, $field->name());
            }
        }

        return $values;
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
        if ($this->fields === []) {
            return $meta;
        }

        return [...$this->extract($data, $request), ...$meta];
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
        if ($this->fields === []) {
            return [];
        }

        $schemas = new SchemaProjector();
        $properties = $schemas->projectDocumentMeta($this->fields)->get('properties');
        $properties = \is_array($properties) ? $properties : [];

        $violations = [];
        $declared = [];
        foreach ($this->fields as $field) {
            $name = $field->name();
            $declared[$name] = true;
            if (!\array_key_exists($name, $meta)) {
                if ($schemas->isRequired($field, true)) {
                    $violations[] = \sprintf('the required member "%s" is missing', $name);
                }
                continue;
            }

            $schema = $properties[$name] ?? null;
            $expected = $schema instanceof \haddowg\JsonApi\OpenApi\Schema ? self::jsonTypes($schema->toArray()) : null;
            $actual = self::jsonType($meta[$name]);
            if ($expected !== null && !self::matches($actual, $expected)) {
                $violations[] = \sprintf('the member "%s" is %s, not %s', $name, $actual === 'empty' ? 'an empty array' : $actual, \implode(' or ', $expected));
            }
        }

        foreach (\array_keys($meta) as $name) {
            $name = (string) $name;
            if (!isset($declared[$name]) && !($this->collection && \in_array($name, self::COLLECTION_MEMBERS, true))) {
                $violations[] = \sprintf('the member "%s" is not declared', $name);
            }
        }

        return $violations;
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

    /**
     * The JSON types a projected schema allows, or `null` when it does not say.
     *
     * @param array<array-key, mixed> $schema
     *
     * @return list<string>|null
     */
    private static function jsonTypes(array $schema): ?array
    {
        $type = $schema['type'] ?? null;
        if (\is_string($type)) {
            return [$type];
        }
        if (\is_array($type)) {
            return \array_values(\array_filter($type, \is_string(...)));
        }

        $branches = $schema['anyOf'] ?? $schema['oneOf'] ?? null;
        if (!\is_array($branches)) {
            return null;
        }
        $types = [];
        foreach ($branches as $branch) {
            $branchTypes = \is_array($branch) ? self::jsonTypes($branch) : null;
            if ($branchTypes === null) {
                return null;
            }
            $types = [...$types, ...$branchTypes];
        }

        return \array_values(\array_unique($types));
    }

    private static function jsonType(mixed $value): string
    {
        if ($value instanceof \JsonSerializable) {
            $value = $value->jsonSerialize();
        }

        return match (true) {
            $value === null => 'null',
            \is_bool($value) => 'boolean',
            \is_int($value) => 'integer',
            \is_float($value) => 'number',
            \is_string($value) => 'string',
            \is_array($value) => $value === [] ? 'empty' : (\array_is_list($value) ? 'array' : 'object'),
            default => 'object',
        };
    }

    /**
     * @param list<string> $expected
     */
    private static function matches(string $actual, array $expected): bool
    {
        return match ($actual) {
            'empty' => \in_array('array', $expected, true) || \in_array('object', $expected, true),
            'integer' => \in_array('integer', $expected, true) || \in_array('number', $expected, true),
            default => \in_array($actual, $expected, true),
        };
    }
}
