<?php
//copyright (c) MetaClass Groningen 2014

namespace Metaclass\TresholdsGovernor\Tests\Gateway;

use Metaclass\TresholdsGovernor\Gateway\RdbGateway;
use \Metaclass\TresholdsGovernor\Tests\Mock\RecordingWrapper;

class RdbGatewayTest extends \PHPUnit_Framework_TestCase
{
    STATIC $connection;

    protected $wrapper, $gateway, $requestData1;

    function setup()
    {
        if (!isSet(self::$connection)) {
            $this->makeConnection();
            self::createTables();
        }
        $this->wrapper = new RecordingWrapper(self::$connection);
        $this->gateway = new RdbGateway($this->wrapper);

        $this->requestData1 = array(
            'dtFrom' => '2001-08-12 15:33:08',
            'ipAddress' => '122.2.3.4',
            'username' => 'gateway_test_user',
            'token' => 'gateway_test_cookie',
        );
        $this->dtLimit = '2001-08-12 15:33:07';
    }

    static function createTables()
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
        self::$connection ->executeQuery($sql);
        self::$connection ->executeQuery("CREATE INDEX `byDtFrom` ON secu_requests(`dtFrom`)");
        self::$connection ->executeQuery("CREATE INDEX `byUsername` ON secu_requests(`username`,`dtFrom`,`userReleasedAt`)");
        self::$connection ->executeQuery("CREATE INDEX `byAddress` ON secu_requests(`ipAddress`,`dtFrom`,`addressReleasedAt`)");
        self::$connection ->executeQuery("CREATE INDEX `byUsernameAndAddress` ON secu_requests(`username`,`ipAddress`,`dtFrom`,`userReleasedForAddressAndCookieAt`)");

