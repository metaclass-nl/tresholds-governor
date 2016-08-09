<?php
// copyright (c) MetaClass Groningen 2014
// Requires \Doctrine\DBAL

namespace Metaclass\TresholdsGovernor\Tests\Gateway;

use \Doctrine\DBAL\DriverManager;

/**
 * For testing RdbGateway with Doctrine DBAL
 */
class DbalGatewayTest extends RdbGatewayTest {

    protected function makeConnection()
    {
        self::$connection = DriverManager::getConnection([
            'memory ' => true,
            'driver' => 'pdo_sqlite',
        ]);
    }


}