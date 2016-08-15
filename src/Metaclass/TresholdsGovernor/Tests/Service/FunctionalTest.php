<?php 
namespace Metaclass\TresholdsGovernor\Tests\Service;

use Metaclass\TresholdsGovernor\Manager\StatisticsManagerInterface;
use Metaclass\TresholdsGovernor\Service\TresholdsGovernor;
use Metaclass\TresholdsGovernor\Manager\RdbManager;
use Metaclass\TresholdsGovernor\Result\Rejection;
use Metaclass\TresholdsGovernor\Result\IpAddressBlocked;
use Metaclass\TresholdsGovernor\Result\UsernameBlocked;
use Metaclass\TresholdsGovernor\Result\UsernameBlockedForCookie;
use Metaclass\TresholdsGovernor\Result\UsernameBlockedForIpAddress;

use Metaclass\TresholdsGovernor\Tests\Gateway\RdbGatewayTest;
use Metaclass\TresholdsGovernor\Gateway\RdbGateway;

use \PDO;
use Metaclass\TresholdsGovernor\Connection\PDOConnection;

class FunctionalTest extends \PHPUnit_Framework_TestCase
{
    STATIC $connection;

    /**
     * @var TresholdsGovernor
     */
    protected $governor;

    /**
     * @var StatisticsManagerInterface
     */
    protected $statisticsManager;

    function setup()
    {
        if (!isSet(self::$connection)) {
            $pdo = new PDO('sqlite::memory:');
            self::$connection = new PDOConnection($pdo);

            RdbGatewayTest::$connection = self::$connection;
            RdbGatewayTest::createTables();
        }
        $gateway = new RdbGateway(self::$connection);
        $manager = new RdbManager($gateway);
        $this->governor = new TresholdsGovernor(array(), $manager);
        $this->statisticsManager = $manager;

        $this->governor->dtString = '1980-07-01 00:00:00';
        $this->governor->counterDurationInSeconds = 300; //5 minutes
        $this->governor->blockUsernamesFor = '30 days'; 
        $this->governor->blockIpAddressesFor = '30 days'; //not very realistic, but should still work
        $this->governor->allowReleasedUserOnAddressFor = '30 days'; 
        $this->governor->allowReleasedUserByCookieFor =  '10 days';
    }

    protected function get($propName)
    {
        $rClass = new \ReflectionClass($this->governor);
        $rProp = $rClass->getProperty($propName);
        $rProp->setAccessible(true);
        return $rProp->getValue($this->governor);
    }

    
    function testSetup()
    {
        $dt = new \DateTime($this->governor->dtString);
        $this->assertEquals('1980-07-01 00:00:00', $dt->format('Y-m-d H:i:s'), 'DateTime is properly constructed');
    }
    
    /** test that the request counts dtFrom will be set floored to 5 minutes, as setup has configured $this->governor */
    function testGetRequestCountsDt()
    {
        $this->assertEquals('1980-07-01 00:00:00', $this->governor->getRequestCountsDt('1980-07-01 00:00:00')->format('Y-m-d H:i:s'));
        $this->assertEquals('1980-07-01 00:00:00', $this->governor->getRequestCountsDt('1980-07-01 00:00:01')->format('Y-m-d H:i:s'));
        $this->assertEquals('1980-07-01 00:00:00', $this->governor->getRequestCountsDt('1980-07-01 00:04:59')->format('Y-m-d H:i:s'));
        $this->assertEquals('1980-07-01 00:05:00', $this->governor->getRequestCountsDt('1980-07-01 00:05:00')->format('Y-m-d H:i:s'));
    }
    
    function testInitFor() 
    {
        $this->governor->initFor('192.168.255.255', 'testuser1', 'whattheheck', 'cookieToken1');
        $this->assertEquals(0, $this->get('failureCountForIpAddress'), 'failure count for ip address');
        $this->assertEquals(0, $this->get('failureCountForUserName'), 'failure count for username');
        $this->assertEquals(0, $this->get('failureCountForUserOnAddress'), 'failure count for username on address');
        $this->assertEquals(0, $this->get('failureCountForUserByCookie'), 'failure count for username by cookie');
    }
    
