<?php
//copyright (c) MetaClass Groningen 2014

namespace Metaclass\TresholdsGovernor\Tests\Gateway;

use \Doctrine\DBAL\DriverManager;

class DbalGatewayTest extends RdbGatewayTest {

    protected function makeConnection()
    {
        self::$connection = DriverManager::getConnection([
            'memory ' => true,
            'driver' => 'pdo_sqlite',
        ]);
    }


}