        $sql = "
    CREATE TABLE `secu_releases` (
      `id` INTEGER PRIMARY KEY, -- alias for ROWID, like auto_increment
      `username` varchar(25) NOT NULL DEFAULT '',
      `ipAddress` varchar(25) NOT NULL DEFAULT '',
      `cookieToken` varchar(40) NOT NULL DEFAULT '',
      `releasedAt` datetime DEFAULT NULL
    ) ;
        ";
        self::$connection ->executeQuery($sql);
        self::$connection ->executeQuery("CREATE INDEX `releasedAt` ON secu_releases(`releasedAt`)");
        self::$connection ->executeQuery("CREATE INDEX `extkey` ON secu_releases(`username`,`ipAddress`,`cookieToken`)");
        self::$connection ->executeQuery("CREATE INDEX `byCookie` ON secu_releases(`username`,`cookieToken`)");
    }

    function test_createRequestCountsWith()
    {
        $loginSucceeded = false;
        $blockedCounterName = null;
        $this->gateway->insertOrIncrementCount(new \DateTime($this->requestData1['dtFrom']), $this->requestData1['username'], $this->requestData1['ipAddress'], $this->requestData1['token'], $loginSucceeded, $blockedCounterName);

        $this->assertEquals('executeQuery', $this->wrapper->calls[0][0]);
        $callParams = $this->wrapper->calls[0][1];
        $this->assertEquals("SELECT r.id FROM secu_requests r
    WHERE (r.username = :username)
    AND (r.ipAddress = :ipAddress)
    AND (r.dtFrom = :dtFrom)
    AND (r.cookieToken = :token)
    AND (addressReleasedAt IS NULL)
    AND (userReleasedAt IS NULL)
    AND (userReleasedForAddressAndCookieAt IS NULL)"
            , $callParams[0]);
        $this->assertEquals($this->requestData1, $callParams[1], 'parameters');

        $updateCall = $this->wrapper->calls[1];
        $this->assertEquals('executeQuery', $updateCall[0], 'call 1');
        $this->assertEquals(
            "INSERT INTO secu_requests (dtFrom, username, ipAddress, cookieToken, loginsFailed) VALUES (:dtFrom, :username, :ipAddress, :cookieToken, :loginsFailed)",
            $updateCall[1][0],
            'call 1 param 0');
        $params = $this->requestData1;
        $params['cookieToken'] = $this->requestData1['token'];
        unset($params['token']);
        $params['loginsFailed'] = 1;
        $this->assertEquals($params, $updateCall[1][1], 'call 1 param 1');

        $result = self::$connection->executeQuery("SELECT * FROM secu_requests")->fetchAll();
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

    function test_countWhereLoginsSucceededSpecifiedAfter()
    {
        $result = $this->gateway->countWhereSpecifiedAfter('loginsSucceeded', $this->requestData1['username'], $this->requestData1['ipAddress'], $this->requestData1['token'], new \DateTime($this->dtLimit), 'userReleasedAt');

        $this->assertEquals('executeQuery', $this->wrapper->calls[0][0], 'first call');
        $callParams = $this->wrapper->calls[0][1];
        $this->assertEquals("SELECT sum(r.loginsSucceeded) FROM secu_requests r WHERE (r.dtFrom > :dtLimit) AND (r.username = :username) AND (r.ipAddress = :ipAddress) AND (r.cookieToken = :token) AND (userReleasedAt IS NULL)"
            , $callParams[0]);

        $expectedParams = $this->requestData1;
        unset($expectedParams['dtFrom']);
        $expectedParams['dtLimit'] = $this->dtLimit;
        $this->assertEquals($expectedParams, $callParams[1], 'parameters');

        $this->assertEquals(0, $result, '0 loginsSucceeded');
    }


    function test_incrementCount()
    {
        $this->gateway->insertOrIncrementCount(new \DateTime($this->requestData1['dtFrom']), $this->requestData1['username'], $this->requestData1['ipAddress'], $this->requestData1['token'], false, 'ipAddressBlocked');

        $this->assertEquals('executeQuery', $this->wrapper->calls[0][0]);
        $callParams = $this->wrapper->calls[0][1];

        $this->assertEquals("SELECT r.id FROM secu_requests r
    WHERE (r.username = :username)
    AND (r.ipAddress = :ipAddress)
    AND (r.dtFrom = :dtFrom)
    AND (r.cookieToken = :token)
    AND (addressReleasedAt IS NULL)
    AND (userReleasedAt IS NULL)
    AND (userReleasedForAddressAndCookieAt IS NULL)"
            , $callParams[0]);
        $this->assertEquals($this->requestData1, $callParams[1], 'parameters');

        $id = self::$connection->executeQuery($callParams[0], $callParams[1])->fetchColumn();
        $this->assertNotNull($id, 'id');

        $updateCall = $this->wrapper->calls[1];
        $this->assertEquals('executeQuery', $updateCall[0], 'call 1');
        $this->assertEquals(
            "UPDATE secu_requests SET loginsFailed = loginsFailed + 1, ipAddressBlocked = ipAddressBlocked + 1 WHERE id = :id",
            $updateCall[1][0],
            'call 1 param 0');
        $this->assertArrayHasKey('id', $updateCall[1][1], 'call 1 param 1 has [id]');
        $this->assertEquals($id, $updateCall[1][1]['id'], 'call 1 param 1 [id]');
    }

    function test_countWhereLoginsFailedSpecifiedAfter()
    {
        $result = $this->gateway->countWhereSpecifiedAfter('loginsFailed', null, null, null, new \DateTime($this->dtLimit), null);

        $this->assertEquals('executeQuery', $this->wrapper->calls[0][0], 'first call');
        $callParams = $this->wrapper->calls[0][1];
        $this->assertEquals(
            "SELECT sum(r.loginsFailed) FROM secu_requests r WHERE (r.dtFrom > :dtLimit)"
            ,
            $callParams[0]
        );
        $expectedParams['dtLimit'] = $this->dtLimit;
        $this->assertEquals($expectedParams, $callParams[1], 'parameters');

        $this->assertEquals(2, $result, 'loginsFailed');
    }

    /** @expectedException \BadFunctionCallException */
    function testException_updateCountsColumnWhereColumnNullAfterSupplied()
    {
        $this->gateway->updateCountsColumnWhereColumnNullAfterSupplied('userReleasedAt', new \DateTime($this->requestData1['dtFrom']), new \DateTime($this->dtLimit), null, null, null);
    }

    function test_updateCountsColumnWhereColumnNullAfterSupplied()
    {
        $this->gateway->updateCountsColumnWhereColumnNullAfterSupplied('userReleasedForAddressAndCookieAt', new \DateTime($this->requestData1['dtFrom']), new \DateTime($this->dtLimit), $this->requestData1['username'], $this->requestData1['ipAddress'], $this->requestData1['token']);

        $this->assertEquals('executeQuery', $this->wrapper->calls[0][0], 'first call');
        $callParams = $this->wrapper->calls[0][1];
        $this->assertEquals(
            "UPDATE secu_requests
    SET userReleasedForAddressAndCookieAt = :value
    WHERE (userReleasedForAddressAndCookieAt IS NULL)
    AND (dtFrom > :dtLimit) AND (username = :username) AND (ipAddress = :ipAddress) AND (cookieToken = :token)"
            , $callParams[0]
        );
        $expectedParams = $this->requestData1;
        $expectedParams['value'] = $this->requestData1['dtFrom'];
        unset($expectedParams['dtFrom']);
        $expectedParams['dtLimit'] = $this->dtLimit;
        $this->assertEquals($expectedParams, $callParams[1], 'parameters');

        $result = self::$connection->executeQuery("SELECT * FROM secu_requests")->fetchAll();
        $this->assertEquals(1, count($result), '1 row');
        $this->assertEquals($this->requestData1['dtFrom'], $result[0]['dtFrom'], 'dtFrom');
        $this->assertEquals($this->requestData1['username'], $result[0]['username'], 'username');
        $this->assertEquals($this->requestData1['ipAddress'], $result[0]['ipAddress'], 'ipAddress');
        $this->assertEquals($this->requestData1['token'], $result[0]['cookieToken'], 'cookieToken');
        $this->assertNull($result[0]['userReleasedAt'], 'userReleasedAt');
        $this->assertNull($result[0]['addressReleasedAt'], 'addressReleasedAt');
        $this->assertEquals($expectedParams['value'], $result[0]['userReleasedForAddressAndCookieAt'], 'userReleasedForAddressAndCookieAt');
    }

    /** @expectedException \TypeError */
    function testExceptionDeleteCountsUntil()
    {
        $this->gateway->deleteCountsUntil(null);
    }

    function test_deleteCountsUntil()
    {
        $now = new \DateTime();
        $this->gateway->deleteCountsUntil($now) ;

        $this->assertEquals('executeQuery', $this->wrapper->calls[0][0], 'first call');
        $callParams = $this->wrapper->calls[0][1];
        $this->assertEquals(
            "DELETE FROM secu_requests WHERE dtFrom < :dtLimit"
            , $callParams[0]
        );
        $expectedParams = ['dtLimit' => $now->format('Y-m-d H:i:s')];
        $this->assertEquals($expectedParams, $callParams[1], 'parameters');

        $result = self::$connection->executeQuery("SELECT * FROM secu_requests")->fetchAll();
        $this->assertEquals(0, count($result), '0 rows');

    }

    function test_restoreCounter()
    {
        $loginSucceeded = false;
        $blockedCounterName = null;
        $this->gateway->insertOrIncrementCount(new \DateTime($this->requestData1['dtFrom']), $this->requestData1['username'], $this->requestData1['ipAddress'], $this->requestData1['token'], $loginSucceeded, $blockedCounterName);
    }

    function test_countsGroupedByIpAddress()
    {
        $now = new \DateTime();
        $result = $this->gateway->countsGroupedByIpAddress(new \DateTime($this->dtLimit), $now, $this->requestData1['username']);

        $this->assertEquals('executeQuery', $this->wrapper->calls[0][0], 'connection method called');
        $callParams = $this->wrapper->calls[0][1];
        $this->assertEquals(
            "SELECT r.ipAddress
          , count(distinct(r.username)) as usernames
          , sum(r.loginsSucceeded) as loginsSucceeded
          , sum(r.loginsFailed) as loginsFailed
          , sum(r.ipAddressBlocked) as ipAddressBlocked
          , sum(r.usernameBlocked) as usernameBlocked
          , sum(r.usernameBlockedForIpAddress) as usernameBlockedForIpAddress
          , sum(r.usernameBlockedForCookie) as usernameBlockedForCookie
            FROM secu_requests r
            WHERE (r.dtFrom >= ?) AND (r.addressReleasedAt IS NULL) AND (r.dtFrom < ?) AND (r.username = ?)
            GROUP BY r.ipAddress
            ORDER BY r.ipAddress
            LIMIT 200"
            , $callParams[0]
        );
        $expectedParams = [$this->dtLimit, $now->format('Y-m-d H:i:s'), $this->requestData1['username']];
        $this->assertEquals($expectedParams, $callParams[1], 'parameters');

        $this->assertTrue(is_array($result), 'array');
        $this->assertEquals(1, count($result), '1 row');

        $this->assertEquals($this->requestData1['ipAddress'], $result[0]['ipAddress'], 'ipAddress');
        $this->assertEquals(1, $result[0]['usernames'], 'usernames');
        $this->assertEquals(0, $result[0]['loginsSucceeded'], 'loginsSucceeded');
        $this->assertEquals(1, $result[0]['loginsFailed'], 'loginsFailed');
        $this->assertEquals(0, $result[0]['ipAddressBlocked'], 'ipAddressBlocked');
        $this->assertEquals(0, $result[0]['usernameBlocked'], 'usernameBlocked');
        $this->assertEquals(0, $result[0]['usernameBlockedForIpAddress'], 'usernameBlockedForIpAddress');
        $this->assertEquals(0, $result[0]['usernameBlockedForCookie'], 'usernameBlockedForCookie');
    }

    function test_countsBetween()
    {
        $now = new \DateTime();
        $result = $this->gateway->countsBetween(new \DateTime($this->dtLimit), $now, $this->requestData1['username'], $this->requestData1['ipAddress']);

        $this->assertEquals('executeQuery', $this->wrapper->calls[0][0], 'connection method called');
        $callParams = $this->wrapper->calls[0][1];
        $this->assertEquals(
            "SELECT * FROM secu_requests r
            WHERE (r.dtFrom >= ?)  AND (r.dtFrom < ?) AND (r.username = ?) AND (r.ipAddress = ?)
            ORDER BY r.dtFrom DESC
            LIMIT 500"
            , $callParams[0]
        );
        $expectedParams = [$this->dtLimit, $now->format('Y-m-d H:i:s'), $this->requestData1['username'], $this->requestData1['ipAddress'] ];
        $this->assertEquals($expectedParams, $callParams[1], 'parameters');

        $this->assertTrue(is_array($result), 'array');
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

    function test_insertRelease()
    {
        $this->gateway->insertOrUpdateRelease(new \DateTime($this->requestData1['dtFrom']), $this->requestData1['username'], $this->requestData1['ipAddress'], $this->requestData1['token']);

        $this->assertEquals('executeQuery', $this->wrapper->calls[0][0], 'connection method called');
        $callParams = $this->wrapper->calls[0][1];
        $this->assertEquals(
            "SELECT id from secu_releases WHERE username = :username AND ipAddress = :ipAddress AND cookieToken = :cookieToken"
            , $callParams[0]
        );
        $expectedParams = [
            'ipAddress' => $this->requestData1['ipAddress'],
            'username' => $this->requestData1['username'],
            'cookieToken' => $this->requestData1['token'],
        ];
        $this->assertEquals($expectedParams, $callParams[1], 'parameters');

        $this->assertEquals('executeQuery', $this->wrapper->calls[1][0], 'connection method called');
        $callParams = $this->wrapper->calls[1][1];
        $this->assertEquals(
            "INSERT INTO secu_releases (releasedAt, username, ipAddress, cookieToken) VALUES (:releasedAt, :username, :ipAddress, :cookieToken)"
            , $callParams[0]
        );
        $expectedParams = array_merge($expectedParams, ['releasedAt' => $this->requestData1['dtFrom']]);
        $this->assertEquals($expectedParams, $callParams[1], 'parameters');

        $result = self::$connection->executeQuery("SELECT * FROM secu_releases")->fetchAll();
        $this->assertEquals(1, count($result), '1 row');
        $this->assertEquals($this->requestData1['dtFrom'], $result[0]['releasedAt'], 'releasedAt');
        $this->assertEquals($this->requestData1['username'], $result[0]['username'], 'username');
        $this->assertEquals($this->requestData1['ipAddress'], $result[0]['ipAddress'], 'ipAddress');
        $this->assertEquals($this->requestData1['token'], $result[0]['cookieToken'], 'cookieToken');
    }

    function test_updateRelease()
    {
        $now = new \DateTime();
        $this->gateway->insertOrUpdateRelease($now, $this->requestData1['username'], $this->requestData1['ipAddress'], $this->requestData1['token']);

        $this->assertEquals('executeQuery', $this->wrapper->calls[0][0], 'first connection method called');
        $callParams = $this->wrapper->calls[0][1];
        $this->assertEquals(
            "SELECT id from secu_releases WHERE username = :username AND ipAddress = :ipAddress AND cookieToken = :cookieToken"
            , $callParams[0]
        );
        $expectedParams = [
            'ipAddress' => $this->requestData1['ipAddress'],
            'username' => $this->requestData1['username'],
            'cookieToken' => $this->requestData1['token'],
        ];
        $this->assertEquals($expectedParams, $callParams[1], 'parameters');
        $id = self::$connection->executeQuery($callParams[0], $callParams[1])->fetchColumn();
        $this->assertNotFalse($id, 'id found');

        $this->assertEquals('executeQuery', $this->wrapper->calls[1][0], 'second connection method called');
        $callParams = $this->wrapper->calls[1][1];
        $this->assertEquals(
            "UPDATE secu_releases SET releasedAt = :releasedAt WHERE id = :id"
            , $callParams[0]
        );
        $expectedParams = [
            'releasedAt' => $now->format('Y-m-d H:i:s'),
            'id' => $id,
        ];
        $this->assertEquals($expectedParams, $callParams[1], 'parameters');

        $result = self::$connection->executeQuery("SELECT * FROM secu_releases")->fetchAll();
        $this->assertEquals(1, count($result), '1 row');
        $this->assertEquals($now->format('Y-m-d H:i:s'), $result[0]['releasedAt'], 'releasedAt');

        //restore releasedAt
        $this->gateway->insertOrUpdateRelease(new \DateTime($this->requestData1['dtFrom']), $this->requestData1['username'], $this->requestData1['ipAddress'], $this->requestData1['token']);
    }

    function test_isUserReleasedOnAddressFrom()
    {
        $result = $this->gateway->isUserReleasedOnAddressFrom($this->requestData1['username'], $this->requestData1['ipAddress'], new \DateTime($this->dtLimit));

        $this->assertEquals('executeQuery', $this->wrapper->calls[0][0], 'first connection method called');
        $callParams = $this->wrapper->calls[0][1];
        $this->assertEquals(
            "SELECT max(r.releasedAt)
        FROM secu_releases r
        WHERE r.releasedAt >= ?
                AND r.username = ? AND r.ipAddress = ? "
            , $callParams[0]
        );
        $expectedParams = [
            $this->dtLimit,
            $this->requestData1['username'],
            $this->requestData1['ipAddress'],
        ];
        $this->assertEquals($expectedParams, $callParams[1], 'parameters');

        $this->assertTrue($result, 'result');
    }

    function test_isUserReleasedByCookieFrom()
    {
        $result = $this->gateway->isUserReleasedByCookieFrom($this->requestData1['username'], $this->requestData1['token'], new \DateTime($this->dtLimit));

        $this->assertEquals('executeQuery', $this->wrapper->calls[0][0], 'first connection method called');
        $callParams = $this->wrapper->calls[0][1];
        $this->assertEquals(
            "SELECT max(r.releasedAt)
        FROM secu_releases r
        WHERE r.releasedAt >= ?
                AND r.username = ? AND r.cookieToken = ? "
            , $callParams[0]
        );
        $expectedParams = [
            $this->dtLimit,
            $this->requestData1['username'],
            $this->requestData1['token'],
        ];
        $this->assertEquals($expectedParams, $callParams[1], 'parameters');

        $this->assertTrue($result, 'result');
    }

    function test_deleteReleasesUntil()
    {
        $this->gateway->deleteReleasesUntil(new \DateTime($this->dtLimit)); // Before releasedAt

        $this->assertEquals('executeQuery', $this->wrapper->calls[0][0]);
        $callParams = $this->wrapper->calls[0][1];
        $this->assertEquals("DELETE FROM secu_releases WHERE releasedAt < :dtLimit"
            , $callParams[0]);
        $this->assertEquals(['dtLimit' => $this->dtLimit], $callParams[1], 'parameters');

        $result = self::$connection->executeQuery("SELECT * FROM secu_releases")->fetchAll();
        $this->assertEquals(1, count($result), '1 row');

        $this->gateway->deleteReleasesUntil(new \DateTime());
        $result = self::$connection->executeQuery("SELECT * FROM secu_releases")->fetchAll();
        $this->assertEquals(0, count($result), '1 row');
    }
}