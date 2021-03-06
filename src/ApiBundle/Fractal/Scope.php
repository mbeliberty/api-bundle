<?php

/*
 * This file is part of the League\Fractal package.
 *
 * (c) Phil Sturgeon <me@philsturgeon.uk>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 **/

namespace Eliberty\ApiBundle\Fractal;

use Doctrine\Common\Inflector\Inflector;
use Doctrine\ORM\EntityNotFoundException;
use Eliberty\ApiBundle\Api\Resource;
use Eliberty\ApiBundle\Context\GroupsContextChainer;
use Eliberty\ApiBundle\Fractal\Serializer\DataHydraSerializer;
use League\Fractal\Resource\Item;
use League\Fractal\Resource\ResourceInterface;
use League\Fractal\Scope as BaseFractalScope;
use League\Fractal\Resource\Collection;
use Dunglas\ApiBundle\Api\ResourceInterface as DunglasResource;
use League\Fractal\Serializer\SerializerAbstract;
use League\Fractal\TransformerAbstract;

/**
 * Scope.
 *
 * The scope class acts as a tracker, relating a specific resource in a specific
 * context. For example, the same resource could be attached to multiple scopes.
 * There are root scopes, parent scopes and child scopes.
 */
class Scope extends BaseFractalScope
{
    /**
     * @var DunglasResource
     */
    protected $dunglasResource;

    /**
     * @var TransformerAbstract
     */
    protected $transformer;
    /**
     * @var array
     */
    protected $data;

    /**
     * @var Scope
     */
    protected $parent;

    /**
     * @var string
     */
    const HYDRA_COLLECTION = 'hydra:Collection';
    /**
     * @var string
     */
    const HYDRA_PAGED_COLLECTION = 'hydra:PagedCollection';

    /**
     * @var Logger
     */
    protected $logger;

    /**
     * Convert the current data for this scope to an array.
     *
     * @return array
     *
     * @throws \Exception
     */
    public function toArray()
    {
        $this->resource->setResourceKey($this->scopeIdentifier);

        if ($this->resource instanceof Collection) {
            return $this->collectionNormalizer();
        }

        return $this->itemNormalizer();
    }

    protected function collectionNormalizer()
    {
        $serializer = $this->manager->getSerializer();

        // Don't use hydra:Collection in sub levels
        $context['json_ld_sub_level'] = true;

        list($rawData, $rawIncludedData) = $this->executeResourceTransformers();

        if (!\is_array($rawData) || \count($rawData) === 0) {
            return [];
        }

        $data = $this->serializeResource($serializer, $rawData);

        if ($this->resource instanceof Collection) {
            if ($this->resource->hasCursor()) {
                $pagination = $serializer->cursor($this->resource->getCursor());
            } elseif ($this->resource->hasPaginator()) {
                $pagination = $serializer->paginator($this->resource->getPaginator());
            }

            if (!empty($pagination)) {
                $this->resource->setMetaValue(key($pagination), current($pagination));
            }
        }

        // Pull out all of OUR metadata and any custom meta data to merge with the main level data
        $meta = $serializer->meta($this->resource->getMeta());

        return array_merge($meta, $data);
    }

    /**
     * check if scope has parent.
     */
    public function hasParent()
    {
        return $this->parent instanceof self;
    }

    /**
     * @return array
     *
     * @throws \Exception
     */
    protected function itemNormalizer()
    {
        $serializer = $this->manager->getSerializer();

        // Don't use hydra:Collection in sub levels
        $context['json_ld_sub_level'] = true;

        list($rawData, $rawIncludedData) = $this->executeResourceTransformers();

        $data = $this->serializeResource($serializer, $rawData);

        if (is_array($data) && null === $this->getDunglasResource()) {
            return $data;
        }

        // If the serializer wants the includes to be side-loaded then we'll
        // serialize the included data and merge it with the data.
        if ($serializer->sideloadIncludes()) {
            $includedData = $serializer->includedData($this->resource, $rawIncludedData);

            $data = array_merge($data, $includedData);
        }

        // Pull out all of OUR metadata and any custom meta data to merge with the main level data
        $meta = $serializer->meta($this->resource->getMeta());

        if (!is_array($data)) {
            return null;
        }

        return array_merge($meta, $data);
    }

    /**
     * Execute the resources transformer and return the data and included data.
     *
     * @internal
     *
     * @return array
     */
    protected function executeResourceTransformers()
    {
        $data = $this->resource->getData();

        if (null === $data || is_array($data)) {
            return [$data, []];
        }

        return parent::executeResourceTransformers();
    }

    /**
     * @return string
     */
    protected function getEntityName()
    {
        if (substr($this->scopeIdentifier, -1) === 's') {
            return ucwords(Inflector::singularize($this->scopeIdentifier));
        }

        return ucwords($this->scopeIdentifier);
    }

    /**
     * @throws \Exception
     */
    public function getDunglasResource()
    {
        if (null !== $this->dunglasResource) {
            return $this->dunglasResource;
        }

        $collection = $this->manager->getResourceCollection();
        if (null === $collection) {
            throw new \RuntimeException('unable to guess resource collection ' . $this->getEntityName());
        }

        $resource = $collection->getResourceForShortName(
            $this->getEntityName(),
            $this->getApiVersion()
        );

        return $resource === null ? new Resource(null, null) : $resource;
    }

