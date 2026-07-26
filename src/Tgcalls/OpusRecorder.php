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

namespace danog\MadelineProto\Tgcalls;

use Amp\ByteStream\WritableStream;
use danog\MadelineProto\LocalFile;
use danog\MadelineProto\OggWriter;
use Webrtc\Codecs\EncodedPacket;
use Webrtc\RTP\MediaStreamTrack\RemoteStreamTrack;
use Revolt\EventLoop;
use Throwable;

use function Amp\File\openFile;

/**
 * Writes the audio of a remote WebRTC track to an OGG OPUS stream.
 *
 * The receiver is put in raw mode, so the OPUS frames that arrive over RTP are muxed into OGG
 * exactly as they were sent, with no decode/encode round trip: the recording is bit-identical to
 * what the peer transmitted, it costs almost nothing, and it needs no codec library (and therefore
 * no FFI extension). The resulting file can be played back by MadelineProto as-is.
 *
 * @internal
 */
final class OpusRecorder
{
    /** How often the frame queue is drained. */
    private const POLL_INTERVAL = 0.02;
    /** Granule position increment assumed for a frame whose duration we cannot derive. */
    private const DEFAULT_FRAME_SAMPLES = 960;
    /** An OPUS frame can never be longer than 120ms, i.e. 5760 samples at 48kHz. */
    private const MAX_FRAME_SAMPLES = 5760;

    private OggWriter $writer;
    private ?RemoteStreamTrack $track = null;
    private ?string $watcher = null;
    private bool $closed = false;
    /** RTP timestamp of the previously written frame, used to derive granule increments. */
    private ?int $lastTimestamp = null;

    public readonly int $streamId;
    public readonly ?LocalFile $file;

    public function __construct(LocalFile|WritableStream $out, ?int $streamId = null, string $description = 'incoming audio stream')
    {
        if ($out instanceof LocalFile) {
            $this->file = $out;
            $out = openFile($out->file, 'w');
        } else {
            $this->file = null;
        }
        $this->streamId = $streamId ?? random_int(-(2**31), (2**31)-1);
        $this->writer = new OggWriter($out, $this->streamId);
        $this->writer->writeHeader(1, OpusPlaybackTrack::CLOCK_RATE, $description);
    }

    /**
     * Attach the remote track whose audio should be recorded.
     */
    public function setTrack(RemoteStreamTrack $track): void
    {
        if ($this->closed) {
            return;
        }
        $this->track = $track;
        $this->watcher ??= EventLoop::repeat(self::POLL_INTERVAL, $this->drain(...));
    }

    private function drain(): void
    {
        if ($this->closed || $this->track === null) {
            return;
        }
        while (true) {
            $frame = $this->track->receiveData();
            if ($frame === null) {
                return;
            }
            if (!$frame instanceof EncodedPacket) {
                // The receiver was not put in raw mode: decoded PCM cannot be muxed into OGG OPUS.
                continue;
            }
            $timestamp = $frame->getTimestamp();
            // The OGG granule position must advance by the duration of each frame, which the RTP
            // timestamps give us directly; the first frame has no predecessor to measure against.
            $granule = self::DEFAULT_FRAME_SAMPLES;
            if ($this->lastTimestamp !== null) {
                $delta = ($timestamp - $this->lastTimestamp) & 0xFFFFFFFF;
                if ($delta > 0 && $delta <= self::MAX_FRAME_SAMPLES) {
                    $granule = $delta;
                }
            }
            $this->lastTimestamp = $timestamp;
            $this->writer->writeChunk($frame->getData(), $granule, false);
        }
    }

    /**
     * Write one bare OPUS frame, as delivered by the legacy libtgvoip engine.
     *
     * libtgvoip always uses 60ms frames, so the granule advances by a fixed amount.
     */
    public function writeOpus(string $frame, int $samples = 2880): void
    {
        if ($this->closed || $frame === '') {
            return;
        }
        $this->writer->writeChunk($frame, $samples, false);
    }

    /**
     * Flush and close the output stream.
     */
    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;
        if ($this->watcher !== null) {
            EventLoop::cancel($this->watcher);
            $this->watcher = null;
        }
        try {
            $this->writer->writeChunk('', 0, true);
        } catch (Throwable) {
        }
        $this->track = null;
    }
}