    function testRegisterAuthenticationFailure() 
    {
        $this->governor->initFor('192.168.255.255', 'testuser1', 'whattheheck', 'cookieToken1');
        $this->governor->registerAuthenticationFailure();
        
        $this->governor->initFor('192.168.255.250', 'testuserX', 'xxx', 'cookieTokenX');
        $this->governor->registerAuthenticationFailure();
        
        $this->governor->initFor('192.168.255.255', 'testuser2', 'whattheheck', 'cookieToken1');
        $this->assertEquals(1, $this->get('failureCountForIpAddress'), 'failure count by ip address');
        $this->assertEquals(0, $this->get('failureCountForUserName'), 'failure count by other username');
        $this->assertEquals(0, $this->get('failureCountForUserOnAddress'), 'failure count for other username on address');
        $this->assertEquals(0, $this->get('failureCountForUserByCookie'), 'failure count for other username by cookie');
        $this->assertFalse($this->get('isUserReleasedOnAddress'), 'is other user released on address');
        $this->assertFalse($this->get('isUserReleasedByCookie'), 'is other user released by cookie');
         
        $this->governor->initFor('192.168.255.254', 'testuser1', 'whattheheck', 'cookieToken1');
        $this->assertEquals(0, $this->get('failureCountForIpAddress'), 'failure count by other ip address');
        $this->assertEquals(1, $this->get('failureCountForUserName'), 'failure count by username');
        $this->assertEquals(0, $this->get('failureCountForUserOnAddress'), 'failure count for username on other address');
        $this->assertEquals(1, $this->get('failureCountForUserByCookie'), 'failure count for username by cookie');
        $this->assertFalse($this->get('isUserReleasedOnAddress'), 'is user released on other address');
        $this->assertFalse($this->get('isUserReleasedByCookie'), 'is user released by cookie');
        
        $this->governor->initFor('192.168.255.255', 'testuser1', 'whattheheck', 'cookieToken2');
        $this->assertEquals(1, $this->get('failureCountForIpAddress'), 'failure count by ip address');
        $this->assertEquals(1, $this->get('failureCountForUserName'), 'failure count by username');
        $this->assertEquals(1, $this->get('failureCountForUserOnAddress'), 'failure count for username on address');
        $this->assertEquals(0, $this->get('failureCountForUserByCookie'), 'failure count for username by other cookie');        
        $this->assertFalse($this->get('isUserReleasedOnAddress'), 'is user released on address');
        $this->assertFalse($this->get('isUserReleasedByCookie'), 'is user released on other cookie');

        //count increments because 'just failed' are transient, governor is reinitialized in next test

        // Assert that one counter exists for 192.168.255.255
        $from = new \DateTime('1980-07-01 00:00:00');
        $until = new \DateTime('1981-01-01');
        $counts = $this->statisticsManager->countsByAddressBetween('192.168.255.255', $from, $until);
        $this->assertEquals(1, count($counts), '192.168.255.255 one counter');
        $this->assertEquals(0, $counts[0]['ipAddressBlocked'], '192.168.255.255 ipAddressBlocked');
        $this->assertEquals(0, $counts[0]['usernameBlocked'], '192.168.255.255 usernameBlocked');
        $this->assertEquals(0, $counts[0]['usernameBlockedForIpAddress'], '192.168.255.255 usernameBlockedForIpAddress');
        $this->assertEquals(0, $counts[0]['usernameBlockedForCookie'], '192.168.255.255 usernameBlockedForCookie');
    }

    // Feature Not used
    function checkAuthenticationJustFailed()
    {
        $this->governor->limitPerUserName = 3;
        $this->governor->limitBasePerIpAddress = 3;
        $this->governor->initFor('192.168.255.255', 'testuser1', 'whattheheck', 'cookieToken1');
        $this->assertEquals(1, $this->get('failureCountForIpAddress'), 'failure count for ip address');
        $this->assertEquals(1, $this->get('failureCountForUserName'), 'failure count for username');
        $this->assertEquals(1, $this->get('failureCountForUserOnAddress'), 'failure count for username on address');
        $this->assertEquals(1, $this->get('failureCountForUserByCookie'), 'failure count for username by cookie');
        
        $this->assertNull($this->governor->checkAuthentication(true)); 
        $this->assertEquals(2, $this->get('failureCountForIpAddress'), 'failure count for ip address');
        $this->assertEquals(2, $this->get('failureCountForUserName'), 'failure count for username');
        $this->assertEquals(2, $this->get('failureCountForUserOnAddress'), 'failure count for username on address');
        $this->assertEquals(2, $this->get('failureCountForUserByCookie'), 'failure count for username by cookie');
    }
    
