<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Testing;

use haddowg\JsonApi\Operation\CreateResourceOperation;
use haddowg\JsonApi\Operation\DeleteResourceOperation;
use haddowg\JsonApi\Operation\FetchResourceOperation;
use haddowg\JsonApi\Operation\JsonApiOperation;
use haddowg\JsonApi\Operation\OperationContext;
use haddowg\JsonApi\Operation\QueryParameters;
use haddowg\JsonApi\Operation\Target;
use haddowg\JsonApi\Operation\UpdateResourceOperation;
use haddowg\JsonApi\Request\JsonApiRequest;
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Server\ServerInterface;

/**
 * Fluent builder for {@see JsonApiOperation} value objects, for programmatic-
 * dispatch tests that pair with {@see \haddowg\JsonApi\Server\Server::dispatch()}:
 *
 * ```php
 * $operation = JsonApiOperationBuilder::create('posts', $server)
 *     ->withAttribute('title', 'Hello')
 *     ->withRelationship('author', type: 'users', id: '42')
 *     ->build();
 * ```
 *
 * Body-carrying verbs (create/update) assemble a {@see JsonApiRequest} from the
 * declared attributes/relationships; bodyless verbs (fetch/delete) ignore them.
 * A {@see ServerInterface} is required for the {@see OperationContext}.
 */
final class JsonApiOperationBuilder
{
    /**
     * @var array<string, mixed>
     */
    private array $attributes = [];

    /**
     * @var array<string, array{data: array<string, mixed>|list<array<string, mixed>>}>
     */
    private array $relationships = [];

    private function __construct(
        private readonly string $verb,
        private readonly string $type,
        private readonly ServerInterface $server,
        private readonly ?string $id = null,
    ) {}

    public static function create(string $type, ServerInterface $server): self
    {
        return new self('create', $type, $server);
    }

    public static function update(string $type, string $id, ServerInterface $server): self
    {
        return new self('update', $type, $server, $id);
    }

    public static function fetch(string $type, string $id, ServerInterface $server): self
    {
        return new self('fetch', $type, $server, $id);
    }

    public static function delete(string $type, string $id, ServerInterface $server): self
    {
        return new self('delete', $type, $server, $id);
    }

    public function withAttribute(string $name, mixed $value): self
    {
        $this->attributes[$name] = $value;

        return $this;
    }

    public function withRelationship(string $name, string $type, string $id): self
    {
        $this->relationships[$name] = ['data' => ['type' => $type, 'id' => $id]];

        return $this;
    }

    /**
     * @param list<array{type: string, id: string}> $identifiers
     */
    public function withRelationships(string $name, array $identifiers): self
    {
        $data = \array_map(
            static fn(array $identifier): array => ['type' => $identifier['type'], 'id' => $identifier['id']],
            $identifiers,
        );
        $this->relationships[$name] = ['data' => $data];

        return $this;
    }

    public function build(): \haddowg\JsonApi\Operation\JsonApiOperationInterface
    {
        $target = new Target($this->type, $this->id);
        $context = new OperationContext($this->server);
        $query = new QueryParameters([], [], [], [], []);

        return match ($this->verb) {
            'create' => new CreateResourceOperation($target, $query, $context, $this->body()),
            'update' => new UpdateResourceOperation($target, $query, $context, $this->body()),
            'fetch' => new FetchResourceOperation($target, $query, $context),
            'delete' => new DeleteResourceOperation($target, $query, $context),
            default => throw new \LogicException("Unknown verb '{$this->verb}'."),
        };
    }

    private function body(): JsonApiRequestInterface
    {
        $resource = ['type' => $this->type];
        if ($this->id !== null) {
            $resource['id'] = $this->id;
        }
        if ($this->attributes !== []) {
            $resource['attributes'] = $this->attributes;
        }
        if ($this->relationships !== []) {
            $resource['relationships'] = $this->relationships;
        }

        $method = $this->verb === 'create' ? 'POST' : 'PATCH';

        return new JsonApiRequest(
            Internal\RequestStub::psr($method)->withParsedBody(['data' => $resource]),
        );
    }
}
