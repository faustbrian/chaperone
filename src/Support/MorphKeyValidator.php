<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Chaperone\Support;

use Cline\Chaperone\Exceptions\MorphKeyViolationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Config;

use function array_key_exists;

/**
 * Validates polymorphic morph key mappings for models.
 *
 * Ensures models used in polymorphic relationships have proper key mappings
 * configured. When enforcement is enabled, prevents unmapped models from
 * being stored in morph type columns, maintaining database consistency.
 *
 * @author Brian Faust <brian@cline.sh>
 */
final class MorphKeyValidator
{
    /**
     * Validate that a model has the correct morph key mapping.
     *
     * Checks the configured morphKeyMap and enforceMorphKeyMap settings to ensure
     * the model class has a defined key mapping. When enforceMorphKeyMap is configured
     * and the model is listed there, throws MorphKeyViolationException if the actual
     * key column doesn't match the configured column.
     *
     * This validation ensures database consistency by preventing models with incorrect
     * or missing key configurations from being stored in polymorphic relationships.
     *
     * @param Model  $model     The model instance to validate
     *
     * @throws MorphKeyViolationException When enforcement is enabled and model lacks mapping
     */
    public static function validateMorphKey(Model $model): void
    {
        $class = $model::class;

        /** @var array<class-string, string> $morphKeyMap */
        $morphKeyMap = Config::get('chaperone.morphKeyMap', []);

        /** @var array<class-string, string> $enforceMorphKeyMap */
        $enforceMorphKeyMap = Config::get('chaperone.enforceMorphKeyMap', []);

        // If enforceMorphKeyMap is configured, ensure the model is in the mapping
        throw_if($enforceMorphKeyMap !== [] && !array_key_exists($class, $enforceMorphKeyMap), MorphKeyViolationException::class, $class);

        // If model is in either mapping, verify the key column matches
        $expectedColumn = $enforceMorphKeyMap[$class] ?? $morphKeyMap[$class] ?? null;

        if ($expectedColumn !== null) {
            $actualColumn = $model->getKeyName();
            throw_if($actualColumn !== $expectedColumn, MorphKeyViolationException::class, $class);
        }
    }
}
