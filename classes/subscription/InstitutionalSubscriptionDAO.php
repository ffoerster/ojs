<?php

/**
 * @file classes/subscription/InstitutionalSubscriptionDAO.php
 *
 * Copyright (c) 2014-2021 Simon Fraser University
 * Copyright (c) 2003-2021 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class InstitutionalSubscriptionDAO
 *
 * @ingroup subscription
 *
 * @see InstitutionalSubscription
 *
 * @brief Operations for retrieving and modifying InstitutionalSubscription objects.
 */

namespace APP\subscription;

use APP\core\Application;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;
use PKP\core\Core;
use PKP\db\DAOResultFactory;
use PKP\db\DBResultRange;
use PKP\facades\Locale;
use PKP\identity\Identity;
use PKP\institution\Institution;
use PKP\plugins\Hook;

class InstitutionalSubscriptionDAO extends SubscriptionDAO
{
    public const SUBSCRIPTION_INSTITUTION_NAME = 0x20;
    public const SUBSCRIPTION_DOMAIN = 0x21;
    public const SUBSCRIPTION_IP_RANGE = 0x22;

    /**
     * Retrieve an institutional subscription by subscription ID.
     *
     * @param int $subscriptionId Subscription ID
     * @param int $journalId Journal ID
     *
     * @return InstitutionalSubscription
     */
    public function getById($subscriptionId, $journalId = null)
    {
        $params = [(int) $subscriptionId];
        if ($journalId) {
            $params[] = (int) $journalId;
        }
        $result = $this->retrieve(
            'SELECT s.*, iss.*
            FROM subscriptions s
            JOIN subscription_types st ON s.type_id = st.type_id
            JOIN institutional_subscriptions iss ON s.subscription_id = iss.subscription_id
            WHERE
                st.institutional = 1
                AND s.subscription_id = ?
                ' . ($journalId ? ' AND s.journal_id = ?' : ''),
            $params
        );
        $row = $result->current();
        return $row ? $this->_fromRow((array) $row) : null;
    }

    /**
     * Retrieve institutional subscriptions by user ID.
     *
     * @param int $userId
     * @param ?DBResultRange $rangeInfo
     *
     * @return DAOResultFactory<InstitutionalSubscription> Object containing matching InstitutionalSubscriptions
     */
    public function getByUserId($userId, $rangeInfo = null)
    {
        $result = $this->retrieveRange(
            'SELECT s.*, iss.*
            FROM subscriptions s
            JOIN subscription_types st ON st.type_id = s.type_id
            JOIN institutional_subscriptions iss ON s.subscription_id = iss.subscription_id
            WHERE
                st.institutional = 1
                AND s.user_id = ?',
            [(int) $userId],
            $rangeInfo
        );
        return new DAOResultFactory($result, $this, '_fromRow');
    }

    /**
     * Retrieve institutional subscriptions by user ID and journal ID.
     *
     * @param int $userId
     * @param int $journalId
     * @param ?DBResultRange $rangeInfo
     *
     * @return DAOResultFactory<InstitutionalSubscription> Object containing matching InstitutionalSubscriptions
     */
    public function getByUserIdForJournal($userId, $journalId, $rangeInfo = null)
    {
        $result = $this->retrieveRange(
            'SELECT s.*, iss.*
            FROM subscriptions s
            JOIN subscription_types st ON s.type_id = st.type_id
            JOIN institutional_subscriptions iss ON s.subscription_id = iss.subscription_id
            WHERE
                st.institutional = 1
                AND s.user_id = ?
                AND s.journal_id = ?',
            [(int) $userId, (int) $journalId],
            $rangeInfo
        );
        return new DAOResultFactory($result, $this, '_fromRow');
    }

    /**
     * Return number of institutional subscriptions with given status for journal.
     *
     * @param int $journalId
     * @param null|mixed $status
     *
     * @return int
     */
    public function getStatusCount($journalId, $status = null)
    {
        $params = [(int) $journalId];
        if ($status !== null) {
            $params[] = (int) $status;
        }
        $result = $this->retrieve(
            'SELECT COUNT(*) AS row_count
            FROM subscriptions s
            JOIN subscription_types st ON s.type_id = st.type_id
            WHERE
                st.institutional = 1
                AND s.journal_id = ?
                ' . ($status !== null ? ' AND s.status = ?' : ''),
            $params
        );
        $row = $result->current();
        return $row ? $row->row_count : 0;
    }

