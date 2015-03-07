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

use Persistor\Exceptions\InvalidQueryException;
use Persistor\Exceptions\InvalidRegistrationException;
use Persistor\Exceptions\InvalidConnectionCredentialsException;

use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\DriverManager;

class UnitOfWork
{
    /*
     * @const represents commiting all the registered objects
     */
    const COMMIT_ALL = 0;

    /*
     * @const represents commiting only the objects coming from the same persistor
     */
    const COMMIT_PERSISTOR = 1;

    /*
     * @const represents commiting a single given object
     */
    const COMMIT_OBJECT = 2;

    private static $instance = null;

    protected $connection = null;
    protected $dbConfig = null;

    protected $classPersistorMapping = array();
    protected $objects = array();
    protected $classifiedObjects = array();

    protected $commitedObjects = array();

    private function __construct()
    {
        $this->dbConfig = new Configuration();

        $classification = array("insert", "update", "delete");

        foreach ($classification as $value) {
            $this->classifiedObjects[$value] = array();
        }              
    }

    private function __clone()
    {
    }

    public static function getInstance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public function connection($credentials = null)
    {
        if ($this->connection === null) {
            if ($credentials === null) {
                throw new \InvalidArgumentException("The unit work does not have a valid connection. Please pass credentials to this method to establish a valid connection to the database.");
            }

            if (is_array($credentials)) {
                if (array_key_exists("dbname", $credentials) &&
                    array_key_exists("user", $credentials) &&
                    array_key_exists("password", $credentials) &&
                    array_key_exists("host", $credentials) &&
                    array_key_exists("driver", $credentials)
                ) {            
                    $this->connection = DriverManager::getConnection($credentials, $this->dbConfig);
                } else {
                    throw new InvalidConnectionCredentialsException("The database connection credentials are invalid. The credentials array is expected to have the following keys: dbname, user, password, host and driver");
                }  
            } else if (is_string($credentials)) {
                $this->connection = DriverManager::getConnection($credentials, $this->dbConfig);
            } else {
                throw new InvalidConnectionCredentialsException("The connection credentials must be either an array with all the connection parameters or a url string with all the details");
            }
        }
        
        return $this->connection;
    }

    public function getRegisteredObjects()
    {
        return $this->objects;
    }

    public function getRegistrationId($object)
    {
        $objectId = spl_object_hash($object);

        if (array_key_exists($objectId, $this->objects)) {
            return $objectId;
        }

        return null;
    }

    public function getRegisteredObject($objectId)
    {
        if (array_key_exists($objectId, $this->objects)) {
            return $this->objects[$objectId];
        }

        return null;
    }

    public function getRegisteredObjectStatus($objectData)
    {
        if (is_string($objectData)) {
            $objectId = $objectData;
        } else if(is_object($objectData)) {
            $objectId = spl_object_hash($objectData);
        } else {
            throw new \InvalidArgumentException("The object data must be a string representing the object ID or the object itself.");
        }

        if (array_key_exists($objectId, $this->objects)) {
            return $this->objects[$objectId]["status"];
        }

        return null;
    }

    public function hasObjectRegistered($object) {
        if (!(is_object($object))) {
            throw new \InvalidArgumentException("The element to check for in the unit of work must be an object. Element of type `". gettype($object) ."` given.");
        }

        $objectId = spl_object_hash($object);

        if (array_key_exists($objectId, $this->objects)) {
            return true;
        }

        return false;
    }

    public function registerNew($object, $persistor)
    {        
        $class = get_class($object);
        $objectId = spl_object_hash($object);

        // Make sure one persistor per object type
        if (!isset($this->classPersistorMapping[$class])) {
            if (!isset($this->classPersistorMapping[$class])) {
                $this->classPersistorMapping[$class] = $persistor;
            }
        }

        // Make sure this object has not been added already
        if (array_key_exists($objectId, $this->objects)) {
            if (!array_key_exists($objectId, $this->classifiedObjects["insert"])) {
                throw new InvalidRegistrationException("The object given for registration as a new object has already been registered as dirty or for deletion and cannot be registered as new.");
            }
            return $objectId;
        }

        // Create the dependency object
        $dependencyObject = new Dependency($object);

        // We have a new object for insertion
        $this->objects[$objectId]["dependency-object"] = $dependencyObject;
        $this->objects[$objectId]["status"] = "insert";   

        $this->classifiedObjects["insert"][$objectId] =  $object;   

        return $dependencyObject;
    }    

    public function registerDirty($object, $persistor)
    {
        $class = get_class($object);
        $objectId = spl_object_hash($object);

        // Make sure one persistor per object type
        if (!isset($this->classPersistorMapping[$class])) {
            if (!isset($this->classPersistorMapping[$class])) {
                $this->classPersistorMapping[$class] = $persistor;
            }
        }

        // Make sure this object has not been added already
        if (array_key_exists($objectId, $this->objects)) {
            if (!array_key_exists($objectId, $this->classifiedObjects["update"])) {
                throw new InvalidRegistrationException("The object given for registration as a dirty object has already been registered as new or for deletion and cannot be registered as dirty.");
            }
            return $objectId;
        }

        // Create the dependency object
        $dependencyObject = new Dependency($object);

        // We have a new object for insertion
        $this->objects[$objectId]["dependency-object"] = $dependencyObject;
        $this->objects[$objectId]["status"] = "update";

        $this->classifiedObjects["update"][$objectId] =  $object;       

        return $dependencyObject;
    }

