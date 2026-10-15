<?php

/**
 * Fail-closed boundary for GachtheFast incoming bank-transfer / top-up processing.
 *
 * STATUS: INTENTIONALLY NOT IMPLEMENTED.
 *
 * No GachtheFast bank-transfer contract has been confirmed. There is no document in the
 * materials provided that establishes an endpoint, host, scheme, authentication/signature
 * scheme, request or response schema, pagination, transfer-reference rule, account
 * ownership, status meanings or settlement behaviour for this flow. None of those may be
 * invented, so this boundary deliberately:
 *   - makes no provider request of any kind (there is no HTTP call in this file);
 *   - stores nothing and credits nothing;
 *   - exposes no provider field names, no reference format and no matching rule;
 *   - reports itself as unavailable, so every caller must behave as disabled.
 *
 * When the official contract is supplied and reviewed, this class is the single place that
 * receives a real implementation, together with a fresh design review and tests. Until then
 * `isAvailable()` returns false and every operation refuses.
 *
 * There is deliberately NO bank counterpart to GtfCredit: the only money-moving routine in
 * this integration is `GtfCredit::applyWithin()`, and nothing here may call it.
 */

if (!defined('IN_SITE')) {
    http_response_code(404);
    exit;
}

final class GtfBankService
{
    private $db;
    private $config;

    public function __construct(PDO $db, array $config)
    {
        // Kept for signature stability with the entry points and for the future reviewed
        // implementation; the stub itself reads neither of them.
        $this->db = $db;
        $this->config = $config;
    }

    /**
     * Always false: the bank flow has no confirmed contract. A future implementation must
     * keep every entry point gated on this method.
     */
    public function isAvailable(): bool
    {
        return false;
    }

    public function unavailableReason(): string
    {
        return 'Chưa có hợp đồng API nạp ngân hàng GachtheFast nào được xác nhận, nên tính năng này bị khoá và không thể gọi nhà cung cấp hay cộng tiền.';
    }

    /** @throws DomainException always, while no contract is confirmed */
    public function poll(int $limit = 0): array
    {
        throw $this->refusal();
    }

    /** @throws DomainException always, while no contract is confirmed */
    public function credit(int $eventId, int $userId, string $gross): string
    {
        throw $this->refusal();
    }

    /** @throws DomainException always, while no contract is confirmed */
    public function adminCredit(int $eventId, int $userId, string $gross, string $note): string
    {
        throw $this->refusal();
    }

    /** @throws DomainException always, while no contract is confirmed */
    public function adminResolve(int $eventId, string $status, string $note): void
    {
        throw $this->refusal();
    }

    /** @throws DomainException always, while no contract is confirmed */
    public function recent(int $limit = 50): array
    {
        throw $this->refusal();
    }

    /** @throws DomainException always, while no contract is confirmed */
    public function reviewQueue(int $limit = 50): array
    {
        throw $this->refusal();
    }

    /** @throws DomainException always, while no contract is confirmed */
    public function history(int $userId, int $limit = 20): array
    {
        throw $this->refusal();
    }

    private function refusal(): DomainException
    {
        return new DomainException($this->unavailableReason());
    }
}
