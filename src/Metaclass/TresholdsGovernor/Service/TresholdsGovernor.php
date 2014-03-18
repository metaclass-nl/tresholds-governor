<?php 
namespace Metaclass\TresholdsGovernor\Service;

use Doctrine\DBAL\Connection;

use Metaclass\TresholdsGovernor\Result\UsernameBlocked;
use Metaclass\TresholdsGovernor\Result\IpAddressBlocked;
use Metaclass\TresholdsGovernor\Result\UsernameBlockedForCookie;
use Metaclass\TresholdsGovernor\Result\UsernameBlockedForIpAddress;

use Metaclass\TresholdsGovernor\Gateway\DbalGateway;

class TresholdsGovernor {

    //dependencies
    public $requestCountsGateway;
    public $releasesGateway;
    public $dtString; //Y-m-d H:i:s
    
    //config with defaults
    public $counterDurationInSeconds = 180; //how long each counter counts
    public $blockUsernamesFor = '25 minutes'; 
    public $limitPerUserName = 3;
    public $blockIpAddressesFor = '17 minutes'; 
    public $limitBasePerIpAddress = 10; //limit may be higher, depending on successfull logins and requests (NYI)
    public $allowReleasedUserOnAddressFor = '30 days'; //if empty feature is switched off
    public $allowReleasedUserByCookieFor = ''; //if empty feature is switched off
    public $releaseUserOnLoginSuccess = false;
    
    //variables
    protected $ipAddress;
    protected $username;
    protected $cookieToken;
    protected $failureCountForIpAddress;
    protected $failureCountForUserName;
    protected $isUserReleasedOnAddress = false;
    protected $failureCountForUserOnAddress;
    protected $isUserReleasedByCookie = false;
    protected $failureCountForUserByCookie;

            
    public function __construct($params, DbalGateway $gateway=null) {
        $this->requestCountsGateway = $gateway;
        $this->releasesGateway =  $gateway;
        $this->dtString = date('Y-m-d H:i:s');
        $this->setPropertiesFromParams($params);
    }
    
    /** @throws ReflectionException */
    protected function setPropertiesFromParams($params)
    {
        $rClass = new \ReflectionClass($this);
        forEach($params as $key => $value)
        {
            $rProp = $rClass->getProperty($key);
            if (!$rProp->isPublic()) {
                throw new \ReflectionException("Property must be public: '$key'");
            }
            $rProp->setValue($this, $value);
        }
    }
    
    public function initFor($ipAddress, $username, $password, $cookieToken) 
    {
        //cast to string because null is used for control in some Repo functions
        $this->ipAddress = (string) $ipAddress;
        $this->username = (string) $username;
        $this->cookieToken = (string) $cookieToken; 
        //$this->password = (string) $password;
        
        
        $timeLimit = new \DateTime("$this->dtString - $this->blockIpAddressesFor");
        $this->failureCountForIpAddress  = $this->requestCountsGateway->countWhereSpecifiedAfter('loginsFailed', null, $ipAddress, null, $timeLimit, 'addresReleasedAt');

        $timeLimit = new \DateTime("$this->dtString - $this->blockUsernamesFor");
        $this->failureCountForUserName = $this->requestCountsGateway->countWhereSpecifiedAfter('loginsFailed', $username,  null, null, $timeLimit, 'userReleasedAt');
        $this->failureCountForUserOnAddress = $this->requestCountsGateway->countWhereSpecifiedAfter('loginsFailed', $username, $ipAddress, null, $timeLimit, 'userReleasedForAddressAndCookieAt');
        $this->failureCountForUserByCookie = $this->requestCountsGateway->countWhereSpecifiedAfter('loginsFailed', $username, null, $cookieToken, $timeLimit, 'userReleasedForAddressAndCookieAt');

        if ($this->allowReleasedUserOnAddressFor) {
            $timeLimit = new \DateTime("$this->dtString - $this->allowReleasedUserOnAddressFor");
            $this->isUserReleasedOnAddress = $this->releasesGateway->isUserReleasedOnAddressFrom($username, $ipAddress, $timeLimit);
        }
        if ($this->allowReleasedUserByCookieFor) {
            $timeLimit = new \DateTime("$this->dtString - $this->allowReleasedUserByCookieFor");
            $this->isUserReleasedByCookie = $this->releasesGateway->isUserReleasedByCookieFrom($username, $cookieToken, $timeLimit);
        }
    }

