<?php 
namespace Metaclass\TresholdsGovernor\Gateway;

use Metaclass\TresholdsGovernor\Connection\PDOConnection;

class RdbGateway
{
    
    /**
     * @var PDOConnection|\Doctrine\DBAL\Connection $dbalConnection The database connection to use.
     */
    protected $connection;
    
    /**
     * @param PDOConnection|\Doctrine\DBAL\Connection $dbalConnection The database connection to use.
     */
    public function __construct($dbalConnection)
    {
        $this->connection = $dbalConnection;
    }
    
    /**
     * @return PDOConnection|\Doctrine\DBAL\Connection
     */
    protected function getConnection()
    {
        return $this->connection;
    }

//----------------------------- RequestCountsGatewayInterface ------------------------------------

    /**
     * @return int Total of $counterColumn counted for $ipAddress with dtFrom after $timeLimit
     * AND as far as specified username, ipAddress and cookieToken equal to specified.
     * AND $releaseColumn is null if specified
     * 
     * WARNING: Supply literal string or well-validated vale for $counterColumn and $releaseColumn to prevent SQL injection!
     * 
     * @param string $counterColumn
     * @param string $username
     * @param string $ipAddress
     * @param string $cookieToken
     * @param \DateTime $dtLimit
     * @param string $releaseColumn
     * @throws BadFunctionCallException
     */
    public function countWhereSpecifiedAfter($counterColumn, $username, $ipAddress, $cookieToken, \DateTime $dtLimit, $releaseColumn=null)
    {
        $sql = "SELECT sum(r.$counterColumn) FROM secu_requests r WHERE (r.dtFrom > :dtLimit)";
        $params = array('dtLimit' => $dtLimit->format('Y-m-d H:i:s'));
        if ($username !== null) {
            $sql .= ' AND (r.username = :username)';
            $params['username'] = $username;
        }
        if ($ipAddress !== null) {
            $sql .= ' AND (r.ipAddress = :ipAddress)';
            $params['ipAddress'] = $ipAddress;
        }
        if ($cookieToken !== null) {
            $sql .= ' AND (r.cookieToken = :token)';
            $params['token'] = $cookieToken;
        }
        if ($releaseColumn !== null) {
            $sql .= " AND ($releaseColumn IS NULL)";
        }
        return (int) $this->getConnection()->executeQuery($sql, $params)->fetchColumn();
    }

    /**
     * Insert 1 or increment the counter column corresonding to $loginSucceeded 
     * AND all corrsponding column values equal to the other parameters.
     * 
     * Due to race conditions multiple records of RequestCounts may exist for the same combination of 
     * $dateTime, $username, $ipAddress and $cookieToken. This is no problem as their counters will all
     * be summarized by ::countWhereSpecifiedAfter and they will all be released by ::updateCountsColumnWhereColumnNullAfterSupplied
     *    
     * @param \DateTime $dateTime for dtFrom
     * @param string $username
     * @param string $ipAddress
     * @param string $cookieToken
     * @param boolean $loginSucceeded wheather the login succeede (otherwise it failed)
     * @param string $blockedCounterName if supplied, also increment the corresponding counter
     *    WARNING: Supply literal string or well-validated vale to prevent SQL injection!
     */
    public function insertOrIncrementCount(\DateTime $dateTime, $username, $ipAddress, $cookieToken, $loginSucceeded, $blockedCounterName=null)
    {
        $counter = $loginSucceeded ? 'loginsSucceeded' : 'loginsFailed';
        $id = $this->getCountsIdWhereDateAndUsernameAndIpAddressAndCookie($dateTime, $username, $ipAddress, $cookieToken);
        if ($id) {
            $this->incrementCountWhereId($counter, $id, $blockedCounterName);
        } else {
            $this->createRequestCountsWith($dateTime, $ipAddress, $username, $cookieToken, $counter, $blockedCounterName);
        }
    }
    
