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

namespace Bain\Metric\Adapter\Prometheus;

use Hyperf\Codec\Json;
use Hyperf\Context\ApplicationContext;
use Hyperf\Logger\Logger;
use Hyperf\Logger\LoggerFactory;
use Bain\Metric\Exception\InvalidArgumentException;
use Prometheus\Counter;
use Prometheus\Gauge;
use Prometheus\Histogram;
use Prometheus\Math;
use Prometheus\MetricFamilySamples;
use Prometheus\Storage\Adapter;
use Prometheus\Summary;
use RedisException;

class Redis implements Adapter
{
    private static string $metricGatherKeySuffix = ':metric_keys';

    private static string $prefix = 'prometheus:';

    protected Logger $logger;

    /**
     * @param \Redis $redis
     */
    public function __construct(protected mixed $redis)
    {
        $this->logger=ApplicationContext::getContainer()->get(LoggerFactory::class)->get("log");
        $this->logger->info("Prometheus 调用自定义Redis");
    }


    public static function setPrefix(string $prefix): void
    {
        self::$prefix = $prefix;
    }

    /**
     * @throws RedisException
     */
    public function flushRedis(): void
    {
        $this->wipeStorage();
    }

    /**
     * @throws RedisException
     */
    public function wipeStorage(): void
    {
        $searchPattern = '';
        $globalPrefix = $this->redis->_prefix('');

        if (is_string($globalPrefix)) {
            $searchPattern .= $globalPrefix;
        }

        $searchPattern .= self::$prefix;
        $searchPattern .= '*';

        $this->redis->eval(
            <<<'LUA'
local cursor = "0"
repeat 
    local results = redis.call('SCAN', cursor, 'MATCH', ARGV[1])
    cursor = results[1]
    for _, key in ipairs(results[2]) do
        redis.call('DEL', key)
    end
until cursor == "0"
return 1
LUA
            ,
            [$searchPattern],
            0
        );
    }

    private function metaKey(array $data): string
    {
        return implode(':', [
            $data['name'],
            'meta'
        ]);
    }

    private function valueKey(array $data): string
    {
        return implode(':', [
            $data['name'],
            $this->encodeLabelValues($data['labelValues']),
            'value'
        ]);
    }

    /**
     * @return MetricFamilySamples[]
     *
     * @throws RedisException
     */
    public function collect(bool $sortMetrics = true): array
    {

        $metrics = $this->collectHistograms();
        $metrics = array_merge($metrics, $this->collectGauges($sortMetrics));
        $metrics = array_merge($metrics, $this->collectCounters($sortMetrics));
        $metrics = array_merge($metrics, $this->collectSummaries());

        return array_map(
            fn (array $metric) => new MetricFamilySamples($metric),
            $metrics
        );

    }

    /**
     * @throws RedisException
     */
    public function updateHistogram(array $data): void
    {
        $metaData = $data;
        unset($metaData['value'], $metaData['labelValues']);

        $bucketToIncrease = '+Inf';
        foreach ($data['buckets'] as $bucket) {
            if ($data['value'] <= $bucket) {
                $bucketToIncrease = $bucket;
                break;
            }
        }

        $res=$this->redis->eval(
            <<<LUA
local result = redis.call('hIncrByFloat', KEYS[1], ARGV[1], ARGV[3])
redis.call('hIncrBy', KEYS[1], ARGV[2], 1)
if tonumber(result) >= tonumber(ARGV[3]) then
    redis.call('hSet', KEYS[1], '__meta', ARGV[4])
    redis.call('sAdd', KEYS[2], KEYS[1])
end
return result
LUA
            ,
            [
                $this->toMetricKey($data),
                $this->getMetricGatherKey(Histogram::TYPE),
                Json::encode(['b' => 'sum', 'labelValues' => $data['labelValues']]),
                Json::encode(['b' => $bucketToIncrease, 'labelValues' => $data['labelValues']]),
                $data['value'],
                Json::encode($metaData),
            ],
            2
        );

    }


    public function updateSummary(array $data): void
    {
        // store meta
        $summaryKey=$this->getMetricGatherKey(Summary::TYPE);
        $metaKey = $summaryKey . ':' . $this->metaKey($data);
        $json = json_encode($this->metaData($data));
        if (false === $json) {
            throw new \RuntimeException(json_last_error_msg());
        }
        $this->redis->setNx($metaKey, $json);  /** @phpstan-ignore-line */

        // store value key
        $valueKey = $summaryKey . ':' . $this->valueKey($data);
        $json = json_encode($this->encodeLabelValues($data['labelValues']));
        if (false === $json) {
            throw new \RuntimeException(json_last_error_msg());
        }
        $this->redis->setNx($valueKey, $json); /** @phpstan-ignore-line */

        // trick to handle uniqid collision
        $done = false;
        while (!$done) {
            $sampleKey = $valueKey . ':' . uniqid('', true);
            $done = $this->redis->set($sampleKey, $data['value'], ['NX', 'EX' => $data['maxAgeSeconds']]);
        }
    }

