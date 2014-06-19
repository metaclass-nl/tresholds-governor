<?php 
namespace Metaclass\TresholdsGovernor\Gateway;

class DbalGateway {
    
    /**
     * @var Doctrine\DBAL\Connection $dbalConnection The database connection to use.
     */
    protected $dbalConnection;
    
    /**
     * @param Doctrine\DBAL\Connection $dbalConnection The database connection to use.
     */
    public function __construct($dbalConnection) {
        $this->dbalConnection = $dbalConnection;
    }
    
    /**
     * @return Doctrine\DBAL\Connection
     */
    protected function getConnection() 
    {
        return $this->dbalConnection;
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
        $qb = $this->getConnection()->createQueryBuilder();
        $qb->select("sum(r.$counterColumn)")
            ->from('secu_requests', 'r')
            ->where("r.dtFrom > :dtLimit")
            ->setParameter('dtLimit', $dtLimit->format('Y-m-d H:i:s'));
        if ($username !== null) {
            $qb->andWhere("r.username = :username")
                ->setParameter('username', $username);
        }
        if ($ipAddress !== null) {
            $qb->andWhere("r.ipAddress = :ipAddress")
                ->setParameter('ipAddress', $ipAddress);
        }
        if ($cookieToken !== null) {
            $qb->andWhere("r.cookieToken = :token")
                ->setParameter('token', $cookieToken);
        }
        if ($releaseColumn !== null) {
            $qb->andWhere("$releaseColumn IS NULL");
        }
        return (int) $qb->execute()->fetchColumn();
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
    protected function getCountsIdWhereDateAndUsernameAndIpAddressAndCookie(\DateTime $dateTime, $username, $ipAddress, $cookieToken) {
        $conn = $this->getConnection();
        $qb = $conn->createQueryBuilder();
        $qb->select('r.id')
            ->from('secu_requests', 'r');
        $this->qbWhereDateAndUsernameAndIpAddressAndCookie($qb, $dateTime, $username, $ipAddress, $cookieToken);
        return $qb->execute()->fetchColumn();
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
        $conn = $this->getConnection();
        $params = array('id' => $id);
        $sql = "UPDATE secu_requests SET $columnToUpdate = $columnToUpdate + 1";
        if ($blockedCounterName !== null) {
            $sql .= ", $blockedCounterName = $blockedCounterName + 1";
        }
        $sql .= " WHERE id = :id";
        $conn->executeUpdate($sql, $params);
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
        $conn = $this->getConnection();
        $params = array(
            'dtFrom' => $datetime->format('Y-m-d H:i:s'),
            'username' => $username,
            'ipAddress' => $ipAddress,
            'cookieToken' => $cookieToken,
            $counter => 1 );
        $columns = implode(', ', array_keys($params));
        $values = ':'. implode(', :', array_keys($params));
        $sql = "INSERT INTO secu_requests ($columns) VALUES ($values)";
        $conn->executeUpdate($sql, $params);
    }

    /**
     * Add WHERE clause and parameters to $qb for dtFrom = $dateTime AND all release columns are NULL 
     * AND username = $username AND ipAddress = $ipAddress AND cookieToken = $cookieToken
     * @param QueryBuilder $qb
     * @param \DateTime $dateTime
     * @param string $username
     * @param string $ipAddress
     * @param string $cookieToken
     */
    protected function qbWhereDateAndUsernameAndIpAddressAndCookie($qb, $dateTime, $username, $ipAddress, $cookieToken) {
        $qb->where('r.username = :username')
            ->andWhere('r.ipAddress = :ipAddress')
            ->andWhere('r.dtFrom = :dtFrom')
            ->andWhere('r.cookieToken = :token')
            ->andWhere("addressReleasedAt IS NULL")
            ->andWhere("userReleasedAt IS NULL")
            ->andWhere("userReleasedForAddressAndCookieAt IS NULL")
            ->setParameter('username', $username)
            ->setParameter('ipAddress', $ipAddress)
            ->setParameter('dtFrom', $dateTime->format('Y-m-d H:i:s') )
            ->setParameter('token', $cookieToken);
            ;
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
    public function updateCountsColumnWhereColumnNullAfterSupplied($columnToUpdate, \DateTime $value, \DateTime $dtLimit, $username, $ipAddress, $cookieToken) {
        if ($username === null && $ipAddress == null) {
            throw new BadFunctionCallException ('At least one of username and ip address must be supplied');
        }
        $conn = $this->getConnection();
        $qb = $conn->createQueryBuilder();
        $qb->update('secu_requests', 'r')
            ->set($columnToUpdate, ':value')
            ->setParameter('value', $value->format('Y-m-d H:i:s'))
            ->where("$columnToUpdate IS NULL")
            ->andWhere("r.dtFrom > :dtLimit")
            ->setParameter('dtLimit', $dtLimit->format('Y-m-d H:i:s'));
        if ($username !== null) {
            $qb->andWhere("r.username = :username")
                ->setParameter('username', $username);
        }
        if ($ipAddress != null) {
            $qb->andWhere("r.ipAddress = :ipAddress")
                ->setParameter('ipAddress', $ipAddress);
        }
        if ($cookieToken !== null) {
            $qb->andWhere("r.cookieToken = :token")
                ->setParameter('token', $cookieToken);
        }
        $qb->execute();
    }
    
    /**
     * Delete all RequestCounts with dtFrom before $dtLimit
     * @param \DateTime $dtLimit 
     */
    public function deleteCountsUntil(\DateTime $dtLimit) 
    {
        if (!$dtLimit) {
            throw new \Exception('DateTime limit must be specified');
        }
        $conn = $this->getConnection();
        $qb = $conn->createQueryBuilder();
        $qb->delete('secu_requests')
        ->where("dtFrom < :dtLimit")
        ->setParameter('dtLimit', $dtLimit->format('Y-m-d H:i:s'));
        $qb->execute();
    }

//Statistics

    public function countsGroupedByIpAddress(\DateTime $limitFrom, \DateTime $limitUntil=null, $username=null)
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

        $conn = $this->getConnection();
        return $conn->fetchAll($sql, $params);
    }

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

        $conn = $this->getConnection();
        return $conn->fetchAll($sql, $params);
    }

