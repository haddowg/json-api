<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Atomic;

/**
 * The JSON:API Atomic Operations extension: a batch of write operations applied
 * in order, all-or-nothing, within a single request.
 *
 * Holds the extension's canonical {@see URI} (matched against, and echoed in, the
 * `ext` media-type parameter — mirroring the profile-class URI pattern) and its
 * reserved member {@see NAMESPACE} prefix (`atomic`), under which the document's
 * `atomic:operations` request member and the response's `atomic:results` member
 * are named.
 *
 * @see https://jsonapi.org/ext/atomic/
 */
final class AtomicExtension
{
    /**
     * The extension's canonical URI — advertised in, and matched against, the
     * `ext` media-type parameter.
     */
    public const string URI = 'https://jsonapi.org/ext/atomic';

    /**
     * The reserved member-name prefix the extension claims: the request carries an
     * `atomic:operations` array and a successful response carries an
     * `atomic:results` array.
     */
    public const string NAMESPACE = 'atomic';

    /**
     * The request document member naming the ordered list of operations.
     */
    public const string OPERATIONS_MEMBER = 'atomic:operations';

    /**
     * The response document member naming the ordered list of result fragments.
     */
    public const string RESULTS_MEMBER = 'atomic:results';
}
