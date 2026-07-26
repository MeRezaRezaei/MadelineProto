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

use danog\MadelineProto\Loop\VoIP\DjLoop;
use Webrtc\Codecs\EncodedPacket;
use Webrtc\RTP\Enum\MediaKind;
use Webrtc\RTP\MediaStreamTrack\MediaStreamTrack;

/**
 * An outgoing audio track that emits the pre-encoded OPUS packets produced by a {@see DjLoop}.
 *
 * MadelineProto stores and generates audio as 60ms mono 48kHz OGG OPUS packets, which is exactly
 * what both the tgcalls one-to-one peer and the Telegram group call SFU expect: the packets are
 * therefore passed through to the RTP sender without a decode/encode round trip, which also means
 * that no codec library (and therefore no FFI) is ever needed for playback.
 *
 * Note that {@see self::receiveData()} must never suspend: `RTCRtpSender` polls it from a
 * zero-interval periodic timer, which the event loop happily re-enters while a previous
 * invocation is suspended. Pacing is therefore done by returning `null` until the next 60ms
 * frame is due.
 *
 * @internal
 */
final class OpusPlaybackTrack extends MediaStreamTrack
{
    /** Duration of a single MadelineProto OPUS frame, in seconds. */
    public const FRAME_DURATION = 0.06;
    /** Number of 48kHz samples in a single MadelineProto OPUS frame. */
    public const FRAME_SAMPLES = 2880;
    /** OPUS always uses a 48kHz clock rate on the wire. */
    public const CLOCK_RATE = 48000;

    protected MediaKind $kind = MediaKind::Audio;

    /** Wall clock time at which the next frame is due. */
    private ?float $nextFrame = null;
    /** RTP timestamp (in 48kHz samples) of the next frame. */
    private int $timestamp = 0;

    private bool $muted = true;

    public function __construct(
        private readonly DjLoop $dj,
        private readonly CallInterface $call,
        private readonly ?WebmSource $webm = null,
    ) {
        parent::__construct();
    }

    /**
     * Whether we're currently not transmitting any audio.
     */
    public function isMuted(): bool
    {
        return $this->muted;
    }

    #[\Override]
    public function receiveData(): ?EncodedPacket
    {
        if ($this->ended || $this->call->isCallEnded()) {
            return null;
        }
        $now = microtime(true);
        if ($this->nextFrame === null) {
            $this->nextFrame = $now;
        } elseif ($now < $this->nextFrame) {
            return null;
        } elseif ($now - $this->nextFrame > 1.0) {
            // We fell way behind (the process was suspended, or the call was just resumed):
            // resynchronize instead of bursting out a second of audio.
            $this->nextFrame = $now;
        }

        // Audio coming from a WebM file wins: it has to stay in sync with that file's video.
        $fromWebm = $this->webm?->pullAudio();
        if ($fromWebm !== null) {
            $this->muted = false;
            $this->nextFrame += self::FRAME_DURATION;
            $this->timestamp += self::FRAME_SAMPLES;
            return new EncodedPacket($fromWebm['data'], $this->timestamp);
        }

        $opus = $this->dj->tryPullPacket();
        if ($opus === null) {
            if (!$this->muted) {
                $this->muted = true;
                $this->call->log("Muting outgoing audio in {$this->call}");
            }
            // Keep the frame grid aligned while silent, so that playback resumes in sync.
            $this->nextFrame += self::FRAME_DURATION;
            $this->timestamp += self::FRAME_SAMPLES;
            return null;
        }
        if ($this->muted) {
            $this->muted = false;
            $this->call->log("Unmuting outgoing audio in {$this->call}");
        }

        $packet = new EncodedPacket($opus, $this->timestamp);

        $this->nextFrame += self::FRAME_DURATION;
        $this->timestamp += self::FRAME_SAMPLES;

        return $packet;
    }
}
