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

use Persistor\Exceptions\InvalidColumnException;
use Persistor\Exceptions\InvalidTableException;
use Persistor\Exceptions\InvalidClassException;
use Persistor\Exceptions\InvalidMethodException;
use Persistor\Exceptions\InvalidPropertyException;
use Persistor\Exceptions\FailedQueryException;

use Persistor\Interfaces\MetadataMapperInterface;

class Persistor
{
    protected $metadataMapper = null;
    protected $queryRunner = null;
    protected $queryBuilder = null;
    protected $container = array();

    protected $identityMap = null;
    protected $unitOfWork = null;

    protected static $credentials = array();

    public function __construct(MetadataMapperInterface $metadataMapper, $unitOfWork = null)
    {
        $this->metadataMapper = $this->initMetadataMapper($metadataMapper);
        $this->queryRunner = new QueryRunner();
        $this->initContainer();

        $this->identityMap = IdentityMap::factory($metadataMapper);
        $this->unitOfWork = $unitOfWork;

        $this->queryBuilder = $this->unitOfWork->connection()->createQueryBuilder();
        $this->queryRunner->connection($this->unitOfWork->connection());
    }

    private function initContainer()
    {
        $keys = $this->metadataMapper->getKeys();

        foreach ($keys as $value) {
            $this->container[$value] = array();
        }
    }

    private function initMetadataMapper($metadataMapper)
    {
        // Validate the database table
        $table = $metadataMapper->getTable();
        if (!is_string($table)) {
            throw new InvalidColumnException("The table given is not of type string.");
        }

        // Validate the database columns
        $columns = array_values($metadataMapper->getMapping());
        foreach ($columns as $column) {
            if (!is_string($column)) {
                throw new InvalidColumnException("One of the columns in the class-table mapping is not of type string.");
            }
        }        

        // Validate the given class
        $class = $metadataMapper->getClass();
        if (!class_exists($class)) {
            throw new InvalidClassException("The given class to the metadata mapper does not exist.");
        }

        // Validate the class properties
        $properties = array_keys($metadataMapper->getMapping());
        foreach ($properties as $property) {
            if (!is_string($property)) {
                throw new InvalidPropertyException("One of the properties in the class-table mapping is not of type string.");
            }

            if (strpos($property, ":") === false && !property_exists($class, $property)) {
                throw new InvalidPropertyException("The property `$property` does not exist on the class and makes the mapping invalid.");
            }
        }

        // Validate that the construction method exists on the given class
        $method = $metadataMapper->getConstructMethod();
        if (!method_exists($class, $method)) {
            throw new InvalidMethodException("The given method `$method` that should be used to build the object does not on the class `$class`.");
        }

        // The ID setting method is not compulsory?

        return $metadataMapper;
    }

    public static function factory($metadataMapper, $credentials = null)
    {
        $unitOfWork = UnitOfWork::getInstance();

        if ($credentials !== null) {
            $unitOfWork->connection($credentials);
        }

        return new self($metadataMapper, $unitOfWork);
    }

    public function connection($credentials = null)
    {
        return $this->unitOfWork->connection($credentials);
    }

    public function unitOfWork($unitOfWork = null)
    {
        if ($unitOfWork !== null) {
            $this->unitOfWork = $unitOfWork;
        }

        return $this->unitOfWork;
    }

    public function identityMap($identityMap = null)
    {
        if ($identityMap != null) {
            $this->identityMap = $identityMap;
        }

        return $this->identityMap;
    }

    public function getQueryRunner()
    {
        return $this->queryRunner;
    }

    public function getQueryBuilder()
    {
        return $this->queryBuilder;
    }

    public function getMetadataMapper()
    {
        return $this->metadataMapper;
    }

    public function insert($object, $returnInsertId = false)
    {
        $class = $this->metadataMapper->getClass();
        if (!($object instanceof $class)) {
            throw new \InvalidArgumentException("The object given for persistance is not an instance of $class or is not an object.");
        }

        // Get ID setter
        $setId = $this->metadataMapper->getIdSetter();

        $queryData = $this->getInsertQuery($object);
        $query = $queryData["query"];
        $parameters = $queryData["parameters"];

        try {
            $this->queryRunner->insert($parameters)->using($query)->run();
        } catch(FailedQueryException $exception) {
            throw $exception;
        }

        $insertId = $this->queryRunner->getLastInsertId();

        if (isset($this->metadataMapper->getKeys()["primary"])) {
            $identityMapKey = $this->identityMap->set($object);
            $this->container[$this->metadataMapper->getKeys()["primary"]][$insertId] = $identityMapKey;
        }

        if ($returnInsertId === true) {
            return $insertId;
        }

        $object->$setId($insertId);
        return $object;
    }