    public function registerDelete($object, $persistor)
    {
        $class = get_class($object);
        $objectId = spl_object_hash($object);

        // Make sure one persistor per object type
        if (!isset($this->classPersistorMapping[$class])) {
            if (!isset($this->classPersistorMapping[$class])) {
                $this->classPersistorMapping[$class] = $persistor;
            }
        }

        // Make sure this object has not been added already
        if (array_key_exists($objectId, $this->objects)) {
            if (!array_key_exists($objectId, $this->classifiedObjects["delete"])) {
                throw new InvalidRegistrationException("The object given for registration as an object to delete has already been registered as new or as dirty and cannot be registered for deletion.");
            }
            return $objectId;
        }

        // Create the dependency object
        $dependencyObject = new Dependency($object);

        // We have a new object for insertion
        $this->objects[$objectId]["dependency-object"] = $dependencyObject;
        $this->objects[$objectId]["status"] = "delete";

        $this->classifiedObjects["delete"][$objectId] =  $object;       

        return $dependencyObject;
    }

    /*
     * The methods bellow do the actual work
     */
    public function commit($mode = self::COMMIT_ALL, $objectToCommit = null)
    {
        $results = array();

        // Begin the transaction
        $this->connection->setAutoCommit(false);
        $this->connection->beginTransaction();

        try {
            // If we have been given a single object to commit
            if ($mode === self::COMMIT_OBJECT) {
                if ($objectToCommit == null) {
                    throw new \InvalidArgumentException("Commit mode has been set to commiting a single object but the object to commit was not specified");
                }

                $objectId = spl_object_hash($objectToCommit);
                if (!isset($this->objects[$objectId])) {
                    throw new \InvalidArgumentException("The object given to commit has not been registered. Please register it first.");
                }

                $this->commitObject($objectToCommit);
            }

            // If a persistor was specified, we commit everything related to that persistor
            if ($mode === self::COMMIT_PERSISTOR) {
                if ($objectToCommit == null) {
                    throw new \InvalidArgumentException("Commit mode has been set to commiting a entire persistor but none was provided. Please provide one");                    
                }

                if (!($objectToCommit instanceof Persistor)) {
                    throw new \InvalidArgumentException("Commit mode is set to commiting a persistor but the persistor provided is not an instance of Persistor. Maybe you wanted to commit a single object?");
                }

                $persistorMappedClass = array_search($objectToCommit, $this->classPersistorMapping);
                if ($persistorMappedClass === false) {
                    throw new \Exception("The persistor provided has no objects registered.");
                }

                foreach ($this->objects as $objectId => $objectData) {
                    $object = $objectData["dependency-object"]->getObject();                   
                    $objectClass = get_class($object);

                    if ($objectClass === $persistorMappedClass) {
                        $this->commitObject($object);
                    }
                }
            }

            // If we have mode COMMIT_ALL or no mode at all, we commit everything
            if ($mode === self::COMMIT_ALL) {
                foreach ($this->objects as $objectData) {
                    $object = $objectData["dependency-object"]->getObject();
                    $this->commitObject($object);
                }
            }
        } catch(\Exception $exception) {
            // Recompose the array of objects to commit
            if (count($this->commitedObjects) > 0) {
                $this->objects = array_merge($this->objects, $this->commitedObjects);
                $this->commitedObjects = array();
            }

            // Rollback the transaction
            $this->connection->rollback();

            // Rethrow the exception. This should ideally be a FailedQueryException
            throw $exception;
        }

        // End of transaction
        $this->connection->setAutoCommit(true);

        // We can this far without anything to rollback, delete the array that holds already commited objects
        $this->commitedObjects = array();

        // Return the results array
        return $results;
    }

    private function commitObject($object)
    {
        $objectId = spl_object_hash($object);
        $parent = $this->objects[$objectId]["dependency-object"];

        // Resolve dependencies
        $dependencies = $parent->resolve();

        foreach ($dependencies as $dependency) {
            $object = $dependency->getObject();
            $objectId = spl_object_hash($object);

            // Make sure we haven't commited this object by checking the array of objects to commit
            if (array_key_exists($objectId, $this->objects)) {
               $this->runTransaction($object);
            }
        }
    }

    private function runTransaction($object)
    {
        // The object ID and class
        $objectId = spl_object_hash($object);
        $class = get_class($object);

        // Get the persistor
        $persistor = $this->classPersistorMapping[$class];

        // Get the object query status
        $status = $this->objects[$objectId]["status"];

        // Get the query and the parameters needed for running the transaction
        switch ($status) {
            case "insert":
                $insertQuery = $persistor->getInsertQuery($object);
                $query = $insertQuery["query"];
                $parameters = $insertQuery["parameters"];
                break;

            case "update":
                $updateQuery = $persistor->getUpdateQuery($object);
                $query = $updateQuery["query"];
                $parameters = $updateQuery["parameters"];
                break;

            case "delete":
                $deleteQuery = $persistor->getDeleteQuery($object);
                $query = $deleteQuery["query"];
                $parameters = $deleteQuery["parameters"];
                break;
            
            default:
                throw new \Exception("Internal error: Unable to run query because of unknown transaction status. Current status is `$status`.");
                break;
        }

        // Normalize the query
        $query = is_string($query) ? $query : $query->getSQL();

        try {
            // Prepare the query
            $statement = $this->connection->prepare($query);

            // Execute the query
            $statement->execute($parameters);

            $this->connection->commit();

            // Remove the object from the list of objects to persist
            $this->commitedObjects[$objectId] = $this->objects[$objectId];
            unset($this->objects[$objectId]);
        } catch(\Exception $exception) {            
            throw $exception;
        }
    }
}
