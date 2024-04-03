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

class GuzzlePoolWatcher extends PoolWatcher implements ListenerInterface
{
    public function getPrefix()
    {
        return 'guzzle';
    }

    /**
     * {@inheritdoc}
     */
    public function process(object $event): void
    {
        $poolNames=$this->container->get(PoolFactory::class)->getPoolNames();

        $logger=$this->container->get(LoggerFactory::class)->get("log");
        $logger->info("GuzzlePoolWatcher Pool 调用 ".json_encode($poolNames,JSON_UNESCAPED_UNICODE));

        foreach ($poolNames as $poolName) {
            if (!str_contains($poolName,'guzzle.handler'))
            {
                continue;
            }
            $workerId = (int) ($event->workerId ?? 0);
            $pool = $this->container->get(PoolFactory::class)->getPool($poolName);
            $this->watch($pool, $poolName, $workerId);
        }
    }
}
