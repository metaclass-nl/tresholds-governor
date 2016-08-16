<?php

namespace Metaclass\TresholdsGovernor\Connection;

class PDOException extends \PDOException
{
    public function __construct($errorInfo)
    {
        $sqlState = isset($errorInfo[0]) ? $errorInfo[0] : '-';
        $driverMessage = isset($errorInfo[2]) ? $errorInfo[2] : 'Unknown';

        parent::__construct("$sqlState $driverMessage", 0);

        $this->code      = $sqlState;
        $this->errorInfo = $errorInfo;
    }
}
