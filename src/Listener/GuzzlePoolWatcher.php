<?php
declare(strict_types=1);
/**
 * Date 25/03/2024 4:44 pm
 * @author Bain <Bain2018@gmail.com>
 * @version 1.0.0
 * @comment :
 */

namespace Bain\Metric\Listener;

use Hyperf\Event\Contract\ListenerInterface;
use Hyperf\Pool\SimplePool\PoolFactory;
use Hyperf\Logger\LoggerFactory;
use Hyperf\Coordinator\Constants;
use Hyperf\Coordinator\CoordinatorManager;
use Hyperf\Coroutine\Coroutine;
use Hyperf\Contract\ConfigInterface;

class GuzzlePoolWatcher extends PoolWatcher implements ListenerInterface
{

    protected array $pools=[];

    public function getPrefix()
    {
        return 'guzzle';
    }


    /**
     * Date 03/04/2024 2:10 pm
     * @param object $event
     * @return void
     * @comment :
     * @author Bain <Bain2018@gmail.com>
     * @version 1.0.0
     * 在启动的初期，尚未有http请求，因此，无法获取到guzzle.handler.* 连接池
     * 因此，在启动后，需要定时刷新数据
     */
    public function process(object $event): void
    {
        $config = $this->container->get(ConfigInterface::class);
        $timerInterval = $config->get('metric.default_metric_interval', 5);
        /**
         * 定时刷新SimplePool 查询是否有新的链接池
         */
        $timerId = $this->timer->tick($timerInterval, function (){
            $poolNames=$this->container->get(PoolFactory::class)->getPoolNames();
            $logger=$this->container->get(LoggerFactory::class)->get("log");

            foreach ($poolNames as $poolName)
            {
                if (!str_contains($poolName,'guzzle.handler'))
                {
                    continue;
                }

                /**
                 * 新增一个监控
                 */
                if (!in_array($poolName,$this->pools))
                {
                    $logger->info("Find A New GuzzlePoolWatcher==== 【{$poolName}】 ");
                    $this->pools[]=$poolName;
                    $workerId = (int) ($event->workerId ?? 0);
                    $pool = $this->container->get(PoolFactory::class)->getPool($poolName);
                    $this->watch($pool, $poolName, $workerId);
                }
            }
        });

        Coroutine::create(function () use ($timerId) {
            CoordinatorManager::until(Constants::WORKER_EXIT)->yield();
            $this->timer->clear($timerId);
        });
    }
}
