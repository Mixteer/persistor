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

use Persistor\Interfaces\MetadataMapperInterface;

class IdentityMap
{
    protected static $maps = array();
    protected $class = "";

    public function __construct($class)
    {
        if (!is_string($class)) {
            throw new \InvalidArgumentException("The class definition from the metadata mapper must be a string.");
        }

        $this->class = $class;

        self::$maps[$class]["__instance__"] = $this;
        self::$maps[$class]["map"] = array();
    }

    public static function factory(MetadataMapperInterface $metadataMapper)
    {
        if (isset(self::$maps[$metadataMapper->getClass()])) {
            return self::$maps[$metadataMapper->getClass()]["__instance__"];
        }

        return new self($metadataMapper->getClass());
    }

    public function get($id)
    {
        if (isset(self::$maps[$this->class]["map"][$id])) {
            return self::$maps[$this->class]["map"][$id];
        }

        return null;
    }

    public function set($object)
    {
        $id = spl_object_hash($object);

        if (isset(self::$maps[$this->class]["map"][$id])) {
            throw new \InvalidArgumentException("The given ID already exists in the identity map.");
        }

        if (is_object($object) && !($object instanceof $this->class)) {
            throw new \InvalidArgumentException("This map expects object of type `". $this->class ."` but type received is `". get_class($object) ."`");
        }

        self::$maps[$this->class]["map"][$id] = $object;

        return $id;
    }

    public function remove($id)
    {
        $offset = array_search($id, array_keys(self::$maps[$this->class]["map"]));

        if ($offset === false) {
            return array();
        }

        return array_splice(self::$maps[$this->class]["map"], $offset , 1);
    }

    public function map($map = null)
    {
        if (!is_null($map) && !is_array($map)) {
            throw new \InvalidArgumentException("The map for the idenity map is expected to be an array that maps IDs to objects.");
        }

        if (!is_null($map)) {
            self::$maps[$this->class]["map"] = $map;
        }

        return self::$maps[$this->class]["map"];
    }
}
