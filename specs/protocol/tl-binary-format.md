# Telegram Type Language (TL) Binary Wire Format Specification

## 1. Overview
Telegram's Type Language (TL) is a strictly typed binary serialization format for remote procedure calls (RPC) and event updates over MTProto.

---

## 2. Primitive Type Encoding

### 2.1 Integer (`int`)
- 4 bytes, 32-bit signed, little-endian format (`pack('l', $value)`).

### 2.2 Long (`long`)
- 8 bytes, 64-bit signed, little-endian format (`pack('q', $value)`).

### 2.3 Double (`double`)
- 8 bytes, 64-bit IEEE 754 floating point format (`pack('d', $value)`).

### 2.4 String & Bytes (`string`, `bytes`)
Strings are length-prefixed with 4-byte padding:
- **Case 1 (Length $\le 253$ bytes):**
  - First byte: $L$ (unsigned 8-bit integer length).
  - Next $L$ bytes: raw string/bytes.
  - Padding: $(4 - ((L + 1) \bmod 4)) \bmod 4$ zero bytes.
- **Case 2 (Length $\ge 254$ bytes):**
  - First byte: `0xFE`.
  - Next 3 bytes: $L$ in 24-bit little-endian ($L_0 + L_1 \cdot 256 + L_2 \cdot 65536$).
  - Next $L$ bytes: raw string/bytes.
  - Padding: $(4 - ((L + 4) \bmod 4)) \bmod 4$ zero bytes.

---

## 3. Vector / List Encoding (`vector<T>`)
- Vector constructor ID: `0x1cb5c415`.
- Next 4 bytes: count of elements $N$ (`int`).
- Next: $N$ serialized elements sequentially.

---

## 4. Constructor / Object Encoding
- First 4 bytes: CRC32 constructor ID (e.g. `0x215c04dd` for `user`).
- Next fields: Serialized according to constructor definition in `schema.tl`.
- Conditional flags: Encoded using a 32-bit `flags` bitmask before optional fields.
