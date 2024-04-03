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

namespace Bain\Metric\Adapter\RemoteProxy;

use Bain\Metric\Contract\CounterInterface;
use Bain\Metric\Contract\GaugeInterface;
use Bain\Metric\Contract\HistogramInterface;
use Bain\Metric\Contract\MetricFactoryInterface;
use Bain\Metric\Exception\RuntimeException;

class MetricFactory implements MetricFactoryInterface
{
    public function makeCounter(string $name, ?array $labelNames = []): CounterInterface
    {
        return new Counter(
            $name,
            $labelNames
        );
    }

    public function makeGauge(string $name, ?array $labelNames = []): GaugeInterface
    {
        return new Gauge(
            $name,
            $labelNames
        );
    }

    public function makeHistogram(string $name, ?array $labelNames = []): HistogramInterface
    {
        return new Histogram(
            $name,
            $labelNames
        );
    }

    public function handle(): void
    {
        throw new RuntimeException('RemoteProxy adapter cannot handle metrics reporting/serving directly');
    }
}