    /**
     * @throws \Exception
     */
    protected function getApiVersion()
    {
        $version = 'v2';
        if ($this->parent instanceof self && $this->parent->getDunglasResource() instanceof DunglasResource) {
            $version = $this->parent->getDunglasResource()->getVersion();
        }

        return $version;
    }

    /**
     * Fire the main transformer.
     *
     * @internal
     *
     * @param TransformerAbstract|callable $transformer
     * @param mixed                        $data
     *
     * @return array
     *
     * @throws EntityNotFoundException
     */
    protected function fireTransformer($transformer, $data)
    {
        $this->transformer = $transformer;
        $includedData      = [];
        $transformedData   = [];
        $dataTransformer   = [];

        if ($this->getManager()->getSerializer() instanceof DataHydraSerializer && !empty($data)) {
            $transformedData['@id'] = $this->getGenerateRoute($data);
        }

        if ($this->getManager()->getSerializer() instanceof DataHydraSerializer && !empty($this->getEntityName())) {
            $transformedData['@type'] = $this->getEntityName();
        }

        try {
            $dataTransformer = is_callable($transformer) ? call_user_func($transformer, $data) : $transformer->transform($data);
        } catch (EntityNotFoundException $e) {
            if ($this->resource instanceof Item && !$this->parent instanceof self) {
                throw new EntityNotFoundException();
            }
        }

        $transformedData = array_merge($transformedData, $dataTransformer);

        if ($this->getManager()->getGroupsContextChainer() instanceof GroupsContextChainer && $this->getDunglasResource() instanceof DunglasResource) {
            $transformedData = $this->getManager()
                ->getGroupsContextChainer()
                ->serialize(
                    $this->transformer->getCurrentResourceKey(),
                    $transformedData,
                    $this->getDunglasResource()->getVersion()
                );
        }

        if ($this->transformerHasIncludes($transformer)) {
            $includedData = $this->fireIncludedTransformers($transformer, $data);
            // If the serializer does not want the includes to be side-loaded then
            // the included data must be merged with the transformed data.
            if (!$this->manager->getSerializer()->sideloadIncludes() && is_array($includedData)) {
                $transformedData = array_merge($transformedData, $includedData);
            }
        }

        return array($transformedData, $includedData);
    }

    /**
     * @return ResourceInterface
     */
    public function getResource()
    {
        return $this->resource;
    }

    /**
     * @param array
     *
     * @return $this
     */
    public function setData($data)
    {
        $this->data = $data;

        return $this;
    }

    /**
     * @return array
     */
    public function getData()
    {
        return $this->data;
    }

    /**
     * @return scope
     */
    public function getParent()
    {
        return $this->parent;
    }

    /**
     * @param mixed $parent
     *
     * @return $this
     */
    public function setParent($parent)
    {
        $this->parent = $parent;

        return $this;
    }

    /**
     * Determine if a transformer has any available includes.
     *
     * @internal
     *
     * @param TransformerAbstract|callable $transformer
     *
     * @return bool
     */
    protected function transformerHasIncludes($transformer)
    {
        $parentScope = $this->getParent();

        if (!$parentScope instanceof self) {
            return parent::transformerHasIncludes($transformer);
        }

        if ($parentScope->getDunglasResource()->getShortName() === $this->getDunglasResource()->getShortName()) {
            return true;
        }

        if ($parentScope->getParent() instanceof self) {
            $embedsRequest = array_keys($transformer->getRequestEmbeds());
            $transformer->setDefaultIncludes([]);

            return in_array(strtolower($this->getIdentifierWithoutSourceIdentifier()), $embedsRequest);
        }

        return true;
    }

    /**
     * @param string $position
     *
     * @return mixed
     */
    public function getSingleIdentifier($position = 'desc')
    {
        $identifiers = explode('.', $this->getIdentifier());

        return ('desc' === $position) ? array_pop($identifiers) : array_shift($identifiers);
    }

    /**
     * @return string
     */
    public function getIdentifierWithoutSourceIdentifier()
    {
        return str_replace($this->getSingleIdentifier('asc').'.', '', $this->getIdentifier());
    }

    /**
     * @param DunglasResource $dunglasResource
     *
     * @return $this
     */
    public function setDunglasResource($dunglasResource)
    {
        $this->dunglasResource = $dunglasResource;

        return $this;
    }

    /**
     * @param $data
     *
     * @return mixed
     */
    public function getGenerateRoute($data, $params = [])
    {
        $router = $this->getManager()->getRouter();
        if (null !== $router) {
            if (method_exists($router, 'setScope')) {
                $router->setScope($this);
            }
            return $router->generate($data, $params);
        }

        return [];
    }

    /**
     * Serialize a resource.
     *
     * @internal
     *
     * @param SerializerAbstract $serializer
     * @param mixed              $data
     *
     * @return array
     */
    protected function serializeResource(SerializerAbstract $serializer, $data)
    {
        $serializer->setScope($this);

        if (is_array($data)) {
            return parent::serializeResource($serializer, $data);
        }

        return null;
    }
}
