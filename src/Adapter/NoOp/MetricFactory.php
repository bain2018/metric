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

use Hyperf\Contract\ConfigInterface;
use Hyperf\Coordinator\Constants;
use Hyperf\Coordinator\CoordinatorManager;
use Bain\Metric\Contract\CounterInterface;
use Bain\Metric\Contract\GaugeInterface;
use Bain\Metric\Contract\HistogramInterface;
use Bain\Metric\Contract\MetricFactoryInterface;

class MetricFactory implements MetricFactoryInterface
{
    private string $name;
    public function __construct(
        private ConfigInterface $config,
    ) {
        $this->name = $this->config->get('metric.default');
    }


    public function makeCounter(string $name, ?array $labelNames = []): CounterInterface
    {
        return new Counter();
    }

    public function makeGauge(string $name, ?array $labelNames = []): GaugeInterface
    {
        return new Gauge();
    }

    public function makeHistogram(string $name, ?array $labelNames = []): HistogramInterface
    {
        return new Histogram();
    }

    public function handle(): void
    {
        $coordinator = CoordinatorManager::until(Constants::WORKER_EXIT);
        $coordinator->yield();
    }

    public function makeCounterWithNameSpace(string $name, ?array $labelNames = [], string $namespace = ""): CounterInterface
    {
        return new Counter();
    }

    public function makeGaugeWithNameSpace(string $name, ?array $labelNames = [], string $namespace = ""): GaugeInterface
    {
        return new Gauge();
    }

    public function makeHistogramWithNameSpace(string $name, ?array $labelNames = [], string $namespace = ""): HistogramInterface
    {
        return new Histogram();
    }
}
