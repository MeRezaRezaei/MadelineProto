<?php declare(strict_types=1);

/**
 * This file is part of MadelineProto.
 * MadelineProto is free software: you can redistribute it and/or modify it under the terms of the GNU Affero General Public License as published by the Free Software Foundation, either version 3 of the License, or (at your option) any later version.
 * MadelineProto is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
 * See the GNU Affero General Public License for more details.
 * You should have received a copy of the GNU General Public License along with MadelineProto.
 * If not, see <http://www.gnu.org/licenses/>.
 *
 * @author    Daniil Gentili <daniil@daniil.it>
 * @copyright 2016-2025 Daniil Gentili <daniil@daniil.it>
 * @license   https://opensource.org/licenses/AGPL-3.0 AGPLv3
 * @link https://docs.madelineproto.xyz MadelineProto documentation
 */

namespace danog\MadelineProto\Test;

use danog\MadelineProto\Db\Migrations;
use danog\MadelineProto\Db\PdoDriver;
use danog\MadelineProto\Sync\SyncTargets;
use PHPUnit\Framework\TestCase;

class SyncTargetsTest extends TestCase
{
    private SyncTargets $targets;

    protected function setUp(): void
    {
        $driver = new PdoDriver('sqlite::memory:');
        (new Migrations($driver))->migrate();
        $this->targets = new SyncTargets($driver);
    }

    public function testAddAndIsTarget(): void
    {
        $since = time() - 31557600;
        $this->targets->add(100, 'channel', $since);

        $this->assertTrue($this->targets->isTarget(100));
        $this->assertSame($since, $this->targets->historySince(100));
        $this->assertFalse($this->targets->isTarget(999));
    }

    public function testNullHistorySinceMeansAllTime(): void
    {
        $this->targets->add(200, 'group', null);
        $this->assertNull($this->targets->historySince(200));
    }

    public function testDisabledTargetIsNotATarget(): void
    {
        $this->targets->add(300, 'private_chat');
        $this->targets->setEnabled(300, false);
        $this->assertFalse($this->targets->isTarget(300));
    }

    public function testRemove(): void
    {
        $this->targets->add(400, 'channel');
        $this->targets->remove(400);
        $this->assertFalse($this->targets->isTarget(400));
    }

    public function testListEnabledOnlyReturnsEnabled(): void
    {
        $this->targets->add(500, 'channel', 111);
        $this->targets->add(501, 'group');
        $this->targets->add(502, 'group');
        $this->targets->setEnabled(502, false);

        $ids = array_column($this->targets->listEnabled(), 'peer_id');
        $this->assertSame([500, 501], $ids);
    }
}
