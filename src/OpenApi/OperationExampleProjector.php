<?php

declare(strict_types=1);

namespace haddowg\JsonApi\OpenApi;

use haddowg\JsonApi\OpenApi\Metadata\Accepted;
use haddowg\JsonApi\OpenApi\Metadata\AcceptedBody;
use haddowg\JsonApi\OpenApi\Metadata\ActionInputMode;
use haddowg\JsonApi\OpenApi\Metadata\ActionMetadataInterface;
use haddowg\JsonApi\OpenApi\Metadata\ActionResource;
use haddowg\JsonApi\OpenApi\Metadata\ActionScope;
use haddowg\JsonApi\OpenApi\Metadata\DeclaresActionExample;
use haddowg\JsonApi\OpenApi\Metadata\DeclaresOperationExamples;
use haddowg\JsonApi\OpenApi\Metadata\OperationExample;
use haddowg\JsonApi\OpenApi\Metadata\OperationKey;
use haddowg\JsonApi\OpenApi\Metadata\OperationKind;
use haddowg\JsonApi\OpenApi\Metadata\OperationType;
use haddowg\JsonApi\OpenApi\Metadata\SeeOther;
use haddowg\JsonApi\OpenApi\Metadata\ServerMetadataInterface;
use haddowg\JsonApi\OpenApi\Metadata\TypeMetadataInterface;

/**
 * Puts an `example` on the JSON:API media type of every projected operation's request
 * body and success responses, and on each shared error response.
 *
 * Each operation is found in the projected paths by the path template and method the
 * {@see OperationProjector} built it under. Its generated examples stand or fall
 * together: when any value one of them needs has no declared example, the operation gets
 * none and each such value becomes a {@see MissingExample}. A type's
 * {@see DeclaresOperationExamples} and an action's {@see DeclaresActionExample} replace or
 * switch off parts of that.
 *
 * @internal
 *
 * @phpstan-type Parts array{request: (\Closure(): mixed)|null, responses: array<int, \Closure(): mixed>}
 */
final class OperationExampleProjector
{
    private readonly ExampleDocumentBuilder $builder;

    /** @var array<string, MissingExample> */
    private array $missing = [];

    private bool $emitted = false;

    public function __construct(private readonly ServerMetadataInterface $server, LinkSchemaProjector $links)
    {
        $this->builder = new ExampleDocumentBuilder($server, $links);
    }

    /**
     * The paths with every operation's examples in place.
     */
    public function paths(Paths $paths): Paths
    {
        foreach ($this->server->types() as $type) {
            $paths = $this->type($type, $paths);
        }

        return $paths;
    }

    /**
     * The shared error responses with an example holding one error: the status's
     * representative code ({@see REPRESENTATIVE_CODES}) when the catalogue has it, else
     * the first code catalogued for the status. A document listing every code a status
     * can carry reads as noise wherever the example is quoted. A status no catalogued
     * code claims gets none.
     *
     * @param array<string, Response>                                               $components   response component name → response
     * @param array<int, non-empty-list<\haddowg\JsonApi\Exception\ErrorDescriptor>> $descriptors status → descriptors, in catalogue order
     *
     * @return array<string, Response>
     */
    public function errorResponses(array $components, array $descriptors): array
    {
        foreach ($descriptors as $status => $byStatus) {
            $name = ErrorResponseProjector::name((string) $status);
            $response = $components[$name] ?? null;
            if ($response === null) {
                continue;
            }

            [$example, $misses] = $this->builder->attempt(fn(): array => $this->builder->errorDocument(self::representative($status, $byStatus)));
            foreach ($misses as [$code, $field]) {
                $this->recordMiss($name, $code, $field);
            }
            if ($example !== null) {
                $components[$name] = $this->withResponseExample($response, $example);
            }
        }

        return $components;
    }

