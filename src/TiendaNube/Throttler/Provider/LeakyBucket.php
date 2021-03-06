<?php declare(strict_types=1);

namespace TiendaNube\Throttler\Provider;

use TiendaNube\Throttler\Exception\LeakyBucketException;
use TiendaNube\Throttler\Exception\StorageNotDefinedException;
use TiendaNube\Throttler\Storage\StorageInterface;

/**
 * Class LeakyBucket
 *
 * @package TiendaNube\Throttler\Provider
 */
class LeakyBucket implements ProviderInterface
{
    /**
     * Default Leaky Bucket settings
     */
    const DEFAULT_CAPACITY = 10;
    const DEFAULT_LEAK_RATE = 1;

    /**
     * The bucket capacity
     *
     * @var int
     */
    private $capacity;

    /**
     * The bucket leak rate by seconds
     *
     * @var float
     */
    private $leakRate;

    /**
     * The current storage adapter
     *
     * @var StorageInterface
     */
    private $storage;

    /**
     * LeakyBucket constructor.
     *
     * @param int $capacity
     * @param float $leakRate
     * @param StorageInterface|null $storage
     * @throws LeakyBucketException
     */
    public function __construct(
        int $capacity = self::DEFAULT_CAPACITY,
        float $leakRate = self::DEFAULT_LEAK_RATE,
        StorageInterface $storage = null
    ) {
        if ($capacity > 0) {
            $this->capacity = $capacity;
        } else {
            throw new LeakyBucketException('The bucket capacity should be greater than zero.');
        }

        if ($leakRate > 0) {
            $this->leakRate = $leakRate;
        } else {
            throw new LeakyBucketException('The bucket leak rate should be greater than zero.');
        }

        if (!is_null($storage)) {
            $this->setStorage($storage);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function setStorage(StorageInterface $storage)
    {
        $this->storage = $storage;
    }

    /**
     * {@inheritdoc}
     */
    public function getStorage()
    {
        if (is_null($this->storage)) {
            throw new StorageNotDefinedException('You must define an storage adapter');
        }

        return $this->storage;
    }

    /**
     * {@inheritdoc}
     */
    public function incrementUsage(string $namespace, int $count = 1): int
    {
        $bucket = $this->getBucket($namespace);

        $drops = $bucket['drops'];
        $limit = $this->getLimit($namespace);

        if (($drops + $count) > $limit) {
            $exceeded = ($drops + $count) - $limit;
            throw new LeakyBucketException(sprintf(
                'Cannot increment bucket in %s drops, exceeded bucket capacity by %s',
                $count, $exceeded
            ));
        }

        $filledBucket = $this->fillBucket($bucket,$count);
        $this->saveBucket($namespace,$filledBucket);

        return $filledBucket['drops'];
    }

    /**
     * {@inheritdoc}
     */
    public function getRatio(string $namespace, int $factor = self::RATIO_FACTOR_BY_SECOND): int
    {
        return intval(ceil($this->leakRate * $factor));
    }

    /**
     * {@inheritdoc}
     */
    public function getUsage(string $namespace): int
    {
        $bucket = $this->getBucket($namespace);
        return $bucket['drops'];
    }

    /**
     * {@inheritdoc}
     */
    public function getLimit(string $namespace): int
    {
        return $this->capacity;
    }

    /**
     * {@inheritdoc}
     */
    public function hasLimit(string $namespace): bool
    {
        return ($this->getLimit($namespace) - $this->getUsage($namespace)) > 0;
    }

    /**
     * {@inheritdoc}
     */
    public function getRemaining(string $namespace): int
    {
        return $this->getLimit($namespace) - $this->getUsage($namespace);
    }

    /**
     * {@inheritdoc}
     */
    public function getEstimate(string $namespace): int
    {
        return !$this->hasLimit($namespace) ? intval(ceil(1000 / $this->leakRate)) : 0;
    }

    /**
     * {@inheritdoc}
     */
    public function getReset(string $namespace): int
    {
        $bucket = $this->getBucket($namespace);

        if ($bucket['drops'] > 0) {
            $remainingTimeInSeconds = ceil($bucket['drops'] / $this->leakRate);
            return intval($remainingTimeInSeconds * 1000);
        }

        return 0;
    }

    /**
     * Save the current bucket in the storage.
     *
     * @param string $namespace
     * @param array $bucket
     * @throws StorageNotDefinedException
     * @return bool
     */
    protected function saveBucket(string $namespace, array $bucket): bool
    {
        $storage = $this->getStorage();

        $serializedBucket = serialize($bucket);
        if ($storage->hasItem($namespace)) {
            return $storage->replaceItem($namespace,$serializedBucket);
        }

        return $storage->setItem($namespace,$serializedBucket);
    }

    /**
     * Get the bucket from the storage.
     *
     * @param string $namespace
     * @throws StorageNotDefinedException
     * @throws LeakyBucketException
     * @return array
     */
    protected function getBucket(string $namespace): array
    {
        $storage = $this->getStorage();

        if ($storage->hasItem($namespace)) {
            $bucket = unserialize($storage->getItem($namespace));

            $leakedBucket = $this->leakBucket($bucket);

            if ($this->saveBucket($namespace,$leakedBucket)) {
                return $leakedBucket;
            } else {
                throw new LeakyBucketException(
                    'An error occurred at try to save the leaked bucket in the storage'
                );
            }
        }

        return $this->getEmptyBucket();
    }

    /**
     * Fill the bucket with a number of drops.
     *
     * @param array $bucket
     * @param int $drops
     * @return array
     */
    protected function fillBucket(array $bucket, int $drops): array
    {
        $current = $bucket['drops'];

        if ($current + $drops >= $this->capacity) {
            $bucket['drops'] = $this->capacity;
        } else {
            $bucket['drops'] += $drops;
        }

        $bucket['timestamp'] = microtime(true);

        return $bucket;
    }

    /**
     * Leak the expired drops from the bucket.
     *
     * @param array $bucket
     * @return array
     */
    protected function leakBucket(array $bucket): array
    {
        $elapsed = microtime(true) - $bucket['timestamp'];
        $leakage = round($elapsed * $this->leakRate);

        $bucket['drops'] = ($leakage <= $bucket['drops']) ? intval($bucket['drops'] - $leakage) : 0;
        $bucket['timestamp'] = microtime(true);

        return $bucket;
    }

    /**
     * Get an empty bucket.
     *
     * @return array
     */
    private function getEmptyBucket(): array
    {
        return [
            'drops' => 0,
            'timestamp' => microtime(true),
        ];
    }
}
