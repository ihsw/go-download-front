<?php

namespace Ihsw\SecretBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\JsonResponse;
use Ihsw\SecretBundle\Cache\Region;

class DefaultController extends Controller
{
    public function indexAction()
    {
    	// services
    	$container = $this->get('service_container');
    	$redisHelper = $this->get("my.redis_helper");

        // caches
    	$redis = $redisHelper->getRedis();
    	$regionCache = new Region($redis);

        return $this->render('IhswSecretBundle:Default:home.html.twig', array(
        	"greeting" => $regionCache->greeting()
    	));
    }
}