INSTALLATION AND CONFIGURATION
==============================

Installation and usage
----------------------

From Symfony 2: follow the instructions of [the AuthenticationGuardBundle](https://github.com/metaclass-nl/MetaclassAuthenticationGuardBundle)

From your own application:

1. Require the library in your composer.json
	```js
	{
	    "require": {
	        "metaclass-nl/tresholds-governor": "*@dev"
	    }
	}
	```
2. download the bundle by:

	``` bash
	$ php composer.phar update metaclass-nl/tresholds-governor
	```

	Composer will install the bundle to your `vendor/metaclass-nl` folder.

3. Create the database table

	```sql
    CREATE TABLE `secu_requests` (
      `id` int(11) NOT NULL AUTO_INCREMENT,
      `dtFrom` datetime NOT NULL,
      `username` varchar(25) COLLATE utf8_unicode_ci NOT NULL,
      `ipAddress` varchar(25) COLLATE utf8_unicode_ci NOT NULL,
      `cookieToken` varchar(40) COLLATE utf8_unicode_ci NOT NULL,
      `loginsFailed` int(11) NOT NULL DEFAULT '0',
      `loginsSucceeded` int(11) NOT NULL DEFAULT '0',
      `ipAddressBlocked` int(11) NOT NULL DEFAULT '0',
      `usernameBlocked` int(11) NOT NULL DEFAULT '0',
      `usernameBlockedForIpAddress` int(11) NOT NULL DEFAULT '0',
      `usernameBlockedForCookie` int(11) NOT NULL DEFAULT '0',
      `requestsAuthorized` int(11) NOT NULL DEFAULT '0',
      `requestsDenied` int(11) NOT NULL DEFAULT '0',
      `userReleasedAt` datetime DEFAULT NULL,
      `addressReleasedAt` datetime DEFAULT NULL,
      `userReleasedForAddressAndCookieAt` datetime DEFAULT NULL,
      PRIMARY KEY (`id`),
      KEY `byDtFrom` (`dtFrom`),
      KEY `byUsername` (`username`,`dtFrom`,`userReleasedAt`),
      KEY `byAddress` (`ipAddress`,`dtFrom`,`addressReleasedAt`),
      KEY `byUsernameAndAddress` (`username`,`ipAddress`,`dtFrom`,`userReleasedForAddressAndCookieAt`)
    ) ENGINE=InnoDB  DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

    CREATE TABLE `secu_releases` (
      `id` int(11) NOT NULL AUTO_INCREMENT,
      `username` varchar(25) COLLATE utf8_unicode_ci NOT NULL DEFAULT '',
      `ipAddress` varchar(25) COLLATE utf8_unicode_ci NOT NULL DEFAULT '',
      `cookieToken` varchar(40) COLLATE utf8_unicode_ci NOT NULL DEFAULT '',
      `releasedAt` datetime DEFAULT NULL,
      PRIMARY KEY (`id`),
      KEY `releasedAt` (`releasedAt`),
      KEY `extkey` (`username`,`ipAddress`,`cookieToken`),
      KEY `byCookie` (`username`,`cookieToken`)
    ) ENGINE=InnoDB  DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;
```
	(you may use MyISAM)
	(you may use some other DBMS that is supported by Doctrine DBAL or PDO)

4. From your own application's authentication code:

	```php
    use Metaclass\TresholdsGovernor\Service\TresholdsGovernor;
    use Metaclass\TresholdsGovernor\Manager\RdbManager;
    use Metaclass\TresholdsGovernor\Gateway\RdbGateway;
    use Metaclass\TresholdsGovernor\Connection\PDOConnection; // not necessary if you use DBAL

    //initialize your Doctrine\DBAL\Connection or PDO
    // if using PDO directy: $connection = new PDOConnection($pdo);

    $gateway = new RdbGateway($connection);
    $manager = new RdbManager($gateway);
    //parameters see step 6
    $governor = new TresholdsGovernor($parameters, $manager);
    //alternatively you may set separate gateways for RequestCounts to $governor->requestCountsGateway
    //and for Releases to $governor->releasesGateway

    $governor->initFor($ipAddress, $username, $password, ''); //using the last parameter is not yet documented
    $result = $governor->checkAuthentication();
    if ($result !== null) {
        //$result holds an instance of a subclass of Metaclass\TresholdsGovernor\Result\AuthenticationBlocked
        //block authentication.
    } else {
        //attempt to authenticate user

        // if authentication succeeded
        $governor->registerAuthenticationSuccess();

        // else
        $governor->registerAuthenticationFailure()
    }
	```

5. From cron or so you may garbage-collect/pack stored RequestCounts:

    ```php
    use Metaclass\TresholdsGovernor\Service\TresholdsGovernor;
    use Metaclass\TresholdsGovernor\Gateway\RdbGateway;
    use Metaclass\TresholdsGovernor\Manager\RdbManager;

    //initialize your Doctrine\DBAL\Connection or PDO
    // if using PDO directy: $connection = new PDOConnection($pdo);

    $gateway = new RdbGateway($connection);
    $manager = new RdbManager($gateway);
    //parameters see step 6
    $governor = new TresholdsGovernor($parameters, $manager);
    $governor->packData();
    ```

6. You may also set the following configuraton parameters to the TresholdsGovernor (defaults shown):

	```php
    $parameters = array(
        'counterDurationInSeconds' => 300,
        'blockUsernamesFor' => "24 minutes",     // actual blocking for up to counterDurationInSeconds shorter!
        'limitPerUserName' => 3,
        'blockIpAddressesFor' => "17 minutes",   // actual blocking for up to counterDurationInSeconds shorter!
        'limitBasePerIpAddress' => 10,
        'releaseUserOnLoginSuccess' => false,
        'allowReleasedUserOnAddressFor' => "30 days",
        'keepCountsFor' => '4 days',
        'fixedExecutionSeconds' => 0.1);
```
  
Configurations
--------------

1. Counting duration

	counterDurationInSeconds

	From this setting the Tresholds Governor decides when a new RequestCounts record will be made for the same combination of 
	username, IP address and user agent. The higher you set this, the less records will be generated, thus the faster counting will be. 
	But it needs to be substantially shorter then the blockIpAddressesFor and blockUsernamesFor durations not to get too unprecise countings.
	
2. Username blocking duration
 
	blockUsernamesFor
	
	The duration for which failed login counters are summed per username. Values like "3 minutes", "12 hours", "5 years" are allowed.
	The actual duration of blocking will be up to 'counterDurationInSeconds' shorter.
	
	The OWASP Guide: 
	> If necessary, such as for compliance with a national security standard, a configurable soft lockout of approximately 15-30 minutes should apply, with an error message stating the reason and when the account will become active again.
	Hoever, many applications block user accounts after three or five attempts until they are reactivated explicitly. 
	This is not supported, but you may set the duration long. Be aware that the number of counters may have to become
	very high, slowing down the authentication process [idea for improvement](https://github.com/metaclass-nl/MetaclassAuthenticationGuardBundle/wiki). 

	Counters that start before the system time minus this duration do not count for this purpose.
	However, this does not mean that usernames that became blocked will never be blocked after this duration: if more 
	failed logins where counted afterwards in newer RequestCounts records, these will remain to count while the older
	RequestCounts are no longer counted. As long as the total is higher then limitPerUserName, the username will
	remain blocked, unless it is released*.
	

3. Username blocking theshold

	limitPerUserName
	
	The number of failed login attempts that are allowed per username within the username blocking duration. 
	If the number of failed logins is higher the user will be blocked, unless his failures are released*.
	
4. IP address blocking duration.

	blockIpAddressesFor 
	
	The duration for which failed login counters are summed per ip address. Values like "3 minutes", "12 hours", "5 years" are allowed.
	The actual duration of blocking will be up to 'counterDurationInSeconds' shorter.
	
	The OWASP Guide suggests a duration of 15 minutes, but also suggests additional measures that are currenly not supported
	by this Bundle. 
	
	Counters that start before the system time minus this duration do not count for this purpose.
	However, this does not mean that addresses that became blocked will never be blocked after this duration: if more 
	failed logins where counted afterwards in newer RequestCounts records, these will remain to count while the older
	RequestCounts are no longer counted. As long as the total is higher then limitPerIpAddress, the addresses will
	remain blocked, unless it is released*.
	
5. IP address blocking treshold
	
	limitBasePerIpAddress
	
	The number of failed login attempts that are allowed per IP address within the IP adress blocking duration. 
	If the number of failed logins is higher the address will be blocked, unless its failures are released*.
	
6. Release user on login success

	releaseUserOnLoginSuccess
	
	Most systems that count failed logins per user account only count the failed logins since the last successfull one.
	If this option is set to true, you get the same result: each time the user logs in sucessfully, the
	username is released for all ip addresses and user agents. And only failures AFTER the last release are counted. 

	This allows slow/distributed attacks to go on for a long period when the user logs in frequently.
	If this option is set to false, user names are only released for the IP address and user agent the
	successfull login was made from. The username may still become blocked for all the other IP addresses 
	and user agents. The disadvantage is that the user will be blocked when his IP address or user agent changes,
	for example because he wants to log in from a different device or connection.

7. Username release duration by IP address

	allowReleasedUserOnAddressFor
	
	For how long a username will remain released per IP address. Values like "3 minutes", "12 hours", "5 years" are allowed.

	If a user logs in frequently this will frequently produce new releases. This allows the user to
	log in from the same IP address even if his username is under constant attack, as long as the attacks 
	do not come from his IP address. However, he may take a vacation and not log in for some weeks or so. 
	This setting basically says how long this vacation may be and still be allowed to
	log in because of his user agent.
	
8. Garbage collection delay

    keepCountsFor

    For how long the requestcounts will be kept before being garbage-collected. Values like "4 days".

    If you use the AuthenticationGuardBundle and have enabled the user interface for user
    administrators to look into why a user may have been blocked, this is how long they can
    look back in time to see what happened.

    This value must allways be set longer then both blockUsernamesFor and blockIpAddressesFor,
    otherwise counters will be deleted before blocking should end and no longer be counted in
    for blocking.

    Currently the AuthenticationGuardBundle's user interface shows no information about active releases, but for
    future extension this value also acts as a minimum for how long releases will be kept before being
    garbage collected, but if allowReleasedUserOnAddressFor (or allowReleasedUserByCookieFor)
    is set to a longer duration, the releases will be kept longer (according to the longest one).

9. Fixed execution time

    fixedExecutionSeconds

    Fixed execution time in order to mitigate timing attacks. To apply, call ::sleepUntilFixedExecutionTime.

10. Maximum random sleeping time in nanoseconds

    randomSleepingNanosecondsMax

    Because of doubts about the accurateness of microtime() and to hide system clock
    details a random between 0 and this value is added by ::sleepUntilSinceInit (which
    is called by ::sleepUntilFixedExecutionTime).

Notes

- DBAL sets PDO attribute PDO::ATTR_ERRMODE to PDO::ERRMODE_EXCEPTION.
  The Tresholds Governor expects exceptions to be thrown on database errors.
  If your application is using PDO::ERRMODE_SILENT you may prefer using
  Metaclass\TresholdsGovernor\Connection\PDOConnection because it will
  throw Exceptions on silent PDO errors.
  (and add maybe a try catch around
  ```php
      $governor->initFor($ipAddress, $username, $password, ''); //using the last parameter is not yet documented
      $result = $governor->checkAuthentication();
  )
```

- releasing is possible for a username in general, an IP address in general, or for the combination of a username with an ip address

