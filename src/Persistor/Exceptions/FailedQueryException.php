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

namespace Persistor\Exceptions;

class FailedQueryException extends \Exception
{
    protected $details = null;

    public function __construct($message, $code, $details)
    {
        parent::__construct($message, $code);
        $this->details = $details;
    }

    public function getInfo()
    {
        return $this->details;
    }
}