    /**
     * Get the number of institutional subscriptions for a particular journal.
     *
     * @param int $journalId
     *
     * @return int
     */
    public function getSubscribedUserCount($journalId)
    {
        return $this->getStatusCount($journalId);
    }

    /**
     * Check if an institutional subscription exists for a given subscriptionId.
     *
     * @param int $subscriptionId Subscription ID
     * @param int $journalId Optional journal ID
     *
     * @return bool
     */
    public function subscriptionExists($subscriptionId, $journalId = null)
    {
        $params = [(int) $subscriptionId];
        if ($journalId) {
            $params[] = (int) $journalId;
        }
        $result = $this->retrieve(
            'SELECT COUNT(*) AS row_count
            FROM subscriptions s
            JOIN subscription_types st ON s.type_id = st.type_id
            WHERE
                st.institutional = 1
                AND s.subscription_id = ?
                ' . ($journalId ? ' AND s.journal_id = ?' : ''),
            $params
        );
        $row = $result->current();
        return $row ? (bool) $row->row_count : false;
    }

    /**
     * Check if an institutional subscription exists for a given user.
     *
     * @param int $subscriptionId Subscription ID
     * @param int $userId User ID
     *
     * @return bool
     */
    public function subscriptionExistsByUser($subscriptionId, $userId)
    {
        $result = $this->retrieve(
            'SELECT COUNT(*) AS row_count
            FROM subscriptions s
            JOIN subscription_types st ON s.type_id = st.type_id
            WHERE
                st.institutional = 1
                AND s.subscription_id = ?
                AND s.user_id = ?',
            [(int) $subscriptionId, (int) $userId]
        );
        $row = $result->current();
        return $row ? (bool) $row->row_count : false;
    }

    /**
     * Check if an institutional subscription exists for a given user and journal.
     *
     * @param int $userId
     * @param int $journalId
     *
     * @return bool
     */
    public function subscriptionExistsByUserForJournal($userId, $journalId)
    {
        $result = $this->retrieve(
            'SELECT COUNT(*) AS row_count
            FROM subscriptions s
            JOIN subscription_types st ON s.type_id = st.type_id
            WHERE
                st.institutional = 1
                AND s.user_id = ?
                AND s.journal_id = ?',
            [(int) $userId, (int) $journalId]
        );
        $row = $result->current();
        return $row ? (bool) $row->row_count : false;
    }

    /**
     * Insert a new institutional subscription.
     *
     * @param InstitutionalSubscription $institutionalSubscription
     *
     * @return int
     */
    public function insertObject($institutionalSubscription)
    {
        $subscriptionId = null;
        if ($this->_insertObject($institutionalSubscription)) {
            $subscriptionId = $institutionalSubscription->getId();

            $this->update(
                'INSERT INTO institutional_subscriptions
				(subscription_id, institution_id, mailing_address, domain)
				VALUES
				(?, ?, ?, ?)',
                [
                    (int) $subscriptionId,
                    (int) $institutionalSubscription->getInstitutionId(),
                    $institutionalSubscription->getInstitutionMailingAddress(),
                    $institutionalSubscription->getDomain()
                ]
            );
        }

        return $subscriptionId;
    }

    /**
     * Update an existing institutional subscription.
     *
     * @param InstitutionalSubscription $institutionalSubscription
     */
    public function updateObject($institutionalSubscription)
    {
        $this->_updateObject($institutionalSubscription);

        $this->update(
            'UPDATE	institutional_subscriptions
			SET	institution_id = ?,
				mailing_address = ?,
				domain = ?
			WHERE	subscription_id = ?',
            [
                $institutionalSubscription->getInstitutionId(),
                $institutionalSubscription->getInstitutionMailingAddress(),
                $institutionalSubscription->getDomain(),
                (int) $institutionalSubscription->getId()
            ]
        );
    }

    /**
     * Delete an institutional subscription by subscription ID.
     */
    public function deleteById(int $subscriptionId, ?int $journalId = null): int
    {
        if (!$this->subscriptionExists($subscriptionId, $journalId)) {
            return 0;
        }

        // Let subscriptions delete cascade to institutional_subscriptions
        return DB::table('subscriptions')
            ->where('subscription_id', '=', $subscriptionId)
            ->delete();
    }

    /**
     * Delete institutional subscriptions by journal ID.
     *
     * @param int $journalId
     */
    public function deleteByJournalId($journalId)
    {
        $result = $this->retrieve('SELECT s.subscription_id AS subscription_id FROM subscriptions s WHERE s.journal_id = ?', [(int) $journalId]);
        foreach ($result as $row) {
            $this->deleteById($row->subscription_id);
        }
    }

