<?php 
namespace Metaclass\TresholdsGovernor\Result;

/**
 * An instance of this class reprecents a decision by a TreholdsGovernor to reject a login attempt because 
 * the user name is blocked for the token from the cookie that was sent with the request.
 *  
 * @author Henk Verhoeven
 * @copyright MetaClass Groningen 2014
 */
class UsernameBlockedForCookie extends Rejection
{
    
}
?>