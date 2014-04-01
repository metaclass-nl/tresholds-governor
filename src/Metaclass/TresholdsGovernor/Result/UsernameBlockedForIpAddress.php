<?php 
namespace Metaclass\TresholdsGovernor\Result;

/**
 * An instance of this class reprecents a decision by a TreholdsGovernor to reject a login attempt because 
 * the user name is blocked for the IP address from whick the request was sent.
 *  
 * @author Henk Verhoeven
 * @copyright MetaClass Groningen 2014
 */
 class UsernameBlockedForIpAddress extends Rejection
{

}
?>