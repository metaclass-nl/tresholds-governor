<?php 
namespace Metaclass\TresholdsGovernor\Service;

use Doctrine\DBAL\Connection;

use Metaclass\TresholdsGovernor\Result\Rejection;
use Metaclass\TresholdsGovernor\Result\UsernameBlocked;
use Metaclass\TresholdsGovernor\Result\IpAddressBlocked;
use Metaclass\TresholdsGovernor\Result\UsernameBlockedForCookie;
use Metaclass\TresholdsGovernor\Result\UsernameBlockedForIpAddress;

/**
 * Registers authentication counts, summarizes them and decides to block by username or client ip address for which 
 * authentication failed too often. Based on the "Tresholds Governer" described in the OWASP Guide.
 * @link documentation https://github.com/metaclass-nl/tresholds-governor/blob/master/doc/Counting%20and%20deciding.md
 * 
 * @author Henk Verhoeven
 * @copyright MetaClass Groningen 2013 - 2014
 */
class TresholdsGovernor
{

    //dependencies
    /** @var \Metaclass\TresholdsGovernor\Manager\RequestCountsManagerInterface $requestCountsManager does the actual storage and summation of RequestCounts */
    public $requestCountsManager;

    /** @var \Metaclass\TresholdsGovernor\Manager\ReleasesManagerInterface $releasesManager does the actual storage and summation of Releases */
    public $releasesManager;

    /** @var string $dtString holding the current date and time in format Y-m-d H:i:s */
    public $dtString;
    
//config with defaults
    /** var int $counterDurationInSeconds how many seconds each counter counts. */
    public $counterDurationInSeconds = 180;
    
    /** @var string $blockUsernamesFor The duration for which failed login counters are summed per username. Format as DateTime offset */
    public $blockUsernamesFor = '25 minutes';
    
    /** @var int The number of failed login attempts that are allowed per username within the $blockUsernamesFor duration. */
    public $limitPerUserName = 3;
    
    /** @var string $blockIpAddressesFor The duration for which failed login counters are summed per ip addess. Format as DateTime offset */
    public $blockIpAddressesFor = '17 minutes';

    /** @var int $limitBasePerIpAddress The number of failed login attempts that are allowed per IP address within the $blockIpAddressesFor duration. */
    public $limitBasePerIpAddress = 10; //limit may be higher, depending on successfull logins and requests (NYI)

    /** @var string $allowReleasedUserOnAddressFor For how long a username will remain released per IP address. 
     * Format as DateTime offset. If empty feature is switched off. */
    public $allowReleasedUserOnAddressFor = '30 days';
    
    /** @var string $allowReleasedUserByCookieFor For how long a username will remain released per IP address. 
     * Format as DateTime offset. If empty feature is switched off. 
     * Currently AuthenticationGuard does not provide cookietokens. */
    public $allowReleasedUserByCookieFor = '';
    
    /** @var boolean $releaseUserOnLoginSuccess Wheather each time the user logs in sucessfully, the username is released for all ip addresses and user agents. */
    public $releaseUserOnLoginSuccess = false;

    /** @var string For how long RequestCounts are kept. Usually administrators want to look into
     * past blockings if a user ask questions when he/she can't log in.
     * This setting should never be set lower then $blockUsernamesFor and $blockIpAddressesFor */
    public $keepCountsFor = '4 days';

    /** @var float fixed execution time in order to mitigate timing attacks. To apply, call sleepUntilFixedExecutionTime */
    public $fixedExecutionSeconds = 0.1;

    /** @var int Maximum random nanoseconds sleeping time  */
    public $randomSleepingNanosecondsMax = 99999;

//variables
    /** @var float microtime of init */
    protected $initMicrotime;

    /** @var string $ipAddress IP Address sending the request that is being processed */
    protected $ipAddress;
    
    /** @var string $username username from the request that is being processed */
    protected $username;
    
    /** @var string $cookieToken token from the cookie from  the request that is being processed. */
    protected $cookieToken;
    
    /** @var int $failureCountForIpAddress Total number of failures counted by $this->ipAddress within the $this->blockIpAddressesFor duration */
    protected $failureCountForIpAddress;
    
