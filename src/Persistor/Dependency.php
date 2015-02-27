<?php
/**
 * Persistor - A minimal ORM libray for quick development.
 *
 * @author      Ntwali Bashige <ntwali.bashige@gmail.com>
 * @copyright   2015 Mixteer
 * @link        http://os.mixteer.com/baseutils/persistor
 * @license     http://os.mixteer.com/baseutils/persistor/license
 * @version     0.1.0
 *
 * MIT LICENSE
 */

namespace Persistor;

class Dependency
{
    protected $object = null;
    protected $dependencies = array();
    protected $unitOfWork = null;

    public function __construct($object)
    {
        $this->object = $object;
        $this->unitOfWork = UnitOfWork::getInstance();
    }

    public function getObject()
    {
        return $this->object;
    }

    public function getDependencies()
    {
        return $this->dependencies;
    }

    public function dependsOn($object)
    {
        if ($object instanceof self) {
            $object = $object->getObject();
        }

        $objectId = spl_object_hash($object);

        if (!is_object($object)) {
            throw new \InvalidArgumentException("The element to add as a dependency must be an object. Element of type `". gettype($object) ."` given.");
        }

        if ($this->unitOfWork === null) {
            throw new \Exception("The unit of work has not been initialized therefore no dependencies can be defined.");
        }

        $registeredObject = $this->unitOfWork->getRegisteredObject($objectId);        
        if ($registeredObject == null) {
            throw new \InvalidArgumentException("The object to add as a dependency must registered with the unit of work.");
        }

        $dependency = $registeredObject["dependency-object"];

        $this->dependencies[] = $dependency;
    }

    public function resolve()
    {
        $resolved = [];
        $seen = [];

        $this->resolveDependencies($this, $resolved, $seen);

        return $resolved;
    }

    private function resolveDependencies($parent, &$resolved, &$seen)
    {
        $seen[] = $parent;

        $dependencies = $parent->getDependencies();

        foreach ($dependencies as $dependency) {
            if (in_array($dependency, $resolved) === false) {
                if (in_array($dependency, $seen) === true) {
                    throw new \Exception("Circular dependency detected. Please check your dependencies.");
                }

                $this->resolveDependencies($dependency, $resolved, $seen);
            }        
        }

        $resolved[] = $parent;
    }
}
