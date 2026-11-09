<?php

declare(strict_types=1);

namespace haddowg\JsonApi\OpenApi;

use haddowg\JsonApi\OpenApi\Metadata\AcceptedBody;
use haddowg\JsonApi\OpenApi\Metadata\ActionInputMode;
use haddowg\JsonApi\OpenApi\Metadata\ActionMetadataInterface;
use haddowg\JsonApi\OpenApi\Metadata\ServerMetadataInterface;

/**
 * Which actions take a JSON:API request document and which types those documents carry,
 * so the write components, the action request bodies and their examples agree.
 *
 * @internal
 */
final class ActionDocumentInput
{
    /**
     * Whether the action takes a JSON:API request document: its input mode is `Document`,
     * or one of its accepted bodies is the document.
     */
    public static function takesDocument(ActionMetadataInterface $action): bool
    {
        if ($action->inputMode() === ActionInputMode::Document) {
            return true;
        }
        foreach (AcceptedBody::of($action) as $accepted) {
            if ($accepted->document) {
                return true;
            }
        }

        return false;
    }

    /**
     * The types some action on the server takes as its request document's input, keyed by
     * type.
     *
     * @return array<string, true>
     */
    public static function inputTypes(ServerMetadataInterface $server): array
    {
        $types = [];
        foreach ($server->types() as $type) {
            foreach ($type->actions() as $action) {
                $input = $action->inputType();
                if ($input !== null && self::takesDocument($action)) {
                    $types[$input] = true;
                }
            }
        }

        return $types;
    }
}