    /**
     * Delete institutional subscriptions by user ID.
     *
     * @param int $userId
     */
    public function deleteByUserId($userId)
    {
        $result = $this->retrieve('SELECT s.subscription_id AS subscription_id FROM subscriptions s WHERE s.user_id = ?', [(int) $userId]);
        foreach ($result as $row) {
            $this->deleteById($row->subscription_id);
        }
    }

    /**
     * Delete institutional subscriptions by user ID and journal ID.
     *
     * @param int $userId User ID
     * @param int $journalId Journal ID
     */
    public function deleteByUserIdForJournal($userId, $journalId)
    {
        $result = $this->retrieve('SELECT s.subscription_id AS subscription_id FROM subscriptions s WHERE s.user_id = ? AND s.journal_id = ?', [(int) $userId, (int) $journalId]);
        foreach ($result as $row) {
            $this->deleteById($row->subscription_id);
        }
    }

    /**
     * Delete all institutional subscriptions by subscription type ID.
     *
     * @param int $subscriptionTypeId Subscription type ID
     */
    public function deleteByTypeId($subscriptionTypeId)
    {
        $result = $this->retrieve('SELECT s.subscription_id AS subscription_id FROM subscriptions s WHERE s.type_id = ?', [(int) $subscriptionTypeId]);
        foreach ($result as $row) {
            $this->deleteById($row->subscription_id);
        }
    }

    /**
     * Retrieve all institutional subscriptions.
     *
     * @param ?DBResultRange $rangeInfo
     *
     * @return DAOResultFactory<InstitutionalSubscription> Object containing InstitutionalSubscriptions
     */
    public function getAll($rangeInfo = null)
    {
        $result = $this->retrieveRange(
            'SELECT	s.*, iss.*
                ' . $this->getInstitutionNameFetchColumns() . '
			FROM	subscriptions s
				JOIN subscription_types st ON (s.type_id = st.type_id)
				JOIN institutional_subscriptions iss ON (s.subscription_id = iss.subscription_id)
                ' . $this->getInstitutionNameFetchJoins() . '
			WHERE	st.institutional = 1
            ORDER BY institution_name ASC, s.subscription_id',
            $this->getInstitutionNameFetchParameters(),
            $rangeInfo
        );
        return new DAOResultFactory($result, $this, '_fromRow');
    }