    /** @var int $failureCountForUserName Total number of failures counted by $this->username within the $this->blockUsernamesFor duration */
    protected $failureCountForUserName;
    
    /** @var boolean $isUserReleasedOnAddress Weather $this->username has been released for $this->ipAddress within the $this->allowReleasedUserOnAddressFor duration */
    public $isUserReleasedOnAddress = false;
    
    /** @var int $failureCountForUserOnAddress Total number of failures counted by the combination of both $this->username and $this->ipAddress within the $this->blockUsernamesFor duration */
    protected $failureCountForUserOnAddress;
    
    /** @var boolean $isUserReleasedByCookie Wheater $this->username has been released for $this->cookieToken within the $this->allowReleasedUserByCookieFor duration. */
    protected $isUserReleasedByCookie = false;
    
    /** @var int $failureCountForUserByCookie Total number of failures counted by the combination of both $this->username and $this->cookieToken within the $this->blockUsernamesFor duration */
    protected $failureCountForUserByCookie;
            
    /** 
     * 
     * @param array $params Initialization parameters. Keys must match public property names.
     * @param Object $dataManager must implement both RequestCountsManagerInterface and ReleasesManagerInterface. 
     *     This parameter should be left null if separate RequestCountsManager and ReleasesManager will be set to the corresponding public properties.
     * @throws \ReflectionException if property with the name of a key does not exist or is not public
     */
    public function __construct($params, $dataManager=null)
    {
        $this->requestCountsManager = $dataManager;
        $this->releasesManager =  $dataManager;
        $this->dtString = date('Y-m-d H:i:s');
        $this->setPropertiesFromParams($params);
    }
    
    /** Sets the protected parameter properties 
     * @param array $params Initialization parameters. 
     * @throws \ReflectionException if property with the name of a key does not exist or is not public */
    protected function setPropertiesFromParams($params)
    {
        $rClass = new \ReflectionClass($this);
        foreach ($params as $key => $value) {
            $rProp = $rClass->getProperty($key);
            if (!$rProp->isPublic()) {
                throw new \ReflectionException("Property must be public: '$key'");
            }
            $rProp->setValue($this, $value);
        }
    }
    
    /**
     * Initializes this with the supplied parameters and the counts and booleans calculated with the parameters.
     * Null parameter values are processed as empty strings.
     * @param string $ipAddress IP Address sending the request that is being processed
     * @param string $username username from the request that is being processed 
     * @param string $password not used
     * @param string $cookieToken token from the cookie from  the request that is being processed
     */
    public function initFor($ipAddress, $username, $password, $cookieToken)
    {
        $this->initMicrotime = microtime(true);

        //cast to string because null is used for control in some Gateway functions
        $this->ipAddress = (string) $ipAddress;
        $this->username = (string) $username;
        $this->cookieToken = (string) $cookieToken;
        //$this->password = (string) $password;

        $timeLimit = new \DateTime("$this->dtString - $this->blockIpAddressesFor");
        $this->failureCountForIpAddress  = $this->requestCountsManager->countLoginsFailedForIpAddres($ipAddress, $timeLimit);

        $timeLimit = new \DateTime("$this->dtString - $this->blockUsernamesFor");
        $this->failureCountForUserName = $this->requestCountsManager->countLoginsFailedForUserName($username, $timeLimit);
        $this->failureCountForUserOnAddress = $this->requestCountsManager->countLoginsFailedForUserOnAddress($username, $ipAddress, $timeLimit);

        $this->failureCountForUserByCookie = $cookieToken
            ? $this->requestCountsManager->countLoginsFailedForUserByCookie($username, $cookieToken, $timeLimit)
            : null;

        if ($this->allowReleasedUserOnAddressFor) {
            $timeLimit = new \DateTime("$this->dtString - $this->allowReleasedUserOnAddressFor");
            $this->isUserReleasedOnAddress = $this->releasesManager->isUserReleasedOnAddressFrom($username, $ipAddress, $timeLimit);
        } //else feature is switched off
        if ($this->allowReleasedUserByCookieFor) {
            $timeLimit = new \DateTime("$this->dtString - $this->allowReleasedUserByCookieFor");
            $this->isUserReleasedByCookie = $this->releasesManager->isUserReleasedByCookieFrom($username, $cookieToken, $timeLimit);
        } //else feature is switched off
    }

