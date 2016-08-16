<?php 
namespace Metaclass\TresholdsGovernor\Tests\Mock;

class MockGateway
{
    public $deleteReleasesLimit;
    public $deleteCountsLimit;
    
    public function deleteReleasesUntil(\DateTime $dtLimit)
    {
        $this->deleteReleasesLimit = $dtLimit;
    }
    
    public function deleteCountsUntil(\DateTime $dtLimit)
    {
        $this->deleteCountsLimit = $dtLimit;
    }
}
