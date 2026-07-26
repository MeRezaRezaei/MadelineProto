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

// IMPORTANT NOTE: Please keep the above copyright notice intact if copying or rewriting this file in another language.

namespace danog\MadelineProto\Tgcalls;

use Amp\ByteStream\ReadableStream;
use Amp\ByteStream\WritableStream;
use danog\MadelineProto\Exception;
use danog\MadelineProto\GroupCallController;
use danog\MadelineProto\LocalFile;
use danog\MadelineProto\Logger;
use danog\MadelineProto\Loop\VoIP\DjLoop;
use danog\MadelineProto\RemoteUrl;
use Webrtc\DTLS\RTCDtlsTransport;
use Webrtc\RTP\Enum\MediaKind;
use Webrtc\RTP\MediaStreamTrack\MediaStreamTrack;
use Webrtc\RTP\MediaStreamTrack\RemoteStreamTrack;
use Webrtc\SDP\Enum\SDPDirections;
use Webrtc\SDP\RTCSessionDescription;
use Webrtc\Webrtc\Enum\ConnectionState;
use Webrtc\Webrtc\RTCPeerConnection;
use Throwable;

use function React\Async\await;

/**
 * WebRTC engine of a Telegram group call.
 *
 * The Telegram SFU does not exchange SDP: the client sends a small JSON join payload describing its
 * ICE/DTLS parameters and outgoing audio SSRC to
 * [phone.joinGroupCall](https://core.telegram.org/method/phone.joinGroupCall), and gets back the
 * SFU's transport parameters in
 * [updateGroupCallConnection](https://core.telegram.org/constructor/updateGroupCallConnection).
 * All media is bundled over that single transport; every participant is identified by their
 * [groupCallParticipant](https://core.telegram.org/constructor/groupCallParticipant).`source` SSRC.
 *
 * Since the SFU never renegotiates, new participants are wired in with a purely local
 * offer/answer round: {@see GroupSdp} rebuilds the SFU's answer from the same transport parameters,
 * adding one receive-only m-line per participant.
 *
 * @internal
 */
final class GroupConnection
{
    private RTCPeerConnection $peerConnection;
    private OpusPlaybackTrack $outgoingAudio;
    private Vp8PlaybackTrack $outgoingVideo;
    private WebmSource $webm;

    /** Our own outgoing audio SSRC, as an unsigned 32-bit integer. */
    private int $audioSsrc;
    /** Our own outgoing video SSRC, as an unsigned 32-bit integer. */
    private int $videoSsrc;
    /** Retransmission SSRC paired with the video one, as tgcalls always declares a FID group. */
    private int $videoRtxSsrc;

    /** Transport parameters returned by the SFU. */
    private ?array $transport = null;

    /**
     * Remote audio SSRCs currently wired to a transceiver, as `mid => ssrc`.
     *
     * @var array<string, int>
     */
    private array $sources = [];
    /**
     * Remote audio SSRCs, in the order their receive-only transceivers were created.
     *
     * @var list<int>
     */
    private array $orderedSources = [];
    /**
     * Recorders for incoming audio, indexed by the unsigned SSRC of the participant.
     *
     * @var array<int, OpusRecorder>
     */
    private array $recorders = [];
    /**
     * Output files/streams requested before the corresponding participant's track showed up.
     *
     * @var array<int, LocalFile|WritableStream>
     */
    private array $pendingOutputs = [];

    private bool $closed = false;
    private bool $renegotiating = false;
    private bool $renegotiatePending = false;

    public function __construct(
        private readonly GroupCallController $call,
        DjLoop $dj,
    ) {
        $this->peerConnection = new RTCPeerConnection(['iceServers' => []]);
        $this->webm = new WebmSource($call);
        $this->outgoingAudio = new OpusPlaybackTrack($dj, $call, $this->webm);
        $transceiver = $this->peerConnection->addTransceiver($this->outgoingAudio, SDPDirections::sendonly);
        $this->audioSsrc = $transceiver->getSender()->getSsrc();
        $this->outgoingVideo = new Vp8PlaybackTrack($this->webm, $call);
        $videoTransceiver = $this->peerConnection->addTransceiver($this->outgoingVideo, SDPDirections::sendonly);
        $this->videoSsrc = $videoTransceiver->getSender()->getSsrc();
        $this->videoRtxSsrc = $videoTransceiver->getSender()->getRtxSsrc();

        $this->peerConnection->on('track', $this->onTrack(...));
        $this->peerConnection->on('connectionstatechange', function (): void {
            $state = $this->peerConnection->getConnectionState();
            $this->call->log("WebRTC connection state of {$this->call} is now {$state->name}");
            if ($state === ConnectionState::failed) {
                $this->call->onConnectionFailed();
            }
        });
    }