    /**
     * @return int the id of a RequestCount with all corrsponding column values equal to the parameters 
     * AND all release columns null.
     * @param \DateTime $dateTime for dtFrom
     * @param string $username
     * @param string $ipAddress
     * @param string $cookieToken
     */
    protected function getCountsIdWhereDateAndUsernameAndIpAddressAndCookie(\DateTime $dateTime, $username, $ipAddress, $cookieToken)
    {
        $sql = 'SELECT r.id FROM secu_requests r
    WHERE (r.username = :username)
    AND (r.ipAddress = :ipAddress)
    AND (r.dtFrom = :dtFrom)
    AND (r.cookieToken = :token)
    AND (addressReleasedAt IS NULL)
    AND (userReleasedAt IS NULL)
    AND (userReleasedForAddressAndCookieAt IS NULL)';
        $params = array(
            'username' => $username,
            'ipAddress' => $ipAddress,
            'dtFrom' => $dateTime->format('Y-m-d H:i:s'),
            'token' => $cookieToken,
        );
        return $this->getConnection()->executeQuery($sql, $params)->fetchColumn();
    }

    /** Increment RequestCounts $columnToUpdate where id = $id
     * 
     * WARNING: Supply literal string or well-validated vale for $columnToUpdate to prevent SQL injection!
     * 
     * @param string $columnToUpdate
     * @param int $id
     * @param string $blockedCounterName if supplied, also increment the corresponding counter
     *    WARNING: Supply literal string or well-validated vale to prevent SQL injection!
     */
    protected function incrementCountWhereId($columnToUpdate, $id, $blockedCounterName=null)
    {
        $params = array('id' => $id);
        $sql = "UPDATE secu_requests SET $columnToUpdate = $columnToUpdate + 1";
        if ($blockedCounterName !== null) {
            $sql .= ", $blockedCounterName = $blockedCounterName + 1";
        }
        $sql .= " WHERE id = :id";
        $this->getConnection()->executeQuery($sql, $params);
    }
    
    /**
     * Insert RequestCounts with 1 for the $counter column  
     * and all corrsponding column values set to the other parameter values
     * 
     * WARNING: Supply literal string or well-validated vale for $counter to prevent SQL injection!
     * 
     * @param \DateTime $dateTime for dtFrom
     * @param string $username
     * @param string $ipAddress
     * @param string $cookieToken
     * @param string $counter name of the counter column
     * @param string $blockedCounterName if supplied, set increment the corresponding counter to 1
     *    WARNING: Supply literal string or well-validated vale to prevent SQL injection!
     */
    protected function createRequestCountsWith($datetime, $ipAddress, $username, $cookieToken, $counter, $blockedCounterName=null)
    {
        $params = array(
            'dtFrom' => $datetime->format('Y-m-d H:i:s'),
            'username' => $username,
            'ipAddress' => $ipAddress,
            'cookieToken' => $cookieToken,
            $counter => 1 );
        if (null !== $blockedCounterName) {
            $params[$blockedCounterName] = 1;
        }
        $columns = implode(', ', array_keys($params));
        $values = ':'. implode(', :', array_keys($params));
        $sql = "INSERT INTO secu_requests ($columns) VALUES ($values)";

        $this->getConnection()->executeQuery($sql, $params);
    }

    /**
     * Set Requestcounts $columnToUpdate to $value where $columnToUpdate is null AND dtFrom > $dtLimit
     * AND as far as specified username, ipAddress and cookieToken equal to specified.

     * WARNING: Supply literal string or well-validated vale for $columnToUpdate to prevent SQL injection!
     * 
     * @param string $columnToUpdate
     * @param \DateTime $value date and time of the release
     * @param \DateTime $dtLimit
     * @param string $username
     * @param string $ipAddress
     * @param string $cookieToken
     * @throws BadFunctionCallException
     */
    public function updateCountsColumnWhereColumnNullAfterSupplied($columnToUpdate, \DateTime $value, \DateTime $dtLimit, $username, $ipAddress, $cookieToken)
    {
        if ($username === null && $ipAddress == null) {
            throw new \BadFunctionCallException('At least one of username and ip address must be supplied');
        }
        $sql = "UPDATE secu_requests
    SET $columnToUpdate = :value
    WHERE (userReleasedForAddressAndCookieAt IS NULL)
    AND (dtFrom > :dtLimit)";
        $params = array(
            'value' => $value->format('Y-m-d H:i:s'),
            'dtLimit' => $dtLimit->format('Y-m-d H:i:s'),
        );
        if ($username !== null) {
            $sql .= ' AND (username = :username)';
            $params['username'] = $username;
        }
        if ($ipAddress != null) {
            $sql .= ' AND (ipAddress = :ipAddress)';
            $params['ipAddress'] = $ipAddress;
        }
        if ($cookieToken !== null) {
            $sql .= ' AND (cookieToken = :token)';
            $params['token'] = $cookieToken;
        }
        $this->getConnection()->executeQuery($sql, $params);
    }
    
