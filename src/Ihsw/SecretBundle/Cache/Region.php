<?php

namespace Ihsw\SecretBundle\Cache;

class Region
{
	public function findAll()
	{
		$redis = $this->redis;

		return $redis->lRange("region_ids", 0, -1);
	}
}