    /**
     * The core code each shared error response's example prefers, in order, when the
     * catalogue has it: the error a reader most likely meets at that status on any of the
     * operations sharing the response. Catalogue order sorts by class name, which would
     * show an atomic-batch error for every `400` and a missing relationship for every `404`.
     */
    private const REPRESENTATIVE_CODES = [
        400 => ['QUERY_PARAM_MALFORMED', 'REQUEST_BODY_INVALID_JSON_API'],
        403 => ['ACCESS_DENIED'],
        404 => ['RESOURCE_NOT_FOUND'],
        409 => ['RESOURCE_TYPE_UNACCEPTABLE'],
        422 => ['ATTRIBUTE_VALUE_INVALID'],
    ];

    /**
     * @param non-empty-list<\haddowg\JsonApi\Exception\ErrorDescriptor> $descriptors
     */
    private static function representative(int $status, array $descriptors): \haddowg\JsonApi\Exception\ErrorDescriptor
    {
        foreach (self::REPRESENTATIVE_CODES[$status] ?? [] as $code) {
            foreach ($descriptors as $descriptor) {
                if ($descriptor->code === $code) {
                    return $descriptor;
                }
            }
        }

        return $descriptors[0];
    }

    /**
     * @return list<MissingExample>
     */
    public function missingExamples(): array
    {
        return \array_values($this->missing);
    }

    /**
     * Whether any example was put on the document, which the generator contract reflects
     * ({@see GeneratorContract::EXAMPLES}).
     */
    public function emitted(): bool
    {
        return $this->emitted;
    }

    // ---- Per type ---------------------------------------------------------------

    private function type(TypeMetadataInterface $type, Paths $paths): Paths
    {
        $overrides = $type instanceof DeclaresOperationExamples ? $type->operationExamples() : [];
        $known = [];
        $actions = [];

        foreach (OperationEnumerator::forType($type) as $enumerated) {
            if ($enumerated->action !== null) {
                $actions[] = $enumerated;
                continue;
            }

            $item = $paths->items[$enumerated->path] ?? null;
            $operation = $item?->operations[$enumerated->method] ?? null;
            if ($item === null || $operation === null) {
                continue;
            }
            $name = $enumerated->key->name();
            $known[$name] = true;

            $override = \array_key_exists($name, $overrides) ? ($overrides[$name] ?? false) : null;
            $paths = $paths->with($enumerated->path, $item->withOperation($enumerated->method, $this->operation($operation, $this->parts($type, $enumerated), $override, $name)));
        }

        foreach (\array_keys($overrides) as $name) {
            $key = OperationKey::tryFromName((string) $name);
            if ($key === null || !isset($known[$key->name()])) {
                throw new \InvalidArgumentException(\sprintf(
                    'The type "%s" declares an example for the operation "%s", which it does not expose. Name one of: %s.',
                    $type->type(),
                    $name,
                    \implode(', ', \array_keys($known)),
                ));
            }
        }

        foreach ($actions as $enumerated) {
            $paths = $this->action($type, $enumerated, $paths);
        }

        return $paths;
    }

    /**
     * The builders of a CRUD, related or relationship operation's examples.
     *
     * @return Parts
     */
    private function parts(TypeMetadataInterface $type, EnumeratedOperation $enumerated): array
    {
        $builder = $this->builder;
        $uriType = $type->uriType();
        $path = $enumerated->path;
        $relation = $enumerated->relation;

        if ($relation === null) {
            return match ($enumerated->key->kind) {
                OperationKind::FetchCollection => [
                    'request' => null,
                    'responses' => [200 => fn(): array => $builder->collectionDocument($type, $path, OperationReference::fetchCollection($uriType), $type->pageSchema(), $type)],
                ],
                OperationKind::Create => [
                    'request' => fn(): array => $builder->writeDocument($type, creating: true),
                    'responses' => [
                        201 => fn(): array => $builder->resourceDocument($type, $path, OperationReference::create($uriType)),
                        ...$this->jobResponses($type, OperationType::Create),
                    ],
                ],
                OperationKind::FetchOne => [
                    'request' => null,
                    'responses' => [200 => fn(): array => $builder->resourceDocument($type, $builder->path($path, $builder->id($type)), OperationReference::fetchOne($uriType))],
                ],
                OperationKind::Update => [
                    'request' => fn(): array => $builder->writeDocument($type, creating: false),
                    'responses' => [
                        200 => fn(): array => $builder->resourceDocument($type, $builder->path($path, $builder->id($type)), OperationReference::update($uriType)),
                        ...$this->jobResponses($type, OperationType::Update),
                    ],
                ],
                default => ['request' => null, 'responses' => []],
            };
        }

        return match ($enumerated->key->kind) {
            OperationKind::FetchRelated => [
                'request' => null,
                'responses' => [200 => fn(): array => $builder->relatedDocument($type, $relation)],
            ],
            OperationKind::FetchRelationship => [
                'request' => null,
                'responses' => [200 => fn(): array => $builder->relationshipDocument($type, $relation, reading: true)],
            ],
            default => [
                'request' => fn(): array => $builder->relationshipRequest($type, $relation),
                'responses' => [200 => fn(): array => $builder->relationshipDocument($type, $relation, reading: false)],
            ],
        };
    }

