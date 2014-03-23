<?php 
namespace Metaclass\TresholdsGovernor\Manager;

interface ReleasesManagerInterface
{
    public function isUserReleasedOnAddressFrom($username, $ipAddress, $timeLimit);
    
    public function isUserReleasedByCookieFrom($username, $cookieToken, $timeLimit);
     
    public function insertOrUpdateRelease($dateTime, $username, $ipAddress, $cookieToken);
     
    public function deleteReleasesUntil($limit);
    
}