    public function update($object)
    {
        $class = $this->metadataMapper->getClass();
        if (!($object instanceof $class)) {
            throw new \InvalidArgumentException("The object given for update is not an instance of $class or is not an object.");
        }

        $queryData = $this->getUpdateQuery($object);
        $query = $queryData["query"];
        $parameters = $queryData["parameters"];

        try {
            $rowCount = $this->queryRunner->insert($parameters)->using($query)->run();
        } catch(FailedQueryException $exception) {
            throw $exception;
        }
        
        return $rowCount;
    }

    public function delete($object)
    {
        $class = $this->metadataMapper->getClass();
        if (!($object instanceof $class)) {
            throw new \InvalidArgumentException("The object given for deletion is not an instance of $class or is not an object.");
        }

        $queryData = $this->getDeleteQuery($object);
        $query = $queryData["query"];
        $parameters = $queryData["parameters"];

        try {
            $rowCount = $this->queryRunner->delete($parameters)->using($query)->run();
        } catch(FailedQueryException $exception) {
            throw $exception;
        }
        
        return $rowCount;
    }

    public function getLastInsertId()
    {
        return $this->queryRunner->getLastInsertId();
    }

    public function getBy($field, $parameter)
    {
        $identityMapKey = null;

        // Attempt to get the data from the identity map
        //if (isset($this->container[$field]) && count($this->container[$field])) -> Is this required?
        if (isset($this->container[$field])) {
            $identityMapKey = isset($this->container[$field][$parameter]) ? $this->container[$field][$parameter] : null;
        }

        if ($identityMapKey !== null) {
            return $this->identityMap->get($identityMapKey);
        }

        // Previous attemps failed, we get the object from the database
        $object = $this->readByKey($field, $parameter);

        // If we got an object, add it to the identity map
        if ($object !== null && isset($this->container[$field])) {
            $id = $this->identityMap->set($object);
            $this->container[$field][$parameter] = $id;
        }

        // Return the new object
        return $object;
    }

    public function getWhere($field, $parameter)
    {
        $objects = $this->readByWhere($field, $parameter);
        return $objects;
    }

    public function getLike($field, $parameter)
    {
        $objects = $this->readByLike($field, $parameter);
        return $objects;
    }

    private function readByKey($field, $parameter)
    {
        $result = array();

        $columns = array_values($this->metadataMapper->getMapping());
        $table = $this->metadataMapper->getTable();        

        $query = $this->queryBuilder
                      ->select($columns)
                      ->from($table)
                      ->where("$field = :$field")
                      ->setParameter(":$field", $parameter);

        $parameters = $query->getParameters();

        try {
            $result = $this->queryRunner
                           ->read($parameters, QueryRunner::FETCH)
                           ->using($query)
                           ->run();
        } catch(FailedQueryException $exception) {
            throw $exception;
        }

        if ($result !== false) {
            return $this->hydrate($result);
        }

        return null;
    }

    private function readByLike($field, $parameter)
    {
        $results = array();
        $objects = array();

        $columns = array_values($this->metadataMapper->getMapping());
        $table = $this->metadataMapper->getTable();        

        $query = $this->queryBuilder
                      ->select($columns)
                      ->from($table)
                      ->where("$field like :$field")
                      ->setParameter(":$field", "%$parameter%");

        $parameters = $query->getParameters();

        try {
            $results = $this->queryRunner
                           ->read($parameters, QueryRunner::FETCH_ALL)
                           ->using($query)
                           ->run();
        } catch(FailedQueryException $exception) {
            throw $exception;
        }

        if (count($results) > 0) {
            foreach ($results as $row) {
                $objects[] = $this->hydrate($row);
            }
        }

        return $objects;
    }

    private function readByWhere($field, $parameter)
    {
        $results = array();
        $objects = array();

        $columns = array_values($this->metadataMapper->getMapping());
        $table = $this->metadataMapper->getTable();        

        $query = $this->queryBuilder
                      ->select($columns)
                      ->from($table)
                      ->where("$field = :$field")
                      ->setParameter(":$field", "$parameter");

        $parameters = $query->getParameters();

        try {
            $results = $this->queryRunner
                           ->read($parameters, QueryRunner::FETCH_ALL)
                           ->using($query)
                           ->run();
        } catch(FailedQueryException $exception) {
            throw $exception;
        }

        if (count($results) > 0) {
            foreach ($results as $row) {
                $objects[] = $this->hydrate($row);
            }
        }

        return $objects;
    }

