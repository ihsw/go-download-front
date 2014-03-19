<?php

namespace Ihsw\SecretBundle\Cache;

use Ihsw\SecretBundle\AuctionUtility;
use Ihsw\SecretBundle\Helper\Cache as CacheHelper;
use Ihsw\SecretBundle\Helper\Redis as RedisHelper;

abstract class AbstractNewCache
{
	protected $cacheHelper;
	protected $redisHelper;

	private $entity;

	public function __construct(CacheHelper $cacheHelper, RedisHelper $redisHelper)
	{
		$this->cacheHelper = $cacheHelper;
		$this->redisHelper = $redisHelper;

		$this->entity = null;
	}

	// utility
	protected function resolveSettings($defaultSettings, $options)
	{
		return array_merge($defaultSettings, $options);
	}

	protected function getRedis()
	{
		return $this->getRedis();
	}

	// basic accessors
	public function fetchFromId($id, $options = [])
	{
		$dataList = $this->fetchFromIds([$id], $options);
		return empty($dataList) ? null : $dataList[$id];
	}

	public function fetchFromIds($ids, $options = [])
	{
		$settings = $this->resolveSettings([
			"unmarshal" => true,
			"namespace" => static::$namespace
		], $options);

		$cachehelper = $this->cacheHelper;
		$redis = $cachehelper->getRedis();

		// misc
		$keyFormat = sprintf("%s:%%s", $settings["namespace"]);
		$dataList = [];

		// checking the cachehelper cache
		$remainingIds = [];
		foreach ($ids as $id)
		{
			$data = $cachehelper->find(sprintf($keyFormat, $id));
			if (is_null($data))
			{
				$remainingIds[$id] = $id;
				continue;
			}

			$dataList[$id] = $data;
		}

		// checking redis
		if (!empty($remainingIds))
		{
			$redis->multi(\Redis::PIPELINE);
			foreach ($remainingIds as $id)
			{
				list($bucketKey, $remainder) = $this->getBucketKey($id, $settings["namespace"]);
				$redis->hGet($bucketKey, $remainder);
			}
			$remainingDataList = $redis->exec();

			// decoding the data list and removing values that aren't json-decodable
			$remainingDataList = array_filter(array_map(function($data){
				return json_decode($data, true);
			}, $remainingDataList), function($data){
				return !is_null($data);
			});

			// optionally swapping keys in each item in the remaining data list
			if ($this->canSwap)
			{
				$remainingDataList = array_map(function($data){
					return $this->swapKeys($data);
				}, $remainingDataList);
			}

			// converting it to an array keyed by each entity's id
			$keys = array_map(function($data){
				return $data["id"];
			}, $remainingDataList);
			$remainingDataList = array_combine($keys, array_values($remainingDataList));

			// pushing the remaining data list onto the total data list
			$dataList = $dataList + $remainingDataList;
		}

		// sorting by ID
		usort($dataList, function($a, $b){
			if ($a["id"] == $b["id"])
			{
				return 0;
			}

			return $b["id"] > $a["id"] ? -1 : 1;
		});

		// converting it to an array keyed by each entity's id (since usort didn't preserve keys)
		$keys = array_map(function($data){
			return $data["id"];
		}, $dataList);
		$dataList = array_combine($keys, array_values($dataList));

		// pushing it onto the cachehelper cache
		$cachehelper->persistList($keyFormat, $dataList);

		return $settings["unmarshal"] === false ? $dataList : $this->unmarshalAll($dataList);
	}

	// derived accessors
	public function findAllInList($listKey, $options = [])
	{
		$settings = $this->resolveSettings([
			"start" => 0,
			"end" => -1
		], $options);

		return $this->fetchFromIds($this->getRedis()->lRange($listKey, $settings["start"], $settings["end"]), $settings);
	}