    /**
     * 
     * @return  Metaclass\TresholdsGovernor\Result\Rejection or null if not to be blocked
     */
    public function checkAuthentication($justFailed=false) 
    {
        $result = null;
        if ($justFailed) { // failure, but not yet registered, add it here
            $this->failureCountForUserName++;
            $this->failureCountForIpAddress++;
            $this->failureCountForUserOnAddress++;
            $this->failureCountForUserByCookie++;
            //WARNING, these increments must be done BEFORE decision making, but unit tests do not test that 
        }
        if ($this->isUserReleasedOnAddress) {
            if ($this->failureCountForUserOnAddress > $this->limitPerUserName) { 
                $result = new UsernameBlockedForIpAddress("Username '%username%' is blocked for IP Address '%ipAddress%'",
                     array('%username%' => $this->username, '%ipAddress%' => $this->ipAddress));
            }
        } elseif ($this->isUserReleasedByCookie) { 
            if ($this->failureCountForUserByCookie > $this->limitPerUserName) {
                $result = new UsernameBlockedForCookie("Username '%username%' is blocked for cookie '%cookieToken%'",
                    array('%username%' => $this->username, '%cookieToken%' => $this->cookieToken));
            }
        } else {
            if ($this->failureCountForIpAddress > $this->limitBasePerIpAddress) {
                $result = new IpAddressBlocked("IP Adress '%ipAddress%' is blocked",
                    array('%ipAddress%' => $this->ipAddress));
            }
            if ($this->failureCountForUserName > $this->limitPerUserName) {
                $result = new UsernameBlocked("Username '%username%' is blocked",
                    array('%username%' => $this->username));
            }
        }
       if ($justFailed || $result) {
           $this->registerAuthenticationFailure();
       }
       return $result;
    }
    
    /**
     * 
     * @param string $dtString DateTime string  
     * @return int the seconds since UNIX epoch for the RequestCounts dtFrom
     */
    public function getRequestCountsDt($dtString)
    {
        $dt = new \DateTime($dtString);
        $remainder = $dt->getTimestamp() % $this->counterDurationInSeconds;
        return $remainder
            ? $dt->sub(new \DateInterval('PT'.$remainder.'S'))
            : $dt;
    }
    
    public function registerAuthenticationSuccess() 
    {
        //? should we releaseUserNameForIpAddress? And should'nt that have a shorter effect then release from e-mail?
        //? should we register (some) other failures in the session and release those here? 
        
        $dateTime = $this->getRequestCountsDt($this->dtString);
        $this->requestCountsGateway->insertOrUpdateCounts($dateTime, $this->username, $this->ipAddress, $this->cookieToken, true);

        if ($this->releaseUserOnLoginSuccess) {
            $this->releaseUserName();
        } 
        $this->releaseUserNameForIpAddressAndCookie();
    }
    
    public function registerAuthenticationFailure() 
    {
        //SBAL/Query/QueryBuilder::execute does not provide QueryCacheProfile to the connection, so the query will not be cached
        $dateTime = $this->getRequestCountsDt($this->dtString);
        $this->requestCountsGateway->insertOrUpdateCounts($dateTime, $this->username, $this->ipAddress, $this->cookieToken, false);
    }
    
    /** only to be combined with new password */
    public function releaseUserName() 
    {
        $dateTime = new \DateTime($this->dtString);
        $timeLimit = new \DateTime("$this->dtString - $this->blockUsernamesFor");
        $this->requestCountsGateway->updateCountsColumnWhereColumnNullAfterSupplied(
            'userReleasedAt', $dateTime, $timeLimit, $this->username, null, null);
    }
    
    public function releaseUserNameForIpAddressAndCookie()
    {
        $dateTime = new \DateTime($this->dtString);
        $timeLimit = new \DateTime("$this->dtString - $this->blockUsernamesFor");
        $this->requestCountsGateway->updateCountsColumnWhereColumnNullAfterSupplied(
            'userReleasedForAddressAndCookieAt', $dateTime, $timeLimit, $this->username, $this->ipAddress, null);
        $this->requestCountsGateway->updateCountsColumnWhereColumnNullAfterSupplied(
            'userReleasedForAddressAndCookieAt', $dateTime, $timeLimit, $this->username, null, $this->cookieToken);

        if ($this->allowReleasedUserByCookieFor || $this->allowReleasedUserOnAddressFor) {
            $this->releasesGateway->insertOrUpdateRelease($dateTime, $this->username, $this->ipAddress, $this->cookieToken);
        }
    }

    public function adminReleaseIpAddress()
    {
        $dateTime = new \DateTime($this->dtString);
        $timeLimit = new \DateTime("$this->dtString - $this->blockIpAddressesFor");
        $this->requestCountsGateway->updateCountsColumnWhereColumnNullAfterSupplied(
            'addresReleasedAt', $dateTime, $timeLimit, null, $this->ipAddress, null);
    }

    public function packData() 
    {
        $usernameLimit = new \DateTime("$this->dtString - $this->blockUsernamesFor");
        $addressLimit = new \DateTime("$this->dtString - $this->blockIpAddressesFor");
        $this->requestCountsGateway->deleteCountsUntil(min($usernameLimit, $addressLimit));
        //idea pack RequestCounts to lower granularity for period between both limits
        
        $limit = new \DateTime($this->dtString);
        if ($this->allowReleasedUserOnAddressFor) {
            $limit = min($limit, new \DateTime("$this->dtString - $this->allowReleasedUserOnAddressFor"));
        }
        if ($this->allowReleasedUserByCookieFor) {
            $limit = min($limit, new \DateTime("$this->dtString - $this->allowReleasedUserByCookieFor"));
        }        
        $this->releasesGateway->deleteReleasesUntil($limit);
    }
    
}

?>