<?php
//copyright (c) MetaClass Groningen 2014

namespace Metaclass\TresholdsGovernor\Tests\Mock;


class RecordingWrapper {

    protected $wrapped;
    public $calls;

    public function __construct($wrapped)
    {
        $this->wrapped = $wrapped;
        $this->calls = array();
    }

    public function __call($method, $arguments)
    {
        $result = call_user_func_array(array($this->wrapped, $method), $arguments);
        $this->calls[] = array($method, $arguments, $result);
        return $result;
    }

} 