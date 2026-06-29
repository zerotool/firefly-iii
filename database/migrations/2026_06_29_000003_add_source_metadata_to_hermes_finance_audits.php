<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddSourceMetadataToHermesFinanceAudits extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('hermes_finance_audits')) {
            return;
        }

        Schema::table(
            'hermes_finance_audits',
            function (Blueprint $table) {
                if (!Schema::hasColumn('hermes_finance_audits', 'source_type')) {
                    $table->string('source_type', 32)->nullable()->after('source');
                }
                if (!Schema::hasColumn('hermes_finance_audits', 'source_hash')) {
                    $table->string('source_hash', 128)->nullable()->after('source_id');
                }
            }
        );

        Schema::table(
            'hermes_finance_audits',
            function (Blueprint $table) {
                $table->index(['source_type', 'source_id'], 'hfa_source_type_id_index');
                $table->index('source_hash', 'hfa_source_hash_index');
            }
        );
    }

    public function down(): void
    {
        if (!Schema::hasTable('hermes_finance_audits')) {
            return;
        }

        Schema::table(
            'hermes_finance_audits',
            function (Blueprint $table) {
                $table->dropIndex('hfa_source_type_id_index');
                $table->dropIndex('hfa_source_hash_index');
            }
        );

        Schema::table(
            'hermes_finance_audits',
            function (Blueprint $table) {
                if (Schema::hasColumn('hermes_finance_audits', 'source_hash')) {
                    $table->dropColumn('source_hash');
                }
                if (Schema::hasColumn('hermes_finance_audits', 'source_type')) {
                    $table->dropColumn('source_type');
                }
            }
        );
    }
}
