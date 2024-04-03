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
