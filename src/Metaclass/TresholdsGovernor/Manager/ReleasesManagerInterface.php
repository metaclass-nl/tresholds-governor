<?php 
namespace Metaclass\TresholdsGovernor\Manager;

/**
 * Instances of classes implementing this interface store and retrieve data about releases. 
 * Releases allow a user to log in despite the fact that his username and/or IP addess is blocked.
 * 
 * @author Henk Verhoeven
 * @copyright 2014 MetaClass Groningen 
 */
interface ReleasesManagerInterface
{
    /** 
     * @param string $username
     * @param string $ipAddress
     * @param \DateTime $timeLimit
     * @return boolean Whether $username has been released for $ipAddress on or after the $timeLimit.
     */
    public function isUserReleasedOnAddressFrom($username, $ipAddress, $timeLimit);
    
    /** 
     * @param string $username
     * @param string $cookieToken
     * @param \DateTime $timeLimit
     * @return boolean Whether $username has been released for $cookieToken on or after the $timeLimit.
     */
    public function isUserReleasedByCookieFrom($username, $cookieToken, $timeLimit);
    
    /** 
     * Register the release at $dateTime of $username for both $ipAddress and $cookieToken.
     * 
     * @param \DateTime $dateTime of the release
     * @param string $username
     * @param string $ipAddress
     * @param string $cookieToken
     */
    public function insertOrUpdateRelease($dateTime, $username, $ipAddress, $cookieToken);
    
    /**
     * Delete all releases dated before $limit
     * @param \DateTime $limit
     */
    public function deleteReleasesUntil($limit);
    
}