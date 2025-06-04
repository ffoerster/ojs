<?php

/**
 * @file classes/migration/upgrade/v3_6_0/I4261_AddPostgreSQLInetSupport.php
 *
 * Copyright (c) 2014-2021 Simon Fraser University
 * Copyright (c) 2003-2021 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class I4261_AddPostgreSQLInetSupport
 *
 * @brief Add PostgreSQL inet type support for IP address storage and comparison
 */

namespace APP\migration\upgrade\v3_6_0;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PKP\migration\Migration;

class I4261_AddPostgreSQLInetSupport extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $driver = DB::getDriverName();

        // Only apply PostgreSQL-specific changes for PostgreSQL
        if ($driver !== 'pgsql') {
            return;
        }

        // Check if inet columns already exist to avoid duplicate migrations
        if (Schema::hasColumn('institution_ip', 'ip_start_inet')) {
            return; // Migration already applied
        }

        // Add new inet columns for PostgreSQL
        Schema::table('institution_ip', function (Blueprint $table) {
            // Using DB::raw to ensure proper PostgreSQL inet type
            $table->addColumn('string', 'ip_start_inet')->nullable()->after('ip_end_binary');
            $table->addColumn('string', 'ip_end_inet')->nullable()->after('ip_start_inet');
        });

        // Convert the string columns to proper inet type using raw SQL
        DB::statement('ALTER TABLE institution_ip ALTER COLUMN ip_start_inet TYPE inet USING ip_start_inet::inet');
        DB::statement('ALTER TABLE institution_ip ALTER COLUMN ip_end_inet TYPE inet USING ip_end_inet::inet');

        // Add comments to the columns
        DB::statement('COMMENT ON COLUMN institution_ip.ip_start_inet IS \'PostgreSQL inet type for IP start (supports both IPv4 and IPv6 with efficient indexing)\'');
        DB::statement('COMMENT ON COLUMN institution_ip.ip_end_inet IS \'PostgreSQL inet type for IP end (supports both IPv4 and IPv6 with efficient indexing)\'');

        // Add indexes for better performance with inet type
        Schema::table('institution_ip', function (Blueprint $table) {
            $table->index(['ip_start_inet'], 'institution_ip_start_inet');
            $table->index(['ip_end_inet'], 'institution_ip_end_inet');
        });

        // Migrate existing data to inet format
        $this->migrateExistingDataToInet();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $driver = DB::getDriverName();

        if ($driver !== 'pgsql') {
            return;
        }

        Schema::table('institution_ip', function (Blueprint $table) {
            $table->dropIndex('institution_ip_start_inet');
            $table->dropIndex('institution_ip_end_inet');
            $table->dropColumn(['ip_start_inet', 'ip_end_inet']);
        });
    }

    /**
     * Migrate existing IP data to PostgreSQL inet format
     */
    private function migrateExistingDataToInet(): void
    {
        $ipRanges = DB::table('institution_ip')->get();

        foreach ($ipRanges as $ipRange) {
            $ipStartInet = null;
            $ipEndInet = null;

            // Convert from ip_string or binary data to inet format
            if (!empty($ipRange->ip_string)) {
                // Parse the IP string and convert to individual IPs
                $parsedRange = $this->parseIPStringToInetRange($ipRange->ip_string);
                if ($parsedRange) {
                    $ipStartInet = $parsedRange['start'];
                    $ipEndInet = $parsedRange['end'];
                }
            } elseif ($ipRange->ip_start_binary !== null && $ipRange->ip_version) {
                // Convert from binary format
                $ipStartInet = $this->binaryToInet($ipRange->ip_start_binary, $ipRange->ip_version);
                if ($ipRange->ip_end_binary !== null) {
                    $ipEndInet = $this->binaryToInet($ipRange->ip_end_binary, $ipRange->ip_version);
                }
            } elseif ($ipRange->ip_start !== null) {
                // Legacy IPv4 long format
                $ipStartInet = long2ip($ipRange->ip_start);
                if ($ipRange->ip_end !== null) {
                    $ipEndInet = long2ip($ipRange->ip_end);
                }
            }

            if ($ipStartInet) {
                DB::table('institution_ip')
                    ->where('institution_ip_id', $ipRange->institution_ip_id)
                    ->update([
                        'ip_start_inet' => $ipStartInet,
                        'ip_end_inet' => $ipEndInet
                    ]);
            }
        }
    }

    /**
     * Parse IP string format to inet range
     */
    private function parseIPStringToInetRange(string $ipString): ?array
    {
        $ipString = trim($ipString);

        // Handle CIDR notation
        if (strpos($ipString, '/') !== false) {
            return ['start' => $ipString, 'end' => null]; // PostgreSQL inet handles CIDR directly
        }

        // Handle range notation (e.g., "192.168.1.1-192.168.1.10")
        if (strpos($ipString, '-') !== false) {
            $parts = explode('-', $ipString, 2);
            $start = trim($parts[0]);
            $end = trim($parts[1]);

            if (filter_var($start, FILTER_VALIDATE_IP) && filter_var($end, FILTER_VALIDATE_IP)) {
                return ['start' => $start, 'end' => $end];
            }
        }

        // Handle wildcard notation (e.g., "192.168.1.*")
        if (strpos($ipString, '*') !== false) {
            $cidr = $this->wildcardToCIDR($ipString);
            if ($cidr) {
                return ['start' => $cidr, 'end' => null];
            }
        }

        // Single IP address
        if (filter_var($ipString, FILTER_VALIDATE_IP)) {
            return ['start' => $ipString, 'end' => null];
        }

        return null;
    }

    /**
     * Convert wildcard notation to CIDR
     */
    private function wildcardToCIDR(string $wildcard): ?string
    {
        // Convert "192.168.1.*" to "192.168.1.0/24"
        $parts = explode('.', $wildcard);
        $wildcardCount = 0;

        for ($i = count($parts) - 1; $i >= 0; $i--) {
            if ($parts[$i] === '*') {
                $parts[$i] = '0';
                $wildcardCount++;
            } else {
                break;
            }
        }

        if ($wildcardCount === 0) {
            return null;
        }

        $ip = implode('.', $parts);
        $prefix = 32 - ($wildcardCount * 8);

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return $ip . '/' . $prefix;
        }

        return null;
    }

    /**
     * Convert binary representation back to inet format
     */
    private function binaryToInet(string $binary, int $version): ?string
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