    /**
     * Retrieve institutional subscriptions matching a particular journal ID.
     *
     * @param int $journalId
     * @param int $status
     * @param int $searchField
     * @param string $searchMatch "is" or "contains" or "startsWith"
     * @param string $search to look in $searchField for
     * @param int $dateField
     * @param string $dateFrom date to search from
     * @param string $dateTo date to search to
     * @param ?DBResultRange $rangeInfo
     *
     * @return DAOResultFactory<InstitutionalSubscription> Object containing matching Subscriptions
     */
    public function getByJournalId($journalId, $status = null, $searchField = null, $searchMatch = null, $search = null, $dateField = null, $dateFrom = null, $dateTo = null, $rangeInfo = null)
    {
        $locale = Locale::getLocale();
        $request = Application::get()->getRequest();
        $journal = $request->getContext();
        $journalPrimaryLocale = $journal->getPrimaryLocale();
        $site = $request->getSite();
        $sitePrimaryLocale = $site->getPrimaryLocale();

        $q = DB::table('subscriptions', 's')
            ->join('subscription_types AS st', 's.type_id', '=', 'st.type_id')
            ->join('users AS u', 's.user_id', '=', 'u.user_id')
            ->join('institutional_subscriptions AS iss', 's.subscription_id', '=', 'iss.subscription_id')
            ->leftJoin(
                'institution_settings AS isal',
                fn (JoinClause $j) =>
                $j->on('isal.institution_id', '=', 'iss.institution_id')
                    ->where('isal.setting_name', '=', 'name')
                    ->where('isal.locale', '=', $locale)
            )
            ->leftJoin(
                'institution_settings AS isapl',
                fn (JoinClause $j) =>
                $j->on('isapl.institution_id', '=', 'iss.institution_id')
                    ->where('isapl.setting_name', '=', 'name')
                    ->where('isapl.locale', '=', $journalPrimaryLocale)
            )
            ->leftJoin(
                'user_settings AS ugl',
                fn (JoinClause $j) =>
                $j->on('u.user_id', '=', 'ugl.user_id')
                    ->where('ugl.setting_name', '=', Identity::IDENTITY_SETTING_GIVENNAME)
                    ->where('ugl.locale', '=', $locale)
            )
            ->leftJoin(
                'user_settings AS ugpl',
                fn (JoinClause $j) =>
                $j->on('u.user_id', '=', 'ugpl.user_id')
                    ->where('ugpl.setting_name', '=', Identity::IDENTITY_SETTING_GIVENNAME)
                    ->where('ugpl.locale', '=', $sitePrimaryLocale)
            )
            ->leftJoin(
                'user_settings AS ufl',
                fn (JoinClause $j) =>
                $j->on('u.user_id', '=', 'ufl.user_id')
                    ->where('ufl.setting_name', '=', Identity::IDENTITY_SETTING_FAMILYNAME)
                    ->where('ufl.locale', '=', $locale)
            )
            ->leftJoin(
                'user_settings AS ufpl',
                fn (JoinClause $j) =>
                $j->on('u.user_id', '=', 'ufpl.user_id')
                    ->where('ufpl.setting_name', '=', Identity::IDENTITY_SETTING_FAMILYNAME)
                    ->where('ufpl.locale', '=', $sitePrimaryLocale)
            )
            ->where('st.institutional', '=', 1)
            ->where('s.journal_id', '=', $journalId)
            ->orderBy('institution_name')
            ->orderBy('s.subscription_id')
            ->select(
                's.*',
                'iss.institution_id',
                'iss.mailing_address',
                'iss.domain',
                DB::raw('COALESCE(isal.setting_value, isapl.setting_value) AS institution_name'),
                DB::raw('COALESCE(ugl.setting_value, ugpl.setting_value) AS user_given'),
                DB::raw("CASE WHEN ugl.setting_value <> '' THEN ufl.setting_value ELSE ufpl.setting_value END AS user_family")
            )
            ->distinct();

        if (!empty($search)) {
            $field = match ($searchField) {
                self::SUBSCRIPTION_INSTITUTION_NAME => 'insl.setting_value',
                self::SUBSCRIPTION_DOMAIN => 'iss.domain',
                self::SUBSCRIPTION_IP_RANGE => 'inip.ip_string',
                default => null
            };
            match ($searchField) {
                self::SUBSCRIPTION_INSTITUTION_NAME => $q->join('institution_settings AS insl', fn (JoinClause $j) => $j->on('insl.institution_id', '=', 'iss.institution_id')->where('insl.setting_name', '=', 'name')),
                self::SUBSCRIPTION_IP_RANGE => $q->join('institution_ip AS inip', 'inip.institution_id', '=', 'iss.institution_id'),
                default => null
            };
            if ($field) {
                match ($searchMatch) {
                    'is' => $q->whereRaw("LOWER({$field}) = LOWER(?)", [$search]),
                    'contains' => $q->whereRaw("LOWER({$field}) LIKE LOWER(?)", ["%{$search}%"]),
                    //startsWith
                    default => $q->whereRaw("LOWER({$field}) = LOWER(?)", ["{$search}%"])
                };
            }
        }

        $this->applySearchFilters($q, $status, $searchField, $searchMatch, $search, $dateField, $dateFrom, $dateTo, $params);

        $result = $this->retrieveRange($q, [], $rangeInfo);
        return new DAOResultFactory($result, $this, '_fromRow', [], $q, [], $rangeInfo); // Counted in subscription grid paging
    }