    /**
     * The methods bellow deal with batch queries via the unit of work
     */
    public function getInsertQuery($object)
    {
        $class = $this->metadataMapper->getClass();
        if (!($object instanceof $class)) {
            throw new \InvalidArgumentException("The object given for persistance is not an instance of $class or is not an object.");
        }

        // Deconstruct the object
        $values = $this->extract($object);

        // Get mappings and table
        $mappings = $this->metadataMapper->getMapping();
        $columns = array_values($mappings);
        $table = $this->metadataMapper->getTable();
        $primaryKey = isset($this->metadataMapper->getKeys()["primary"]) ? $this->metadataMapper->getKeys()["primary"] : null;

        // Prepare query
        $query = $this->queryBuilder
                      ->insert($table)
                      ->setParameters(array());

        foreach ($columns as $column) {
            if ($column !== $primaryKey) {
                $query->setValue("$column", ":$column");
            }
        } 

        foreach ($mappings as $property => $column) {
            if ($column !== $primaryKey) {
                $query->setParameter(":$column", $values[$property]);                
            }
        }

        $parameters = $query->getParameters();

        return array(
            "parameters" => $parameters,
            "query" => $query
        );
    }

    public function getUpdateQuery($object)
    {
        $class = $this->metadataMapper->getClass();
        if (!($object instanceof $class)) {
            throw new \InvalidArgumentException("The object given for persistance is not an instance of $class or is not an object.");
        }

        // Deconstruct the object
        $values = $this->extract($object);

        // Get mappings and table
        $mappings = $this->metadataMapper->getMapping();
        $columns = array_values($mappings);
        $table = $this->metadataMapper->getTable();
        $primaryKey = isset($this->metadataMapper->getKeys()["primary"]) ? $this->metadataMapper->getKeys()["primary"] : null;

        // Prepare query
        $query = $this->queryBuilder
                      ->update($table)
                      ->setParameters(array());
        foreach ($columns as $column) {
            if ($column !== $primaryKey) {
                $query->set("$column", ":$column");
            }
        }
        foreach ($mappings as $property => $column) {
            if ($column !== $primaryKey) {
                $query->setParameter(":$column", $values[$property]);
            }
        }

        $parameters = $query->getParameters();

        return array(
            "parameters" => $parameters,
            "query" => $query
        );
    }

    public function getDeleteQuery($object)
    {
        $class = $this->metadataMapper->getClass();
        $mappings = $this->metadataMapper->getMapping();
        $columns = array_values($mappings);
        $keys = $this->metadataMapper->getKeys();
        $table = $this->metadataMapper->getTable();

        if (!($object instanceof $class)) {
            throw new \InvalidArgumentException("The object given for deletion is not an instance of $class or is not an object.");
        }

        // If a primary key has been registered for this type of objects, we retrieve it        
        $primaryKey = null;
        if (isset($keys["primary"])) {
            $primaryKey = $keys["primary"];
            $primaryProperty = array_search($primaryKey, $mappings);
            if ($primaryProperty === false) {
                throw new \Exception("A table primary key has been provided but the associated object property is missing from the table to object mapping.");
            }

            $primaryKeyValue = $this->readProperty($object, $primaryProperty);

            if ($primaryKeyValue == null) {
                throw new \Exception("The primary key value is null therefore a delete by primary key cannot be performed. If you do not wish to delete by primary key, don't supply a primary key in the keys array - from getKeys(). The other possibility is that you're trying to delete an object that hasn't been persisted yet.");
            }
        }

        // If no primary key was registered then we delete objects matching all the attributes of the object
        if ($primaryKey === null) {
            $values = $this->extract($object);
            $where = "";
        }

        // Prepare query
        $query = $this->queryBuilder
                      ->delete($table)
                      ->setParameters(array());

        if ($primaryKey !== null) {
            $query->where("$primaryKey = :$primaryKey");
            $query->setParameter(":$primaryKey", $primaryKeyValue);
        } else {
            // Prepare where clause
            end($columns);
            $end = key($columns);
            reset($columns);

            foreach ($columns as $key => $column) {
                if ($key !== $end) {
                    $where .= "$column = :$column AND ";
                }

                if ($key === $end) {
                    $where .= "$column = :$column";
                }                
            }
            $query->where($where);

            // Fill the parameters
            foreach ($mappings as $property => $column) {
                $query->setParameter(":$column", $values[$property]);
            }
        }        

        $parameters = $query->getParameters();
    
        return array(
            "parameters" => $parameters,
            "query" => $query
        );
    }

