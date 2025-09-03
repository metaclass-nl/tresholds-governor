<?php 
namespace Metaclass\TresholdsGovernor\Manager;

use Metaclass\TresholdsGovernor\Result\Rejection;

/**
 * Instances of classes implementing this interface store and retrieve data and perform counting 
 * about requests. 
 * 
 * @author Henk Verhoeven
 * @copyright 2014 MetaClass Groningen 
 */
interface RequestCountsManagerInterface
{
    /**
     * @return int Total number of failures counted for $ipAddress with dtFrom after $timeLimit
     * @param string $ipAddress
     * @param \DateTime $timeLimit
     */
    public function countLoginsFailedForIpAddres($ipAddress, \DateTime $timeLimit);
    
    /**
     * @return int Total number of failures counted for $username with dtFrom after $timeLimit
     * @param string $username
     * @param \DateTime $timeLimit
     */
    public function countLoginsFailedForUserName($username, \DateTime $timeLimit);
    
    /**
     * @return int Total number of failures counted for $username on $ipAddress with dtFrom after $timeLimit
     * @param string $username
     * @param string $ipAddress
     * @param \DateTime $timeLimit
     */
    public function countLoginsFailedForUserOnAddress($username, $ipAddress, \DateTime $timeLimit);
    
    /**
     * @return int Total number of failures counted for $username with $cookieToken and dtFrom after $timeLimit
     * @param string $username
     * @param string $cookieToken
     * @param \DateTime $timeLimit
     */
    public function countLoginsFailedForUserByCookie($username, $cookieToken, \DateTime $timeLimit);

    /**
     * Insert 1 or increment loginsSucceeded for the supplied parameters.
     * @param \DateTime $dateTime
     * @param string $username
     * @param string $ipAddress
     * @param string $cookieToken
     */
    public function insertOrIncrementSuccessCount(\DateTime $dateTime, $username, $ipAddress, $cookieToken);
    
    /**
     * Insert 1 or increment loginsFailed with column values equal to all the corrsponding parameters.
     * If a Rejection is supplied, also increment the corresponding blocking counter.
     * @param \DateTime $dateTime
     * @param string $username
     * @param string $ipAddress
     * @param string $cookieToken
     * @param \Metaclass\TresholdsGovernor\Result\Rejection $rejection or null if other kind of failure
     */
    public function insertOrIncrementFailureCount(\DateTime $dateTime, $username, $ipAddress, $cookieToken, ?Rejection $rejection=null);
    
    /** Release the RequestCounts for $username with dtFrom after $timeLimit 
     * 
     * @param string $username
     * @param \DateTime $dateTime the date and time of the release
     * @param \DateTime $timeLimit 
     */
    public function releaseCountsForUserName($username, \DateTime $dateTime, \DateTime $timeLimit);
    
    /** Release the RequestCounts for $ipAddress with dtFrom after $timeLimit 
     * 
     * @param string $ipAddress
     * @param \DateTime $dateTime the date and time of the release
     * @param \DateTime $timeLimit 
     */
    public function releaseCountsForIpAddress($ipAddress, \DateTime $dateTime, \DateTime $timeLimit);
    
    /** Release the RequestCounts for user on ip address and cookieToken 
     * with $username and $ipAddress and dtFrom after $timeLimit 
     * 
     * @param string $username
     * @param string $ipAddress
     * @param \DateTime $dateTime the date and time of the release
     * @param \DateTime $timeLimit 
     */
    public function releaseCountsForUserNameAndIpAddress($username, $ipAddress, \DateTime $dateTime, \DateTime $timeLimit);
    
    /** Release the RequestCounts for user on ip address and cookieToken 
     * with $username and $cookieToken and dtFrom after $timeLimit 
     * 
     * @param string $username
     * @param string $cookieToken
     * @param \DateTime $dateTime the date and time of the release
     * @param \DateTime $timeLimit 
     */
    public function releaseCountsForUserNameAndCookie($username, $cookieToken, \DateTime $dateTime, \DateTime $timeLimit);
    
    /**
     * Delete all RequestCounts with dtFrom before $limit
     * @param \DateTime $limit 
     */
    public function deleteCountsUntil(\DateTime $limit);
}