	public function findAllInZset($zsetKey, $options = [])
	{
		$settings = $this->resolveSettings([
			"start" => 0,
			"end" => -1
		], $options);

		return $this->fetchFromIds($this->getRedis()->zRange($zsetKey, $settings["start"], $settings["end"]), $settings);
	}

	public function findAllInSet($setKey, $options = [])
	{
		return $this->fetchFromIds($this->getRedis()->sMembers($setKey), $options);
	}

	protected function findOneByKey($key, $options = [])
	{
		$dataList = $this->findAllInKeys([$key], $options);
		return empty($dataList) ? null : current($dataList);
	}

	protected function findAllInKeys($keys, $options = [])
	{
		// going over the keys
		$redis = $this->getRedis();
		$redis->multi(\Redis::PIPELINE);
		foreach ($keys as $key)
		{
			$redis->get($key);
		}

		// gathering valid ids
		$ids = array_filter($redis->exec(), function($id){
			return $id !== false;
		});

		return $this->fetchFromIds($ids, $options);
	}

	// persistance
	protected function persistHashBucket($id, $data, $encode = true)
	{
		// generating a bucket key/remainder pair for this entity
		list($bucketKey, $remainder) = $this->getBucketKey($id);

		// optionally json-encoding
		if ($encode)
		{
			if (is_array($data))
			{
				$data = $this->swapKeys($data, true);
			}
			$data = json_encode($data, JSON_FORCE_OBJECT);
		}

		// pushing the data into the hash
		$this->getRedis()->hSet($bucketKey, $remainder, $data);
	}

	protected function persistChildKeys($childKeys, $value)
	{
		// misc
		$redis = $this->getRedis();

		// going over the child keys
		$redis->multi(\Redis::PIPELINE);
		foreach ($childKeys as $key)
		{
			$redis->set($key, $value);
		}
		$redis->exec();
	}

	protected function persistLists($listKeys, $value)
	{
		// misc
		$redis = $this->getRedis();

		// going over the list keys
		$redis->multi(\Redis::PIPELINE);
		foreach ($listKeys as $key)
		{
			$redis->rPush($key, $value);
		}
		$redis->exec();
	}

	// inherited
	protected function unmarshal($data)
	{
		return $this->unmarshalAll([$data["id"] => $data])[$data["id"]];
	}

	protected function unmarshalAll($dataList)
	{
		return $dataList;
	}

	protected function marshal($entity)
	{
		return $entity;
	}

	public function persist(&$entity)
	{
		$entities = [$entity];
		$this->persistAll($entity);
		$entity = $entities[0];
	}

	protected function getBucketKey($id, $namespace = null)
	{
		// resolving the namespace
		if (is_null($namespace))
		{
			$namespace = static::$namespace;
		}

		// getting the subkey
		$itemsPerBucket = CacheHelper::ITEMS_PER_BUCKET;
		$remainder = $id % $itemsPerBucket;

		// getting the bucket key
		$bucketId = ($id - $remainder) / $itemsPerBucket;
		$bucketKey = sprintf("%s_bucket:%s", $namespace, $bucketId);

		return [$bucketKey, $remainder];
	}

	protected function isNew($idKey, &$data)
	{
		$isNew = array_key_exists("id", $data) === false;
		if ($isNew)
		{
			$data["id"] = $this->getRedis()->incr($idKey);
		}

		return $isNew;
	}

	public function newEntity()
	{
		if (is_null($this->entity))
		{
			$this->entity = array_flip(static::$keys);
		}

		return $this->entity;
	}

	protected function swapKeys($data, $flip = false)
	{
		$keys = static::$keys;

		// optionally flipping the keys
		if ($flip === true)
		{
			$keys = array_flip($keys);
		}

		// creating a new array keyed by this entity's keys where possible
		$_data = [];
		foreach ($data as $key => $value)
		{
			if (array_key_exists($key, $keys))
			{
				$key = $keys[$key];
			}

			$_data[$key] = $value;
		}

		return $_data;
	}
}