    /**
     * Delete all RequestCounts with dtFrom before $dtLimit
     * @param \DateTime $dtLimit 
     */
    public function deleteCountsUntil(\DateTime $dtLimit)
    {
        $sql = 'DELETE FROM secu_requests WHERE dtFrom < :dtLimit';
        $params = array('dtLimit' => $dtLimit->format('Y-m-d H:i:s'));
        $this->getConnection()->executeQuery($sql, $params);
    }

// Statistics

    /** Selects counts grouped by `ipAdress`
     * with `dtFrom` after or equal to $limitFrom AND as far as specified
     * `dtFrom` before $limitUntil, `username` equals specified,
     * in ascending order of `ipAdress`
     * Counts are:
     *   count(distinct(r.username)) as usernames,
     *   sum(r.loginsSucceeded) as loginsSucceeded,
     *   sum(r.loginsFailed) as loginsFailed,
     *   sum(r.ipAddressBlocked) as ipAddressBlocked,
     *   sum(r.usernameBlocked) as usernameBlocked,
     *   sum(r.usernameBlockedForIpAddress) as usernameBlockedForIpAddress,
     *   sum(r.usernameBlockedForCookie) as usernameBlockedForCookie.
     * @param \DateTime $limitFrom
     * @param \DateTime|null $limitUntil
     * @param string|null $username
     * @return array of array each with ip address and its counts
     */
    public function countsGroupedByIpAddress(\DateTime $limitFrom, ?\DateTime $limitUntil=null, $username=null)
    {
        $params = array($limitFrom->format('Y-m-d H:i:s'));
        $sql = "SELECT r.ipAddress
          , count(distinct(r.username)) as usernames
          , sum(r.loginsSucceeded) as loginsSucceeded
          , sum(r.loginsFailed) as loginsFailed
          , sum(r.ipAddressBlocked) as ipAddressBlocked
          , sum(r.usernameBlocked) as usernameBlocked
          , sum(r.usernameBlockedForIpAddress) as usernameBlockedForIpAddress
          , sum(r.usernameBlockedForCookie) as usernameBlockedForCookie
            FROM secu_requests r
            WHERE (r.dtFrom >= ?) AND (r.addressReleasedAt IS NULL)";
        if ($limitUntil !== null) {
            $sql .= " AND (r.dtFrom < ?)";
            $params[] = $limitUntil->format('Y-m-d H:i:s');
        }
        if ($username !== null) {
            $sql .= " AND (r.username = ?)";
            $params[] = $username;
        }
        $sql .= "
            GROUP BY r.ipAddress
            ORDER BY r.ipAddress
            LIMIT 200";

        $stmt = $this->getConnection()->executeQuery($sql, $params);
        $result = $stmt->fetchAll();
        $stmt->closeCursor();

        return $result;
    }

    /** Selects counts grouped by `username`
     * with `dtFrom` after or equal to $limitFrom AND as far as specified
     * `dtFrom` before $limitUntil, `username` equals specified,
     * in ascending order of `username`
     * Counts are:
     *   count(distinct(r.ipAddress)) as ipAddresses,
     *   further @see ::countsGroupedByIpAddress
     * @param \DateTime $limitFrom
     * @param \DateTime|null $limitUntil
     * @param string|null $ipAddress
     * @return array of array each with username address and its counts
     */
    public function countsGroupedByUsername(\DateTime $limitFrom, ?\DateTime $limitUntil=null, $ipAddress=null)
    {
        $params = array($limitFrom->format('Y-m-d H:i:s'));
        $sql = "SELECT r.username
          , count(distinct(r.ipAddress)) as ipAddresses
          , sum(r.loginsSucceeded) as loginsSucceeded
          , sum(r.loginsFailed) as loginsFailed
          , sum(r.ipAddressBlocked) as ipAddressBlocked
          , sum(r.usernameBlocked) as usernameBlocked
          , sum(r.usernameBlockedForIpAddress) as usernameBlockedForIpAddress
          , sum(r.usernameBlockedForCookie) as usernameBlockedForCookie
            FROM secu_requests r
            WHERE (r.dtFrom >= ?) AND (r.userReleasedAt IS NULL)";
        if ($limitUntil !== null) {
            $sql .= " AND (r.dtFrom < ?)";
            $params[] = $limitUntil->format('Y-m-d H:i:s');
        }
        if ($ipAddress !== null) {
            $sql .= " AND (r.ipAddress = ?)";
            $params[] = $ipAddress;
        }
        $sql .= "
            GROUP BY r.username
            ORDER BY r.username
            LIMIT 200";

        $stmt = $this->getConnection()->executeQuery($sql, $params);
        $result = $stmt->fetchAll();
        $stmt->closeCursor();

        return $result;
    }