    /**
     * Check whether there is a valid institutional subscription for a given journal.
     *
     * @param string $domain
     * @param string $IP
     * @param int $journalId
     * @param int $check Test using either start date, end date, or both (default)
     * @param string $checkDate (YYYY-MM-DD) Use this date instead of current date
     *
     * @return int|false Found subscription ID, or false for none.
     */
    public function isValidInstitutionalSubscription($domain, $IP, $journalId, $check = Subscription::SUBSCRIPTION_DATE_BOTH, $checkDate = null)
    {
        if (empty($journalId) || (empty($domain) && empty($IP))) {
            return false;
        }

        $today = $this->dateToDB(Core::getCurrentDate());

        if ($checkDate == null) {
            $checkDate = $today;
        } else {
            $checkDate = $this->dateToDB($checkDate);
        }

        switch ($check) {
            case Subscription::SUBSCRIPTION_DATE_START:
                $dateSql = sprintf('%s >= s.date_start AND %s >= s.date_start', $checkDate, $today);
                break;
            case Subscription::SUBSCRIPTION_DATE_END:
                $dateSql = sprintf('%s <= s.date_end AND %s >= s.date_start', $checkDate, $today);
                break;
            default:
                $dateSql = sprintf('%s BETWEEN s.date_start AND s.date_end', $checkDate);
        }

        // Check if domain match
        if (!empty($domain)) {
            $result = $this->retrieve(
                '
                SELECT	iss.subscription_id
                FROM	institutional_subscriptions iss
                    JOIN subscriptions s ON (iss.subscription_id = s.subscription_id)
                    JOIN subscription_types st ON (s.type_id = st.type_id)
                WHERE	POSITION(UPPER(LPAD(iss.domain, LENGTH(iss.domain)+1, \'.\')) IN UPPER(LPAD(?, LENGTH(?)+1, \'.\'))) != 0
                    AND iss.domain != \'\'
                    AND s.journal_id = ?
                    AND s.status = ' . Subscription::SUBSCRIPTION_STATUS_ACTIVE . '
                    AND st.institutional = 1
                    AND ((st.duration IS NULL) OR (st.duration IS NOT NULL AND (' . $dateSql . ')))
                    AND (st.format = ' . SubscriptionType::SUBSCRIPTION_TYPE_FORMAT_ONLINE . '
                    OR st.format = ' . SubscriptionType::SUBSCRIPTION_TYPE_FORMAT_PRINT_ONLINE . ')',
                [$domain, $domain, (int) $journalId]
            );
            $row = $result->current();
            if ($row) {
                return $row->subscription_id;
            }
        }

        // Check for IP match (both IPv4 and IPv6)
        if (!empty($IP)) {
            // Check if we have IPv6 support in the database
            if ($this->hasIPv6Support()) {
                // Use the new binary IP matching for both IPv4 and IPv6
                $ipData = $this->ipToBinary($IP);

                if ($ipData) {
                    $binaryParam = $this->prepareBinaryForDB($ipData['binary']);
                    $ipRangeSQL = $this->getBinaryIPRangeSQL('?', 'iip.ip_start_binary', 'iip.ip_end_binary');

                    $result = $this->retrieve(
                        'SELECT	iss.subscription_id
                        FROM	institutional_subscriptions iss
                            JOIN institution_ip iip ON (iip.institution_id = iss.institution_id)
                            JOIN subscriptions s ON (iss.subscription_id = s.subscription_id)
                            JOIN subscription_types st ON (s.type_id = st.type_id)
                        WHERE	s.journal_id = ?
                            AND iip.ip_version = ?
                            AND ' . $ipRangeSQL . '
                            AND s.status = ' . Subscription::SUBSCRIPTION_STATUS_ACTIVE . '
                            AND st.institutional = 1
                            AND (
                                st.duration IS NULL
                                OR (' . $dateSql . ')
                            )
                            AND (
                                st.format = ' . SubscriptionType::SUBSCRIPTION_TYPE_FORMAT_ONLINE . '
                                OR st.format = ' . SubscriptionType::SUBSCRIPTION_TYPE_FORMAT_PRINT_ONLINE . '
                            )',
                        [(int) $journalId, $ipData['version'], $binaryParam]
                    );
                    $row = $result->current();
                    if ($row) {
                        return $row->subscription_id;
                    }
                }
            }

            // Fallback to legacy IPv4 matching for backward compatibility
            if ($this->getIPVersion($IP) === 4) {
                $IPLong = sprintf('%u', ip2long($IP));
                $result = $this->retrieve(
                    'SELECT	iss.subscription_id
                    FROM	institutional_subscriptions iss
                        JOIN institution_ip iip ON (iip.institution_id = iss.institution_id)
                        JOIN subscriptions s ON (iss.subscription_id = s.subscription_id)
                        JOIN subscription_types st ON (s.type_id = st.type_id)
                    WHERE	s.journal_id = ?
                        AND ? BETWEEN iip.ip_start AND COALESCE(iip.ip_end, iip.ip_start)
                        AND s.status = ' . Subscription::SUBSCRIPTION_STATUS_ACTIVE . '
                        AND st.institutional = 1
                        AND (
                            st.duration IS NULL
                            OR (' . $dateSql . ')
                        )
                        AND (
                            st.format = ' . SubscriptionType::SUBSCRIPTION_TYPE_FORMAT_ONLINE . '
                            OR st.format = ' . SubscriptionType::SUBSCRIPTION_TYPE_FORMAT_PRINT_ONLINE . '
                        )',
                    [(int) $journalId, $IPLong]
                );
                $row = $result->current();
                if ($row) {
                    return $row->subscription_id;
                }
            }
        }

        return false;
    }

    /**
     * Retrieve active institutional subscriptions matching a particular end date and journal ID.
     *
     * @param string $dateEnd (YYYY-MM-DD)
     * @param int $journalId
     * @param ?DBResultRange $rangeInfo
     *
     * @return DAOResultFactory<InstitutionalSubscription> Object containing matching InstitutionalSubscriptions
     */
    public function getByDateEnd($dateEnd, $journalId, $rangeInfo = null)
    {
        $dateEnd = explode('-', $dateEnd);

        $params = array_merge([$dateEnd[0], $dateEnd[1], $dateEnd[2], (int) $journalId], $this->getInstitutionNameFetchParameters());

        $result = $this->retrieveRange(
            'SELECT	s.*, iss.*
                ' . $this->getInstitutionNameFetchColumns() . ',
			FROM	subscriptions s
				JOIN subscription_types st ON (s.type_id = st.type_id)
				JOIN institutional_subscriptions iss ON (s.subscription_id = iss.subscription_id)
                ' . $this->getInstitutionNameFetchJoins() . '
			WHERE	s.status = ' . Subscription::SUBSCRIPTION_STATUS_ACTIVE . '
				AND st.institutional = 1
				AND EXTRACT(YEAR FROM s.date_end) = ?
				AND EXTRACT(MONTH FROM s.date_end) = ?
				AND EXTRACT(DAY FROM s.date_end) = ?
				AND s.journal_id = ?
            ORDER BY institution_name ASC, s.subscription_id',
            $params,
            $rangeInfo
        );

        return new DAOResultFactory($result, $this, '_fromRow');
    }

    /**
     * Renew an institutional subscription by dateEnd + duration of subscription type
     * if the institutional subscription is expired, renew to current date + duration
     *
     * @param InstitutionalSubscription $institutionalSubscription
     */
    public function renewSubscription($institutionalSubscription)
    {
        return $this->_renewSubscription($institutionalSubscription);
    }

    /**
     * Generator function to create object.
     *
     * @return InstitutionalSubscription
     */
    public function newDataObject()
    {
        return new InstitutionalSubscription();
    }

    /**
     * Internal function to return an InstitutionalSubscription object from a row.
     *
     * @param array $row
     *
     * @return InstitutionalSubscription
     *
     * @hook InstitutionalSubscriptionDAO::_fromRow [[&$institutionalSubscription, &$row]]
     */
    public function _fromRow($row)
    {
        /** @var InstitutionalSubscription */
        $institutionalSubscription = parent::_fromRow($row);

        $institutionalSubscription->setInstitutionId($row['institution_id']);
        $institutionalSubscription->setInstitutionMailingAddress($row['mailing_address']);
        $institutionalSubscription->setDomain($row['domain']);

        Hook::call('InstitutionalSubscriptionDAO::_fromRow', [&$institutionalSubscription, &$row]);

        return $institutionalSubscription;
    }

    /**
     * Return a list of extra parameters to bind to the institution fetch queries.
     *
     * @return array
     */
    public function getInstitutionNameFetchParameters()
    {
        $locale = Locale::getLocale();
        $journal = Application::get()->getRequest()->getContext();
        $primaryLocale = $journal->getPrimaryLocale();
        return [
            'name', $locale,
            'name', $primaryLocale,
        ];
    }

    /**
     * Return a SQL snippet of extra columns to fetch during institution fetch queries.
     *
     * @return string
     */
    public function getInstitutionNameFetchColumns()
    {
        return 'COALESCE(isal.setting_value, isapl.setting_value) AS institution_name';
    }

    /**
     * Return a SQL snippet of extra joins to include during institution fetch queries.
     *
     * @return string
     */
    public function getInstitutionNameFetchJoins()
    {
        return 'LEFT JOIN institution_settings isal ON (isal.institution_id = iss.institution_id AND isal.setting_name = ? AND isal.locale = ?)
        LEFT JOIN institution_settings isapl ON (isapl.institution_id = iss.institution_id AND isapl.setting_name = ? AND isapl.locale = ?)';
    }

    /**
     * Convert an IP address string to binary format for database storage
     *
     * @param string $ip IP address string (IPv4 or IPv6)
     *
     * @return array|null Array with keys: 'binary' (binary representation), 'version' (4 or 6), or null if invalid
     */
    private function ipToBinary(string $ip): ?array
    {
        $ip = trim($ip);

        // Check if it's IPv4
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $binary = inet_pton($ip);
            if ($binary === false) {
                return null;
            }
            // Convert IPv4 to IPv4-mapped IPv6 format for consistent storage
            $ipv6Binary = str_repeat("\x00", 10) . "\xff\xff" . $binary;
            return ['binary' => $ipv6Binary, 'version' => 4];
        }

        // Check if it's IPv6
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $binary = inet_pton($ip);
            if ($binary === false) {
                return null;
            }
            return ['binary' => $binary, 'version' => 6];
        }

        return null;
    }

    /**
     * Parse an IP range string and convert to binary format
     * Supports: single IPs, CIDR notation, wildcards, and ranges
     *
     * @param string $ipRange IP range string
     *
     * @return array|null Array with keys: 'start_binary', 'end_binary', 'version', or null if invalid
     */
    private function parseIPRange(string $ipRange): ?array
    {
        $ipRange = trim($ipRange);

        // Handle range notation (e.g., "192.168.1.1-192.168.1.255")
        if (strpos($ipRange, Institution::IP_RANGE_RANGE) !== false) {
            return $this->parseIPRangeWithDash($ipRange);
        }

        // Handle CIDR notation (e.g., "192.168.1.0/24" or "2001:db8::/32")
        if (strpos($ipRange, '/') !== false) {
            return $this->parseCIDR($ipRange);
        }

        // Handle wildcards (e.g., "192.168.1.*")
        if (strpos($ipRange, Institution::IP_RANGE_WILDCARD) !== false) {
            return $this->parseWildcard($ipRange);
        }

        // Handle single IP
        $result = $this->ipToBinary($ipRange);
        if ($result) {
            return [
                'start_binary' => $result['binary'],
                'end_binary' => $result['binary'],
                'version' => $result['version']
            ];
        }

        return null;
    }

    /**
     * Parse IP range with dash notation
     */
    private function parseIPRangeWithDash(string $ipRange): ?array
    {
        $parts = explode(Institution::IP_RANGE_RANGE, $ipRange, 2);
        if (count($parts) !== 2) {
            return null;
        }

        $startIP = trim($parts[0]);
        $endIP = trim($parts[1]);

        // Handle wildcards in start and end
        if (strpos($startIP, Institution::IP_RANGE_WILDCARD) !== false) {
            $startIP = str_replace(Institution::IP_RANGE_WILDCARD, '0', $startIP);
        }
        if (strpos($endIP, Institution::IP_RANGE_WILDCARD) !== false) {
            $endIP = str_replace(Institution::IP_RANGE_WILDCARD, '255', $endIP);
        }

        $startResult = $this->ipToBinary($startIP);
        $endResult = $this->ipToBinary($endIP);

        if (!$startResult || !$endResult || $startResult['version'] !== $endResult['version']) {
            return null;
        }

        return [
            'start_binary' => $startResult['binary'],
            'end_binary' => $endResult['binary'],
            'version' => $startResult['version']
        ];
    }

    /**
     * Parse CIDR notation
     */
    private function parseCIDR(string $cidr): ?array
    {
        $parts = explode('/', $cidr, 2);
        if (count($parts) !== 2) {
            return null;
        }

        $networkIP = trim($parts[0]);
        $prefixLength = (int)trim($parts[1]);

        $ipResult = $this->ipToBinary($networkIP);
        if (!$ipResult) {
            return null;
        }

        if ($ipResult['version'] === 4) {
            return $this->parseCIDRv4($networkIP, $prefixLength);
        } else {
            return $this->parseCIDRv6($networkIP, $prefixLength);
        }
    }

    /**
     * Parse IPv4 CIDR
     */
    private function parseCIDRv4(string $networkIP, int $prefixLength): ?array
    {
        if ($prefixLength < 0 || $prefixLength > 32) {
            return null;
        }

        $networkLong = ip2long($networkIP);
        if ($networkLong === false) {
            return null;
        }

        if ($prefixLength === 0) {
            $mask = 0;
        } else {
            $mask = (0xffffffff << (32 - $prefixLength)) & 0xffffffff;
        }

        $networkLong = $networkLong & $mask;
        $broadcastLong = $networkLong | (~$mask & 0xffffffff);

        $startBinary = inet_pton(long2ip($networkLong));
        $endBinary = inet_pton(long2ip($broadcastLong));

        // Convert to IPv4-mapped IPv6 format
        $startBinary = str_repeat("\x00", 10) . "\xff\xff" . $startBinary;
        $endBinary = str_repeat("\x00", 10) . "\xff\xff" . $endBinary;

        return [
            'start_binary' => $startBinary,
            'end_binary' => $endBinary,
            'version' => 4
        ];
    }

    /**
     * Parse IPv6 CIDR
     */
    private function parseCIDRv6(string $networkIP, int $prefixLength): ?array
    {
        if ($prefixLength < 0 || $prefixLength > 128) {
            return null;
        }

        $networkBinary = inet_pton($networkIP);
        if ($networkBinary === false) {
            return null;
        }

        // Create subnet mask
        $maskBytes = [];
        for ($i = 0; $i < 16; $i++) {
            $bitsInThisByte = min(8, max(0, $prefixLength - ($i * 8)));
            if ($bitsInThisByte === 8) {
                $maskBytes[] = 0xFF;
            } elseif ($bitsInThisByte === 0) {
                $maskBytes[] = 0x00;
            } else {
                $maskBytes[] = (0xFF << (8 - $bitsInThisByte)) & 0xFF;
            }
        }

        $mask = pack('C*', ...$maskBytes);

        // Calculate network and broadcast addresses
        $startBinary = $networkBinary & $mask;
        $endBinary = $networkBinary | (~$mask);

        return [
            'start_binary' => $startBinary,
            'end_binary' => $endBinary,
            'version' => 6
        ];
    }

    /**
     * Parse wildcard notation (only for IPv4)
     */
    private function parseWildcard(string $wildcard): ?array
    {
        // Only support IPv4 wildcards for now
        if (!preg_match('/^(\d{1,3}|\*)\.(\d{1,3}|\*)\.(\d{1,3}|\*)\.(\d{1,3}|\*)$/', $wildcard)) {
            return null;
        }

        $startIP = str_replace(Institution::IP_RANGE_WILDCARD, '0', $wildcard);
        $endIP = str_replace(Institution::IP_RANGE_WILDCARD, '255', $wildcard);

        $startResult = $this->ipToBinary($startIP);
        $endResult = $this->ipToBinary($endIP);

        if (!$startResult || !$endResult) {
            return null;
        }

        return [
            'start_binary' => $startResult['binary'],
            'end_binary' => $endResult['binary'],
            'version' => 4
        ];
    }

    /**
     * Get IP version (4 or 6)
     *
     * @param string $ip IP address string
     *
     * @return int|null IP version (4 or 6) or null if invalid
     */
    private function getIPVersion(string $ip): ?int
    {
        $ip = trim($ip);
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return 4;
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return 6;
        }
        return null;
    }