    public function registerNew($object){
        $class = $this->metadataMapper->getClass();
        if (!($object instanceof $class)) {
            throw new \InvalidArgumentException("The object given for new instance registration is not an instance of $class or is not an object.");
        }

        return $this->unitOfWork->registerNew($object, $this);
    }

    public function registerDirty($object){
        $class = $this->metadataMapper->getClass();
        if (!($object instanceof $class)) {
            throw new \InvalidArgumentException("The object given for dirty registration is not an instance of $class or is not an object.");
        }

        return $this->unitOfWork->registerDirty($object, $this);
    }

    public function registerDelete($object){
        $class = $this->metadataMapper->getClass();
        if (!($object instanceof $class)) {
            throw new \InvalidArgumentException("The object given for deletion registration is not an instance of $class or is not an object.");
        }

        return $this->unitOfWork->registerDelete($object, $this);
    }

    public function commit($object = null)
    {
        if ($object != null) {
            return $this->unitOfWork->commit(UnitOfWork::COMMIT_OBJECT, $object);
        }

        return $this->unitOfWork->commit(UnitOfWork::COMMIT_PERSISTOR, $this);
    }

    public function hydrate($values)
    {
        $class = $this->metadataMapper->getClass();
        $method = $this->metadataMapper->getConstructMethod();
        $mappings = $this->metadataMapper->getMapping();

        $data = array();

        // Build the data to hydrate the object
        foreach ($mappings as $property => $column) {
            if (strpos($property, ":") !== false) {
                list($property, ) = explode(":", $property);
            }

            $data[$property] = $values[$column];
        }

        // If we detect any objects to lazy load, we do just that
        $loaders = $this->metadataMapper->getLoadersMapping();

        if (count($loaders) > 0) {
            foreach ($loaders as $property => $loaderData) {
                if (strpos($loaderData, ":") === false) {
                    $loader = function() use ($data, $property, $loaderData) {
                        return new $loaderData($data[$property]);
                    };
                } else {
                    list($loaderClass, $loaderMethod) = explode(":", $loaderData);

                    if (!method_exists($loaderClass, $loaderMethod)) {
                        throw new \BadMethodCallException("The method `$loaderMethod` does not exist on the class `$loaderClass`. Please correct that in order to enable lazy loading.");
                    }

                    $loader = function() use($loaderClass, $loaderMethod, $data, $property) {
                        return forward_static_call(array($loaderClass, $loaderMethod), $data[$property]);
                    };
                }

                $data[$property] = $loader;                
            }
        }

        $object = forward_static_call(array($class, $method), $data);

        return $object;
    }

    public function extract($object)
    {
        $values = array();
        $mapping = $this->metadataMapper->getMapping();

        $reader = function ($object, $property) {
            $value = \Closure::bind(function () use ($property) {
                return $this->$property;
            }, $object, $object)->__invoke();

            return $value;
        }; 

        foreach ($mapping as $property => $column) {
            if (strpos($property, ":") !== false) {
                list($propertyTrimmed, $method) = explode(":", $property);

                $propertyObject = $reader($object, $propertyTrimmed);

                if ($propertyObject === null) {
                    $propertyValue = $propertyObject;
                } else {
                    if ($method === "" || !method_exists($propertyObject, $method)) {
                        throw new \BadMethodCallException("Error during object values extraction: `$property` has been specified as an object but the provided method `$method` to get it's value does not exist on the corresponding object.");
                    }
                    $propertyValue = $propertyObject->$method();
                }
            } else {
                $propertyValue = $reader($object, $property);
            }            

            $values[$property] = $propertyValue;
        }

        return $values;
    }

    public function readProperty($object, $property)
    {
        $reader = function ($object, $property) {
            $value = \Closure::bind(function () use ($property) {
                return $this->$property;
            }, $object, $object)->__invoke();
            
            return $value;
        };

        if (strpos($property, ":") !== false) {
            list($propertyTrimmed, $method) = explode(":", $property);

            $propertyObject = $reader($object, $propertyTrimmed);

            if ($propertyObject === null) {
                $propertyValue = $propertyObject;
            } else {
                if ($method !== "" && !method_exists($propertyObject, $method)) {
                    throw new \BadMethodCallException("Error during object values extraction: `$property` has been specified as an object but the provided method `$method` tp get it's value does not exist on the corresponding object.");
                }
                $propertyValue = $propertyObject->$method();
            }
        } else {
            $propertyValue = $reader($object, $property);
        }

        return $propertyValue;
    }
}
