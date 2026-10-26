<?php

declare(strict_types=1);

namespace haddowg\JsonApi\OpenApi\Metadata;

/**
 * Action metadata that says how safe the action is to call, so a consumer can tell a
 * lookup from a write without guessing from the HTTP method. The projector emits the
 * declared flags as the action operation's `x-safety` extension.
 *
 * Each flag is `null` when the action does not declare it, and only declared flags are
 * projected. An action may not be both read-only and destructive;
 * {@see ActionDeclarations::validate()} rejects that pair.
 *
 * A separate, opt-in contract rather than methods on {@see ActionMetadataInterface}, so an
 * existing implementation keeps working, the same shape as {@see DeclaresActionLink}.
 */
interface DeclaresActionSafety extends ActionMetadataInterface
{
    /**
     * Whether the action leaves server state unchanged.
     */
    public function readOnly(): ?bool;

    /**
     * Whether the action may delete data or otherwise change it in a way that cannot be
     * undone.
     */
    public function destructive(): ?bool;

    /**
     * Whether calling the action again with the same input has no further effect.
     */
    public function idempotent(): ?bool;
}