    /**
     * @throws RedisException
     */
    public function updateGauge(array $data): void
    {
        $metaData = $data;
        unset($metaData['value'], $metaData['labelValues'], $metaData['command']);

        $cmd=$this->getRedisCommand($data['command']);
        $script="";
        if ($cmd=='hSet')
        {
            $script= <<<LUA
local result = redis.call('hSet', KEYS[1], ARGV[2], ARGV[3])
if result == 1 then
   redis.call('hSet', KEYS[1], '__meta', ARGV[4])
   redis.call('sAdd', KEYS[2], KEYS[1])
end
return result
LUA;
        }

        if ($cmd=='hIncrBy')
        {
            $script=<<<LUA
local result = redis.call('hIncrBy', KEYS[1], ARGV[2], ARGV[3])
if result == ARGV[3] then
   redis.call('hSet', KEYS[1], '__meta', ARGV[4])
   redis.call('sAdd', KEYS[2], KEYS[1])
end
return result
LUA;
        }

        if ($cmd=='hIncrByFloat')
        {
            $script=<<<LUA
local result = redis.call('hIncrByFloat', KEYS[1], ARGV[2], ARGV[3])
if result == ARGV[3] then
   redis.call('hSet', KEYS[1], '__meta', ARGV[4])
   redis.call('sAdd', KEYS[2], KEYS[1])
end
return result
LUA;
        }

        $res=$this->redis->eval($script,
            [
                $this->toMetricKey($data),
                $this->getMetricGatherKey(Gauge::TYPE),
                $this->getRedisCommand($data['command']),
                Json::encode($data['labelValues']),
                $data['value'],
                Json::encode($metaData),
            ],
            2
        );
    }

    /**
     * @throws RedisException
     */
    public function updateCounter(array $data): void
    {
        $metaData = $data;
        unset($metaData['value'], $metaData['labelValues'], $metaData['command']);

        $cmd=$this->getRedisCommand($data['command']);
        $script="";
        if ($cmd=='hSet')
        {
            $script= <<<LUA
local result = redis.call('hSet', KEYS[1], ARGV[3], ARGV[2])
local added = redis.call('sAdd', KEYS[2], KEYS[1])
if added == 1 then
    redis.call('hMSet', KEYS[1], '__meta', ARGV[4])
end
return result
LUA;
        }

        if ($cmd=='hIncrBy')
        {
            $script= <<<LUA
local result = redis.call('hIncrBy', KEYS[1], ARGV[3], ARGV[2])
local added = redis.call('sAdd', KEYS[2], KEYS[1])
if added == 1 then
    redis.call('hMSet', KEYS[1], '__meta', ARGV[4])
end
return result
LUA;
        }

        if ($cmd=='hIncrByFloat')
        {
            $script= <<<LUA
local result = redis.call('hIncrByFloat', KEYS[1], ARGV[3], ARGV[2])
local added = redis.call('sAdd', KEYS[2], KEYS[1])
if added == 1 then
    redis.call('hMSet', KEYS[1], '__meta', ARGV[4])
end
return result
LUA;
        }

        $res=$this->redis->eval($script,
            [
                $this->toMetricKey($data),
                $this->getMetricGatherKey(Counter::TYPE),
                $this->getRedisCommand($data['command']),
                $data['value'],
                Json::encode($data['labelValues']),
                Json::encode($metaData),
            ],
            2
        );
    }


    private function metaData(array $data): array
    {
        $metricsMetaData = $data;
        unset($metricsMetaData['value'], $metricsMetaData['command'], $metricsMetaData['labelValues']);
        return $metricsMetaData;
    }

