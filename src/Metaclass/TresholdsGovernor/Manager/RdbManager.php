<?php 
namespace Metaclass\TresholdsGovernor\Manager;

/**
 * 
 * @author Henk Verhoeven
 * @copyright 2014 MetaClass Groningen 
 */
class RdbManager implements ReleasesManagerInterface,  RequestCountsManagerInterface
{
    
    public $gateway;
    
    public function __construct($gateway)
    {
        $this->gateway = $gateway;
    }
    
    //RequestCountsManagerInterface
    
    public function countLoginsFailedForIpAddres($ipAddress, $timeLimit)
    {
        return $this->gateway->countWhereSpecifiedAfter('loginsFailed', null, $ipAddress, null, $timeLimit, 'addresReleasedAt');
    }
    
    public function countLoginsFailedForUserName($username, $timeLimit)
    { 
        return $this->gateway->countWhereSpecifiedAfter('loginsFailed', $username,  null, null, $timeLimit, 'userReleasedAt');
    }
    
    public function countLoginsFailedForUserOnAddress($username, $ipAddress, $timeLimit)
    { 
        return $this->gateway->countWhereSpecifiedAfter('loginsFailed', $username, $ipAddress, null, $timeLimit, 'userReleasedForAddressAndCookieAt');
    }
    
    public function countLoginsFailedForUserByCookie($username, $cookieToken, $timeLimit)
    {
        return $this->gateway->countWhereSpecifiedAfter('loginsFailed', $username, null, $cookieToken, $timeLimit, 'userReleasedForAddressAndCookieAt');
    }
    
    
    public function insertOrIncrementSuccessCount($dateTime, $username, $ipAddress, $cookieToken)
    {
         $this->gateway->insertOrIncrementCount($dateTime, $username, $ipAddress, $cookieToken, true);
    }
    
    public function insertOrIncrementFailureCount($dateTime, $username, $ipAddress, $cookieToken)
    {
        $this->gateway->insertOrIncrementCount($dateTime, $username, $ipAddress, $cookieToken, false);
    }
    
    public function releaseCountsForUserName($username, $dateTime, $timeLimit)
    {
        $this->gateway->updateCountsColumnWhereColumnNullAfterSupplied(
            'userReleasedAt', $dateTime, $timeLimit, $username, null, null);
    }
    
    public function releaseCountsForIpAddress($ipAddress, $dateTime, $timeLimit)
    {
        $this->gateway->updateCountsColumnWhereColumnNullAfterSupplied(
            'addresReleasedAt', $dateTime, $timeLimit, null, $ipAddress, null);
    }
    
    public function releaseCountsForUserNameAndIpAddress($username, $ipAddress, $dateTime, $timeLimit)
    {
        $this->gateway->updateCountsColumnWhereColumnNullAfterSupplied(
            'userReleasedForAddressAndCookieAt', $dateTime, $timeLimit, $username, $ipAddress, null);
    }
    
    public function releaseCountsForUserNameAndCookie($username, $cookieToken, $dateTime, $timeLimit)
    {
        $this->gateway->updateCountsColumnWhereColumnNullAfterSupplied(
            'userReleasedForAddressAndCookieAt', $dateTime, $timeLimit, $username, null, $cookieToken);
    }
    
    public function deleteCountsUntil($limit)
    {
        $this->gateway->deleteCountsUntil($limit);
    }
    
    //ReleasesManagerInterface
    
    public function isUserReleasedOnAddressFrom($username, $ipAddress, $timeLimit)
    {
        return $this->gateway->isUserReleasedOnAddressFrom($username, $ipAddress, $timeLimit);
    }
    
     public function isUserReleasedByCookieFrom($username, $cookieToken, $timeLimit)
     {
       return $this->gateway->isUserReleasedByCookieFrom($username, $cookieToken, $timeLimit);
     }
     
     public function insertOrUpdateRelease($dateTime, $username, $ipAddress, $cookieToken)
     {
         $this->gateway->insertOrUpdateRelease($dateTime, $username, $ipAddress, $cookieToken);
     }
     
     public function deleteReleasesUntil($limit)
     {
         $this->gateway->deleteReleasesUntil($limit);
     }
}

?>