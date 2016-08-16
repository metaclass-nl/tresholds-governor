<?php 
namespace Metaclass\TresholdsGovernor\Result;

/**
 * An instance of this class represents a decision by a TreholdsGovernor to block a login attempt because
 * the user name is blocked.
 *  
 * @author Henk Verhoeven
 * @copyright MetaClass Groningen 2014
 */
class UsernameBlocked extends Rejection
{
    public function getCounterName()
    {
        return 'usernameBlocked';
    }
}