    /**
     * Our own outgoing audio SSRC, in the signed form used by the API.
     */
    public function getAudioSource(): int
    {
        return GroupSdp::toSignedSsrc($this->audioSsrc);
    }

    /**
     * Generate the JSON join payload for
     * [phone.joinGroupCall](https://core.telegram.org/method/phone.joinGroupCall).
     */
    public function buildJoinPayload(): string
    {
        $description = await($this->peerConnection->createOffer());
        await($this->peerConnection->setLocalDescription($description));

        $transceiver = $this->peerConnection->getTransceivers()[0]
            ?? throw new Exception('No local transceiver was created!');
        $dtls = $transceiver->getDtlsTransport();
        if (!$dtls instanceof RTCDtlsTransport) {
            throw new Exception('The local transceiver has no DTLS transport!');
        }
        $ice = $dtls->getIceTransport()->getIceGatherer()->getLocalParameters();

        $fingerprints = [];
        foreach ($dtls->getLocalParameters()->fingerprints as $fingerprint) {
            $fingerprints[] = [$fingerprint->algorithm, $fingerprint->value];
        }

        return GroupSdp::buildJoinPayload(
            (string) $ice->usernameFragment,
            (string) $ice->password,
            $fingerprints,
            $this->audioSsrc,
            // tgcalls declares one FID group per simulcast layer; we publish a single layer.
            [['semantics' => 'FID', 'ssrcs' => [$this->videoSsrc, $this->videoRtxSsrc]]],
        );
    }

    /**
     * Play a WebM file, transmitting its VP8 video and OPUS audio into the call.
     */
    public function playVideo(LocalFile|RemoteUrl|ReadableStream $file): void
    {
        $this->webm->play($file);
    }

    /**
     * Stop transmitting video.
     */
    public function stopVideo(): void
    {
        $this->webm->stop();
    }

    /**
     * Whether we are currently transmitting video.
     */
    public function isPlayingVideo(): bool
    {
        return $this->outgoingVideo->isPlaying();
    }

    /**
     * Apply the transport parameters returned by the SFU.
     */
    public function setTransport(array $transport): void
    {
        $this->transport = $transport;
        $this->renegotiate();
    }

    /**
     * Update the set of participants whose audio we want to receive.
     *
     * @param list<int> $sources Signed SSRCs, as found in `groupCallParticipant.source`.
     */
    public function setRemoteSources(array $sources): void
    {
        if ($this->transport === null || $this->closed) {
            return;
        }
        $wanted = [];
        foreach ($sources as $source) {
            $unsigned = GroupSdp::toUnsignedSsrc($source);
            if ($unsigned !== 0 && $unsigned !== $this->audioSsrc) {
                $wanted[$unsigned] = true;
            }
        }
        // The SFU never removes m-lines, so we only ever have to add the missing ones.
        $missing = array_diff_key($wanted, array_flip($this->orderedSources));
        if ($missing === []) {
            return;
        }
        foreach (array_keys($missing) as $ssrc) {
            $this->peerConnection->addTransceiver(MediaKind::Audio, SDPDirections::recvonly);
            $this->orderedSources[] = $ssrc;
        }
        $this->renegotiate();
    }

    /**
     * Record the audio of a specific participant.
     *
     * @param int $source The participant's signed SSRC.
     */
    public function setOutput(int $source, LocalFile|WritableStream $file): void
    {
        $ssrc = GroupSdp::toUnsignedSsrc($source);
        ($this->recorders[$ssrc] ?? null)?->close();
        unset($this->recorders[$ssrc]);
        $this->pendingOutputs[$ssrc] = $file;
        foreach ($this->peerConnection->getReceivers() as $receiver) {
            $track = $receiver->getTrack();
            if ($track instanceof RemoteStreamTrack && $this->trackSsrc($track) === $ssrc) {
                $this->attachRecorder($ssrc, $track);
                return;
            }
        }
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;
        foreach ($this->recorders as $recorder) {
            $recorder->close();
        }
        $this->recorders = [];
        try {
            $this->outgoingAudio->stop();
            $this->outgoingVideo->stop();
            $this->webm->stop();
            $this->peerConnection->close();
        } catch (Throwable $e) {
            $this->call->log("Got $e while closing the WebRTC connection of {$this->call}");
        }
    }

