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

use Persistor\Interfaces\QueryInterface;

use Persistor\Exceptions\NullConnectionException;
use Persistor\Exceptions\FailedQueryException;
use Persistor\Exceptions\InvalidQueryException;

class QueryRunner
{
    const FETCH = 0;
    const FETCH_ALL = 1;

    protected $persistor = null;
    protected $connection = null;

    protected $activeQuery = "";
    protected $query = "";

    public function __construct($persistor = null)
    {
        $this->persistor = $persistor;
    }

    public function connection($connection = null)
    {
        if ($connection !== null) {
            $this->connection = $connection;
        }

         return $this->connection;
    }

    public function using($query)
    {
        if ($this->activeQuery == "") {
            throw new \Exception("Please choose the type of query to run first.");
        }

        if (is_object($query) && method_exists($query, "getSQL")) {
            $this->query = $query->getSQL();
        } else if (is_string($query)) {
            $this->query = $query;
        } else {
            throw new InvalidQueryException("The query is expected to be an object with a `getSQL` method that returns the SQL query OR a string representing an SQL query.");
        }

        return $this;
    }

    public function run()
    {
        switch ($this->activeQuery) {
            case "read":
                return $this->runRead();
                break;

            case "insert":
                return $this->runInsert();
                break;

            case "update":
                return $this->runUpdate();
                break;

            case "delete":
                return $this->runDelete();
                break;
            
            default:
                throw new \Exception("No query to run was found.");
                break;
        }
    }

    public function getLastInsertId()
    {
        if ($this->connection != null) {
            return $this->connection->lastInsertId();
        }

        return null;
    }

    public function read($data, $fetchMode = self::FETCH_ALL)
    {
        $this->activeQuery = "read";
        $this->data = $data;
        $this->fetchMode = $fetchMode;

        return $this;
    }

    private function runRead()
    {
        $results = array();

        // Make sure we have a valid connection
        if ($this->connection == null) {
            throw new NullConnectionException("The connection to use for INSERTs is null. Please provide one and check the connection credentials.");            
        }

        // Make sure the query was provided
        if ($this->query == "") {
            throw new \BadMethodCallException("No query was provided for reading data from the database.");
        }

        $statement = $this->connection->prepare($this->query);
        
        try {
            if ($this->data == null) {
                $status = $statement->execute();
            } else {
                $status = $statement->execute($this->data);
            }
            
        } catch(\Exception $exception) {
            $errorCode = $this->connection->errorCode();
            $errorInfo = array(
                "query" => $this->query,
                "error" => $this->connection->errorInfo()
            );

            throw new FailedQueryException($exception->getMessage(), $errorCode, $errorInfo);
        }

        if ($status === false) {
            $errorCode = $this->connection->errorCode();
            $errorInfo = array(
                "query" => $this->query,
                "error" => $this->connection->errorInfo()
            );

            throw new FailedQueryException($exception->getMessage(), $errorCode, $errorInfo);
        }
        
        if ($this->fetchMode === self::FETCH_ALL) {
            $result = $statement->fetchAll(\PDO::FETCH_ASSOC);
            $statement->closeCursor();
        } else if ($this->fetchMode === self::FETCH) {
            $result = $statement->fetch(\PDO::FETCH_ASSOC);
        } else {
            throw new \InvalidArgumentException("The fetch mode is invalid. One is `QueryRunner::FETCH` and the other is `QueryRunner::FETCH_ALL` and are available as this class constants.");
        }       

        return $result;
    }


    public function insert($data)
    {
        $this->activeQuery = "insert";
        $this->data = $data;

        return $this;
    }

    private function runInsert()
    {
        // Make sure we have a valid connection
        if ($this->connection == null) {
            throw new NullConnectionException("The connection to use for INSERTs is null. Please provide one and check the connection credentials.");            
        }

        // Make sure we have the data
        if ($this->data == null) {
            throw new \BadMethodCallException("Attempting to run an INSERT without the data to insert.");
        }

        // Make sure the query was provided
        if ($this->query == "") {
            throw new \BadMethodCallException("No query was provided run the INSERT.");
        }

        $statement = $this->connection->prepare($this->query);
        
        try {
            $status = $statement->execute($this->data);
        } catch(\Exception $exception) {
            $errorCode = $this->connection->errorCode();
            $errorInfo = array(
                "query" => $this->query,
                "error" => $this->connection->errorInfo()
            );

            throw new FailedQueryException($exception->getMessage(), $exception->getCode(), $errorInfo);
        }

        if ($status === false) {
            $errorCode = $this->connection->errorCode();
            $errorInfo = array(
                "query" => $this->query,
                "error" => $this->connection->errorInfo()
            );

            throw new FailedQueryException("Failed to execute the INSERT query found.", $errorCode, $errorInfo);
        }

        $lastInsertId = $this->connection->lastInsertId();

        // Return the status which will always be true
        return $lastInsertId;
    }

    public function update($data)
    {
        $this->activeQuery = "update";
        $this->data = $data;

        return $this;
    }

    private function runUpdate()
    {
        // Make sure we have a valid connection
        if ($this->connection == null) {
            throw new NullConnectionException("The connection to use for UPDATEs is null. Please provide one and check the connection credentials.");            
        }

        // Make sure we have the data
        if ($this->data == null) {
            throw new \BadMethodCallException("Attempting to run an UPDATE without the data to update.");
        }

        // Make sure the query was provided
        if ($this->query == "") {
            throw new \BadMethodCallException("No query was provided run the UPDATE.");
        }

        $statement = $this->connection->prepare($this->query);
        
        try {
            $status = $statement->execute($this->data);
        } catch(\Exception $exception) {
            $errorCode = $this->connection->errorCode();
            $errorInfo = array(
                "query" => $this->query,
                "error" => $this->connection->errorInfo()
            );

            throw new FailedQueryException($exception->getMessage(), $exception->getCode(), $errorInfo);
        }

        if ($status === false) {
            $errorCode = $this->connection->errorCode();
            $errorInfo = array(
                "query" => $this->query,
                "error" => $this->connection->errorInfo()
            );

            throw new FailedQueryException("Failed to execute the UPDATE query found.", $errorCode, $errorInfo);
        }

        $updateRowCount = $statement->rowCount();

        return $updateRowCount;
    }

    public function delete($data)
    {
        $this->activeQuery = "delete";
        $this->data = $data;

        return $this;
    }

    private function runDelete()
    {
        // Make sure we have a valid connection
        if ($this->connection == null) {
            throw new NullConnectionException("The connection to use for DELETEs is null. Please provide one and check the connection credentials.");            
        }

        // Make sure we have the data
        if ($this->data == null) {
            throw new \BadMethodCallException("Attempting to run an DELETE without the data to delete.");
        }

        // Make sure the query was provided
        if ($this->query == "") {
            throw new \BadMethodCallException("No query was provided run the UPDATE.");
        }

        $statement = $this->connection->prepare($this->query);
        
        try {
            $status = $statement->execute($this->data);
        } catch(\Exception $exception) {
            $errorCode = $this->connection->errorCode();
            $errorInfo = array(
                "query" => $this->query,
                "error" => $this->connection->errorInfo()
            );

            throw new FailedQueryException($exception->getMessage(), $exception->getCode(), $errorInfo);
        }

        if ($status === false) {
            $errorCode = $this->connection->errorCode();
            $errorInfo = array(
                "query" => $this->query,
                "error" => $this->connection->errorInfo()
            );

            throw new FailedQueryException("Failed to execute the DELETE query found.", $errorCode, $errorInfo);
        }

        $deleteRowCount = $statement->rowCount();

        return $deleteRowCount;
    }
}
