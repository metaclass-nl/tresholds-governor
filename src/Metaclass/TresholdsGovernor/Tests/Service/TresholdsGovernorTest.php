<?php
//copyright (c) MetaClass Groningen 2014

namespace Metaclass\TresholdsGovernor\Tests\Service;

use Metaclass\TresholdsGovernor\Service\TresholdsGovernor;
use Metaclass\TresholdsGovernor\Manager\RdbManager;
use Metaclass\TresholdsGovernor\Result\AuthenticationBlocked;
use Metaclass\TresholdsGovernor\Result\IpAddressBlocked;
use Metaclass\TresholdsGovernor\Result\UsernameBlocked;
use Metaclass\TresholdsGovernor\Result\UsernameBlockedForCookie;
use Metaclass\TresholdsGovernor\Result\UsernameBlockedForIpAddress;

use Metaclass\TresholdsGovernor\Tests\Mock\MockGateway;

class TresholdsGovernorTest extends \PHPUnit_Framework_TestCase
{
    public $governor;

    function setup() 
    {
	    $this->governor = new TresholdsGovernor(array());

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

    function testPackData()
    {
        $this->governor->requestCountsManager = new RdbManager(new MockGateway());
        $this->governor->releasesManager = new RdbManager(new MockGateway());
        $this->governor->keepCountsFor = '3 days';
        $this->governor->blockUsernamesFor = '4 days';
        $this->governor->blockIpAddressesFor = '5 days';
//        $this->governor->allowReleasedUserOnAddressFor = '30 days';
//        $this->governor->allowReleasedUserByCookieFor =  '10 days';
        
        $this->assertNull($this->governor->requestCountsManager->gateway->deleteReleasesLimit, "releasesLimit on requestCountsGateway");
        $this->assertNull($this->governor->releasesManager->gateway->deleteCountsLimit, "deleteCountsLimit on releasesGateway");

        $this->governor->packData();
        //3 days before 1980-07-01 00:00:00
        $this->assertEquals(new \DateTime('1980-06-28 00:00:00'), $this->governor->requestCountsManager->gateway->deleteCountsLimit, "deleteCountsLimit on requestCountsGateway");
        //30 days before 1980-07-01 00:00:00
        $this->assertEquals(new \DateTime('1980-06-01 00:00:00'), $this->governor->releasesManager->gateway->deleteReleasesLimit, "deleteReleasesLimit on releasesGateway");

        $this->governor->keepCountsFor = '5 days';
        $this->governor->blockUsernamesFor = '4 days';
        $this->governor->blockIpAddressesFor = '3 days';
        $this->governor->allowReleasedUserOnAddressFor = '3 days';
        $this->governor->allowReleasedUserByCookieFor =  '10 days';
        $this->governor->packData();
        //5 days before 1980-07-01 00:00:00
        $this->assertEquals(new \DateTime('1980-06-26 00:00:00'), $this->governor->requestCountsManager->gateway->deleteCountsLimit, "deleteCountsLimit on requestCountsGateway");
        //10 days before 1980-07-01 00:00:00
        $this->assertEquals(new \DateTime('1980-06-21 00:00:00'), $this->governor->releasesManager->gateway->deleteReleasesLimit, "deleteReleasesLimit on releasesGateway");
        
        $this->governor->allowReleasedUserOnAddressFor = '';
        $this->governor->allowReleasedUserByCookieFor =  '11 days';
        $this->governor->packData();
        //11 days before 1980-07-01 00:00:00
        $this->assertEquals(new \DateTime('1980-06-20 00:00:00'), $this->governor->releasesManager->gateway->deleteReleasesLimit, "deleteReleasesLimit on releasesGateway");

        $this->governor->allowReleasedUserOnAddressFor = '2 days';
        $this->governor->allowReleasedUserByCookieFor =  '';
        $this->governor->packData();
        //5 days before 1980-07-01 00:00:00 (becvause of keepCountsFor)
        $this->assertEquals(new \DateTime('1980-06-26 00:00:00'), $this->governor->releasesManager->gateway->deleteReleasesLimit, "deleteReleasesLimit on releasesGateway");
        
        $this->governor->allowReleasedUserOnAddressFor = '';
        $this->governor->allowReleasedUserByCookieFor =  '';
        $this->governor->packData();
        //5 days before 1980-07-01 00:00:00 (becvause of keepCountsFor)
        $this->assertEquals(new \DateTime('1980-06-26 00:00:00'), $this->governor->releasesManager->gateway->deleteReleasesLimit, "deleteReleasesLimit on releasesGateway");
    }
    
    function assertNoException($value, $message = '') 
    {
        //assertNotNull crashes on exception.
        // workaround for ugly $this->assertThat($result, $this->logicalNot(new \PHPUnit_Framework_Constraint_Exception('Exception')) );
        if ($value instanceOf \Exception) {
            $this->assertTrue(true); // replaces self::$count += count($constraint); wich does not work because $count is private :-(
    
            $failureDescription = "Failed asserting no Exception: \n"
                    . get_class($value) . " with message '". $value->getMessage();
            $failureDescription .= "' in ". $value->getFile(). ':'. $value->getLine();
    
            if (!empty($message)) {
                $failureDescription = $message . "\n" . $failureDescription;
            }
            throw new \PHPUnit_Framework_ExpectationFailedException($failureDescription, null);
        }
    }   
}
?>