<?php

declare(strict_types=1);

namespace haddowg\JsonApi\OpenApi\Metadata;

/**
 * Type metadata for a type that may be a singleton: it serves the caller's one resource at
 * `/{uriType}`, with no `{id}` segment, and its relationship, related and action paths hang
 * off that. Its operation ids stay `fetchOne.<type>` and `update.<type>`.
 *
 * A singleton's metadata exposes no collection fetch, create, delete or collection-scope
 * action; the projector refuses one that does
 * ({@see \haddowg\JsonApi\Resource\SingletonMisdeclared}). A separate, opt-in contract
 * rather than a method on {@see TypeMetadataInterface}, so an existing implementation keeps
 * compiling. An adapter fills it from the type's
 * {@see \haddowg\JsonApi\Resource\SingletonResourceInterface::isSingleton()}.
 */
interface DeclaresSingleton extends TypeMetadataInterface
{
    public function isSingleton(): bool;
}
