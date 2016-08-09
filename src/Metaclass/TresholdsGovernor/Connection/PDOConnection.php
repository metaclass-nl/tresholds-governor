<?php
// Partly copied from Doctrine\DBAL\Connection Copyright (c) 2006-2012 Doctrine Project
// Adaptations Copyright (c) MetaClass Groningen, 2016

namespace Metaclass\TresholdsGovernor\Connection;

use \PDO;

/**
 * Simple wrapper offering DBAL compatible ::executeQuery on PDO
 * Exceptions are not coverted.
 * If the DBO errormode is not PDO::ERRMODE_EXCEPTION, PDOExceptions are thrown here
 */
class PDOConnection
{
    /**
     * @var integer
     */
    protected $defaultFetchMode = PDO::FETCH_ASSOC;

    /**
     * @var \PDO
     */
    protected $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Sets the fetch mode.
     *
     * @param integer $fetchMode
     *
     * @return void
     */
    public function setFetchMode($fetchMode)
    {
        $this->defaultFetchMode = $fetchMode;
    }

    /**
     * Executes an, optionally parametrized, SQL query.
     *
     * If parameters are passed, a prepared statement is used.
     * Parameter types are not supported.
     * Does not do logging.
     * PDO must be connected.
     *
     * @param string $query  The SQL query to execute.
     * @param array $params The parameters to bind to the query, if any.

     * @throws \PDOException
     */
    public function executeQuery($query, array $params = array())
    {
        if ($params) {
            $stmt = $this->pdo->prepare($query);
            if (false !== $stmt) {
                $result = $stmt->execute($params);
                if (false === $result) {
                    throw new PDOException($stmt->errorInfo());
                }
            }
        } else {
            $stmt = $this->pdo->query($query);
        }

        if (false === $stmt) {
            throw new PDOException($this->pdo->errorInfo());
        }

        $stmt->setFetchMode($this->defaultFetchMode);

        return $stmt;
    }

    // transactions may be needed for packing data when reducing granularity is added (NYI)
}