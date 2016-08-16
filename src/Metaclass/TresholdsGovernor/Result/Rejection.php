<?php 
namespace Metaclass\TresholdsGovernor\Result;

/**
 * An instance of a subclass of this class represents a decision by a TreholdsGovernor to block a login attempt
 * for a reason specific to the subclass. The message is mainly meant for logging purposes. 
 * The login form will have messages of its own for the user with no more details then the user needs to know. 
*/
abstract class Rejection
{
    public $message;
    public $parameters;
    
    /** 
     * @param string $message Message about the decision (translatable with parameter placeholders)
     * @param array $parameters to be filled in in the message after eventual translation
     */
    public function __construct($message, $parameters=array())
    {
        $this->message = $message;
        $this->parameters = $parameters;
    }


    /** @return string The name of the counter counting rejections of the subclass type.
     * should be equal to lcFirst(get_class_unqalified($this));
     * */
    abstract public function getCounterName();
}
