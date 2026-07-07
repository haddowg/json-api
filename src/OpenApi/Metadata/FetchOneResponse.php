<?php

declare(strict_types=1);

namespace haddowg\JsonApi\OpenApi\Metadata;

/**
 * Marker for a success response the fetch-one operation (`GET /{type}/{id}`) may
 * advertise: {@see Ok} (`200`) and {@see SeeOther} (`303` — the resource represents
 * completed asynchronous work; follow `Location` to the produced resource, per the
 * JSON:API *Asynchronous Processing* recommendation). The runtime counterpart of a
 * `303` is {@see \haddowg\JsonApi\Resource\ResolvesCompletionRedirect}.
 */
interface FetchOneResponse extends OperationResponseInterface {}
