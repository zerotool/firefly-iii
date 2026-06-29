<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateHermesFinanceAudits extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('hermes_finance_audits')) {
            return;
        }

        Schema::create(
            'hermes_finance_audits',
            function (Blueprint $table) {
                $table->increments('id');
                $table->timestamps();
                $table->integer('user_id', false, true)->nullable();
                $table->string('source', 64);
                $table->string('source_id', 191)->nullable();
                $table->string('idempotency_key', 191)->nullable();
                $table->string('action', 64);
                $table->string('mode', 32);
                $table->string('status', 32);
                $table->text('request_text')->nullable();
                $table->mediumText('request_payload')->nullable();
                $table->mediumText('resolved_payload')->nullable();
                $table->mediumText('result_payload')->nullable();
                $table->text('error_message')->nullable();
                $table->string('preview_token_hash', 64)->nullable();
                $table->text('journal_ids')->nullable();

                $table->foreign('user_id')->references('id')->on('users')->onDelete('set null');
                $table->unique(['source', 'idempotency_key'], 'hfa_source_idempotency_unique');
                $table->index(['source', 'source_id'], 'hfa_source_id_index');
                $table->index(['action', 'mode', 'status'], 'hfa_action_mode_status_index');
            }
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('hermes_finance_audits');
    }
}
