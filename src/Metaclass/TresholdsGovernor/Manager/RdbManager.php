<?php 
namespace Metaclass\TresholdsGovernor\Manager;

/**
 * Instances of this class store and retrieve data and perform counting about login requests and releases.
 * The actual storage is done in a relational database queried by an instance of RdbGateway.
 * 
 * @author Henk Verhoeven
 * @copyright 2014 MetaClass Groningen 
 */
class RdbManager implements ReleasesManagerInterface,  RequestCountsManagerInterface
{
    
    /** @var Metaclass\TresholdsGovernor\Gateway\RdbGateway */
    public $gateway;
    
    /**
     * @param Metaclass\TresholdsGovernor\Gateway\RdbGateway $gateway
     */
    public function __construct($gateway)
    {
        $this->gateway = $gateway;
    }
    
//RequestCountsManagerInterface
    
    /** {@inheritdoc} */
    public function countLoginsFailedForIpAddres($ipAddress, \DateTime $timeLimit)
    {
        return $this->gateway->countWhereSpecifiedAfter('loginsFailed', null, $ipAddress, null, $timeLimit, 'addresReleasedAt');
    }
    
    /** {@inheritdoc} */
    public function countLoginsFailedForUserName($username, \DateTime $timeLimit)
    { 
        return $this->gateway->countWhereSpecifiedAfter('loginsFailed', $username,  null, null, $timeLimit, 'userReleasedAt');
    }
    
    /** {@inheritdoc} */
    public function countLoginsFailedForUserOnAddress($username, $ipAddress, \DateTime $timeLimit)
    { 
        return $this->gateway->countWhereSpecifiedAfter('loginsFailed', $username, $ipAddress, null, $timeLimit, 'userReleasedForAddressAndCookieAt');
    }
    
    /** {@inheritdoc} */
    public function countLoginsFailedForUserByCookie($username, $cookieToken, \DateTime $timeLimit)
    {
        return $this->gateway->countWhereSpecifiedAfter('loginsFailed', $username, null, $cookieToken, $timeLimit, 'userReleasedForAddressAndCookieAt');
    }
    
    /** {@inheritdoc} */
    public function insertOrIncrementSuccessCount(\DateTime $dateTime, $username, $ipAddress, $cookieToken)
    {
         $this->gateway->insertOrIncrementCount($dateTime, $username, $ipAddress, $cookieToken, true);
    }
    
    /** {@inheritdoc} */
    public function insertOrIncrementFailureCount(\DateTime $dateTime, $username, $ipAddress, $cookieToken)
    {
        $this->gateway->insertOrIncrementCount($dateTime, $username, $ipAddress, $cookieToken, false);
    }
    
    /** {@inheritdoc} */
    public function releaseCountsForUserName($username, \DateTime $dateTime, \DateTime $timeLimit)
    {
        $this->gateway->updateCountsColumnWhereColumnNullAfterSupplied(
            'userReleasedAt', $dateTime, $timeLimit, $username, null, null);
    }
    
    /** {@inheritdoc} */
    public function releaseCountsForIpAddress($ipAddress, \DateTime $dateTime, \DateTime $timeLimit)
    {
        $this->gateway->updateCountsColumnWhereColumnNullAfterSupplied(
            'addresReleasedAt', $dateTime, $timeLimit, null, $ipAddress, null);
    }
    
    /** {@inheritdoc} */
    public function releaseCountsForUserNameAndIpAddress($username, $ipAddress, \DateTime $dateTime, \DateTime $timeLimit)
    {
        $this->gateway->updateCountsColumnWhereColumnNullAfterSupplied(
            'userReleasedForAddressAndCookieAt', $dateTime, $timeLimit, $username, $ipAddress, null);
    }
    
    /** {@inheritdoc} */
    public function releaseCountsForUserNameAndCookie($username, $cookieToken, \DateTime $dateTime, \DateTime $timeLimit)
    {
        $this->gateway->updateCountsColumnWhereColumnNullAfterSupplied(
            'userReleasedForAddressAndCookieAt', $dateTime, $timeLimit, $username, null, $cookieToken);
    }
    
    /** {@inheritdoc} */
    public function deleteCountsUntil(\DateTime $limit)
    {
        $this->gateway->deleteCountsUntil($limit);
    }
    
//ReleasesManagerInterface

    
    /** {@inheritdoc} */
    public function isUserReleasedOnAddressFrom($username, $ipAddress, $timeLimit)
    {
        return $this->gateway->isUserReleasedOnAddressFrom($username, $ipAddress, $timeLimit);
    }
    
    /** {@inheritdoc} */
    public function isUserReleasedByCookieFrom($username, $cookieToken, $timeLimit)
     {
       return $this->gateway->isUserReleasedByCookieFrom($username, $cookieToken, $timeLimit);
     }
     
    /** {@inheritdoc} */
     public function insertOrUpdateRelease($dateTime, $username, $ipAddress, $cookieToken)
     {
         $this->gateway->insertOrUpdateRelease($dateTime, $username, $ipAddress, $cookieToken);
     }
     
    /** {@inheritdoc} */
     public function deleteReleasesUntil($limit)
     {
         $this->gateway->deleteReleasesUntil($limit);
     }
}

?>