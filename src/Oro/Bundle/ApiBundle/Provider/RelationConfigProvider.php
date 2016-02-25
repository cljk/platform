<?php

namespace Oro\Bundle\ApiBundle\Provider;

use Oro\Bundle\ApiBundle\Config\Config;
use Oro\Bundle\ApiBundle\Config\ConfigExtraInterface;
use Oro\Bundle\ApiBundle\Processor\Config\GetRelationConfig\RelationConfigContext;
use Oro\Bundle\ApiBundle\Processor\Config\RelationConfigProcessor;

class RelationConfigProvider extends AbstractConfigProvider
{
    /** @var RelationConfigProcessor */
    protected $processor;

    /** @var array */
    protected $cache = [];

    /**
     * @param RelationConfigProcessor $processor
     */
    public function __construct(RelationConfigProcessor $processor)
    {
        $this->processor = $processor;
    }

    /**
     * Gets a config for the given version of an entity.
     *
     * @param string                 $className   The FQCN of an entity
     * @param string                 $version     The version of a config
     * @param string[]               $requestType The request type, for example "rest", "soap", etc.
     * @param ConfigExtraInterface[] $extras      Requests for additional configuration data
     *
     * @return Config
     */
    public function getRelationConfig($className, $version, array $requestType = [], array $extras = [])
    {
        if (empty($className)) {
            throw new \InvalidArgumentException('$className must not be empty.');
        }

        $cacheKey = $this->buildCacheKey($className, $version, $requestType, $extras);
        if (array_key_exists($cacheKey, $this->cache)) {
            return $this->cache[$cacheKey];
        }

        /** @var RelationConfigContext $context */
        $context = $this->processor->createContext();
        $this->initContext($context, $className, $version, $requestType, $extras);

        $this->processor->process($context);

        $config = $this->buildResult($context);

        $this->cache[$cacheKey] = $config;

        return $config;
    }
}
