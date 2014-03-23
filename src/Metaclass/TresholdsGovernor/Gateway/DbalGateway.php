<?php 
namespace Metaclass\TresholdsGovernor\Gateway;

class DbalGateway {
    
    protected $dbalConnection;
    
    /**
     * @param Doctrine\DBAL\Connection $dbalConnection The database connection to use.
     */
    public function __construct($dbalConnection) {
        $this->dbalConnection = $dbalConnection;
    }
    
    protected function getConnection() 
    {
        return $this->dbalConnection;
    }

//----------------------------- RequestCountsGatewayInterface ------------------------------------
    
    //WARNING: $counterColumn, $releaseColumn vurnerable for SQL injection!!
    public function countWhereSpecifiedAfter($counterColumn, $username, $ipAddress, $cookieToken, $dtLimit, $releaseColumn=null)
    {
        if ($username === null && $ipAddress == null && $cookieToken == null) {
            throw new BadFunctionCallException ('At least one of username, ipAddress, cookieToken must be supplied');
        }
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
    
    //currently not used
    public function getDateLastLoginSuccessAfter($dtLimit, $username, $ipAddress=null, $cookieToken=null) {
        $qb = $this->getConnection()->createQueryBuilder();
        $qb->select("r.date")
            ->from('secu_requests', 'r')
            ->where("r.dtFrom > :dtLimit")
            ->andWhere("r.username = :username")
            ->setParameter('dtLimit', $dtLimit->format('Y-m-d H:i:s'))
            ->setParameter('username', $username);
        if ($ipAddress !== null) {
            $qb->andWhere("r.ipAddress = :ipAddress")
                ->setParameter('ipAddress', $ipAddress);
        }
        if (cookieToken !== null) {
               $qb->andWhere("(r.cookieToken = :token)")
                  ->setParameter('token', $cookieToken);
        }
    }
    
// may not work without entity
//    public function findByDateAndUsernameAndIpAddressAndCookie($dateTime, $ipAddress, $username, $cookieToken)
//    {
//        $qb = $this->createQueryBuilder('r');
//        $this->qbWhereDateAndUsernameAndIpAddressAndCookie($qb, $dateTime, $username, $ipAddress, $cookieToken);
//
//        return $qb->getQuery()->getOneOrNullResult();
//    }
    
    public function insertOrIncrementCount($dateTime, $username, $ipAddress, $cookieToken, $loginSucceeded)
    {
        $counter = $loginSucceeded ? 'loginsSucceeded' : 'loginsFailed';
        $id = $this->getCountsIdWhereDateAndUsernameAndIpAddressAndCookie($dateTime, $username, $ipAddress, $cookieToken);
        if ($id) {
            $this->incrementCountWhereId($counter, $id);
        } else {
            $this->createRequestCountsWith($dateTime, $ipAddress, $username, $cookieToken, $counter);
        }
        
    }
    
    protected function getCountsIdWhereDateAndUsernameAndIpAddressAndCookie($dateTime, $username, $ipAddress, $cookieToken) {
        $conn = $this->getConnection();
        $qb = $conn->createQueryBuilder();
        $qb->select('r.id')
            ->from('secu_requests', 'r');
        $this->qbWhereDateAndUsernameAndIpAddressAndCookie($qb, $dateTime, $username, $ipAddress, $cookieToken);
        return $qb->execute()->fetchColumn();
    }
    
    //WARNING: $columnToUpdate vurnerable for SQL injection!!
    protected function incrementCountWhereId($columnToUpdate, $id)
    {
        $conn = $this->getConnection();
        $qb = $conn->createQueryBuilder();
        $qb->update('secu_requests', 'r')
            ->set($columnToUpdate, "$columnToUpdate + 1")
            ->where("id = :id")
            ->setParameter('id', $id)
            ->execute();
    }
    
    //WARNING: $counter vurnerable for SQL injection
    protected function createRequestCountsWith($datetime, $ipAddress, $username, $cookieToken, $counter)
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

    //WARNING: $releaseColumn vurnerable for SQL injection!!
    protected function qbWhereDateAndUsernameAndIpAddressAndCookie($qb, $dateTime, $username, $ipAddress, $cookieToken) {
        $qb->where('r.username = :username')
            ->andWhere('r.ipAddress = :ipAddress')
            ->andWhere('r.dtFrom = :dtFrom')
            ->andWhere('r.cookieToken = :token')
            ->andWhere("addresReleasedAt IS NULL")
            ->andWhere("userReleasedAt IS NULL")
            ->andWhere("userReleasedForAddressAndCookieAt IS NULL")
            ->setParameter('username', $username)
            ->setParameter('ipAddress', $ipAddress)
            ->setParameter('dtFrom', $dateTime->format('Y-m-d H:i:s') )
            ->setParameter('token', $cookieToken);
            ;
    }
    
    //WARNING: $columnToUpdate vurnerable for SQL injection!!
    public function updateCountsColumnWhereColumnNullAfterSupplied($columnToUpdate, $value, $dtLimit, $username, $ipAddress, $cookieToken) {
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
    
    //------------------------ ReleasesGatewayInterface ---------------------------------------------
    public function insertOrUpdateRelease($datetime, $username, $ipAddress, $cookieToken)
    {
        $id = $this->getReleasesIdWhereDateAndUsernameAndIpAddressAndCookie($username, $ipAddress, $cookieToken);
        if ($id) {
            $this->updateRelease($datetime, $id);
        } else {
            $this->insertRelease($datetime, $username, $ipAddress, $cookieToken);
        }
    }
    
    protected function getReleasesIdWhereDateAndUsernameAndIpAddressAndCookie($username, $ipAddress, $cookieToken) 
    {
        $params = array(
                'username' => $username,
                'ipAddress' => $ipAddress,
                'cookieToken' => $cookieToken );
        $sql = "SELECT id from secu_releases WHERE username = :username AND ipAddress = :ipAddress AND cookieToken = :cookieToken ORDER BY id";
        $found = $this->getConnection()->fetchColumn($sql, $params);
        return isSet($found[0]) ? $found[0] : null; 
    }
    
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
    
    protected function updateRelease($datetime, $id)
    {
        $params = array(
            'releasedAt' => $datetime->format('Y-m-d H:i:s'),
            'id' => $id );
        $sql = "UPDATE secu_releases SET releasedAt = :releasedAt WHERE id = :id";
        $this->getConnection()->executeUpdate($sql, $params);
    }
    
    public function isUserReleasedOnAddressFrom($username, $ipAddess, $releaseLimit)
    {
        $sql = "SELECT max(r.releasedAt)
        FROM secu_releases r
        WHERE r.releasedAt >= ?
                AND r.username = ? AND r.ipAddress = ? ";
    
        $conn = $this->getConnection();
        return (boolean) $conn->fetchColumn($sql, array($releaseLimit->format('Y-m-d H:i:s'), $username, $ipAddess));
    }
    
    public function isUserReleasedByCookieFrom($username, $cookieToken, $releaseLimit)
    {
        $sql = "SELECT max(r.releasedAt)
        FROM secu_releases r
        WHERE r.releasedAt >= ?
                AND r.username = ? AND r.cookieToken = ? ";
    
        $conn = $this->getConnection();
        return (boolean) $conn->fetchColumn($sql, array($releaseLimit->format('Y-m-d H:i:s'), $username, $cookieToken));
    }
    
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