    //not used
    public function countColumn($column, \DateTime $timeLimit)
    {
        $sql = "SELECT count(r.$column) as total,
        FROM secu_requests r
        WHERE (r.dtFrom >= ?) AND (r.addressReleasedAt IS NULL)";

        $conn = $this->getConnection();
        return $conn->fetchAll($sql, array($timeLimit->format('Y-m-d H:i:s')));
    }

    //werkt misschien niet correct
    public function countAddressesBlocked(\DateTime $timeLimit, $failureLimit)
    {
        $sql = "SELECT count(r.ipAddress) as blocked
        FROM secu_requests r
        WHERE (r.dtFrom >= ?) AND (r.addressReleasedAt IS NULL)
        GROUP BY r.ipAddress
        HAVING sum(r.loginsFailed) >= ?
        ";

        $conn = $this->getConnection();
        return $conn->fetchAll( $sql, array($timeLimit->format('Y-m-d H:i:s'), $failureLimit) );

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
        $found = $this->getConnection()->fetchColumn($sql, $params);
        return isSet($found[0]) ? $found[0] : null; 
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
        $this->getConnection()->executeUpdate($sql, $params);
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
        $this->getConnection()->executeUpdate($sql, $params);
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
    
        $conn = $this->getConnection();
        return (boolean) $conn->fetchColumn($sql, array($releaseLimit->format('Y-m-d H:i:s'), $username, $ipAddess));
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
    
        $conn = $this->getConnection();
        return (boolean) $conn->fetchColumn($sql, array($releaseLimit->format('Y-m-d H:i:s'), $username, $cookieToken));
    }
    
    /**
     * Delete all releases dated before $dtLimit
     * @param DateTime $dtLimit 
     */
    public function deleteReleasesUntil(\DateTime $dtLimit) 
    {
        if (!$dtLimit) {
            throw new \Exception('DateTime limit must be specified');
        }
        $conn = $this->getConnection();
        $qb = $conn->createQueryBuilder();
        $qb->delete('secu_releases')
            ->where("releasedAt < :dtLimit")
            ->setParameter('dtLimit', $dtLimit->format('Y-m-d H:i:s'));
        $qb->execute();
    }
}

?>