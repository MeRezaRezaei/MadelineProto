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

use Amp\ByteStream\ReadableStream;
use danog\MadelineProto\LocalFile;
use danog\MadelineProto\Logger;
use danog\MadelineProto\Matroska;
use danog\MadelineProto\RemoteUrl;
use Revolt\EventLoop;
use SplQueue;
use Throwable;

/**
 * Plays the VP8 video and OPUS audio of a WebM file, without decoding either.
 *
 * Telegram calls carry exactly the codecs WebM stores, so the frames are demuxed by
 * {@see Matroska} and handed to the RTP senders untouched. Audio and video keep the timestamps
 * they had in the file, which is what keeps them in sync.
 *
 * @internal
 */
final class WebmSource
{
    /** VP8 and every other video codec on the wire use a 90kHz RTP clock. */
    public const VIDEO_CLOCK_RATE = 90000;
    /** OPUS always uses a 48kHz RTP clock. */
    public const AUDIO_CLOCK_RATE = 48000;

    /** How far ahead of playback we buffer, in milliseconds. */
    private const BUFFER_AHEAD_MS = 2000;

    /** Pending VP8 frames, as `['data' => string, 'timestamp' => int, 'keyframe' => bool]`. */
    private SplQueue $video;
    /** Pending OPUS frames, as `['data' => string, 'timestamp' => int]`. */
    private SplQueue $audio;

    private bool $finished = false;
    private bool $stopped = false;
    private bool $reading = false;

    /** Highest source timestamp pushed so far, in milliseconds. */
    private int $bufferedUntilMs = 0;
    /** Playback position, in milliseconds, used to throttle the demuxer. */
    private int $playbackMs = 0;

    public function __construct(private readonly CallInterface $call)
    {
        $this->video = new SplQueue;
        $this->audio = new SplQueue;
    }

    /**
     * Start demuxing a WebM file into the playback queues.
     */
    public function play(LocalFile|RemoteUrl|ReadableStream $file): void
    {
        $this->stopped = false;
        $this->finished = false;
        $this->video = new SplQueue;
        $this->audio = new SplQueue;
        $this->bufferedUntilMs = 0;
        $this->playbackMs = 0;

        EventLoop::queue(function () use ($file): void {
            $this->reading = true;
            try {
                $matroska = new Matroska($file);
                foreach ($matroska->frames as $frame) {
                    if ($this->stopped) {
                        break;
                    }
                    $this->push($frame);
                }
            } catch (Throwable $e) {
                $this->call->log("Could not play the WebM file in {$this->call}: $e", Logger::ERROR);
            } finally {
                $this->reading = false;
                $this->finished = true;
            }
        });
    }

    /**
     * @param array{track: int, codec: string, type: int, data: string, timestamp: int, keyframe: bool} $frame
     */
    private function push(array $frame): void
    {
        $timestampMs = $frame['timestamp'];
        $this->bufferedUntilMs = max($this->bufferedUntilMs, $timestampMs);

        match ($frame['codec']) {
            'V_VP8' => $this->video->enqueue([
                'data' => $frame['data'],
                'timestamp' => (int) ($timestampMs * self::VIDEO_CLOCK_RATE / 1000),
                'keyframe' => $frame['keyframe'],
            ]),
            'A_OPUS' => $this->audio->enqueue([
                'data' => $frame['data'],
                'timestamp' => (int) ($timestampMs * self::AUDIO_CLOCK_RATE / 1000),
            ]),
            default => null,
        };
    }

    /**
     * Whether the demuxer should pause because playback is far enough behind.
     *
     * The generator is driven synchronously, so this is checked by the tracks rather than the
     * reader itself; it simply keeps memory bounded for long files.
     */
    public function shouldThrottle(): bool
    {
        return $this->bufferedUntilMs - $this->playbackMs > self::BUFFER_AHEAD_MS;
    }

    /**
     * Report how far playback has progressed, in milliseconds.
     */
    public function setPlaybackPosition(int $milliseconds): void
    {
        $this->playbackMs = max($this->playbackMs, $milliseconds);
    }

    /**
     * @return array{data: string, timestamp: int, keyframe: bool}|null
     */
    public function pullVideo(): ?array
    {
        if ($this->video->isEmpty()) {
            return null;
        }
        /** @var array{data: string, timestamp: int, keyframe: bool} */
        return $this->video->dequeue();
    }

    /**
     * @return array{data: string, timestamp: int}|null
     */
    public function pullAudio(): ?array
    {
        if ($this->audio->isEmpty()) {
            return null;
        }
        /** @var array{data: string, timestamp: int} */
        return $this->audio->dequeue();
    }

    public function hasVideo(): bool
    {
        return !$this->video->isEmpty();
    }

    public function hasAudio(): bool
    {
        return !$this->audio->isEmpty();
    }

    /**
     * Whether the file was fully read and both queues are drained.
     */
    public function isExhausted(): bool
    {
        return $this->finished && $this->video->isEmpty() && $this->audio->isEmpty();
    }

    /**
     * Stop playback and drop everything still buffered.
     */
    public function stop(): void
    {
        $this->stopped = true;
        $this->video = new SplQueue;
        $this->audio = new SplQueue;
    }
}
