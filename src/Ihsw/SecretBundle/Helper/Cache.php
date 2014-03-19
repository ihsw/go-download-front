<?php

namespace Ihsw\SecretBundle\Helper;

class CacheHelper
{
	private $dataCache;

	public function __construct($auctionhelper)
	{
		$this->dataCache = [];
	}

	public function find($key)
	{
		return array_key_exists($key, $this->dataCache) ? $this->dataCache[$key] : null;
	}

	public function persist($key, $data)
	{
		$this->dataCache[$key] = $data;
	}

	public function persistList($keyFormat, $dataCache)
	{
		foreach ($dataCache as $id => $data)
		{
			$this->persist(sprintf($keyFormat, $id), $data);
		}
	}
}