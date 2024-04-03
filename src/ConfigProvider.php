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

namespace Bain\Metric;

use Domnikl\Statsd\Connection;
use Domnikl\Statsd\Connection\UdpSocket;
use Bain\Metric\Adapter\RemoteProxy\MetricCollectorFactory;
use Bain\Metric\Aspect\CounterAnnotationAspect;
use Bain\Metric\Aspect\HistogramAnnotationAspect;
use Bain\Metric\Contract\MetricCollectorInterface;
use Bain\Metric\Contract\MetricFactoryInterface;
use Bain\Metric\Listener\MetricBufferWatcher;
use Bain\Metric\Listener\OnBeforeHandle;
use Bain\Metric\Listener\OnBeforeProcessHandle;
use Bain\Metric\Listener\OnCoroutineServerStart;
use Bain\Metric\Listener\OnMetricFactoryReady;
use Bain\Metric\Listener\OnPipeMessage;
use Bain\Metric\Listener\OnWorkerStart;
use Bain\Metric\Process\MetricProcess;
use InfluxDB\Driver\DriverInterface;
use InfluxDB\Driver\Guzzle;
use Prometheus\Storage\Adapter;
use Prometheus\Storage\InMemory;

class ConfigProvider
{
    public function __invoke(): array
    {
        return [
            'dependencies' => [
                MetricFactoryInterface::class => MetricFactoryPicker::class,
                Adapter::class => InMemory::class,
                Connection::class => UdpSocket::class,
                DriverInterface::class => Guzzle::class,
                MetricCollectorInterface::class => MetricCollectorFactory::class,
            ],
            'aspects' => [
                CounterAnnotationAspect::class,
                HistogramAnnotationAspect::class,
            ],
            'publish' => [
                [
                    'id' => 'config',
                    'description' => 'The config for metric component.',
                    'source' => __DIR__ . '/../publish/metric.php',
                    'destination' => BASE_PATH . '/config/autoload/metric.php',
                ],
            ],
            'listeners' => [
                OnPipeMessage::class,
                OnBeforeProcessHandle::class,
                OnMetricFactoryReady::class,
                OnBeforeHandle::class,
                OnWorkerStart::class,
                OnCoroutineServerStart::class,
                MetricBufferWatcher::class,
            ],
            'processes' => [
                MetricProcess::class,
            ],
        ];
    }
}
