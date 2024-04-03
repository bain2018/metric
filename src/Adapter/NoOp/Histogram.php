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

namespace Bain\Metric\Adapter\NoOp;

use Bain\Metric\Contract\HistogramInterface;

class Histogram implements HistogramInterface
{
    public function with(string ...$labelValues): static
    {
        return $this;
    }

    public function put(float $sample): void
    {
    }
}
