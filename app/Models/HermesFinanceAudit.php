<?php

declare(strict_types=1);

namespace FireflyIII\Models;

use Illuminate\Database\Eloquent\Model;

class HermesFinanceAudit extends Model
{
    /** @var array Fields that can be filled */
    protected $fillable
        = [
            'user_id',
            'source',
            'source_type',
            'source_id',
            'source_hash',
            'idempotency_key',
            'action',
            'mode',
            'status',
            'request_text',
            'request_payload',
            'resolved_payload',
            'result_payload',
            'error_message',
            'preview_token_hash',
            'journal_ids',
        ];

    /** @var array Attributes to cast */
    protected $casts
        = [
            'created_at'       => 'datetime',
            'updated_at'       => 'datetime',
            'request_payload'  => 'array',
            'resolved_payload' => 'array',
            'result_payload'   => 'array',
            'journal_ids'      => 'array',
        ];
}