    function testCheckAuthenticationUnreleased() 
    {
        $this->governor->limitPerUserName = 3;
        $this->governor->limitBasePerIpAddress = 2;
        $this->governor->initFor('192.168.255.255', 'testuser1', 'whattheheck', 'cookieToken1');
        $this->assertNull($this->governor->checkAuthentication()); 

        $this->governor->limitBasePerIpAddress = 1;
        $result = $this->governor->checkAuthentication(); //registers authentication failure, but that only shows up when $this->governor->initFor
        $this->assertNotNull($result, 'result');
        $this->assertInstanceOf('Metaclass\TresholdsGovernor\Result\IpAddressBlocked', $result);
        $this->assertEquals("IP Adress '%ipAddress%' is blocked", $result->message);
        $this->assertEquals(array('%ipAddress%' => '192.168.255.255'), $result->parameters);
        
        $this->governor->limitPerUserName = 2;
        $this->governor->limitBasePerIpAddress = 3;
        $this->assertNull($this->governor->checkAuthentication(), 'result'); 
        
        
        $this->governor->limitPerUserName = 1;
        $result = $this->governor->checkAuthentication(); //registers authentication failure, but that only shows up when $this->governor->initFor
        $this->assertNotNull($result, 'result');
        $this->assertInstanceOf('Metaclass\TresholdsGovernor\Result\UsernameBlocked', $result);
        $this->assertEquals("Username '%username%' is blocked", $result->message);
        $this->assertEquals(array('%username%' => 'testuser1'), $result->parameters);

        // Assert that the one counts for 192.168.255.255 blocked counters have been incremented accordingly
        $from = new \DateTime('1980-07-01 00:00:00');
        $until = new \DateTime('1981-01-01');
        $counts = $this->statisticsManager->countsByAddressBetween('192.168.255.255', $from, $until);
        $this->assertEquals(1, count($counts), '192.168.255.255 one counter');
        $this->assertEquals(1, $counts[0]['ipAddressBlocked'], '192.168.255.255 ipAddressBlocked');
        $this->assertEquals(1, $counts[0]['usernameBlocked'], '192.168.255.255 usernameBlocked');
        $this->assertEquals(0, $counts[0]['usernameBlockedForIpAddress'], '192.168.255.255 usernameBlockedForIpAddress');
        $this->assertEquals(0, $counts[0]['usernameBlockedForCookie'], '192.168.255.255 usernameBlockedForCookie');
    }
    
    function testRegisterAuthenticationSuccess() 
    {
        $this->governor->initFor('192.168.255.255', 'testuser1', 'whattheheck', 'cookieToken1');
        $this->governor->registerAuthenticationSuccess();
        
        $this->governor->initFor('192.168.255.255', 'testuser1', 'whattheheck', 'cookieToken1');
        $this->assertEquals(3, $this->get('failureCountForIpAddress'), 'failure count by ip address');
        $this->assertEquals(3, $this->get('failureCountForUserName'), 'failure count by  username');
        $this->assertEquals(0, $this->get('failureCountForUserOnAddress'), 'failure count for username on address');
        $this->assertEquals(0, $this->get('failureCountForUserByCookie'), 'failure count for username by cookie');
        $this->assertTrue($this->get('isUserReleasedOnAddress'), 'is user released on address');
        $this->assertTrue($this->get('isUserReleasedByCookie'), 'is user released by cookie');
        
        $this->governor->initFor('192.168.255.255', 'testuser2', 'whattheheck', 'cookieToken1');
        $this->assertEquals(3, $this->get('failureCountForIpAddress'), 'failure count by ip address');
        $this->assertEquals(0, $this->get('failureCountForUserName'), 'failure count by other username');
        $this->assertEquals(0, $this->get('failureCountForUserOnAddress'), 'failure count for other username on address');
        $this->assertEquals(0, $this->get('failureCountForUserByCookie'), 'failure count for other username by cookie');
        $this->assertFalse($this->get('isUserReleasedOnAddress'), 'is other user released on address');
        $this->assertFalse($this->get('isUserReleasedByCookie'), 'is other user released by cookie');
        
        $this->governor->initFor('192.168.255.254', 'testuser1', 'whattheheck', 'cookieToken1');
        $this->assertEquals(0, $this->get('failureCountForIpAddress'), 'failure count by other ip address');
        $this->assertEquals(3, $this->get('failureCountForUserName'), 'failure count by username');
        $this->assertEquals(0, $this->get('failureCountForUserOnAddress'), 'failure count for username on other address');
        $this->assertEquals(0, $this->get('failureCountForUserByCookie'), 'failure count for username by cookie');
        $this->assertFalse($this->get('isUserReleasedOnAddress'), 'is user released on other address');
        $this->assertTrue($this->get('isUserReleasedByCookie'), 'is user released by cookie');
        
        $this->governor->initFor('192.168.255.255', 'testuser1', 'whattheheck', 'cookieToken2');
        $this->assertEquals(3, $this->get('failureCountForIpAddress'), 'failure count by ip address');
        $this->assertEquals(3, $this->get('failureCountForUserName'), 'failure count by username, other cookieToken');
        $this->assertEquals(0, $this->get('failureCountForUserOnAddress'), 'failure count for username on address, other cookieToken');
        $this->assertEquals(0, $this->get('failureCountForUserByCookie'), 'failure count for username by other cookie');        
        $this->assertTrue($this->get('isUserReleasedOnAddress'), 'is user released on address');
        $this->assertFalse($this->get('isUserReleasedByCookie'), 'is user released on by cookie');
        
        $this->governor->initFor('192.168.255.254', 'testuser1', 'whattheheck', 'cookieToken2');
        $this->assertEquals(0, $this->get('failureCountForIpAddress'), 'failure count by other ip address');
        $this->assertEquals(3, $this->get('failureCountForUserName'), 'failure count by username, other addres and other cookieToken');
        $this->assertEquals(0, $this->get('failureCountForUserOnAddress'), 'failure count for username on other address, other cookieToken');
        $this->assertEquals(0, $this->get('failureCountForUserByCookie'), 'failure count for username by other cookie, other address');        
        $this->assertFalse($this->get('isUserReleasedOnAddress'), 'is user released on other address');
        $this->assertFalse($this->get('isUserReleasedByCookie'), 'is user released on by cookie');
    }
    
