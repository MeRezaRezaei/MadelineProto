<?php

declare(strict_types=1);

namespace MeRezaRezaei\LaravelTelegram\MTProto;

/**
 * Stateless Session Data Transfer Object.
 * Holds cryptographic keys and connection context for a single Telegram MTProto session.
 */
class SessionData
{
    public function __construct(
        public int $dcId,
        public string $authKey,
        public int $serverTimeDelta = 0,
        public int $seqNo = 0,
        public ?int $userId = null
    ) {}

    public function toArray(): array
    {
        return [
            'dc_id' => $this->dcId,
            'auth_key' => base64_encode($this->authKey),
            'server_time_delta' => $this->serverTimeDelta,
            'seq_no' => $this->seqNo,
            'user_id' => $this->userId,
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            dcId: (int)($data['dc_id'] ?? 2),
            authKey: isset($data['auth_key']) ? base64_decode($data['auth_key']) : '',
            serverTimeDelta: (int)($data['server_time_delta'] ?? 0),
            seqNo: (int)($data['seq_no'] ?? 0),
            userId: isset($data['user_id']) ? (int)$data['user_id'] : null
        );
    }
}