    /**
     * @throws RedisException
     */
    protected function collectHistograms(): array
    {
        $keys = $this->redis->sMembers($this->getMetricGatherKey(Histogram::TYPE));
        sort($keys);
        $histograms = [];
        foreach ($keys as $key) {
            $raw = $this->redis->hGetAll(str_replace($this->redis->_prefix(''), '', $key));
            if (!isset($raw['__meta'])) {
                continue;
            }
            $histogram = json_decode($raw['__meta'], true);
            unset($raw['__meta']);
            $histogram['samples'] = [];

            // Add the Inf bucket so we can compute it later on
            $histogram['buckets'][] = '+Inf';

            $allLabelValues = [];
            foreach (array_keys($raw) as $k) {
                $d = json_decode($k, true);
                if ($d['b'] == 'sum') {
                    continue;
                }
                $allLabelValues[] = $d['labelValues'];
            }

            // We need set semantics.
            // This is the equivalent of array_unique but for arrays of arrays.
            $allLabelValues = array_map("unserialize", array_unique(array_map("serialize", $allLabelValues)));
            sort($allLabelValues);

            foreach ($allLabelValues as $labelValues) {
                // Fill up all buckets.
                // If the bucket doesn't exist fill in values from
                // the previous one.
                $acc = 0;
                foreach ($histogram['buckets'] as $bucket) {
                    $bucketKey = json_encode(['b' => $bucket, 'labelValues' => $labelValues]);
                    if (!isset($raw[$bucketKey])) {
                        $histogram['samples'][] = [
                            'name' => $histogram['name'] . '_bucket',
                            'labelNames' => ['le'],
                            'labelValues' => array_merge($labelValues, [$bucket]),
                            'value' => $acc,
                        ];
                    } else {
                        $acc += $raw[$bucketKey];
                        $histogram['samples'][] = [
                            'name' => $histogram['name'] . '_bucket',
                            'labelNames' => ['le'],
                            'labelValues' => array_merge($labelValues, [$bucket]),
                            'value' => $acc,
                        ];
                    }
                }

                // Add the count
                $histogram['samples'][] = [
                    'name' => $histogram['name'] . '_count',
                    'labelNames' => [],
                    'labelValues' => $labelValues,
                    'value' => $acc,
                ];

                // Add the sum
                $histogram['samples'][] = [
                    'name' => $histogram['name'] . '_sum',
                    'labelNames' => [],
                    'labelValues' => $labelValues,
                    'value' => $raw[json_encode(['b' => 'sum', 'labelValues' => $labelValues])],
                ];
            }
            $histograms[] = $histogram;
        }
        return $histograms;
    }

    /**
     * @param string $key
     *
     * @return string
     * @throws RedisException
     */
    private function removePrefixFromKey(string $key): string
    {
        // @phpstan-ignore-next-line false positive, phpstan thinks getOptions returns int
        if ($this->redis->getOption(\Redis::OPT_PREFIX) === null) {
            return $key;
        }
        // @phpstan-ignore-next-line false positive, phpstan thinks getOptions returns int
        return substr($key, strlen($this->redis->getOption(\Redis::OPT_PREFIX)));
    }

    /**
     * @return array
     * @throws RedisException
     */
    private function collectSummaries(): array
    {
        $math = new Math();
        $summaryKey =$this->getMetricGatherKey(Summary::TYPE);
        $keys = $this->redis->keys($summaryKey . ':*:meta');
        $summaries = [];
        foreach ($keys as $metaKeyWithPrefix) {
            $metaKey = $this->removePrefixFromKey($metaKeyWithPrefix);
            $rawSummary = $this->redis->get($metaKey);
            if ($rawSummary === false) {
                continue;
            }
            $summary = json_decode($rawSummary, true);
            $metaData = $summary;
            $data = [
                'name' => $metaData['name'],
                'help' => $metaData['help'],
                'type' => $metaData['type'],
                'labelNames' => $metaData['labelNames'],
                'maxAgeSeconds' => $metaData['maxAgeSeconds'],
                'quantiles' => $metaData['quantiles'],
                'samples' => [],
            ];

            $values = $this->redis->keys($summaryKey . ':' . $metaData['name'] . ':*:value');
            foreach ($values as $valueKeyWithPrefix) {
                $valueKey = $this->removePrefixFromKey($valueKeyWithPrefix);
                $rawValue = $this->redis->get($valueKey);
                if ($rawValue === false) {
                    continue;
                }
                $value = json_decode($rawValue, true);
                $encodedLabelValues = $value;
                $decodedLabelValues = $this->decodeLabelValues($encodedLabelValues);

                $samples = [];
                $sampleValues = $this->redis->keys($summaryKey . ':' . $metaData['name'] . ':' . $encodedLabelValues . ':value:*');
                foreach ($sampleValues as $sampleValueWithPrefix) {
                    $sampleValue = $this->removePrefixFromKey($sampleValueWithPrefix);
                    $samples[] = (float) $this->redis->get($sampleValue);
                }

                if (count($samples) === 0) {
                    $this->redis->del($valueKey);
                    continue;
                }

                // Compute quantiles
                sort($samples);
                foreach ($data['quantiles'] as $quantile) {
                    $data['samples'][] = [
                        'name' => $metaData['name'],
                        'labelNames' => ['quantile'],
                        'labelValues' => array_merge($decodedLabelValues, [$quantile]),
                        'value' => $math->quantile($samples, $quantile),
                    ];
                }

                // Add the count
                $data['samples'][] = [
                    'name' => $metaData['name'] . '_count',
                    'labelNames' => [],
                    'labelValues' => $decodedLabelValues,
                    'value' => count($samples),
                ];

                // Add the sum
                $data['samples'][] = [
                    'name' => $metaData['name'] . '_sum',
                    'labelNames' => [],
                    'labelValues' => $decodedLabelValues,
                    'value' => array_sum($samples),
                ];
            }

            if (count($data['samples']) > 0) {
                $summaries[] = $data;
            } else {
                $this->redis->del($metaKey);
            }
        }
        return $summaries;
    }

