<?php 
namespace Metaclass\TresholdsGovernor\Result;

/**
 * An instance of this class represents a decision by a TreholdsGovernor to block a login attempt because
 * the user name is blocked for the token from the cookie that was sent with the request.
 *  
 * @author Henk Verhoeven
 * @copyright MetaClass Groningen 2014
 */
class UsernameBlockedForCookie extends Rejection
{
    public function getCounterName()
    {
        return 'usernameBlockedForCookie';
    }
}
