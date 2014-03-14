<?php

namespace Ihsw\SecretBundle\Misc;

class TwigExtension extends \Twig_Extension
{
	/**
	 * required for being a service
	 */
	public function getName()
	{
		return "my.twig_extension";
	}

	/**
	 * twig-specific
	 */
	public function getFilters()
	{
		return array(
			"print_r" => new \Twig_Filter_Method($this, "printR"),
			"var_export" => new \Twig_Filter_Method($this, "varExport")
		);
	}

	/**
	 * our methods
	 */
	public function printR($value)
	{
		return print_r($value, true);
	}

	public function varExport($value)
	{
		return var_export($value, true);
	}
}