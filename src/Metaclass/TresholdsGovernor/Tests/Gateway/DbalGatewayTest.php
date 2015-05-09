<?php
//copyright (c) MetaClass Groningen 2014

namespace Metaclass\TresholdsGovernor\Tests\Gateway;

use Metaclass\TresholdsGovernor\Gateway\DbalGateway;

use \Doctrine\DBAL\Configuration;
use \Doctrine\DBAL\DriverManager;
use \Metaclass\TresholdsGovernor\Tests\Mock\RecordingWrapper;

class DbalGatewayTest extends \PHPUnit_Framework_TestCase {

    STATIC $connection;

    protected $wrapper, $gateway, $requestData1;
    function setup()
    {
        if (!isSet(self::$connection)) {
            $config = new Configuration();
            $connectionParams = array(
                'memory ' => true,
                'driver' => 'pdo_sqlite',
            );
            self::$connection = DriverManager::getConnection($connectionParams, $config);
        }
        $this->wrapper = new RecordingWrapper(self::$connection);
        $this->gateway = new DbalGateway($this->wrapper);

        $this->requestData1 = array(
            'dtFrom' => '2001-08-12 15:33:08',
            'ipAddress' => '122.2.3.4',
            'username' => 'gateway_test_user',
            'token' => 'gateway_test_cookie',
        );
    }

    function test_createTables()
    {
        $sql = "
  CREATE TABLE `secu_requests` (
      `id` INTEGER PRIMARY KEY, -- alias for ROWID, like auto_increment
      `dtFrom` datetime NOT NULL,
      `username` varchar(25) NOT NULL,
      `ipAddress` varchar(25) NOT NULL,
      `cookieToken` varchar(40) NOT NULL,
      `loginsFailed` int(11) NOT NULL DEFAULT '0',
      `loginsSucceeded` int(11) NOT NULL DEFAULT '0',
      `ipAddressBlocked` int(11) NOT NULL DEFAULT '0',
      `usernameBlocked` int(11) NOT NULL DEFAULT '0',
      `usernameBlockedForIpAddress` int(11) NOT NULL DEFAULT '0',
      `usernameBlockedForCookie` int(11) NOT NULL DEFAULT '0',
      `requestsAuthorized` int(11) NOT NULL DEFAULT '0',
      `requestsDenied` int(11) NOT NULL DEFAULT '0',
      `userReleasedAt` datetime DEFAULT NULL,
      `addressReleasedAt` datetime DEFAULT NULL,
      `userReleasedForAddressAndCookieAt` datetime DEFAULT NULL
    ) ;
";
        self::$connection ->executeUpdate($sql);
        self::$connection ->executeUpdate("CREATE INDEX `byDtFrom` ON secu_requests(`dtFrom`)");
        self::$connection ->executeUpdate("CREATE INDEX `byUsername` ON secu_requests(`username`,`dtFrom`,`userReleasedAt`)");
        self::$connection ->executeUpdate("CREATE INDEX `byAddress` ON secu_requests(`ipAddress`,`dtFrom`,`addressReleasedAt`)");
        self::$connection ->executeUpdate("CREATE INDEX `byUsernameAndAddress` ON secu_requests(`username`,`ipAddress`,`dtFrom`,`userReleasedForAddressAndCookieAt`)");
    }

    function test_createRequestCountsWith()
    {
        $loginSucceeded = false;
        $blockedCounterName = null;
        $this->gateway->insertOrIncrementCount(new \DateTime($this->requestData1['dtFrom']), $this->requestData1['username'], $this->requestData1['ipAddress'], $this->requestData1['token'], $loginSucceeded, $blockedCounterName);

        $call = $this->wrapper->calls[0];
        $qb = $call[2];
        $this->assertEquals('createQueryBuilder', $call[0]);
        $this->assertEquals("SELECT r.id FROM secu_requests r WHERE (r.username = :username) AND (r.ipAddress = :ipAddress) AND (r.dtFrom = :dtFrom) AND (r.cookieToken = :token) AND (addressReleasedAt IS NULL) AND (userReleasedAt IS NULL) AND (userReleasedForAddressAndCookieAt IS NULL)"
            , $qb->getSQL());
        $this->assertEquals($this->requestData1, $qb->getParameters(), 'parameters');

        $result = self::$connection->fetchAll("SELECT * FROM secu_requests");
        $this->assertEquals(1, count($result), '1 row');
        $this->assertEquals($this->requestData1['dtFrom'], $result[0]['dtFrom'], 'dtFrom');
        $this->assertEquals($this->requestData1['username'], $result[0]['username'], 'username');
        $this->assertEquals($this->requestData1['ipAddress'], $result[0]['ipAddress'], 'ipAddress');
        $this->assertEquals($this->requestData1['token'], $result[0]['cookieToken'], 'cookieToken');
        $this->assertEquals(1, $result[0]['loginsFailed'], 'loginsFailed');
        $this->assertEquals(0, $result[0]['loginsSucceeded'], 'loginsSucceeded');
        $this->assertEquals(0, $result[0]['ipAddressBlocked'], 'ipAddressBlocked');
        $this->assertEquals(0, $result[0]['usernameBlocked'], 'usernameBlocked');
        $this->assertEquals(0, $result[0]['usernameBlockedForIpAddress'], 'usernameBlockedForIpAddress');
        $this->assertEquals(0, $result[0]['usernameBlockedForCookie'], 'usernameBlockedForCookie');
        $this->assertEquals(0, $result[0]['requestsAuthorized'], 'requestsAuthorized');
        $this->assertEquals(0, $result[0]['requestsDenied'], 'requestsDenied');
        $this->assertNull($result[0]['userReleasedAt'], 'userReleasedAt');
        $this->assertNull($result[0]['addressReleasedAt'], 'addressReleasedAt');
        $this->assertNull($result[0]['userReleasedForAddressAndCookieAt'], 'userReleasedForAddressAndCookieAt');
    }
} 