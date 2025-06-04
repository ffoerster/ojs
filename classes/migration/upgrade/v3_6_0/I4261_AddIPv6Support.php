<?php

/**
 * @file classes/migration/upgrade/v3_6_0/I4261_AddIPv6Support.php
 *
 * Copyright (c) 2014-2021 Simon Fraser University
 * Copyright (c) 2003-2021 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class I4261_AddIPv6Support
 *
 * @brief Add IPv6 support to institution IP ranges
 */

namespace APP\migration\upgrade\v3_6_0;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PKP\migration\Migration;

class I4261_AddIPv6Support extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Check if columns already exist to avoid duplicate migrations
        if (Schema::hasColumn('institution_ip', 'ip_version')) {
            return; // Migration already applied
        }

        // Add new columns for IPv6 support
        Schema::table('institution_ip', function (Blueprint $table) {
            $table->tinyInteger('ip_version')->default(4)->after('ip_string')->comment('IP version: 4 for IPv4, 6 for IPv6');
            $table->binary('ip_start_binary', 16)->nullable()->after('ip_end')->comment('Binary representation of IP start (supports both IPv4 and IPv6)');
            $table->binary('ip_end_binary', 16)->nullable()->after('ip_start_binary')->comment('Binary representation of IP end (supports both IPv4 and IPv6)');
        });

        // Add indexes for better performance
        Schema::table('institution_ip', function (Blueprint $table) {
            $table->index(['ip_version'], 'institution_ip_version');
            $table->index(['ip_start_binary'], 'institution_ip_start_binary');
            $table->index(['ip_end_binary'], 'institution_ip_end_binary');
        });

        // Migrate existing IPv4 data to new binary format
        $this->migrateExistingIPv4Data();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('institution_ip', function (Blueprint $table) {
            $table->dropIndex('institution_ip_version');
            $table->dropIndex('institution_ip_start_binary');
            $table->dropIndex('institution_ip_end_binary');
            $table->dropColumn(['ip_version', 'ip_start_binary', 'ip_end_binary']);
        });
    }

    /**
     * Migrate existing IPv4 data to the new binary format
     */
    private function migrateExistingIPv4Data(): void
    {
        $ipRanges = DB::table('institution_ip')->get();

        foreach ($ipRanges as $ipRange) {
            $ipStartBinary = null;
            $ipEndBinary = null;

            if ($ipRange->ip_start !== null) {
                // Convert IPv4 long to binary format (16 bytes with IPv4-mapped IPv6 prefix)
                $ipStartBinary = $this->ipv4ToBinary($ipRange->ip_start);
            }

            if ($ipRange->ip_end !== null) {
                $ipEndBinary = $this->ipv4ToBinary($ipRange->ip_end);
            }

            DB::table('institution_ip')
                ->where('institution_ip_id', $ipRange->institution_ip_id)
                ->update([
                    'ip_version' => 4,
                    'ip_start_binary' => $ipStartBinary,
                    'ip_end_binary' => $ipEndBinary
                ]);
        }
    }

    /**
     * Convert IPv4 long integer to 16-byte binary format ::ffff:x.x.x.x
     */
    private function ipv4ToBinary(int $ipLong): string
    {
        $ipv4Bytes = pack('N', $ipLong);
        return str_repeat("\x00", 10) . "\xff\xff" . $ipv4Bytes;
    }
}
