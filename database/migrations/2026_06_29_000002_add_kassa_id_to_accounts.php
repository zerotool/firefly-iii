<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddKassaIdToAccounts extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('accounts', 'kassa_id')) {
            return;
        }

        Schema::table(
            'accounts',
            function (Blueprint $table) {
                $table->string('kassa_id', 64)->nullable()->after('iban');
                $table->index('kassa_id', 'accounts_kassa_id_index');
            }
        );
    }

    public function down(): void
    {
        if (!Schema::hasColumn('accounts', 'kassa_id')) {
            return;
        }

        Schema::table(
            'accounts',
            function (Blueprint $table) {
                $table->dropIndex('accounts_kassa_id_index');
                $table->dropColumn('kassa_id');
            }
        );
    }
}