    function testRegisterAuthenticationFailureAfterSuccess() 
    {
        $this->governor->initFor('192.168.255.255', 'testuser1', 'whattheheck', 'cookieToken1');
        $this->governor->registerAuthenticationFailure();
        
        $this->governor->initFor('192.168.255.255', 'testuser2', 'whattheheck', 'cookieToken1');
        $this->assertEquals(4, $this->get('failureCountForIpAddress'), 'failure count by ip address');
        $this->assertEquals(0, $this->get('failureCountForUserName'), 'failure count by other username');
        $this->assertEquals(0, $this->get('failureCountForUserOnAddress'), 'failure count for other username on address');
        $this->assertEquals(0, $this->get('failureCountForUserByCookie'), 'failure count for other username by cookie');
        $this->assertFalse($this->get('isUserReleasedOnAddress'), 'is other user released on address');
        $this->assertFalse($this->get('isUserReleasedByCookie'), 'is other user released by cookie');
         
        $this->governor->initFor('192.168.255.254', 'testuser1', 'whattheheck', 'cookieToken1');
        $this->assertEquals(0, $this->get('failureCountForIpAddress'), 'failure count by other ip address');
        $this->assertEquals(4, $this->get('failureCountForUserName'), 'failure count by username');
        $this->assertEquals(0, $this->get('failureCountForUserOnAddress'), 'failure count for username on other address');
        $this->assertEquals(1, $this->get('failureCountForUserByCookie'), 'failure count for username by cookie');
        $this->assertFalse($this->get('isUserReleasedOnAddress'), 'is user released on other address');
        $this->assertTrue($this->get('isUserReleasedByCookie'), 'is user released by cookie');
        
        $this->governor->initFor('192.168.255.255', 'testuser1', 'whattheheck', 'cookieToken2');
        $this->assertEquals(4, $this->get('failureCountForIpAddress'), 'failure count by ip address');
        $this->assertEquals(4, $this->get('failureCountForUserName'), 'failure count by username');
        $this->assertEquals(1, $this->get('failureCountForUserOnAddress'), 'failure count for username on address');
        $this->assertEquals(0, $this->get('failureCountForUserByCookie'), 'failure count for username by other cookie');        
        $this->assertTrue($this->get('isUserReleasedOnAddress'), 'is user released on address');
        $this->assertFalse($this->get('isUserReleasedByCookie'), 'is user released by other cookie');
    }
    
