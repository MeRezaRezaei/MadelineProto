# Telegram MTProto 2.0 Transport & Framing Specification

## 1. Network DataCenters & Endpoints

Telegram operates 5 production DataCenters (DCs):
- **DC 1 (Miami):** `149.154.175.53:443` (Test: `149.154.175.10:443`)
- **DC 2 (Amsterdam):** `149.154.167.51:443` (Test: `149.154.167.40:443`)
- **DC 3 (Miami):** `149.154.175.100:443`
- **DC 4 (Amsterdam):** `149.154.167.91:443`
- **DC 5 (Singapore):** `91.108.56.130:443`

Default ports: `443`, `80`, `5222`.

---

## 2. Packet Framing (Abridged & Intermediate Transports)

### 2.1 Intermediate Transport (`0xeeeeeefe`)
- Initiated by sending `0xee, 0xee, 0xee, 0xee` as the first 4 bytes of the TCP connection.
- Subsequent packets: `[4-byte packet_length][payload]`.

### 2.2 Obfuscated Transport
- Prevents ISP DPI filtering: generates 64 random bytes, uses bytes 8..39 as AES-CTR key and 40..55 as IV for client-to-server stream, and reversed for server-to-client stream.

---

## 3. MTProto 2.0 Encryption (AES-256-IGE)

Encrypted MTProto 2.0 messages consist of:
$$\text{packet} = [\text{auth\_key\_id (8 bytes)}] + [\text{msg\_key (16 bytes)}] + [\text{encrypted\_data}]$$

### 3.1 Key Derivation ($msg\_key \to aes\_key, aes\_iv$)
For client $\to$ server messages ($x = 0$):
1. $msg\_key = \text{SHA256}(auth\_key[88..120] + \text{plaintext})[0..16]$
2. $sha256\_a = \text{SHA256}(msg\_key + auth\_key[0..36])$
3. $sha256\_b = \text{SHA256}(auth\_key[40..76] + msg\_key)$
4. $aes\_key = sha256\_a[0..8] + sha256\_b[8..24] + sha256\_a[24..32]$
5. $aes\_iv = sha256\_b[0..8] + sha256\_a[8..24] + sha256\_b[24..32]$

### 3.2 AES-256-IGE Cipher
IGE mode (Infinite Garble Extension) XORs the plaintext with previous ciphertext blocks:
$$c_i = E_K(p_i \oplus c_{i-1}) \oplus p_{i-1}$$
$$p_i = D_K(c_i \oplus p_{i-1}) \oplus c_{i-1}$$
where $c_0 = iv_1$ (first 16 bytes of IV) and $p_0 = iv_2$ (last 16 bytes of IV).