    /**
     * Decides wheater or not to block the current request amd registers failure on blocking
     * @param boolean $justFailed Wheater the login has already failed (for reasons external to this governor)
     *     but is not yet registered as a failure. Default is false.
     * @return  \Metaclass\TresholdsGovernor\Result\Rejection or null if the governor does not require the login to be blocked.
     *   (Blocking may still take place for reasons external to this governor)
     */
    public function checkAuthentication($justFailed=false)
    {
        if ($justFailed) { // failure, but not yet registered, add it here
            $this->failureCountForUserName++;
            $this->failureCountForIpAddress++;
            $this->failureCountForUserOnAddress++;
            $this->failureCountForUserByCookie++;
            //WARNING, these increments must be done BEFORE decision making, but unit tests do not test that 
        }
        $result = $this->decide();

        if ($justFailed || $result) {
            $this->registerAuthenticationFailure($result);
        }
        return $result;
    }

    /**
     * Decides wheater or not to block the current request.
     * @param boolean $justFailed Wheather the login has already failed (for reasons external to this governor)
     *     but is not yet registered as a failure. Default is false.
     * @return  \Metaclass\TresholdsGovernor\Result\Rejection or null if the governor does not require the login to be blocked.
     *   (Blocking may still take place for reasons external to this governor)
     */
    public function decide()
    {
        if ($this->isUserReleasedOnAddress) {
            if ($this->failureCountForUserOnAddress >= $this->limitPerUserName) {
                return new UsernameBlockedForIpAddress("Username '%username%' is blocked for IP Address '%ipAddress%'",
                    array('%username%' => $this->username, '%ipAddress%' => $this->ipAddress));
            }
        } elseif ($this->isUserReleasedByCookie) {
            if ($this->failureCountForUserByCookie >= $this->limitPerUserName) {
                return new UsernameBlockedForCookie("Username '%username%' is blocked for cookie '%cookieToken%'",
                    array('%username%' => $this->username, '%cookieToken%' => $this->cookieToken));
            }
        } else {
            if ($this->failureCountForIpAddress >= $this->limitBasePerIpAddress) {
                return new IpAddressBlocked("IP Adress '%ipAddress%' is blocked",
                    array('%ipAddress%' => $this->ipAddress));
            }
            if ($this->failureCountForUserName >= $this->limitPerUserName) {
                return new UsernameBlocked("Username '%username%' is blocked",
                    array('%username%' => $this->username));
            }
        }
        return null;
    }

    /**
     * Get a dtFrom value for the creation or update of a RequestCounts record.
     * @param string $dtString DateTime string  
     * @return \DateTime from $dtString ceiled to a whole number of $this->counterDurationInSeconds since UNIX epoch
     */
    public function getRequestCountsDt($dtString)
    {
        $dt = new \DateTime($dtString);
        $remainder = $dt->getTimestamp() % $this->counterDurationInSeconds;
        return $remainder
            ? $dt->sub(new \DateInterval('PT'.$remainder.'S'))
            : $dt;
    }
    
    /** Register that the current login attempt was successfull */
    public function registerAuthenticationSuccess()
    {
        //? should we releaseUserNameForIpAddress? And shouldn't that have a shorter effect then release from e-mail?
        //? should we register (some) other failures in the session and release those here? 

        $dateTime = $this->getRequestCountsDt($this->dtString);
        $this->requestCountsManager->insertOrIncrementSuccessCount($dateTime, $this->username, $this->ipAddress, $this->cookieToken);

        if ($this->releaseUserOnLoginSuccess) {
            $this->releaseUserName();
        }
        $this->releaseUserNameForIpAddressAndCookie();
    }
    
    /** Register that the current login attempt has failed
     * @param ?\Metaclass\TresholdsGovernor\Result\Rejection or null if other kind of failure */
    public function registerAuthenticationFailure(?Rejection $rejection=null)
    {
        //SBAL/Query/QueryBuilder::execute does not provide QueryCacheProfile to the connection, so the query will not be cached
        $dateTime = $this->getRequestCountsDt($this->dtString);
        $this->requestCountsManager->insertOrIncrementFailureCount($dateTime, $this->username, $this->ipAddress, $this->cookieToken, $rejection);
    }
    