    private function action(TypeMetadataInterface $type, EnumeratedOperation $enumerated, Paths $paths): Paths
    {
        $builder = $this->builder;
        $action = $enumerated->action;
        $path = $enumerated->path;
        $item = $paths->items[$path] ?? null;
        $operation = $item?->operations[$enumerated->method] ?? null;
        if ($action === null || $item === null || $operation === null) {
            return $paths;
        }

        $override = $action instanceof DeclaresActionExample ? $action->example() : null;
        $reference = $enumerated->reference();
        $responses = [];
        foreach ($action->responds() as $response) {
            if ($response instanceof ActionResource) {
                $body = $builder->type($response->bodyType());
                if ($body !== null) {
                    $responses[200] = fn(): array => $builder->resourceDocument(
                        $body,
                        $action->scope() === ActionScope::Resource ? $builder->path($path, $builder->id($type)) : $path,
                        $reference,
                    );
                }
            } elseif ($response instanceof SeeOther) {
                $responses[200] ??= fn(): array => $builder->locationDocument(
                    $action->scope() === ActionScope::Resource ? $builder->path($path, $builder->id($type)) : $path,
                    $reference,
                    $response->mediaType,
                );
            } elseif ($response instanceof Accepted) {
                $job = $builder->type($response->jobType());
                if ($job !== null) {
                    $responses[202] = fn(): array => $builder->resourceDocument($job, '', $reference, selfLink: false);
                }
            }
        }
        $generate = ['request' => $this->actionRequest($action), 'responses' => $responses];

        return $paths->with($path, $item->withOperation($enumerated->method, $this->operation($operation, $generate, $override, 'action.' . $action->path())));
    }

    /**
     * The builder of an action's request document, when its input is a resource document:
     * the input type's create request, or its resource object when that type cannot be
     * created.
     *
     * @return (\Closure(): array<string, mixed>)|null
     */
    private function actionRequest(ActionMetadataInterface $action): ?\Closure
    {
        $document = $action->inputMode() === ActionInputMode::Document;
        foreach (AcceptedBody::of($action) as $accepted) {
            $document = $document || $accepted->document;
        }
        $inputType = $action->inputType();
        $input = $inputType === null ? null : $this->builder->type($inputType);
        if (!$document || $input === null) {
            return null;
        }

        return $input->hasFields() && \in_array(OperationType::Create, $input->operations(), true)
            ? fn(): array => $this->builder->writeDocument($input, creating: true)
            : fn(): array => ['data' => $this->builder->resource($input)];
    }

    /**
     * The builders of the `202` job documents a CRUD write may answer with.
     *
     * @return array<int, \Closure(): array<string, mixed>>
     */
    private function jobResponses(TypeMetadataInterface $type, OperationType $operation): array
    {
        foreach ($type->responsesFor($operation) as $response) {
            $jobType = $response->status() === 202 ? $response->jobType() : null;
            $job = $jobType === null ? null : $this->builder->type($jobType);
            if ($job !== null) {
                return [202 => fn(): array => $this->builder->resourceDocument($job, '', null, selfLink: false)];
            }
        }

        return [];
    }

