<?php 
namespace Metaclass\TresholdsGovernor\Manager;

interface RequestCountsManagerInterface
{
    public function countLoginsFailedForIpAddres($ipAddress, $timeLimit);
    
    public function countLoginsFailedForUserName($username, $timeLimit);
    
    public function countLoginsFailedForUserOnAddress($username, $ipAddress, $timeLimit);
    
    public function countLoginsFailedForUserByCookie($username, $cookieToken, $timeLimit);
    
    public function insertOrIncrementSuccessCount($dateTime, $username, $ipAddress, $cookieToken);
    
    public function insertOrIncrementFailureCount($dateTime, $username, $ipAddress, $cookieToken);
    
    public function releaseCountsForUserName($username, $dateTime, $timeLimit);
    
    public function releaseCountsForIpAddress($ipAddress, $dateTime, $timeLimit);
    
    public function releaseCountsForUserNameAndIpAddress($username, $ipAddress, $dateTime, $timeLimit);
    
    public function releaseCountsForUserNameAndCookie($username, $cookieToken, $dateTime, $timeLimit);
    
    public function deleteCountsUntil($limit);
        
}