    /** Release the username from the current request.
     * Meant only to be combined with new password */
    public function releaseUserName()
    {
        $dateTime = new \DateTime($this->dtString);
        $timeLimit = new \DateTime("$this->dtString - $this->blockUsernamesFor");
        $this->requestCountsManager->releaseCountsForUserName($this->username, $dateTime, $timeLimit);
    }
    
    /** Release the username from the request for the IP address sending the request and the token form the cookie that was sent with the request. */
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

    /** Release all counts with $this->ipAddress by IP Address. Meant for administrative purposes only. */
    public function adminReleaseIpAddress()
    {
        $dateTime = new \DateTime($this->dtString);
        $timeLimit = new \DateTime("$this->dtString - $this->blockIpAddressesFor");
        $this->requestCountsManager->releaseCountsForIpAddress($this->ipAddress, $dateTime, $timeLimit);
    }

    /** Delete RequestCounts and Releases that will no longer be used according to the current blocking resp release durations.
     *  Packing RequestCounts into ones with longer durations has not yet been implemented. */
    public function packData()
    {
        $limit = new \DateTime("$this->dtString - $this->keepCountsFor");
        $this->requestCountsManager->deleteCountsUntil($limit);
        $result["requestcounts_deleted_until"] = $limit;
        //idea pack RequestCounts to lower granularity for period between both limits

        if ($this->allowReleasedUserOnAddressFor) {
            $limit = min($limit, new \DateTime("$this->dtString - $this->allowReleasedUserOnAddressFor"));
        }
        if ($this->allowReleasedUserByCookieFor) {
            $limit = min($limit, new \DateTime("$this->dtString - $this->allowReleasedUserByCookieFor"));
        }
        $this->releasesManager->deleteReleasesUntil($limit);
        $result["releases_deleted_until"] = $limit;

        return $result;
    }

    public function getMinBlockingLimit()
    {
        $usernameLimit = new \DateTime("$this->dtString - $this->blockUsernamesFor");
        $addressLimit = new \DateTime("$this->dtString - $this->blockIpAddressesFor");
        return min($usernameLimit, $addressLimit);
    }

    /** @return float seconds that have passed since init was called,
     * accurate to microseconds */
    public function getSecondsPassedSinceInit()
    {
        return microtime(true) - $this->initMicrotime;
    }

    /** Function to reach fixed execution time in order to mitigate timing attacks.
     * Because of doubts about the accurateness of microtime() and to hide system clock details
     * a random between 0 and randomSleepingNanosecondsMax nanoseconds is added.
     * Because the time <until> may in fact be in the past, sleeping will be
     * until next whole multitude of $seconds has passed. I.e. if $seconds is
     * 0.9 and one second has passed, sleeping will be until 1.8
     * @param float $seconds since ::init until when to sleep
     */
    public function sleepUntilSinceInit($seconds)
    {
        $passed = $this->getSecondsPassedSinceInit();
        $multiplier = ceil($passed/$seconds);
        $multitude = $multiplier * $seconds;
        $toSleep = $multitude - $passed;
        $wholeSeconds = floor($toSleep);
        $nanoSeconds = round(($toSleep - $wholeSeconds) * 1000000000);

        // Add random nanoseconds sleeping time
        $nanoSeconds += mt_rand(0, $this->randomSleepingNanosecondsMax);
        if ($nanoSeconds > 1000000000) {
            $nanoSeconds -= 1000000000;
            $wholeSeconds++;
        }

        do {
            $result = time_nanosleep($wholeSeconds, $nanoSeconds);
            if (is_array($result)) {
                $wholeSeconds = $result['seconds'];
                $nanoSeconds = $result['nanoseconds'];
            }
        } while (is_array($result));
    }

    /**
     * Function to reach fixed execution time of the tresholds governor in order to
     * mitigate timing attacks. Typically used if authentication is blocked.
     * If authentication really takes place, more time will be needed because
     * of password hashing. Then ::sleepUntilSinceInit may be used with a custom value.
     */
    public function sleepUntilFixedExecutionTime()
    {
        $this->sleepUntilSinceInit($this->fixedExecutionSeconds);
    }
}