    /**
     * Applies the generated examples, and the operation's override, to one operation.
     * `$override` is `null` to generate everything, `false` for no example at all, or the
     * parts to replace.
     *
     * Only the parts that would be emitted are generated: the ones the override leaves
     * alone and the operation carries a JSON:API body for. Those stand or fall together,
     * and a miss is recorded only for them, so an operation whose override replaces or
     * removes every part reports nothing missing.
     *
     * @param Parts $parts
     */
    private function operation(Operation $operation, array $parts, OperationExample|false|null $override, string $name): Operation
    {
        if ($override === false) {
            return $operation;
        }

        $id = $operation->operationId ?? $name;
        $request = $parts['request'];
        $responses = $parts['responses'];
        $replaced = ['request' => null, 'responses' => []];

        if ($override !== null) {
            if ($override->request !== null) {
                if ($override->request !== false && !$this->hasJsonApiBody($operation->requestBody)) {
                    throw new \InvalidArgumentException(\sprintf('The operation "%s" declares a request example but takes no JSON:API request body.', $id));
                }
                $request = null;
                $replaced['request'] = $override->request === false ? null : $override->request;
            }
            foreach ($override->responses as $status => $example) {
                $response = $operation->responses->responses[(string) $status] ?? null;
                if (!$response instanceof Response || !isset($response->content[MediaType::JSON_API])) {
                    throw new \InvalidArgumentException(\sprintf('The operation "%s" declares an example for a %s response, which it does not answer with a JSON:API document.', $id, $status));
                }
                unset($responses[$status]);
                if ($example !== false) {
                    $replaced['responses'][$status] = $example;
                }
            }
        }

        if (!$this->hasJsonApiBody($operation->requestBody)) {
            $request = null;
        }
        foreach (\array_keys($responses) as $status) {
            $response = $operation->responses->responses[(string) $status] ?? null;
            if (!$response instanceof Response || !isset($response->content[MediaType::JSON_API])) {
                unset($responses[$status]);
            }
        }

        [$generated, $misses] = $this->builder->attempt(static function () use ($request, $responses): array {
            $built = ['request' => $request?->__invoke(), 'responses' => []];
            foreach ($responses as $status => $build) {
                $built['responses'][$status] = $build();
            }

            return $built;
        });
        foreach ($misses as [$type, $field]) {
            $this->recordMiss($id, $type, $field);
        }

        $request = $replaced['request'] ?? $generated['request'] ?? null;
        $examples = $replaced['responses'] + ($generated['responses'] ?? []);

        if ($request !== null && $operation->requestBody instanceof RequestBody && $this->hasJsonApiBody($operation->requestBody)) {
            $media = $operation->requestBody->content[MediaType::JSON_API];
            $operation = $operation->withRequestBody($operation->requestBody->withMediaType(MediaType::JSON_API, $media->withExample($request)));
            $this->emitted = true;
        }

        foreach ($examples as $status => $example) {
            $response = $operation->responses->responses[(string) $status] ?? null;
            if ($response instanceof Response && isset($response->content[MediaType::JSON_API])) {
                $operation = $operation->withResponse((string) $status, $this->withResponseExample($response, $example));
            }
        }

        return $operation;
    }

    private function hasJsonApiBody(RequestBody|Reference|null $body): bool
    {
        return $body instanceof RequestBody && isset($body->content[MediaType::JSON_API]);
    }

    private function withResponseExample(Response $response, mixed $example): Response
    {
        $media = $response->content[MediaType::JSON_API] ?? null;
        if ($media === null) {
            return $response;
        }
        $this->emitted = true;

        return $response->withMediaType(MediaType::JSON_API, $media->withExample($example));
    }

    private function recordMiss(string $operation, string $type, string $field): void
    {
        $this->missing[$operation . "\0" . $type . "\0" . $field] = new MissingExample($operation, $type, $field);
    }
}
