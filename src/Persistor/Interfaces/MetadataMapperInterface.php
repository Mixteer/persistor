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

namespace Persistor\Interfaces;

interface MetadataMapperInterface
{
    /**
     * Returns the class that applies to this mapping
     */
    public function getClass();

    /**
     * Returns the table to which the class maps
     */
    public function getTable();

    /**
     * Returns the class attributes mappings to the database columns
     */
    public function getMapping();

    /**
     * Returns an array of keys that uniquely identifies columns in the database
     */
    public function getKeys();

    /**
     * Returns the static method to invoke when we are to build a new object
     */
    public function getConstructMethod();

    /**
     * Returns the method to call for setting the ID of the returned object
     */
    public function getIdSetter();

    /**
     * Returns an array that maps database columns to Classes that load them
     */
    public function getLoadersMapping();
}