    /**
     * @throws RedisException
     */
    protected function collectGauges(bool $sortMetrics = true): array
    {
        $keys = $this->redis->sMembers($this->getMetricGatherKey(Gauge::TYPE));
        sort($keys);
        $gauges = [];
        foreach ($keys as $key) {
            $raw = $this->redis->hGetAll(str_replace($this->redis->_prefix(''), '', $key));
            if (!isset($raw['__meta'])) {
                continue;
            }
            $gauge = json_decode($raw['__meta'], true);
            unset($raw['__meta']);
            $gauge['samples'] = [];
            foreach ($raw as $k => $value) {
                $gauge['samples'][] = [
                    'name' => $gauge['name'],
                    'labelNames' => [],
                    'labelValues' => json_decode($k, true),
                    'value' => $value,
                ];
            }

            if ($sortMetrics) {
                usort($gauge['samples'], function ($a, $b): int {
                    return strcmp(implode("", $a['labelValues']), implode("", $b['labelValues']));
                });
            }

            $gauges[] = $gauge;
        }
        return $gauges;
    }

    /**
     * @throws RedisException
     * Counter::TYPE
     */
    protected function collectCounters(bool $sortMetrics = true): array
    {
        $keys = $this->redis->sMembers($this->getMetricGatherKey(Counter::TYPE));
        sort($keys);
        $counters = [];
        foreach ($keys as $key) {
            $raw = $this->redis->hGetAll(str_replace($this->redis->_prefix(''), '', $key));
            if (!isset($raw['__meta'])) {
                continue;
            }
            $counter = json_decode($raw['__meta'], true);
            unset($raw['__meta']);
            $counter['samples'] = [];
            foreach ($raw as $k => $value) {
                $counter['samples'][] = [
                    'name' => $counter['name'],
                    'labelNames' => [],
                    'labelValues' => json_decode($k, true),
                    'value' => $value,
                ];
            }

            if ($sortMetrics) {
                usort($counter['samples'], function ($a, $b): int {
                    return strcmp(implode("", $a['labelValues']), implode("", $b['labelValues']));
                });
            }

            $counters[] = $counter;
        }
        return $counters;
    }

    protected function getRedisCommand(int $cmd): string
    {
        return match ($cmd) {
            Adapter::COMMAND_INCREMENT_INTEGER => 'hIncrBy',
            Adapter::COMMAND_INCREMENT_FLOAT => 'hIncrByFloat',
            Adapter::COMMAND_SET => 'hSet',
            default => throw new InvalidArgumentException('Unknown command'),
        };
    }

    protected function toMetricKey(array $data): string
    {
        return self::$prefix . implode(':', [$data['type'] ?? '', $data['name'] ?? '']) . $this->getRedisTag($data['type'] ?? '');
    }

    protected function getMetricGatherKey(string $metricType): string
    {
        return self::$prefix . $metricType . self::$metricGatherKeySuffix . $this->getRedisTag($metricType);
    }

    /**
     * @param mixed[] $values
     * @return string
     * @throws RuntimeException
     */
    private function encodeLabelValues(array $values): string
    {
        $json = json_encode($values);
        if (false === $json) {
            throw new RuntimeException(json_last_error_msg());
        }
        return base64_encode($json);
    }

    /**
     * @param string $values
     * @return mixed[]
     * @throws RuntimeException
     */
    private function decodeLabelValues(string $values): array
    {
        $json = base64_decode($values, true);
        if (false === $json) {
            throw new RuntimeException('Cannot base64 decode label values');
        }
        $decodedValues = json_decode($json, true);
        if (false === $decodedValues) {
            throw new RuntimeException(json_last_error_msg());
        }
        return $decodedValues;
    }

    protected function getRedisTag(string $metricType): string
    {
        return match ($metricType) {
            Counter::TYPE => '{counter}',
            Histogram::TYPE => '{histogram}',
            Gauge::TYPE => '{gauge}',
            Summary::TYPE=>'{summary}',
            default => '',
        };
    }

    public static function fromExistingConnection(mixed $redis): self
    {
        return new self($redis);
    }


    public static function setMetricGatherKeySuffix(string $suffix): void
    {
        self::$metricGatherKeySuffix = $suffix;
    }

}