    /**
     * Re-run the local offer/answer exchange against the (unchanging) SFU transport.
     */
    private function renegotiate(): void
    {
        if ($this->transport === null || $this->closed) {
            return;
        }
        if ($this->renegotiating) {
            // A negotiation is already in flight and suspended on a promise: let it pick up our
            // changes when it is done, instead of interleaving two offer/answer exchanges.
            $this->renegotiatePending = true;
            return;
        }
        $this->renegotiating = true;
        try {
            /** @psalm-suppress TypeDoesNotContainType, RedundantCondition the awaited calls may set these */
            do {
                $this->renegotiatePending = false;
                $offer = await($this->peerConnection->createOffer());
                await($this->peerConnection->setLocalDescription($offer));
                // Fix up the mid => ssrc mapping now that mids are final.
                $this->rebuildSourceMap($offer->getSdp());
                $answer = GroupSdp::buildAnswer($offer->getSdp(), $this->transport, $this->sources);
                await($this->peerConnection->setRemoteDescription(new RTCSessionDescription($answer, 'answer')));
            } while ($this->renegotiatePending && !$this->closed);
        } catch (Throwable $e) {
            $this->call->log("Got $e while negotiating the WebRTC connection of {$this->call}", Logger::ERROR);
        } finally {
            $this->renegotiating = false;
        }
    }

    /**
     * Re-key the `mid => ssrc` map against the mids the local stack actually assigned.
     */
    private function rebuildSourceMap(string $offer): void
    {
        $ssrcs = $this->orderedSources;
        $mids = [];
        foreach (explode("\n", str_replace("\r\n", "\n", $offer)) as $line) {
            $line = trim($line);
            if (str_starts_with($line, 'a=mid:')) {
                $mids[] = substr($line, 6);
            }
        }
        // The first m-line is always our own outgoing audio.
        array_shift($mids);
        $rebuilt = [];
        foreach ($mids as $index => $mid) {
            if (isset($ssrcs[$index])) {
                $rebuilt[$mid] = $ssrcs[$index];
            }
        }
        $this->sources = $rebuilt;
    }

    private function onTrack(MediaStreamTrack $track): void
    {
        if (!$track instanceof RemoteStreamTrack || $track->getKind() !== MediaKind::Audio) {
            return;
        }
        foreach ($this->peerConnection->getReceivers() as $receiver) {
            $receiver->setRawMode(true);
        }
        $ssrc = $this->trackSsrc($track);
        if ($ssrc === null) {
            return;
        }
        $this->call->log("Got the audio track of source $ssrc in {$this->call}", Logger::VERBOSE);
        $this->call->onIncomingSource(GroupSdp::toSignedSsrc($ssrc));
        if (isset($this->pendingOutputs[$ssrc])) {
            $this->attachRecorder($ssrc, $track);
        }
    }

    private function attachRecorder(int $ssrc, RemoteStreamTrack $track): void
    {
        $file = $this->pendingOutputs[$ssrc] ?? null;
        if ($file === null) {
            return;
        }
        unset($this->pendingOutputs[$ssrc]);
        $recorder = new OpusRecorder($file, description: "incoming audio stream of source $ssrc");
        $recorder->setTrack($track);
        $this->recorders[$ssrc] = $recorder;
    }

    /**
     * Resolve the SSRC a remote track belongs to, using the transceiver it was created for.
     */
    private function trackSsrc(RemoteStreamTrack $track): ?int
    {
        foreach ($this->peerConnection->getTransceivers() as $transceiver) {
            if ($transceiver->getReceiver()->getTrack() !== $track) {
                continue;
            }
            $mid = $transceiver->getMid();
            return $mid !== null ? ($this->sources[$mid] ?? null) : null;
        }
        return null;
    }
}
