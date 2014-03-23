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
    public $requestCountsManager;
    public $releasesManager;
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

            
    public function __construct($params, $dataManager=null) {
        $this->requestCountsManager = $dataManager;
        $this->releasesManager =  $dataManager;
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
        $this->failureCountForIpAddress  = $this->requestCountsManager->countLoginsFailedForIpAddres($ipAddress, $timeLimit);

        $timeLimit = new \DateTime("$this->dtString - $this->blockUsernamesFor");
        $this->failureCountForUserName = $this->requestCountsManager->countLoginsFailedForUserName($username, $timeLimit);
        $this->failureCountForUserOnAddress = $this->requestCountsManager->countLoginsFailedForUserOnAddress($username, $ipAddress, $timeLimit);
        $this->failureCountForUserByCookie = $this->requestCountsManager->countLoginsFailedForUserByCookie($username, $cookieToken, $timeLimit);

        if ($this->allowReleasedUserOnAddressFor) {
            $timeLimit = new \DateTime("$this->dtString - $this->allowReleasedUserOnAddressFor");
            $this->isUserReleasedOnAddress = $this->releasesManager->isUserReleasedOnAddressFrom($username, $ipAddress, $timeLimit);
        }
        if ($this->allowReleasedUserByCookieFor) {
            $timeLimit = new \DateTime("$this->dtString - $this->allowReleasedUserByCookieFor");
            $this->isUserReleasedByCookie = $this->releasesManager->isUserReleasedByCookieFrom($username, $cookieToken, $timeLimit);
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
        $this->requestCountsManager->insertOrIncrementSuccessCount($dateTime, $this->username, $this->ipAddress, $this->cookieToken);

        if ($this->releaseUserOnLoginSuccess) {
            $this->releaseUserName();
        } 
        $this->releaseUserNameForIpAddressAndCookie();
    }
    
    public function registerAuthenticationFailure() 
    {
        //SBAL/Query/QueryBuilder::execute does not provide QueryCacheProfile to the connection, so the query will not be cached
        $dateTime = $this->getRequestCountsDt($this->dtString);
        $this->requestCountsManager->insertOrIncrementFailureCount($dateTime, $this->username, $this->ipAddress, $this->cookieToken);
    }
    
    /** only to be combined with new password */
    public function releaseUserName() 
    {
        $dateTime = new \DateTime($this->dtString);
        $timeLimit = new \DateTime("$this->dtString - $this->blockUsernamesFor");
        $this->requestCountsManager->releaseCountsForUserName($this->username, $dateTime, $timeLimit);
    }
    
    public function releaseUserNameForIpAddressAndCookie()
    {
        $dateTime = new \DateTime($this->dtString);
        $timeLimit = new \DateTime("$this->dtString - $this->blockUsernamesFor");
        $this->requestCountsManager->releaseCountsForUserNameAndIpAddress($this->username, $this->ipAddress, $dateTime, $timeLimit);
        $this->requestCountsManager->releaseCountsForUserNameAndCookie($this->username, $this->cookieToken, $dateTime, $timeLimit);

        if ($this->allowReleasedUserByCookieFor || $this->allowReleasedUserOnAddressFor) {
            $this->releasesManager->insertOrUpdateRelease($dateTime, $this->username, $this->ipAddress, $this->cookieToken);
        }
    }

    public function adminReleaseIpAddress()
    {
        $dateTime = new \DateTime($this->dtString);
        $timeLimit = new \DateTime("$this->dtString - $this->blockIpAddressesFor");
        $this->requestCountsManager->releaseCountsForIpAddress($this->ipAddress, $dateTime, $timeLimit);
    }

    public function packData() 
    {
        $usernameLimit = new \DateTime("$this->dtString - $this->blockUsernamesFor");
        $addressLimit = new \DateTime("$this->dtString - $this->blockIpAddressesFor");
        $this->requestCountsManager->deleteCountsUntil(min($usernameLimit, $addressLimit));
        //idea pack RequestCounts to lower granularity for period between both limits
        
        $limit = new \DateTime($this->dtString);
        if ($this->allowReleasedUserOnAddressFor) {
            $limit = min($limit, new \DateTime("$this->dtString - $this->allowReleasedUserOnAddressFor"));
        }
        if ($this->allowReleasedUserByCookieFor) {
            $limit = min($limit, new \DateTime("$this->dtString - $this->allowReleasedUserByCookieFor"));
        }        
        $this->releasesManager->deleteReleasesUntil($limit);
    }
    
}

?>