    /**
     * Check if the current database supports the new IP columns
     *
     * @return bool True if the database has been migrated to support IPv6
     */
    private function hasIPv6Support(): bool
    {
        try {
            // Check if the new columns exist
            $columns = DB::getSchemaBuilder()->getColumnListing('institution_ip');
            return in_array('ip_version', $columns) &&
                   in_array('ip_start_binary', $columns) &&
                   in_array('ip_end_binary', $columns);
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Get the appropriate SQL for binary IP comparison based on database type
     *
     * @param string $ipBinaryParam Parameter placeholder for IP binary data
     * @param string $startColumn Column name for start IP binary
     * @param string $endColumn Column name for end IP binary
     *
     * @return string SQL condition for IP range checking
     */
    private function getBinaryIPRangeSQL(string $ipBinaryParam, string $startColumn, string $endColumn): string
    {
        $driver = DB::getDriverName();

        switch ($driver) {
            case 'mysql':
            case 'pgsql':
            default:
                // Both MySQL and PostgreSQL can compare binary data directly
                return "{$ipBinaryParam} BETWEEN {$startColumn} AND COALESCE({$endColumn}, {$startColumn})";
        }
    }

    /**
     * Prepare binary data for database insertion based on database type
     *
     * @param string $binaryData Raw binary data
     *
     * @return mixed Prepared data for database insertion
     */
    private function prepareBinaryForDB(string $binaryData)
    {
        // Both MySQL and PostgreSQL handle binary data the same way in our use case
        return $binaryData;
    }

    // ...existing code...
}

if (!PKP_STRICT_MODE) {
    class_alias('\APP\subscription\InstitutionalSubscriptionDAO', '\InstitutionalSubscriptionDAO');
    foreach ([
        'SUBSCRIPTION_INSTITUTION_NAME',
        'SUBSCRIPTION_DOMAIN',
        'SUBSCRIPTION_IP_RANGE',
    ] as $constantName) {
        define($constantName, constant('\InstitutionalSubscriptionDAO::' . $constantName));
    }
}
