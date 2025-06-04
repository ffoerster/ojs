<?php

/**
 * @file classes/subscription/InstitutionalSubscriptionHelpers.php
 *
 * Copyright (c) 2014    private static function parseIPRangeWithDash(string $ipRange): ?array
 *
 * @class InstitutionalSubscriptionHelpers
 *
 * @brief Helper functions for institutional subscription IP address handling and database operations
 */

namespace APP\subscription;

use Exception;
use Illuminate\Support\Facades\DB;

class InstitutionalSubscriptionHelpers
{
    // Institution constants for IP range handling
    public const IP_RANGE_WILDCARD = '*';
    public const IP_RANGE_RANGE = '-';
    /**
     * Convert an IP address string to binary format for database storage
     *
     * @param string $ip IP address string (IPv4 or IPv6)
     *
     * @return array|null Array with keys: 'binary' (binary representation), 'version' (4 or 6), or null if invalid
     */
    public static function ipToBinary(string $ip): ?array
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
    public static function parseIPRange(string $ipRange): ?array
    {
        $ipRange = trim($ipRange);

        // Handle range notation (e.g., "192.168.1.1-192.168.1.255")
        if (strpos($ipRange, self::IP_RANGE_RANGE) !== false) {
            return static::parseIPRangeWithDash($ipRange);
        }

        // Handle CIDR notation (e.g., "192.168.1.0/24" or "2001:db8::/32")
        if (strpos($ipRange, '/') !== false) {
            return static::parseCIDR($ipRange);
        }

        // Handle wildcards (e.g., "192.168.1.*")
        if (strpos($ipRange, self::IP_RANGE_WILDCARD) !== false) {
            return static::parseWildcard($ipRange);
        }

        // Handle single IP
        $result = static::ipToBinary($ipRange);
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
    public static function parseIPRangeWithDash(string $ipRange): ?array
    {
        $parts = explode(self::IP_RANGE_RANGE, $ipRange, 2);
        if (count($parts) !== 2) {
            return null;
        }

        $startIP = trim($parts[0]);
        $endIP = trim($parts[1]);

        // Handle wildcards in start and end
        if (strpos($startIP, self::IP_RANGE_WILDCARD) !== false) {
            $startIP = str_replace(self::IP_RANGE_WILDCARD, '0', $startIP);
        }
        if (strpos($endIP, self::IP_RANGE_WILDCARD) !== false) {
            $endIP = str_replace(self::IP_RANGE_WILDCARD, '255', $endIP);
        }

        $startResult = static::ipToBinary($startIP);
        $endResult = static::ipToBinary($endIP);

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
     * Parse CIDR notation (e.g., "192.168.1.0/24" or "2001:db8::/32")
     */
    public static function parseCIDR(string $cidr): ?array
    {
        $parts = explode('/', $cidr, 2);
        if (count($parts) !== 2) {
            return null;
        }

        $networkIP = trim($parts[0]);
        $prefixLength = (int) trim($parts[1]);

        $ipResult = static::ipToBinary($networkIP);
        if (!$ipResult) {
            return null;
        }

        if ($ipResult['version'] === 4) {
            return static::parseCIDRv4($networkIP, $prefixLength);
        } else {
            return static::parseCIDRv6($networkIP, $prefixLength);
        }
    }

    /**
     * Parse IPv4 CIDR notation
     */
    public static function parseCIDRv4(string $networkIP, int $prefixLength): ?array
    {
        if ($prefixLength < 0 || $prefixLength > 32) {
            return null;
        }

        $ipLong = ip2long($networkIP);
        if ($ipLong === false) {
            return null;
        }

        // Calculate network and broadcast addresses
        $mask = ~((1 << (32 - $prefixLength)) - 1);
        $networkLong = $ipLong & $mask;
        $broadcastLong = $networkLong | ~$mask;

        // Convert to IPv4-mapped IPv6 format
        $startResult = static::ipToBinary(long2ip($networkLong));
        $endResult = static::ipToBinary(long2ip($broadcastLong));

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
     * Parse IPv6 CIDR notation
     */
    public static function parseCIDRv6(string $networkIP, int $prefixLength): ?array
    {
        if ($prefixLength < 0 || $prefixLength > 128) {
            return null;
        }

        $networkBinary = inet_pton($networkIP);
        if ($networkBinary === false) {
            return null;
        }

        // Create the subnet mask
        $maskBinary = '';
        $bytesWithFullMask = intval($prefixLength / 8);
        $remainingBits = $prefixLength % 8;

        // Full bytes
        for ($i = 0; $i < $bytesWithFullMask; $i++) {
            $maskBinary .= "\xff";
        }

        // Partial byte
        if ($remainingBits > 0) {
            $partialMask = 0xff << (8 - $remainingBits);
            $maskBinary .= chr($partialMask);
        }

        // Pad to 16 bytes
        $maskBinary = str_pad($maskBinary, 16, "\x00");

        // Calculate network address
        $networkBinary = $networkBinary & $maskBinary;

        // Calculate broadcast address (all host bits set to 1)
        $invertedMask = '';
        for ($i = 0; $i < 16; $i++) {
            $invertedMask .= chr(~ord($maskBinary[$i]));
        }
        $broadcastBinary = $networkBinary | $invertedMask;

        return [
            'start_binary' => $networkBinary,
            'end_binary' => $broadcastBinary,
            'version' => 6
        ];
    }

    /**
     * Parse wildcard notation (e.g., "192.168.1.*")
     */
    public static function parseWildcard(string $wildcard): ?array
    {
        // Replace wildcards with appropriate start/end values
        $startIP = str_replace(self::IP_RANGE_WILDCARD, '0', $wildcard);
        $endIP = str_replace(self::IP_RANGE_WILDCARD, '255', $wildcard);

        $startResult = static::ipToBinary($startIP);
        $endResult = static::ipToBinary($endIP);

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
     * Get the IP version (4 or 6) of an IP address
     *
     * @param string $ip IP address
     *
     * @return int|null 4 for IPv4, 6 for IPv6, null if invalid
     */
    public static function getIPVersion(string $ip): ?int
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return 4;
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return 6;
        }
        return null;
    }

    /**
     * Check if the database has IPv6 support columns
     *
     * @return bool True if IPv6 support columns exist
     */
    public static function hasIPv6Support(): bool
    {
        static $hasSupport = null;

        if ($hasSupport === null) {
            try {
                // Check if the new columns exist
                $result = DB::select("SHOW COLUMNS FROM institution_ip LIKE 'ip_version'");
                $hasSupport = count($result) > 0;
            } catch (Exception $e) {
                $hasSupport = false;
            }
        }

        return $hasSupport;
    }

    /**
     * Check if PostgreSQL inet type support is available
     *
     * @return bool True if PostgreSQL inet columns exist
     */
    public static function hasPostgreSQLInetSupport(): bool
    {
        static $hasSupport = null;

        if ($hasSupport === null) {
            $driver = DB::getDriverName();
            if ($driver !== 'pgsql') {
                $hasSupport = false;
            } else {
                try {
                    // Check if the inet columns exist
                    $result = DB::select("SELECT column_name FROM information_schema.columns WHERE table_name = 'institution_ip' AND column_name = 'ip_start_inet'");
                    $hasSupport = count($result) > 0;
                } catch (Exception $e) {
                    $hasSupport = false;
                }
            }
        }

        return $hasSupport;
    }

    /**
     * Generate SQL for IP range checking based on database type
     *
     * @param string $ipBinaryParam Parameter placeholder for IP binary
     * @param string $startColumn Column name for start IP binary
     * @param string $endColumn Column name for end IP binary
     *
     * @return string SQL condition for IP range checking
     */
    public static function getBinaryIPRangeSQL(string $ipBinaryParam, string $startColumn, string $endColumn): string
    {
        $driver = DB::getDriverName();

        switch ($driver) {
            case 'mysql':
                // MySQL/MariaDB: Use direct binary comparison for best performance
                return "{$ipBinaryParam} BETWEEN {$startColumn} AND COALESCE({$endColumn}, {$startColumn})";
            case 'pgsql':
                // PostgreSQL: Use binary comparison
                return "{$ipBinaryParam} BETWEEN {$startColumn} AND COALESCE({$endColumn}, {$startColumn})";
            default:
                // Generic binary comparison for other databases
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
    public static function prepareBinaryForDB(string $binaryData)
    {
        // Both MySQL and PostgreSQL handle binary data the same way in our use case
        return $binaryData;
    }

    /**
     * Check if MySQL supports INET6_ATON and INET6_NTOA functions
     */
    public static function supportsMySQLINET6Functions(): bool
    {
        static $supports = null;

        if ($supports === null) {
            try {
                $result = DB::select('SELECT INET6_ATON(?) AS test', ['::1']);
                $supports = count($result) > 0;
            } catch (Exception $e) {
                $supports = false;
            }
        }

        return $supports;
    }

    /**
     * Enhanced IP matching using database-specific optimizations when available
     *
     * @param string $ip IP address to check
     * @param int $journalId Journal ID
     * @param string $dateSql Date constraint SQL
     * @param object $dao DAO instance for database operations
     *
     * @return int|false Subscription ID or false if not found
     */
    public static function checkIPWithDatabaseOptimization(string $ip, int $journalId, string $dateSql, $dao)
    {
        $driver = DB::getDriverName();

        if ($driver === 'mysql' && static::supportsMySQLINET6Functions()) {
            return static::checkIPWithMySQLINET6($ip, $journalId, $dateSql, $dao);
        } elseif ($driver === 'pgsql' && static::hasPostgreSQLInetSupport()) {
            return static::checkIPWithPostgreSQLInet($ip, $journalId, $dateSql, $dao);
        }

        // Fallback to binary comparison method
        $ipData = static::ipToBinary($ip);
        if (!$ipData) {
            return false;
        }

        $binaryParam = static::prepareBinaryForDB($ipData['binary']);
        $ipRangeSQL = static::getBinaryIPRangeSQL('?', 'iip.ip_start_binary', 'iip.ip_end_binary');

        $result = $dao->retrieve(
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
            [$journalId, $ipData['version'], $binaryParam]
        );

        $row = $result->current();
        return $row ? $row->subscription_id : false;
    }

    /**
     * Use MySQL's INET6_ATON for IP matching
     */
    public static function checkIPWithMySQLINET6(string $ip, int $journalId, string $dateSql, $dao)
    {
        $ipVersion = static::getIPVersion($ip);

        $result = $dao->retrieve(
            'SELECT	iss.subscription_id
            FROM	institutional_subscriptions iss
                JOIN institution_ip iip ON (iip.institution_id = iss.institution_id)
                JOIN subscriptions s ON (iss.subscription_id = s.subscription_id)
                JOIN subscription_types st ON (s.type_id = st.type_id)
            WHERE	s.journal_id = ?
                AND iip.ip_version = ?
                AND INET6_ATON(?) BETWEEN iip.ip_start_binary AND COALESCE(iip.ip_end_binary, iip.ip_start_binary)
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
            [$journalId, $ipVersion, $ip]
        );

        $row = $result->current();
        return $row ? $row->subscription_id : false;
    }

    /**
     * Use PostgreSQL's inet type for IP matching
     */
    public static function checkIPWithPostgreSQLInet(string $ip, int $journalId, string $dateSql, $dao)
    {
        $ipVersion = static::getIPVersion($ip);

        // For PostgreSQL inet type, we can use native IP comparison operators
        $result = $dao->retrieve(
            'SELECT	iss.subscription_id
            FROM	institutional_subscriptions iss
                JOIN institution_ip iip ON (iip.institution_id = iss.institution_id)
                JOIN subscriptions s ON (iss.subscription_id = s.subscription_id)
                JOIN subscription_types st ON (s.type_id = st.type_id)
            WHERE	s.journal_id = ?
                AND iip.ip_version = ?
                AND (
                    -- Handle CIDR ranges (single inet value)
                    (iip.ip_end_inet IS NULL AND ?::inet <<= iip.ip_start_inet)
                    OR
                    -- Handle IP ranges (start and end inet values)
                    (iip.ip_end_inet IS NOT NULL AND ?::inet BETWEEN iip.ip_start_inet AND iip.ip_end_inet)
                    OR
                    -- Handle single IP (start equals input IP)
                    (iip.ip_end_inet IS NULL AND ?::inet = iip.ip_start_inet)
                )
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
            [$journalId, $ipVersion, $ip, $ip, $ip]
        );

        $row = $result->current();
        return $row ? $row->subscription_id : false;
    }

    /**
     * Convert IP range data to PostgreSQL inet format for storage
     *
     * @param string $ipString Original IP string
     * @param array $parsedRange Parsed IP range data
     *
     * @return array Array with 'start_inet' and 'end_inet' keys for PostgreSQL storage
     */
    public static function convertToPostgreSQLInet(string $ipString, array $parsedRange): array
    {
        $result = ['start_inet' => null, 'end_inet' => null];

        // Handle CIDR notation - PostgreSQL inet can store CIDR directly
        if (strpos($ipString, '/') !== false) {
            if (filter_var(explode('/', $ipString)[0], FILTER_VALIDATE_IP)) {
                $result['start_inet'] = $ipString; // Store CIDR as-is
                $result['end_inet'] = null; // No end needed for CIDR
            }
            return $result;
        }

        // Handle single IP
        if (isset($parsedRange['start_binary']) && !isset($parsedRange['end_binary'])) {
            $startIP = static::binaryToIP($parsedRange['start_binary'], $parsedRange['version']);
            if ($startIP) {
                $result['start_inet'] = $startIP;
                $result['end_inet'] = null;
            }
            return $result;
        }

        // Handle IP ranges
        if (isset($parsedRange['start_binary']) && isset($parsedRange['end_binary'])) {
            $startIP = static::binaryToIP($parsedRange['start_binary'], $parsedRange['version']);
            $endIP = static::binaryToIP($parsedRange['end_binary'], $parsedRange['version']);

            if ($startIP && $endIP) {
                $result['start_inet'] = $startIP;
                $result['end_inet'] = $endIP;
            }
        }

        return $result;
    }

    /**
     * Convert binary IP representation back to string
     *
     * @param string $binary Binary IP representation
     * @param int $version IP version (4 or 6)
     *
     * @return string|null IP address string or null if conversion fails
     */
    public static function binaryToIP(string $binary, int $version): ?string
    {
        if ($version === 4) {
            // Extract IPv4 from IPv4-mapped IPv6 format
            if (strlen($binary) === 16) {
                $ipv4Bytes = substr($binary, 12, 4);
                $ip = inet_ntop($ipv4Bytes);
                return $ip ?: null;
            }
        } elseif ($version === 6) {
            // Direct IPv6 binary
            $ip = inet_ntop($binary);
            return $ip ?: null;
        }

        return null;
    }
}
