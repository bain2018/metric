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

namespace Bain\Metric\Listener;

use Hyperf\Contract\ConfigInterface;
use Hyperf\Coordinator\Constants;
use Hyperf\Coordinator\CoordinatorManager;
use Hyperf\Coordinator\Timer;
use Hyperf\Coroutine\Coroutine;
use Hyperf\Framework\Event\BeforeWorkerStart;
use Bain\Metric\Contract\MetricFactoryInterface;
use Hyperf\Pool\Pool;
use Hyperf\Process\Event\BeforeProcessHandle;
use Hyperf\Server\Event\MainCoroutineServerStart;
use Psr\Container\ContainerInterface;

abstract class PoolWatcher
{
    protected Timer $timer;

    public function __construct(protected ContainerInterface $container)
    {
        $this->timer = new Timer();
    }

    /**
     * @return string[] returns the events that you want to listen
     */
    public function listen(): array
    {
        return [
            BeforeWorkerStart::class,
            MainCoroutineServerStart::class,
            BeforeProcessHandle::class
        ];
    }

    /**
     * Periodically scan metrics.
     * @param BeforeWorkerStart|MainCoroutineServerStart $event
     */
    abstract public function process(object $event);

    public function watch(Pool $pool, string $poolName, int $workerId)
    {
        $config = $this->container->get(ConfigInterface::class);
        $server = $config->get('app_name', 'wechat');

        $connectionsInUseGauge = $this->container->get(MetricFactoryInterface::class)
            ->makeGaugeWithNameSpace($this->getPrefix() . '_connections_in_use', ['pool', 'worker','server'],)->with($poolName, (string) $workerId,$server);

        $connectionsInWaitingGauge = $this->container->get(MetricFactoryInterface::class)
            ->makeGaugeWithNameSpace($this->getPrefix() . '_connections_in_waiting', ['pool', 'worker','server'],)->with($poolName, (string) $workerId,$server);

        $maxConnectionsGauge = $this->container->get(MetricFactoryInterface::class)
            ->makeGaugeWithNameSpace($this->getPrefix() . '_max_connections', ['pool', 'worker','server'],)->with($poolName, (string) $workerId,$server);

        $timerInterval = $config->get('metric.default_metric_interval', 5);
        $timerId = $this->timer->tick($timerInterval, function () use (
            $connectionsInUseGauge,
            $connectionsInWaitingGauge,
            $maxConnectionsGauge,
            $pool
        ) {
            $maxConnectionsGauge->set((float) $pool->getOption()->getMaxConnections());
            $connectionsInWaitingGauge->set((float) $pool->getConnectionsInChannel());
            $connectionsInUseGauge->set((float) $pool->getCurrentConnections());
        });
        Coroutine::create(function () use ($timerId) {
            CoordinatorManager::until(Constants::WORKER_EXIT)->yield();
            $this->timer->clear($timerId);
        });
    }

    abstract protected function getPrefix();
}
