# Telegram 2FA Cloud Password (SRP-6A) Mathematical Proof

## 1. Parameters (from `account.getPassword`)
When Telegram responds with `SESSION_PASSWORD_NEEDED`, the client calls `account.getPassword`, which returns:
- `srp_id` (64-bit integer)
- `current_algo`:
  - `g` (generator, usually 3, 4, 5, 7)
  - `p` (2048-bit prime number)
  - `salt1`, `salt2` (salts for PBKDF2 hashing)
- `srp_B` (server's public SRP key $B$)

---

## 2. Mathematical Steps

### Step 1: KDF Password Hash ($x$)
1. $buf_1 = \text{SHA256}(salt_1 + password + salt_1)$
2. $buf_2 = \text{SHA256}(salt_2 + buf_1 + salt_2)$
3. $pbkdf_2 = \text{PBKDF2-HMAC-SHA512}(buf_2, salt_1, \text{iterations}=100000, \text{length}=64)$
4. $x = \text{SHA256}(salt_2 + pbkdf_2 + salt_2)$ (interpreted as 2048-bit BigInteger)

### Step 2: Client Public Key ($A$) & Multiplier ($k$)
1. $k = \text{SHA256}(p_{pad} + g_{pad})$ (where $p_{pad}, g_{pad}$ are 256-byte zero-padded byte arrays)
2. Generate cryptographically secure random 2048-bit exponent $a$.
3. $A = g^a \pmod p$ (must satisfy $A > 0$)

### Step 3: Shared Random Scrambler ($u$) & Session Key ($S$)
1. $u = \text{SHA256}(A_{pad} + B_{pad})$
2. $g_x = g^x \pmod p$
3. $S = (B - k \cdot g_x)^{a + u \cdot x} \pmod p$
4. $K = \text{SHA256}(S_{pad})$

### Step 4: Verification Proof ($M_1$)
$$M_1 = \text{SHA256}((\text{SHA256}(p) \oplus \text{SHA256}(g)) + \text{SHA256}(salt_1) + \text{SHA256}(salt_2) + A_{pad} + B_{pad} + K)$$

### Step 5: Sending Challenge
The client sends `auth.checkPassword` with `inputCheckPasswordSRP`:
```php
[
    '_' => 'inputCheckPasswordSRP',
    'srp_id' => $srpId,
    'A' => $A->toBytes(),
    'M1' => $M1,
]
```