    function testCheckAuthenticationWithUserReleasedOnIpAddressAndCookie() 
    {
        $this->governor->dtString = '1980-07-01 00:05:00'; //5 minutes later
        
        $this->governor->limitPerUserName = 2;
        $this->governor->limitBasePerIpAddress = 5;
        $this->governor->initFor('192.168.255.255', 'testuser1', 'whattheheck', 'cookieToken1');
        $this->assertNull($this->governor->checkAuthentication());
    
        $this->governor->limitBasePerIpAddress = 4;
        $this->assertNull($this->governor->checkAuthentication());
    
        $this->governor->initFor('192.168.255.254', 'testuser1', 'whattheheck', 'cookieToken1');
        $this->assertNull($this->governor->checkAuthentication()); //assert no Rejection because of cookieToken released
    
        $this->governor->initFor('192.168.255.255', 'testuser1', 'whattheheck', 'cookieToken2');
        $this->assertNull($this->governor->checkAuthentication()); //assert no Rejection because of ip address released
    
        $this->governor->limitPerUserName = 5;
        $this->governor->initFor('192.168.255.254', 'testuser1', 'whattheheck', 'cookieToken2');
        $this->assertNull($this->governor->checkAuthentication()); //assert no Rejection on other ip address
    
        $this->governor->limitBasePerIpAddress = 4;
        $this->governor->limitPerUserName = 2;
        $this->governor->initFor('192.168.255.255', 'testuser2', 'whattheheck', 'cookieToken1');
        $result = $this->governor->checkAuthentication(); //registers authentication failure for testuser2
        $this->assertNotNull($result, 'result');
        $this->assertInstanceOf('Metaclass\TresholdsGovernor\Result\IpAddressBlocked', $result);
        $this->assertEquals("IP Adress '%ipAddress%' is blocked", $result->message);
        $this->assertEquals(array('%ipAddress%' => '192.168.255.255'), $result->parameters);

        $this->governor->initFor('192.168.255.254', 'testuser1', 'whattheheck', 'cookieToken1');
        $this->assertNull($this->governor->checkAuthentication()); //assert no Rejection because of cookieToken released
        
        $this->governor->initFor('192.168.255.255', 'testuser1', 'whattheheck', 'cookieToken2');
        $this->assertNull($this->governor->checkAuthentication()); //assert no Rejection because of ip address released
        
        $this->governor->limitPerUserName = 0;
        $result = $this->governor->checkAuthentication(); //registers authentication failure on cookieToken2 and ip 255
        $this->assertNotNull($result, 'result');
        $this->assertInstanceOf('Metaclass\TresholdsGovernor\Result\UsernameBlockedForIpAddress', $result);
        $this->assertEquals("Username '%username%' is blocked for IP Address '%ipAddress%'", $result->message);
        $this->assertEquals(array('%username%' => 'testuser1', '%ipAddress%' => '192.168.255.255'), $result->parameters);

        $this->governor->initFor('192.168.255.254', 'testuser1', 'whattheheck', 'cookieToken1');
        $result = $this->governor->checkAuthentication(); //registers authentication failure on ip 254 and cookieToken1
        $this->assertNotNull($result, 'result');
        $this->assertInstanceOf('Metaclass\TresholdsGovernor\Result\UsernameBlockedForCookie', $result);
        $this->assertEquals("Username '%username%' is blocked for cookie '%cookieToken%'", $result->message);
        $this->assertEquals(array('%username%' => 'testuser1', '%cookieToken%' => 'cookieToken1'), $result->parameters);
        
        $this->governor->limitPerUserName = 2;
        $this->assertNull($this->governor->checkAuthentication()); //assert no Rejection because of cookieToken released

        // Assert that new counters have been created for the 'failed' decisions,
        // with the right blockedCounter set to 1
        $from = new \DateTime('1980-07-01 00:00:05');
        $until = new \DateTime('1981-01-01');
        $counts = $this->statisticsManager->countsByAddressBetween('192.168.255.255', $from, $until);
        $this->assertEquals(2, count($counts), '192.168.255.255 counter2');

        $testuser1 = array_filter($counts, function($e) {
            return $e['username'] == 'testuser1';
        });
        $this->assertEquals(1, count($testuser1) , '192.168.255.255 testuser1');
        $this->assertEquals(0, current($testuser1)['ipAddressBlocked'], '192.168.255.255 ipAddressBlocked');
        $this->assertEquals(0, current($testuser1)['usernameBlocked'], '192.168.255.255 usernameBlocked');
        $this->assertEquals(1, current($testuser1)['usernameBlockedForIpAddress'], '192.168.255.255 usernameBlockedForIpAddress');
        $this->assertEquals(0, current($testuser1)['usernameBlockedForCookie'], '192.168.255.255 usernameBlockedForCookie');

        $testuser2 = array_filter($counts, function($e) {
            return $e['username'] == 'testuser2';
        });
        $this->assertEquals(1, count($testuser2) , '192.168.255.255 testuser1');
        $this->assertEquals(1, current($testuser2)['ipAddressBlocked'], '192.168.255.255 ipAddressBlocked');
        $this->assertEquals(0, current($testuser2)['usernameBlocked'], '192.168.255.255 usernameBlocked');
        $this->assertEquals(0, current($testuser2)['usernameBlockedForIpAddress'], '192.168.255.255 usernameBlockedForIpAddress');
        $this->assertEquals(0, current($testuser2)['usernameBlockedForCookie'], '192.168.255.255 usernameBlockedForCookie');
    }
    
