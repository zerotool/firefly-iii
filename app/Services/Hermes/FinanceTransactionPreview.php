<?php

declare(strict_types=1);

namespace FireflyIII\Services\Hermes;

use Carbon\Carbon;

class FinanceTransactionPreview
{
    /** @var string */
    private $action;

    /** @var bool */
    private $canApply;

    /** @var array */
    private $payload;

    /** @var array */
    private $resolved;

    /** @var array */
    private $errors;

    /** @var array */
    private $candidates;

    /** @var string|null */
    private $previewToken;

    /** @var Carbon|null */
    private $expiresAt;

    /** @var array */
    private $sourceMetadata;

    /** @var bool */
    private $requiresConfirmation;

    public function __construct(string $action, bool $canApply, array $payload, array $resolved, array $errors = [], array $candidates = [], ?string $previewToken = null, ?Carbon $expiresAt = null, array $sourceMetadata = [], bool $requiresConfirmation = false)
    {
        $this->action               = $action;
        $this->canApply             = $canApply;
        $this->payload              = $payload;
        $this->resolved             = $resolved;
        $this->errors               = $errors;
        $this->candidates           = $candidates;
        $this->previewToken         = $previewToken;
        $this->expiresAt            = $expiresAt;
        $this->sourceMetadata       = $sourceMetadata;
        $this->requiresConfirmation = $requiresConfirmation;
    }

    public function canApply(): bool
    {
        return $this->canApply;
    }

    public function withSourceMetadata(array $sourceMetadata, bool $requiresConfirmation): self
    {
        return new self(
            $this->action,
            $this->canApply,
            $this->payload,
            $this->resolved,
            $this->errors,
            $this->candidates,
            $this->previewToken,
            $this->expiresAt,
            $sourceMetadata,
            $requiresConfirmation
        );
    }

    public function toArray(): array
    {
        return [
            'action'        => $this->action,
            'can_apply'     => $this->canApply,
            'preview_token' => $this->previewToken,
            'expires_at'    => null === $this->expiresAt ? null : $this->expiresAt->toIso8601String(),
            'payload'       => $this->payload,
            'resolved'      => $this->resolved,
            'candidates'    => $this->candidates,
            'errors'        => $this->errors,
            'source_metadata' => $this->sourceMetadata,
            'requires_confirmation' => $this->requiresConfirmation,
        ];
    }
}
