<?php

declare(strict_types=1);

namespace MeRezaRezaei\LaravelTelegram\MTProto\Transport;

use RuntimeException;

/**
 * Clean SOCKS5 proxy socket connector for MTProto network connections.
 */
class Socks5Proxy
{
    /**
     * Connects to a target host through a SOCKS5 proxy server.
     *
     * @param string $proxyHost SOCKS5 server IP/host
     * @param int $proxyPort SOCKS5 server port (e.g. 1080, 9050)
     * @param string $targetHost Telegram DC IP
     * @param int $targetPort Telegram DC port (e.g. 443, 80)
     * @param string|null $username Optional SOCKS5 username
     * @param string|null $password Optional SOCKS5 password
     * @param float $timeout Timeout in seconds
     * @return resource Stream socket resource
     */
    public static function connect(
        string $proxyHost,
        int $proxyPort,
        string $targetHost,
        int $targetPort,
        ?string $username = null,
        ?string $password = null,
        float $timeout = 10.0
    ) {
        $socket = @fsockopen($proxyHost, $proxyPort, $errno, $errstr, $timeout);
        if (!$socket) {
            throw new RuntimeException("Failed to connect to SOCKS5 proxy {$proxyHost}:{$proxyPort}: {$errstr} ({$errno})");
        }

        stream_set_timeout($socket, (int)$timeout);

        // 1. Initial greeting (Negotiation)
        if ($username !== null && $password !== null) {
            fwrite($socket, "\x05\x02\x00\x02");
        } else {
            fwrite($socket, "\x05\x01\x00");
        }

        $response = fread($socket, 2);
        if (strlen($response) < 2 || $response[0] !== "\x05") {
            fclose($socket);
            throw new RuntimeException("Invalid SOCKS5 handshake response from proxy.");
        }

        $authMethod = ord($response[1]);

        // 2. Authenticate if required
        if ($authMethod === 0x02) {
            if ($username === null || $password === null) {
                fclose($socket);
                throw new RuntimeException("Proxy requires username/password authentication.");
            }

            $authPayload = "\x01" . chr(strlen($username)) . $username . chr(strlen($password)) . $password;
            fwrite($socket, $authPayload);

            $authResp = fread($socket, 2);
            if (strlen($authResp) < 2 || $authResp[1] !== "\x00") {
                fclose($socket);
                throw new RuntimeException("SOCKS5 proxy authentication failed.");
            }
        } elseif ($authMethod !== 0x00) {
            fclose($socket);
            throw new RuntimeException("No acceptable SOCKS5 authentication methods.");
        }

        // 3. Connect request (CMD: 0x01 CONNECT, RSV: 0x00, ATYP: 0x01 IPv4 or 0x03 Domain)
        $ip = filter_var($targetHost, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4);
        if ($ip !== false) {
            $dest = "\x01" . inet_pton($ip);
        } else {
            $dest = "\x03" . chr(strlen($targetHost)) . $targetHost;
        }

        $request = "\x05\x01\x00" . $dest . pack('n', $targetPort);
        fwrite($socket, $request);

        $reply = fread($socket, 4);
        if (strlen($reply) < 4 || $reply[1] !== "\x00") {
            fclose($socket);
            $status = isset($reply[1]) ? ord($reply[1]) : -1;
            throw new RuntimeException("SOCKS5 connection to {$targetHost}:{$targetPort} failed with code {$status}.");
        }

        // Read remaining bound address bytes based on ATYP
        $atyp = ord($reply[3]);
        if ($atyp === 0x01) { // IPv4 (4 bytes IP + 2 bytes Port)
            fread($socket, 6);
        } elseif ($atyp === 0x03) { // Domain
            $len = ord((string)fread($socket, 1));
            fread($socket, $len + 2);
        } elseif ($atyp === 0x04) { // IPv6 (16 bytes IP + 2 bytes Port)
            fread($socket, 18);
        }

        return $socket;
    }
}
