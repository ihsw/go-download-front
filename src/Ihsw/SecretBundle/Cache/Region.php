<?php

namespace Ihsw\SecretBundle\Cache;

class Region
{
	private $redis;

	public function __construct(\Redis $redis)
	{
		$this->redis = $redis;
	}

	public function greeting()
	{
		return "Hello, world!";
	}
}