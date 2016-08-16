<?php

namespace Metaclass\TresholdsGovernor\Manager;

/**
 * Instances of classes implementing this interface calculate statistics
 * about requests, usernames and ip addresses
 *
 * @author Henk Verhoeven
 * @copyright 2014-2016 MetaClass Groningen
 */

interface StatisticsManagerInterface
{
    /**
     * @param \DateTime $timeLimit
     * @return int Total of `loginsFailed` counted with `dtFrom` after $timeLimit
     */
    public function countLoginsFailed(\DateTime $timeLimit);

    /**
     * @param \DateTime $timeLimit
     * @return int Total of `loginsSucceeded` counted with `dtFrom` after $timeLimit
     */
    public function countLoginsSucceeded(\DateTime $timeLimit);

    /**
     * @param $username
     * @param \DateTime $timeLimit
     * @return int Total of `loginsSucceeded` counted with `dtFrom` after $timeLimit
     *  AND `username` equal to specified
     */
    public function countLoginsSucceededForUserName($username, \DateTime $timeLimit);

    /** @see RequestCountsManagerInterface::countLoginsFailedForUserName */
    public function countLoginsFailedForUserName($username, \DateTime $timeLimit);

    /** Counts grouped by Ip address
     * with `dtFrom` after $limitFrom AND as far as specified
     * `dtFrom` before $limitUntil, $username equals specified
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
    public function countsGroupedByIpAddress(\DateTime $limitFrom, \DateTime $limitUntil=null, $username=null);

    /** Selects Counts
     * with `dtFrom` after or erual to $limitFrom AND before $limitUntil,
     * and `username` equals specified,
     * ordered by `dtFrom`.
     * @param $username
     * @param \DateTime $limitFrom
     * @param \DateTime $limitUntil
     * @return array of arrays, each with values for all column of `secu_requests`
     */
    public function countsByUsernameBetween($username, \DateTime $limitFrom, \DateTime $limitUntil);

    /** Selects Counts
     * with `dtFrom` after or erual to $limitFrom AND before $limitUntil,
     * and `ipAddress' equals specified,
     * ordered by `dtFrom`.
     * @param $ipAddress
     * @param \DateTime $limitFrom
     * @param \DateTime $limitUntil
     * @return array of arrays, each with values for all column of `secu_requests`
     */
    public function countsByAddressBetween($ipAddress, \DateTime $limitFrom, \DateTime $limitUntil);
}