    function testBlockingDurations() 
    {
        $this->governor->initFor('192.168.255.255', 'testuser1', 'whattheheck', 'cookieToken1');
        $this->assertEquals(6, $this->get('failureCountForIpAddress'), 'failure count by ip address');
        $this->assertEquals(6, $this->get('failureCountForUserName'), 'failure count by username');
        $this->assertEquals(2, $this->get('failureCountForUserOnAddress'), 'failure count for username on address');
        $this->assertEquals(2, $this->get('failureCountForUserByCookie'), 'failure count for username by cookie');
        $this->assertTrue($this->get('isUserReleasedOnAddress'), 'is other released on address');
        $this->assertTrue($this->get('isUserReleasedByCookie'), 'is user released by cookie');
        
        $this->governor->dtString = '1980-07-10 23:59:59';  //just less then 10 days after first request
        
        $this->governor->blockUsernamesFor = '10 days';
        
        $this->governor->initFor('192.168.255.255', 'testuser1', 'whattheheck', 'cookieToken1');
        $this->assertEquals(6, $this->get('failureCountForIpAddress'), 'failure count by ip address');
        $this->assertEquals(6, $this->get('failureCountForUserName'), 'failure count by username');
        $this->assertEquals(2, $this->get('failureCountForUserOnAddress'), 'failure count for username on address');
        $this->assertEquals(2, $this->get('failureCountForUserByCookie'), 'failure count for username by cookie');
        $this->assertTrue($this->get('isUserReleasedOnAddress'), 'is user released on address');
        $this->assertTrue($this->get('isUserReleasedByCookie'), 'is user released by cookie');
        
        $this->governor->blockUsernamesFor = '863995 seconds'; //5 seconds less then 10 days
        $this->governor->initFor('192.168.255.255', 'testuser1', 'whattheheck', 'cookieToken1');
        $this->assertEquals(6, $this->get('failureCountForIpAddress'), 'failure count by ip address');
        $this->assertEquals(2, $this->get('failureCountForUserName'), 'failure count by username');
        $this->assertEquals(1, $this->get('failureCountForUserOnAddress'), 'failure count for username on address');
        $this->assertEquals(1, $this->get('failureCountForUserByCookie'), 'failure count for username by cookie');
        $this->assertTrue($this->get('isUserReleasedOnAddress'), 'is user released on address');
        $this->assertTrue($this->get('isUserReleasedByCookie'), 'is user released by cookie');

        $this->governor->blockIpAddressesFor = '10 days';
        $this->governor->initFor('192.168.255.255', 'testuser1', 'whattheheck', 'cookieToken1');
        $this->assertEquals(6, $this->get('failureCountForIpAddress'), 'failure count by ip address');
        
        $this->governor->blockIpAddressesFor = '863995 seconds'; //5 seconds less then 10 days
        $this->governor->initFor('192.168.255.255', 'testuser1', 'whattheheck', 'cookieToken1');
        $this->assertEquals(2, $this->get('failureCountForIpAddress'), 'failure count by ip address');
        
    }

