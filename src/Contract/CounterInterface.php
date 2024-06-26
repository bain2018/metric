<?php

declare(strict_types=1);
/**
 * This file is part of Hyperf.
 *
 * @link     https://www.hyperf.io
 * @document https://hyperf.wiki
 * @contact  group@hyperf.io
 * @license  https://github.com/hyperf/hyperf/blob/master/LICENSE
 */

namespace Bain\Metric\Contract;

/**
 * Counter describes a metric that accumulates values monotonically.
 * An example of a counter is the number of received HTTP requests.
 */
interface CounterInterface
{
    public function with(string ...$labelValues): static;

    public function add(int $delta): void;
}
