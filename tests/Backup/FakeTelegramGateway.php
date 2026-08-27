<?php declare(strict_types=1);
namespace danog\MadelineProto\Backup;

/**
 * In-memory TelegramGateway used by the pipeline tests (no live account).
 * Records uploads and alerts so later tasks can assert on what was sent.
 */
final class FakeTelegramGateway implements TelegramGateway
{
    private int $nextDocumentId = 0;
    private ?int $latestMessageId = null;
    private int $nextAlertId = 5;
    private array $alerts = [];
    private ?string $lastAlertText = null;
    private $lastAlertPeer = null;

    public function createChannel(string $title, string $about): array
    {
        return ['id' => 1, 'access_hash' => 0];
    }

    public function createBotViaBotFather(string $displayName, string $botUsername): string
    {
        return '12345:fake';
    }

    public function addBotToChannel(int $channelId, string $botUsername): void
    {
    }

    public function sendDocument(int $channelId, string $partPath, int $index, int $total): int
    {
        $this->nextDocumentId++;
        $this->latestMessageId = $this->nextDocumentId;

        return $this->nextDocumentId;
    }

    public function getLatestMessageId(int $channelId): ?int
    {
        return $this->latestMessageId;
    }

    public function sendMessageToPeer(int|string $peer, string $text): int
    {
        $this->alerts[] = ['peer' => $peer, 'text' => $text];
        $this->lastAlertText = $text;
        $this->lastAlertPeer = $peer;

        $id = $this->nextAlertId;
        $this->nextAlertId++;

        return $id;
    }

    public function setLatestMessageId(int $id): void
    {
        $this->latestMessageId = $id;
    }

    public function alertSent(): bool
    {
        return $this->alerts !== [];
    }

    public function lastAlert(): string
    {
        return $this->lastAlertText ?? '';
    }
}