    /** Selects Counts
     * with `dtFrom` after or equal to $limitFrom AND before $limitUntil,
     * and as far as specified, `username` and `ipAddress' equals specified,
     * ordered by `dtFrom`.
     * @param \DateTime $limitFrom
     * @param \DateTime $limitUntil
     * @param string|null $username
     * @param string|null $ipAddress
     * @return array of arrays, each with values for all column of `secu_requests`
     */
    public function countsBetween(\DateTime $limitFrom, \DateTime $limitUntil, $username=null, $ipAddress=null)
    {
        $params = array($limitFrom->format('Y-m-d H:i:s'), $limitUntil->format('Y-m-d H:i:s'));
        $sql = "SELECT * FROM secu_requests r
            WHERE (r.dtFrom >= ?)  AND (r.dtFrom < ?)";
        if ($username !== null) {
            $sql .= " AND (r.username = ?)";
            $params[] = $username;
        }
        if ($ipAddress !== null) {
            $sql .= " AND (r.ipAddress = ?)";
            $params[] = $ipAddress;
        }
        $sql .= "
            ORDER BY r.dtFrom DESC
            LIMIT 500";

        $stmt = $this->getConnection()->executeQuery($sql, $params);
        $result = $stmt->fetchAll();
        $stmt->closeCursor();
        return $result;
    }

    //not used, not tested
    public function countColumn($column, \DateTime $timeLimit)
    {
        $sql = "SELECT count(r.$column) as total,
        FROM secu_requests r
        WHERE (r.dtFrom >= ?) AND (r.addressReleasedAt IS NULL)";

        $conn = $this->getConnection();
        return $conn->fetchAll($sql, array($timeLimit->format('Y-m-d H:i:s')));
    }

    /** Not used, not tested
     * Counts ip adresses that have not been released and have been blocked,
     * i.e. failed a number of times that is over the specified failure limit,
     *  with `dtFrom` after of equal to $failureLimit
     *
     * @param \DateTime $timeLimit
     * @param int $failureLimit
     * @return int
     */
    public function countAddressesBlocked(\DateTime $timeLimit, $failureLimit)
    {
        $sql = "SELECT count(r.ipAddress) as blocked
        FROM secu_requests r
        WHERE (r.dtFrom >= ?) AND (r.addressReleasedAt IS NULL)
        GROUP BY r.ipAddress
        HAVING sum(r.loginsFailed) >= ?
        ";

        $conn = $this->getConnection();
        $found = $conn->fetchAll($sql, array($timeLimit->format('Y-m-d H:i:s'), $failureLimit));
        return isset($found['blocked']) ? $found['blocked'] : 0;
    }


//------------------------ ReleasesGatewayInterface ---------------------------------------------

    /** 
     * Register the release at $dateTime of $username for both $ipAddress and $cookieToken.
     * 
     * @param DateTime $dateTime of the release
     * @param string $username
     * @param string $ipAddress
     * @param string $cookieToken
     */
    public function insertOrUpdateRelease(\DateTime $datetime, $username, $ipAddress, $cookieToken)
    {
        $id = $this->getReleasesIdWhereDateAndUsernameAndIpAddressAndCookie($username, $ipAddress, $cookieToken);
        if ($id) {
            $this->updateRelease($datetime, $id);
        } else {
            $this->insertRelease($datetime, $username, $ipAddress, $cookieToken);
        }
    }
    
