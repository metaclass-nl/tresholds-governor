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
    
    public function getIdWhereDateAndUsernameAndIpAddressAndCookie($dateTime, $username, $ipAddress, $cookieToken) {
        $conn = $this->getConnection();
        $qb = $conn->createQueryBuilder();
        $qb->select('r.id')
            ->from('secu_requests', 'r');
        $this->qbWhereDateAndUsernameAndIpAddressAndCookie($qb, $dateTime, $username, $ipAddress, $cookieToken);
        return $qb->execute()->fetchColumn();
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
    
    public function createWith($datetime, $ipAddress, $username, $cookieToken, $loginSucceeded)
    {
        $conn = $this->getConnection();
        $counter = $loginSucceeded ? 'loginsSucceeded' : 'loginsFailed';
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
    
    //WARNING: $columnToUpdate vurnerable for SQL injection!!
    public function incrementColumnWhereId($columnToUpdate, $id)
    {
        $conn = $this->getConnection();
        $qb = $conn->createQueryBuilder();
        $qb->update('secu_requests', 'r')
            ->set($columnToUpdate, "$columnToUpdate + 1")
            ->where("id = :id")
            ->setParameter('id', $id)
            ->execute();
    }
    
    //WARNING: $columnToUpdate vurnerable for SQL injection!!
    public function updateColumnWhereColumnNullAfterSupplied($columnToUpdate, $value, $dtLimit, $username, $ipAddress, $cookieToken) {
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
        $params = array(
            'releasedAt' => $datetime->format('Y-m-d H:i:s'),
            'username' => $username,
            'ipAddress' => $ipAddress,
            'cookieToken' => $cookieToken );
        $columns = implode(', ', array_keys($params));
        $values = ':'. implode(', :', array_keys($params));
        $columnExpressions = null;
        forEach($params as $key => $value)
        {
            if (isSet($columnExpressions)) {
                $columnExpressions .= ', ';
            } 
            $columnExpressions .= "$key = :upd_$key";
            $params["upd_$key"] = $value;
        }
        $sql = "INSERT INTO secu_releases ($columns) VALUES ($values)
                ON DUPLICATE KEY UPDATE $columnExpressions";
        $this->getConnection()->executeUpdate($sql, $params);
    }
    
    public function isUserReleasedOnAddressFrom($username, $ipAddess, $releaseLimit)
    {
        $sql = "SELECT r.releasedAt
        FROM secu_releases r
        WHERE r.releasedAt >= ?
                AND r.username = ? AND r.ipAddress = ? ";
    
        $conn = $this->getConnection();
        return (boolean) $conn->fetchColumn($sql, array($releaseLimit->format('Y-m-d H:i:s'), $username, $ipAddess));
    }
    
    public function isUserReleasedByCookieFrom($username, $cookieToken, $releaseLimit)
    {
        $sql = "SELECT r.releasedAt
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