# Persistor - a minimalistic ORM

> This library is not production ready because it is not tested yet. Please don't use it in production but do play with it! It's a proof-of-concept.

Persistor is a minimal ORM that helps with object persistence without getting in your way. The basic idea is for you to be able to override any of it's behavior whenever it doesn't accomplish what you want, at least that's the end goal.

Right now, it's at version 0.1.0 so it's not ready for production but if you find the idea worth it, please, submit a pull requests if you contribute and raise issues if you're trying it.

## Install

The library is available on [packagist](https://packagist.org/) and installable via [composer](http://getcomposer.org).

```JSON
{
    "require": {
        "mixteer/persistor": "dev-master"
    }
}
```

## Concepts

Persistor is built around a few design patterns so that you don't have to write them all every time you need them.

1. *Metadata mapper* - this is a class that maps your objects' properties to the corresponding database table fields. The metadata mapper interface provided by Persistor requires a little bit more but nothing out of the ordinary.

2. *Identity map* - an object that keeps all the objects already loaded from the database for faster future access. This will remain transparent to you so you'll generally won't need to bother. But many times, we want our objects to be rather cache than deleted at the end of the request so as the library matures, the API will be stabilised so you might code against a provided interface.

3. *Unit of work* - an object that coordinates the writing of changes to the database and manages concurrency problems. With persistor, the unit of work is coupled with a dependency manager so you might declare your objects dependencies in order to take care of referential integrity.

4. *Lazy loading* - an object that doesn't contain the all data you need but knows how to get it. The lazy loader used by Persistor is rather simple. You specify how you'd like your object loaded when requested and you get an anonymous function that will execute when the data is requested.

Those are the 4 main design patterns that make Persistor work. Everything was kept simple for the sake of the developer being fully in control.

## Usage

To get started using persistor, you need a metadata mapper class that tells Persistor about your objects. Let's assume you have a `Progeny` class whose objects you'd like to persist.

```php
<?php
namespace Test;

class Progeny
{
    protected $userId = 0;
    protected $name;
    protected $age;
    protected $father = null;

    public function __construct($name, $age)
    {
        $this->name = $name;
        $this->age = $age;
    }

    public static function build($data)
    {
        $user = new self($data["name"], $data["age"]);
        $user->setId($data["userId"]);
        $user->father($data["father"]);

        return $user;
    }

    public function setId($id)
    {
        $this->userId = $id;
    }

    public function getId()
    {
        return $this->userId;
    }

    public function changeName($name)
    {
        $this->name = $name;
    }

    public function getName()
    {
        return $this->name;
    }

    public function getAge()
    {
        return $this->age;
    }

    public function father($father = null)
    {
        if ($father !== null) {
            $this->father = $father;
        }

        if (is_callable($this->father)) {
            $that = $this->father;
            return $that();
        }

        return $this->father;
    }
}
```

Now, we want to persist this class. To get started, we declare a metadata mapper. This is class that implements the `Persistor\Interfaces\MetadataMapperInterface` which is made of 7 methods that fully informs how to persist and load objects.

Here is a starter metadata mapper:

```php
<?php
use Persistor\Interfaces\MetadataMapperInterface;

class ProgenyMapper implements MetadataMapperInterface
{
    public function getClass()
    {
        return "Test\Progeny";
    }

    public function getTable()
    {
        return "users";
    }

    public function getMapping()
    {
        return array(
            "userId" => "user_id",
            "name" => "name",
            "age" => "age",
            "father:getId" => "father_id"
        );
    }

    public function getKeys()
    {
        return array("primary" => "user_id");
    }

    public function getConstructMethod()
    {
        return "build";
    }

    public function getIdSetter()
    {
        return "setId";
    }

    public function getLoadersMapping()
    {
        return array(
            "father" => "Test\ProgenyPersistor:findById"
        );
    }
}
```

Here is an explanation of the methods implemented by the metadata mapper:

1. *getClass* - this method returns the full namespaced class. Internally it is used to ensure the right class is always mapped to the right table.

2. *getTable()* - well, it returns the name of the table that is mapped to the class above.

3. *getMapping()* - returns an array that maps the object's attributes to the database fields. It's `object attribute => table field` not the other way around. Note that there's one expection: for non-scalar values mainly objects, they need to be converted to their database equivalent. In this case, when saving a progeny father in the database, we rather need the father ID. Hence, instead of just passing the `father` property to the Persistor, we also tell the persistor how to get the father ID in the format `propert:method` with `method` being the method that will be called to get the father ID. In this case, the father is also a progeny object. If there's no father, null is default.

4. *getKeys()* - returns an array of the selected table's fields that are considered unique. If you have a primary key in your table, you can label it as being primary here but that's optional. In our case, we have a primary key called `user_id`. Note that these keys are used by the identity map to keep track of your loaded objects. Every time you load an object by one of the registered keys, the identity map will save the said object for future access by any other key.

5. *getConstructMethod()* - return a string representing a **static factory** method that will be used during "object construction". Essentially, when a new object is to be pulled out from the database, Persistor will invoke this method and it will pass it all the data it got as an array that maps the registered attributes (as keys) to their table data as values.

6. *getIdSetter()* - returns the method used to set the ID of this object. If you're dealing with value objects from the database without IDs, return an empty string.

7. *getLoadersMapping()* - returns an array that maps an object property to it's persistor. This is used for lazy loading. The value attached to the property is of the form `class:[static finder]`. The `class` represents the class that is responsible for loading objects like in this case `Progeny` objects and the optional `static finder` is a static method that will be called to fetch the object from the database. In our case, to lazy load the progeny's father, we wil use the `ProgenyPersistor` class and we will pass the father ID to it's `findById` static method and Persistor will return an anonymous function which you can use to get the father. Refer to the `father` method in the `Progeny` class to see how this is done.

### Object persistence

There are two ways to persist objects: directly or indirectly using the unit of work.

1. *Directly* - in this case you direclty call the `insert`, `update` and `delete` methods on the `Persistor` object.

```php
<?php
namespace Test;

use Persistor\Persistor;
use Test\ProgenyMapper;

class ProgenyPersistor
{
    protected $persistor = null;
    protected static $_persistor = null;

    public function __construct()
    {
        $credentials = array(
            'driver' => 'pdo_mysql',
            'host' => "localhost",
            'dbname' => "test",
            'user' => "root",
            'password' => ""
        );

        $this->persistor = Persistor::factory(new ProgenyMapper, $credentials);

        self::$_persistor = $this->persistor;
    }

    public function persist($progeny)
    {
        $progeny = $this->persistor->insert($progeny);
    }

    public function update($progeny)
    {
        $rowCount = $this->persistor->delete($progeny);
    }

    public function delete($progeny)
    {
        $rowCount = $this->persistor->delete($progeny);
    }

    public static function findById($id)
    {
        $progeny = self::$_persistor->getBy("user_id", $id);
        return $progeny;
    }
}
```

Essentially, you just call the respective insert, update and delete methods on the persistor object. The insert method will return the new object with the ID set (if you provided a primary key) and if you pass the `insert` method a second parameter with the value `true`, it will return the last insert ID instead of the object. The `update` and `delete` methods will return the row count of affected rows (which in this case would be 0 on failure or 1 on success.  
All the queries executed in direct mode are executed in auto-commit mode.

2. *Unit of work* - in this case, you register objects for persistence and then you commit them. On failure, the transaction will be automatically rolled back and an exception will be raised with the details. All the queries executed by the unit of work are executed within a transaction. After the completion of each transaction, the commit mode is reset to auto-commit.  
Here is an example of the same code executed done using the unit of work:

```php
<?php
namespace Test;

use Persistor\Persistor;
use Test\ProgenyMapper;

class ProgenyPersistor
{
    protected $persistor = null;
    protected static $_persistor = null;

    public function __construct()
    {
        $credentials = array(
            'driver' => 'pdo_mysql',
            'host' => "localhost",
            'dbname' => "test",
            'user' => "root",
            'password' => ""
        );

        $this->persistor = Persistor::factory(new ProgenyMapper, $credentials);

        self::$_persistor = $this->persistor;
    }

    public function persist($progeny)
    {
        $progenyDependency = $this->persistor->registerNew($progeny);
    }

    public function update($progeny)
    {
        $progenyDependency = $this->persistor->registerDirty($progeny);
    }

    public function delete($progeny)
    {
        $progenyDependency = $this->persistor->registerDelete($progeny);
    }

    public function flush()
    {
        $unitOfWork = $this->persistor->unitOfWork();
        $unitOfWork->commit();
    }

    public static function findById($id)
    {
        $progeny = self::$_persistor->getBy("user_id", $id);
        return $progeny;
    }
}
```

You register objects for persistence with the registrations methods `registerNew` for insert, `registerDirty` for uupdates and `registerDelete` for deletion. The `registerDirty` method will usually be called from the object itself whenever a property changes but you might also call it when a domain event is detected from application services in order to avoid clutering domain objects with infrastructure code.

All the registration methods will return a dependency object which you can use to declare dependencies. This is done like bellow:

```php
<?php
public function persist($progeny)
{
    $progenyDependency = $this->persistor->registerNew($progeny);
    
    // You can declare a depency by passing the actual object or it's dependency object
    $progenyDependency->dependsOn($object);
    // Or
    $progenyDependency->dependsOn($objectDependency);
}
```

The one condition is that all the declared dependencies must be registered first. Meaning `$object` must be registered as either new, dirty or delete before other objects can depend on it.

Also, an object cannot be registered in two different ways: as new and dirty for example. It's either new, dirty or delete (the or is exclusive.)

When it comes to committing objects, you have three choices: you can either commit a single object (which will also commit its dependencies) or you can commit all the objects belong to a particular persistor (and all their dependencies) or you can commit all the objects registered to the unit of work.  
The following snippet illustrates this:

```php
<?php
public function flush()
{
    $unitOfWork = $this->persistor->unitOfWork();
    
    // Option 1: commit a single object
    $this->persistor->commit($progeny); // Using the persistor
    $unitOfWork->commit(UnitOfWork::CoMMIT_OBJECT, $progeny); // Directly using the unit of work
    
    // Option 2: commit this persistor objects
    $this->persistor->commit(); // Directly using the persistor
    $this->persistor->commit(UnitOfWork::COMMIT_PERSISTOR, $this); // Directly using the unit of work
    
    // Option 3: commit all the objects registered to the unit of work
    $unitOfWork->commit();
}
```

For the moment, the unit of work returns an empty array which is not useful but in the next version, it will return an array with a result corresponding to each object.

### Fetching objects

Fetching objects is rather easy. A few helper methods are provided to make life easy. For now two are provided: `getBy($key, $value)` and `getLike($field, $value)`. The first one returns an object whose table key matches the given value and the second one returns an array of objects whose table's field is like the provided value.  
Illustration sample bellow:

```php
<?php
public function getProgenyById($id)
{
    
    $progeny = $this->persistor->getBy("user_id", $id);
    return $progeny;
}

public function getProgenysLike($name)
{
    $progeniess = $this->persistor->getLike("name", $name);
    return $progeniess;
}
```

More methods will be added in the future.

### Running your own queries

You can also run your own queries using the query runner. In the case of inserts, it will return the last insert ID, in the case of updates and deletes, it will return the row count and in the case of reads, it will return either one array that represents a single row and an array of arrays that represents multiple rows (depending on how you want it.)  
Sample bellow:

```php
<?php
$query = "SELECT name, age FROM users WHERE user_id = :user_d";
$parameters = array(
    ":user_id" => 1
);
$queryRunner = $this->persistor->queryRunner();

// Read
$queryRunner->read($parameters, QueryRunner::FETCH)->using($query)->run(); // If you do have parameters

$queryRunner->read(null, QueryRunner::FETCH)->using($query)->run(); // If you do not have parameters

// Insert, update and delete follow the same pattern as well. But the parameter is mandatory
$queryRunner->insert($paramters)->using($insertQuery)->run();
```

**Notes** 1) Use `QueryRunner::FETCH_ALL` to fetch multiple objects. 2) `QueryRUnner::FETCH` *does not* close the cursor so you can loop over and get more data if you wish. 3) The fetch mode is always `PDO::FETCH_ASSOC`.

## About

Persistor was born out a desire to quickly prototype domain objects without having to write the same PDO boilerplate code every time and without having to use a full fledged ORM with it's learning curve and quirks. In essence from Persistor, you can either scale down and reuse the code with bare PDO or you can go for an ORM.

## Author
Ntwali Bashige - ntwali.bashige@gmail.com - [http://twitter.com/nbashige  ](http://twitter.com/nbashige)

## License
Reshi is licensed under `MIT`, see LICENSE file.

## Acknowledgment
Internally, Persistor uses [Doctrine DBAL](http://doctrine-dbal.readthedocs.org/en/latest/) which is great tool! Give it try if you haven't.

## Next
Write unit tests. Contributions are welcome!