    /** 
     * Get the id of the release of $username for both $ipAddress and $cookieToken.
     * The same user may have been released for several combinations of ip address and cookieToken
     * resulting in ever so many records. 
     * 
     * Due to race conditions multiple records of releases may exist for the same combination of 
     * $username, $ipAddress and $cookieToken. To compensate the last (highest) release date will be
     * used. Therefore it is irrelevant the release date which release of these is updated.   
     * 
     * @param string $username
     * @param string $ipAddress
     * @param string $cookieToken
     * @return int id of the Release
     */
    protected function getReleasesIdWhereDateAndUsernameAndIpAddressAndCookie($username, $ipAddress, $cookieToken)
    {
        $params = array(
                'username' => $username,
                'ipAddress' => $ipAddress,
                'cookieToken' => $cookieToken );
        $sql = "SELECT id from secu_releases WHERE username = :username AND ipAddress = :ipAddress AND cookieToken = :cookieToken";
        return (int) $this->getConnection()->executeQuery($sql, $params)->fetchColumn();
    }
    
    /** 
     * Insert a new release at $dateTime of $username for both $ipAddress and $cookieToken.
     * 
     * @param DateTime $dateTime of the release
     * @param string $username
     * @param string $ipAddress
     * @param string $cookieToken
     */
    protected function insertRelease($datetime, $username, $ipAddress, $cookieToken)
    {
        $params = array(
            'releasedAt' => $datetime->format('Y-m-d H:i:s'),
            'username' => $username,
            'ipAddress' => $ipAddress,
            'cookieToken' => $cookieToken );
        $columns = implode(', ', array_keys($params));
        $values = ':'. implode(', :', array_keys($params));
        $sql = "INSERT INTO secu_releases ($columns) VALUES ($values)";
        $this->getConnection()->executeQuery($sql, $params);
    }
    
    /** 
     * Update the $dateTime of the release with $id
     * 
     * @param DateTime $dateTime to set
     * @param int $id to select the release by
     */
    protected function updateRelease($datetime, $id)
    {
        $params = array(
            'releasedAt' => $datetime->format('Y-m-d H:i:s'),
            'id' => $id );
        $sql = "UPDATE secu_releases SET releasedAt = :releasedAt WHERE id = :id";
        $this->getConnection()->executeQuery($sql, $params);
    }
    
    /** 
     * @param string $username
     * @param string $ipAddress
     * @param DateTime $releaseLimit
     * @return boolean Wheater $username has been released for $ipAddress on or after the $releaseLimit.
     */
    public function isUserReleasedOnAddressFrom($username, $ipAddess, \DateTime $releaseLimit)
    {
        $sql = "SELECT max(r.releasedAt)
        FROM secu_releases r
        WHERE r.releasedAt >= ?
                AND r.username = ? AND r.ipAddress = ? ";
        $params = array($releaseLimit->format('Y-m-d H:i:s'), $username, $ipAddess);
        return (boolean) $this->getConnection()->executeQuery($sql, $params)->fetchColumn();
    }
    
    /** 
     * @param string $username
     * @param string $cookieToken
     * @param DateTime $releaseLimit
     * @return boolean Wheater $username has been released for $cookieToken on or after the $releaseLimit.
     */
    public function isUserReleasedByCookieFrom($username, $cookieToken, \DateTime $releaseLimit)
    {
        $sql = "SELECT max(r.releasedAt)
        FROM secu_releases r
        WHERE r.releasedAt >= ?
                AND r.username = ? AND r.cookieToken = ? ";
        $params = array($releaseLimit->format('Y-m-d H:i:s'), $username, $cookieToken);
        $conn = $this->getConnection();
        return (boolean) $this->getConnection()->executeQuery($sql, $params)->fetchColumn();
    }
    
    /**
     * Delete all releases dated before $dtLimit
     * @param DateTime $dtLimit 
     */
    public function deleteReleasesUntil(\DateTime $dtLimit)
    {
        $sql = 'DELETE FROM secu_releases WHERE releasedAt < :dtLimit';
        $params = array('dtLimit' => $dtLimit->format('Y-m-d H:i:s'));

        $this->getConnection()->executeQuery($sql, $params);
    }
}
