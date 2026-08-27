<?php

declare(strict_types=1);

namespace Rezarezaei\LaravelTelegram\MTProto\Crypto;

use phpseclib3\Math\BigInteger;
use RuntimeException;

class PasswordCalculator
{
    /**
     * Calculates Telegram SRP 2FA check password proof.
     *
     * @param array<string, mixed> $accountPassword Object returned by account.getPassword
     * @param string $password User's plain 2FA cloud password
     * @return array{srp_id: int, A: string, M1: string}
     */
    public static function computeSrpProof(array $accountPassword, string $password): array
    {
        if (empty($accountPassword['has_password'])) {
            throw new RuntimeException('Account does not have 2FA cloud password enabled.');
        }

        $algo = $accountPassword['current_algo'];
        $g = new BigInteger((string)$algo['g']);
        $p = new BigInteger((string)$algo['p'], 256);
        $salt1 = (string)$algo['salt1'];
        $salt2 = (string)$algo['salt2'];
        $srpB = new BigInteger((string)$accountPassword['srp_B'], 256);
        $srpId = (int)$accountPassword['srp_id'];

        $gForHash = str_pad($g->toBytes(), 256, "\x00", STR_PAD_LEFT);
        $pForHash = str_pad($p->toBytes(), 256, "\x00", STR_PAD_LEFT);

        // KDF x computation
        $buf1 = hash('sha256', $salt1 . $password . $salt1, true);
        $buf2 = hash('sha256', $salt2 . $buf1 . $salt2, true);
        $pbkdf2 = hash_pbkdf2('sha512', $buf2, $salt1, 100000, 64, true);
        $x = new BigInteger(hash('sha256', $salt2 . $pbkdf2 . $salt2, true), 256);

        // k = SHA256(p | g)
        $k = new BigInteger(hash('sha256', $pForHash . $gForHash, true), 256);

        // Random 2048-bit secret exponent a
        $a = new BigInteger(random_bytes(256), 256);

        // A = g^a mod p
        $A = $g->modPow($a, $p);
        $AForHash = str_pad($A->toBytes(), 256, "\x00", STR_PAD_LEFT);
        $BForHash = str_pad($srpB->toBytes(), 256, "\x00", STR_PAD_LEFT);

        // u = SHA256(A | B)
        $u = new BigInteger(hash('sha256', $AForHash . $BForHash, true), 256);

        // gx = g^x mod p
        $gx = $g->modPow($x, $p);

        // S = (B - k * gx)^(a + u * x) mod p
        $kgx = $k->multiply($gx);
        $diff = $srpB->subtract($kgx)->remainder($p);
        if ($diff->compare(new BigInteger(0)) < 0) {
            $diff = $diff->add($p);
        }

        $exp = $a->add($u->multiply($x));
        $S = $diff->modPow($exp, $p);

        $K = hash('sha256', str_pad($S->toBytes(), 256, "\x00", STR_PAD_LEFT), true);

        // M1 proof
        $pHash = hash('sha256', $pForHash, true);
        $gHash = hash('sha256', $gForHash, true);
        $pXorG = $pHash ^ $gHash;

        $salt1Hash = hash('sha256', $salt1, true);
        $salt2Hash = hash('sha256', $salt2, true);

        $M1 = hash('sha256', $pXorG . $salt1Hash . $salt2Hash . $AForHash . $BForHash . $K, true);

        return [
            'srp_id' => $srpId,
            'A' => $A->toBytes(),
            'M1' => $M1,
        ];
    }
}
