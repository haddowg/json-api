<?php

declare(strict_types=1);

namespace haddowg\JsonApi\OpenApi;

/**
 * A projected document and what the projector could not do for it: one
 * {@see MissingExample} per value an operation's example needed and had no declared
 * example for, one {@see UndeclaredScope} per required scope nothing describes, and a
 * {@see ScopeSchemeNotOAuth2} when the scopes are listed under a scheme with no flows. An
 * export command can print them as warnings, or fail on the missing examples and the
 * undeclared scopes under a strict flag.
 */
final readonly class Projection
{
    /**
     * @param list<MissingExample>  $missingExamples
     * @param list<UndeclaredScope> $undeclaredScopes
     */
    public function __construct(
        public OpenApi $document,
        public array $missingExamples = [],
        public array $undeclaredScopes = [],
        public ?ScopeSchemeNotOAuth2 $scopeScheme = null,
    ) {}
}
