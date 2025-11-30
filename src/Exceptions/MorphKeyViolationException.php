<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Chaperone\Exceptions;

use RuntimeException;

use function sprintf;

/**
 * Exception thrown when a polymorphic relationship lacks required key mapping.
 *
 * Signals that a model class is being used in a polymorphic relationship (typically
 * as an initiator type) but no key mapping has been configured. This occurs when
 * enforceMorphKeyMap is enabled and a model type is encountered that isn't in the
 * mapping. The exception ensures database consistency by preventing unmapped class
 * names from being stored in morph type columns.
 *
 * @author Brian Faust <brian@cline.sh>
 */
final class MorphKeyViolationException extends RuntimeException
{
    /**
     * Create an exception for a missing polymorphic key mapping.
     *
     * @param string $class The fully-qualified class name that lacks a polymorphic key mapping.
     *                      This is typically a model class used as an initiator type in
     *                      Chaperone's job supervision system that hasn't been registered in the
     *                      enforceMorphKeyMap configuration.
     */
    public function __construct(string $class)
    {
        parent::__construct(
            sprintf('No polymorphic key mapping defined for [%s]. ', $class).
            'Use morphKeyMap or enforceMorphKeyMap configuration to define key mappings.',
        );
    }
}
