//! crc32(), md5(), sha1().

use md5::{Digest, Md5};
use sha1::Sha1;

fn hex(bytes: &[u8]) -> String {
    bytes.iter().map(|b| format!("{b:02x}")).collect()
}

/// crc32($s): IEEE CRC-32, as a non-negative int.
pub fn crc32(s: &[u8]) -> i64 {
    let mut crc = !0u32;
    for &b in s {
        crc ^= u32::from(b);
        for _ in 0..8 {
            crc = if crc & 1 != 0 { (crc >> 1) ^ 0xEDB8_8320 } else { crc >> 1 };
        }
    }
    i64::from(!crc)
}

/// md5($s): lower-case hex.
pub fn md5(s: &[u8]) -> String {
    hex(&Md5::digest(s))
}

/// sha1($s): lower-case hex.
pub fn sha1(s: &[u8]) -> String {
    hex(&Sha1::digest(s))
}
