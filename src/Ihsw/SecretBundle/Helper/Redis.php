<?php

namespace Ihsw\SecretBundle\Helper;

class Redis
{
	private $redis;
	private $params;

	public function __construct($params)
	{
		$this->params = $params;
		$this->redis = null;
	}

	public function getRedis()
	{
		if (!is_null($this->redis))
		{
			return $this->redis;
		}

		$params = $this->params;
		$this->redis = $redis = new \Redis();
		$redis->connect($params["host"], $params["port"]);
		$redis->select($params["db"]);
		return $redis;
	}
}