    function testReleaseDurations() 
    {
        $this->governor->dtString = '1980-07-11 00:00:00'; //10 days after first request and releases
        
        $this->governor->allowReleasedUserOnAddressFor = '10 days';
        $this->governor->allowReleasedUserByCookieFor = '10 days';
        $this->governor->initFor('192.168.255.255', 'testuser1', 'whattheheck', 'cookieToken1');
        $this->assertTrue($this->get('isUserReleasedOnAddress'), 'is user released on address');
        $this->assertTrue($this->get('isUserReleasedByCookie'), 'is user released by cookie');
        
        $this->governor->allowReleasedUserOnAddressFor = '863995 seconds'; //5 seconds less then 10 days
        $this->governor->initFor('192.168.255.255', 'testuser1', 'whattheheck', 'cookieToken1');
        $this->assertFalse($this->get('isUserReleasedOnAddress'), 'is user released on address');
        $this->assertTrue($this->get('isUserReleasedByCookie'), 'is user released by cookie');
        //should not be influenced:
        $this->assertEquals(2, $this->get('failureCountForUserOnAddress'), 'failure count for username on address');
        $this->assertEquals(2, $this->get('failureCountForUserByCookie'), 'failure count for username by cookie');
                
        $this->governor->allowReleasedUserOnAddressFor = '10 days';
        $this->governor->allowReleasedUserByCookieFor = '863995 seconds'; //5 seconds less then 10 days
        $this->governor->initFor('192.168.255.255', 'testuser1', 'whattheheck', 'cookieToken1');
        $this->assertTrue($this->get('isUserReleasedOnAddress'), 'is user released on address');
        $this->assertFalse($this->get('isUserReleasedByCookie'), 'is user released by cookie');
        //should not be influenced:
        $this->assertEquals(2, $this->get('failureCountForUserOnAddress'), 'failure count for username on address');
        $this->assertEquals(2, $this->get('failureCountForUserByCookie'), 'failure count for username by cookie');
        
    }
    
    function testDeleteData1() 
    {
        $this->get('requestCountsManager')->deleteCountsUntil(new \DateTime('1981-01-01'));
        $this->get('releasesManager')->deleteReleasesUntil(new \DateTime('1981-01-01'));
        
        $this->governor->initFor('192.168.255.255', 'testuser1', 'whattheheck', 'cookieToken1');
        $this->assertEquals(0, $this->get('failureCountForIpAddress'), 'failure count by ip address');
        $this->assertEquals(0, $this->get('failureCountForUserName'), 'failure count by username');
        $this->assertEquals(0, $this->get('failureCountForUserOnAddress'), 'failure count for username on address');
        $this->assertEquals(0, $this->get('failureCountForUserByCookie'), 'failure count for username by cookie');
        
        $this->assertFalse($this->get('isUserReleasedOnAddress'), 'is user released on address');
        $this->assertFalse($this->get('isUserReleasedByCookie'), 'is user released by cookie');
    }

    function testRegisterAuthenticationSuccessReleasingUser() {
        $this->governor->initFor('192.168.255.255', 'testuser1', 'whattheheck', 'cookieToken1');
        $this->governor->releaseUserOnLoginSuccess = true;
        $this->governor->registerAuthenticationFailure();
        $this->governor->registerAuthenticationSuccess();
        
        $this->governor->initFor('192.168.255.255', 'testuser1', 'whattheheck', 'cookieToken1');
        $this->assertEquals(1, $this->get('failureCountForIpAddress'), 'failure count by ip address');
        $this->assertEquals(0, $this->get('failureCountForUserName'), 'failure count by  username');
        $this->assertEquals(0, $this->get('failureCountForUserOnAddress'), 'failure count for username on address');
        $this->assertEquals(0, $this->get('failureCountForUserByCookie'), 'failure count for username by cookie');
        $this->assertTrue($this->get('isUserReleasedOnAddress'), 'is user released on address');
        $this->assertTrue($this->get('isUserReleasedByCookie'), 'is user released by cookie');
        
        $this->governor->initFor('192.168.255.255', 'testuser2', 'whattheheck', 'cookieToken1');
        $this->assertEquals(1, $this->get('failureCountForIpAddress'), 'failure count by ip address');
        $this->assertEquals(0, $this->get('failureCountForUserName'), 'failure count by other username');
        $this->assertEquals(0, $this->get('failureCountForUserOnAddress'), 'failure count for other username on address');
        $this->assertEquals(0, $this->get('failureCountForUserByCookie'), 'failure count for other username by cookie');
        $this->assertFalse($this->get('isUserReleasedOnAddress'), 'is other user released on address');
        $this->assertFalse($this->get('isUserReleasedByCookie'), 'is other user released by cookie');
        
        $this->governor->initFor('192.168.255.254', 'testuser1', 'whattheheck', 'cookieToken1');
        $this->assertEquals(0, $this->get('failureCountForIpAddress'), 'failure count by other ip address');
        $this->assertEquals(0, $this->get('failureCountForUserName'), 'failure count by username');
        $this->assertEquals(0, $this->get('failureCountForUserOnAddress'), 'failure count for username on other address');
        $this->assertEquals(0, $this->get('failureCountForUserByCookie'), 'failure count for username by cookie');
        $this->assertFalse($this->get('isUserReleasedOnAddress'), 'is user released on other address');
        $this->assertTrue($this->get('isUserReleasedByCookie'), 'is user released by cookie');
        
        $this->governor->initFor('192.168.255.255', 'testuser1', 'whattheheck', 'cookieToken2');
        $this->assertEquals(1, $this->get('failureCountForIpAddress'), 'failure count by ip address');
        $this->assertEquals(0, $this->get('failureCountForUserName'), 'failure count by username, other cookieToken');
        $this->assertEquals(0, $this->get('failureCountForUserOnAddress'), 'failure count for username on address, other cookieToken');
        $this->assertEquals(0, $this->get('failureCountForUserByCookie'), 'failure count for username by other cppkie');
        $this->assertTrue($this->get('isUserReleasedOnAddress'), 'is user released on address');
        $this->assertFalse($this->get('isUserReleasedByCookie'), 'is user released by other cookie');
        
        $this->governor->initFor('192.168.255.254', 'testuser1', 'whattheheck', 'cookieToken2');
        $this->assertEquals(0, $this->get('failureCountForIpAddress'), 'failure count by other ip address');
        $this->assertEquals(0, $this->get('failureCountForUserName'), 'failure count by username, other addres and other cookieToken');
        $this->assertEquals(0, $this->get('failureCountForUserOnAddress'), 'failure count for username on other address, other cookieToken');
        $this->assertEquals(0, $this->get('failureCountForUserByCookie'), 'failure count for username by other cookie, other address');
        $this->assertFalse($this->get('isUserReleasedOnAddress'), 'is user released on other address');
        $this->assertFalse($this->get('isUserReleasedByCookie'), 'is user released by other cookie');
    }        
    
