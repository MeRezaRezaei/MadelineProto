<?php declare(strict_types=1);
namespace danog\MadelineProto\Backup;

use PHPUnit\Framework\TestCase;

final class GatewayContractTest extends TestCase
{
    public function testFakeImplementsGateway(): void
    {
        $fake = new FakeTelegramGateway();
        $this->assertInstanceOf(TelegramGateway::class, $fake);
        $ch = $fake->createChannel('t', 'a');
        $this->assertArrayHasKey('id', $ch);
        $token = $fake->createBotViaBotFather('n', 'u_bot');
        $this->assertNotEmpty($token);
        $fake->addBotToChannel(1, 'u_bot');
        $id = $fake->sendDocument(1, '/tmp/x', 1, 1);
        $this->assertSame(1, $id);
        $this->assertSame(1, $fake->getLatestMessageId(1));
        $this->assertSame(5, $fake->sendMessageToPeer('me', 'hi'));
    }
}
