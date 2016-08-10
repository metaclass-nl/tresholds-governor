<?php

namespace Metaclass\TresholdsGovernor\Tests\Gateway;

use Metaclass\TresholdsGovernor\Connection\PDOConnection;
use \PDO;

/**
 * For testing RdbGateway with PDOConnection
 */
class PdoGatewayTest extends RdbGatewayTest
{
    protected function makeConnection()
    {
        $pdo = new PDO('sqlite::memory:');
        self::$connection = new PDOConnection($pdo);
    }

}