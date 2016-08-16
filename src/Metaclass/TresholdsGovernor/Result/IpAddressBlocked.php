<?php 
namespace Metaclass\TresholdsGovernor\Result;

/**
 * An instance of this class represents a decision by a TreholdsGovernor to block a login attempt because
 * the IP address that sent it is blocked.
 *  
 * @author Henk Verhoeven
 * @copyright MetaClass Groningen 2014
 */
class IpAddressBlocked extends Rejection
{
    public function getCounterName()
    {
        return 'ipAddressBlocked';
    }
}