    function testCheckAuthenticationWithUserReleased() 
    {
        $this->governor->limitPerUserName = 1;
        $this->governor->limitBasePerIpAddress = 1;
        $this->governor->initFor('192.168.255.255', 'testuser1', 'whattheheck', 'cookieToken1');
        $this->assertNull($this->governor->checkAuthentication()); //assert no Rejection, which is normal

        $this->governor->limitBasePerIpAddress = 0;
        $this->assertNull($this->governor->checkAuthentication()); //assert no Rejection

        $this->governor->initFor('192.168.255.254', 'testuser1', 'whattheheck', 'cookieToken1');
        $this->assertNull($this->governor->checkAuthentication()); //assert no Rejection because of cookieToken released
        
        $this->governor->initFor('192.168.255.255', 'testuser1', 'whattheheck', 'cookieToken2');
        $this->assertNull($this->governor->checkAuthentication()); //assert no Rejection because of ip address released
        
        $this->governor->limitBasePerIpAddress = 1;
        $this->governor->limitPerUserName = 1;
        $this->governor->initFor('192.168.255.254', 'testuser1', 'whattheheck', 'cookieToken2');
        $this->assertNull($this->governor->checkAuthentication()); //assert no Rejection because user released
        
        $this->governor->limitBasePerIpAddress = 0;
        $this->governor->initFor('192.168.255.255', 'testuser2', 'whattheheck', 'cookieToken1');
        $result = $this->governor->checkAuthentication(); //registers authentication failure for testuser2
        $this->assertNotNull($result, 'result');
        $this->assertInstanceOf('Metaclass\\TresholdsGovernor\\Result\\IpAddressBlocked', $result);
        $this->assertEquals("IP Adress '%ipAddress%' is blocked", $result->message);
        $this->assertEquals(array('%ipAddress%' => '192.168.255.255'), $result->parameters);
    }
        
    function testDeleteData2() 
    {
        $this->get('requestCountsManager')->deleteCountsUntil(new \DateTime('1981-01-01'));
        $this->get('releasesManager')->deleteReleasesUntil(new \DateTime('1981-01-01'));
        
        $this->governor->initFor('192.168.255.255', 'testuser1', 'whattheheck', 'cookieToken1');
        $this->assertEquals(0, $this->get('failureCountForIpAddress'), 'failure count by ip address');
        $this->assertEquals(0, $this->get('failureCountForUserName'), 'failure count by username');
        $this->assertEquals(0, $this->get('failureCountForUserOnAddress'), 'failure count for username on address');
        $this->assertEquals(0, $this->get('failureCountForUserByCookie'), 'failure count for username by cookie');
        
        $this->assertFalse($this->get('isUserReleasedOnAddress'), 'is user released on address');
        $this->assertFalse($this->get('isUserReleasedByCookie'), 'is user released by cookie');
    }


}
?>