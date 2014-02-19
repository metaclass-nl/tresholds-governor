<?php 
namespace Metaclass\TresholdsGovernor\Result;

class Rejection
{
    public $message;
    public $parameters;
    
    public function __construct($message, $parameters=array())
    {
        $this->message = $message;
        $this->parameters = $parameters;
    }
}
?>