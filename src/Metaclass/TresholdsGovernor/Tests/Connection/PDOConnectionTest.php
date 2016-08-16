<?php

namespace Metaclass\TresholdsGovernor\Tests;

use Metaclass\TresholdsGovernor\Connection\PDOException;
use \PDO;
use Metaclass\TresholdsGovernor\Connection\PDOConnection;

class PDOConnectionTest extends \PHPUnit_Framework_TestCase
{
    public static $connection;

    public function setup()
    {
        if (!isset(self::$connection)) {
            $this->makeConnection();
        }
    }

    public function testCreateTable()
    {
        self::$connection->executeQuery('
    CREATE TABLE `testtable` (
    `id` INTEGER PRIMARY KEY, -- alias for ROWID, like auto_increment
    `dtFrom` datetime NOT NULL
    );
            ');
    }

    public function testInsertRetrieve()
    {
        // Insert using parameterized query
        $datetime = '2012-01-21 21:50';
        $sql = "INSERT INTO testtable (dtFrom) VALUES (?)";
        self::$connection->executeQuery($sql, array($datetime));

        // retrieve
        $sql = "SELECT * FROM testtable";
        $found = self::$connection->executeQuery($sql)->fetchAll();

        $this->assertTrue(is_array($found), 'found is array');
        $this->assertEquals(1, count($found), 'one row');
        $this->assertEquals($datetime, $found[0]['dtFrom']);
    }

    public function makeConnection()
    {
        $pdo = new PDO('sqlite::memory:');
        self::$connection = new PDOConnection($pdo);
    }

    public function testErrorHandlingQuery()
    {
        $exception = null;
        try {
            self::$connection->executeQuery('SELECT * FROM nonExistentTable');
        } catch (PDOException $e) {
            $exception = $e;
        }
        $this->assertNotNull($exception, 'PDOException caught');
        $this->assertTrue(is_array($exception->errorInfo), 'errorInfo is array');
        $this->assertEquals(3, count($exception->errorInfo), 'errorInfo has 3 elements');

        $this->assertEquals($exception->errorInfo[0], $exception->getCode(), 'getCode');
        $expectedMessage = $exception->getCode(). ' '. $exception->errorInfo[2];
        $this->assertEquals($expectedMessage, $exception->getMessage(), 'getMessage');

        print 'testErrorHandlingQuery '. $exception->getLine(). ' '. $exception->getMessage(). "\n";
    }

    public function testErrorHandlingPrepare()
    {
        $exception = null;
        try {
            $sql = 'SELECT * FROM nonExistentTable WHERE someColumn = ?';
            $params = array('value');
            self::$connection->executeQuery($sql, $params);
        } catch (PDOException $e) {
            $exception = $e;
        }
        $this->assertNotNull($exception, 'PDOException caught');

        print 'testErrorHandlingPrepare '. $exception->getLine(). ' '. $exception->getMessage(). "\n";
    }

    public function testErrorHandlingExecute()
    {
        $exception = null;
        try {
            $sql = 'INSERT INTO testtable (dtFrom) VALUES (?)';
            $params = array(null);
            self::$connection->executeQuery($sql, $params);
        } catch (PDOException $e) {
            $exception = $e;
        }
        $this->assertNotNull($exception, 'PDOException caught');
        $this->assertTrue(is_array($exception->errorInfo), 'errorInfo is array');
        $this->assertEquals(3, count($exception->errorInfo), 'errorInfo has 3 elements');

        print 'testErrorHandlingExecute '. $exception->getLine(). ' '. $exception->getMessage(). "\n";
    }
}
