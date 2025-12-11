<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Chaperone\Contracts;

/**
 * @author Brian Faust <brian@cline.sh>
 */
interface Supervised
{
    public function heartbeat(array $metadata = []): void;

    public function reportProgress(int $current, int $total, array $metadata = []): void;

    public function getSupervisionId(): string;
}
