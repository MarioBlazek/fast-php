//! ext/standard/string.c: byte strings, ASCII-only case mapping, PHP's argument errors.

use crate::conv::{self, Num};
use crate::math;
use crate::{PhpError, PhpString, PhpValue, fallback, php_version};

/// trim()'s default characters: space, \t, \n, \r, \0 and \v (not \f).
pub const TRIM_DEFAULT: &[u8] = b" \t\n\r\0\x0B";

fn value_error(func: &str, arg: u32, name: &str, msg: &str) -> PhpError {
    PhpError::new("ValueError", format!("{func}(): Argument #{arg} (${name}) {msg}"))
}

/// "cannot be empty" until PHP 8.3, "must not be empty" from 8.4 on.
fn empty_error(func: &str, arg: u32, name: &str) -> PhpError {
    value_error(func, arg, name, if php_version() >= 80400 { "must not be empty" } else { "cannot be empty" })
}

/// php_charmask: "a..z" ranges included. A malformed range makes PHP warn: declined.
pub fn char_mask(chars: &[u8]) -> Result<[bool; 256], PhpError> {
    let mut mask = [false; 256];
    let mut i = 0;
    while i < chars.len() {
        let c = chars[i];
        if i + 3 < chars.len() && chars[i + 1] == b'.' && chars[i + 2] == b'.' && chars[i + 3] >= c {
            for m in &mut mask[c as usize..=chars[i + 3] as usize] {
                *m = true;
            }
            i += 4;
        } else if i + 1 < chars.len() && chars[i] == b'.' && chars[i + 1] == b'.' {
            return fallback();
        } else {
            mask[c as usize] = true;
            i += 1;
        }
    }
    Ok(mask)
}

fn trim_mode<'a>(s: &'a [u8], chars: &[u8], left: bool, right: bool) -> Result<&'a [u8], PhpError> {
    let mask = if chars == TRIM_DEFAULT {
        let mut m = [false; 256];
        for &c in TRIM_DEFAULT {
            m[c as usize] = true;
        }
        m
    } else if chars.len() == 1 {
        let mut m = [false; 256];
        m[chars[0] as usize] = true;
        m
    } else {
        char_mask(chars)?
    };
    let mut start = 0;
    let mut end = s.len();
    if left {
        while start < end && mask[s[start] as usize] {
            start += 1;
        }
    }
    if right {
        while end > start && mask[s[end - 1] as usize] {
            end -= 1;
        }
    }
    Ok(&s[start..end])
}

/// trim($s) with the default characters.
pub fn trim(s: &[u8]) -> &[u8] {
    trim_mode(s, TRIM_DEFAULT, true, true).expect("default mask")
}

pub fn ltrim(s: &[u8]) -> &[u8] {
    trim_mode(s, TRIM_DEFAULT, true, false).expect("default mask")
}

pub fn rtrim(s: &[u8]) -> &[u8] {
    trim_mode(s, TRIM_DEFAULT, false, true).expect("default mask")
}

/// trim($s, $characters), with "a..z" ranges.
pub fn trim_chars<'a>(s: &'a [u8], chars: &[u8]) -> Result<&'a [u8], PhpError> {
    trim_mode(s, chars, true, true)
}

pub fn ltrim_chars<'a>(s: &'a [u8], chars: &[u8]) -> Result<&'a [u8], PhpError> {
    trim_mode(s, chars, true, false)
}

pub fn rtrim_chars<'a>(s: &'a [u8], chars: &[u8]) -> Result<&'a [u8], PhpError> {
    trim_mode(s, chars, false, true)
}

/// strtolower(): ASCII only (PHP 8.2+), other bytes untouched.
pub fn strtolower(s: &[u8]) -> Vec<u8> {
    s.to_ascii_lowercase()
}

pub fn strtoupper(s: &[u8]) -> Vec<u8> {
    s.to_ascii_uppercase()
}

pub fn ucfirst(s: &[u8]) -> Vec<u8> {
    let mut v = s.to_vec();
    if let Some(c) = v.first_mut() {
        c.make_ascii_uppercase();
    }
    v
}

pub fn lcfirst(s: &[u8]) -> Vec<u8> {
    let mut v = s.to_vec();
    if let Some(c) = v.first_mut() {
        c.make_ascii_lowercase();
    }
    v
}

/// ucwords($s, $delimiters = " \t\r\n\f\v").
pub fn ucwords(s: &[u8], delimiters: &[u8]) -> Result<Vec<u8>, PhpError> {
    let mask = char_mask(delimiters)?;
    let mut v = s.to_vec();
    let mut up = true;
    for c in &mut v {
        if up {
            c.make_ascii_uppercase();
        }
        up = mask[*c as usize];
    }
    Ok(v)
}

pub const UCWORDS_DEFAULT: &[u8] = b" \t\r\n\x0c\x0b";

#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub enum Pad {
    Left = 0,
    Right = 1,
    Both = 2,
}

/// str_pad(). `pad_type` is STR_PAD_LEFT (0), STR_PAD_RIGHT (1) or STR_PAD_BOTH (2).
pub fn str_pad(s: &[u8], length: i64, pad: &[u8], pad_type: i64) -> Result<Vec<u8>, PhpError> {
    if length < 0 || (length as u64) <= s.len() as u64 {
        return Ok(s.to_vec());
    }
    if pad.is_empty() {
        return Err(if php_version() >= 80400 {
            empty_error("str_pad", 3, "pad_string")
        } else {
            value_error("str_pad", 3, "pad_string", "must be a non-empty string")
        });
    }
    if !(0..=2).contains(&pad_type) {
        return Err(value_error("str_pad", 4, "pad_type", "must be STR_PAD_LEFT, STR_PAD_RIGHT, or STR_PAD_BOTH"));
    }
    let n = length as usize - s.len();
    let (left, right) = match pad_type {
        0 => (n, 0),
        1 => (0, n),
        _ => (n / 2, n - n / 2),
    };
    let mut out = Vec::with_capacity(length as usize);
    out.extend((0..left).map(|i| pad[i % pad.len()]));
    out.extend_from_slice(s);
    out.extend((0..right).map(|i| pad[i % pad.len()]));
    Ok(out)
}

/// substr($s, $offset, $length): PHP 8 rules, never false.
pub fn substr(s: &[u8], offset: i64, length: Option<i64>) -> &[u8] {
    let len = s.len() as i64;
    if offset > len {
        return &[];
    }
    let f = if offset < 0 { if offset.unsigned_abs() > len as u64 { 0 } else { len + offset } } else { offset };
    let l = match length {
        None => len - f,
        Some(l) if l < 0 => {
            if l.unsigned_abs() > (len - f) as u64 {
                0
            } else {
                len - f + l
            }
        }
        Some(l) => l.min(len - f),
    };
    &s[f as usize..(f + l) as usize]
}

pub fn strrev(s: &[u8]) -> Vec<u8> {
    s.iter().rev().copied().collect()
}

pub fn str_repeat(s: &[u8], times: i64) -> Result<Vec<u8>, PhpError> {
    if times < 0 {
        return Err(value_error("str_repeat", 2, "times", "must be greater than or equal to 0"));
    }
    Ok(s.repeat(times as usize))
}

fn find(h: &[u8], n: &[u8], from: usize) -> Option<usize> {
    if n.is_empty() {
        return (from <= h.len()).then_some(from);
    }
    if from > h.len() {
        return None;
    }
    let rest = &h[from..];
    let at = if n.len() == 1 { memchr::memchr(n[0], rest) } else { memchr::memmem::find(rest, n) };
    at.map(|i| i + from)
}

/// str_replace() with one search string: the result and the number of replacements.
pub fn str_replace(search: &[u8], replace: &[u8], subject: &[u8]) -> (Vec<u8>, i64) {
    if search.is_empty() {
        return (subject.to_vec(), 0);
    }
    let mut out = Vec::with_capacity(subject.len());
    let mut count = 0;
    let mut i = 0;
    while let Some(at) = find(subject, search, i) {
        out.extend_from_slice(&subject[i..at]);
        out.extend_from_slice(replace);
        i = at + search.len();
        count += 1;
    }
    out.extend_from_slice(&subject[i..]);
    (out, count)
}

/// str_ireplace() with one search string (ASCII case-insensitive).
pub fn str_ireplace(search: &[u8], replace: &[u8], subject: &[u8]) -> (Vec<u8>, i64) {
    if search.is_empty() {
        return (subject.to_vec(), 0);
    }
    let lower = subject.to_ascii_lowercase();
    let needle = search.to_ascii_lowercase();
    let mut out = Vec::with_capacity(subject.len());
    let mut count = 0;
    let mut i = 0;
    while let Some(at) = find(&lower, &needle, i) {
        out.extend_from_slice(&subject[i..at]);
        out.extend_from_slice(replace);
        i = at + search.len();
        count += 1;
    }
    out.extend_from_slice(&subject[i..]);
    (out, count)
}

/// str_replace(array $search, array|string $replace, string $subject): one pair after another.
/// A missing replacement is "".
pub fn str_replace_many(search: &[&[u8]], replace: &[&[u8]], subject: &[u8]) -> (Vec<u8>, i64) {
    let mut s = subject.to_vec();
    let mut total = 0;
    for (i, needle) in search.iter().enumerate() {
        let (next, n) = str_replace(needle, replace.get(i).copied().unwrap_or(b""), &s);
        s = next;
        total += n;
        if s.is_empty() {
            break;
        }
    }
    (s, total)
}

/// explode($separator, $string, $limit = PHP_INT_MAX).
pub fn explode(separator: &[u8], s: &[u8], limit: i64) -> Result<Vec<PhpString>, PhpError> {
    if separator.is_empty() {
        return Err(empty_error("explode", 1, "separator"));
    }
    if s.is_empty() {
        return Ok(if limit >= 0 { vec![PhpString(Vec::new())] } else { Vec::new() });
    }
    let mut parts = Vec::new();
    let mut i = 0;
    let max = if limit > 0 { limit as usize } else { usize::MAX };
    let limit = if limit == 0 { 1 } else { limit };
    if limit > 0 {
        while parts.len() + 1 < max {
            match find(s, separator, i) {
                Some(at) => {
                    parts.push(PhpString(s[i..at].to_vec()));
                    i = at + separator.len();
                }
                None => break,
            }
        }
        parts.push(PhpString(s[i..].to_vec()));
        if limit == 1 {
            return Ok(vec![PhpString(s.to_vec())]);
        }
        return Ok(parts);
    }
    while let Some(at) = find(s, separator, i) {
        parts.push(PhpString(s[i..at].to_vec()));
        i = at + separator.len();
    }
    parts.push(PhpString(s[i..].to_vec()));
    let keep = parts.len() as i64 + limit;
    parts.truncate(keep.max(0) as usize);
    Ok(parts)
}

/// implode($separator, $array): values converted like (string).
pub fn implode(separator: &[u8], values: &[PhpValue]) -> Result<PhpString, PhpError> {
    let mut out = Vec::new();
    for (i, v) in values.iter().enumerate() {
        if i > 0 {
            out.extend_from_slice(separator);
        }
        out.extend_from_slice(&conv::to_string(v)?.0);
    }
    Ok(PhpString(out))
}

fn offset_error(func: &str) -> PhpError {
    value_error(func, 3, "offset", "must be contained in argument #1 ($haystack)")
}

/// strpos(): None is false.
pub fn strpos(haystack: &[u8], needle: &[u8], offset: i64) -> Result<Option<usize>, PhpError> {
    let len = haystack.len() as i64;
    let off = if offset < 0 { len + offset } else { offset };
    if off < 0 || off > len {
        return Err(offset_error("strpos"));
    }
    Ok(find(haystack, needle, off as usize))
}

/// stripos(): ASCII case-insensitive.
pub fn stripos(haystack: &[u8], needle: &[u8], offset: i64) -> Result<Option<usize>, PhpError> {
    let len = haystack.len() as i64;
    let off = if offset < 0 { len + offset } else { offset };
    if off < 0 || off > len {
        return Err(offset_error("stripos"));
    }
    Ok(find(&haystack.to_ascii_lowercase(), &needle.to_ascii_lowercase(), off as usize))
}

/// strrpos(): the last occurrence. A negative offset limits where a match may start.
pub fn strrpos(haystack: &[u8], needle: &[u8], offset: i64) -> Result<Option<usize>, PhpError> {
    let len = haystack.len() as i64;
    let nlen = needle.len() as i64;
    let (from, last_start) = if offset >= 0 {
        if offset > len {
            return Err(offset_error("strrpos"));
        }
        (offset, len - nlen)
    } else {
        if offset < -i64::MAX || -offset > len {
            return Err(offset_error("strrpos"));
        }
        (0, if -offset < nlen { len - nlen } else { len + offset })
    };
    if last_start < from {
        return Ok(None);
    }
    Ok((from..=last_start).rev().find(|&i| &haystack[i as usize..(i + nlen) as usize] == needle).map(|i| i as usize))
}

/// substr_count() without offset/length: non-overlapping occurrences.
pub fn substr_count(haystack: &[u8], needle: &[u8]) -> Result<i64, PhpError> {
    if needle.is_empty() {
        return Err(empty_error("substr_count", 2, "needle"));
    }
    let mut n = 0;
    let mut i = 0;
    while let Some(at) = find(haystack, needle, i) {
        n += 1;
        i = at + needle.len();
    }
    Ok(n)
}

pub fn str_contains(haystack: &[u8], needle: &[u8]) -> bool {
    find(haystack, needle, 0).is_some()
}

pub fn str_starts_with(haystack: &[u8], needle: &[u8]) -> bool {
    haystack.starts_with(needle)
}

pub fn str_ends_with(haystack: &[u8], needle: &[u8]) -> bool {
    haystack.ends_with(needle)
}

/// str_split($s, $length = 1). "" gives [].
pub fn str_split(s: &[u8], length: i64) -> Result<Vec<PhpString>, PhpError> {
    if length <= 0 {
        return Err(value_error("str_split", 2, "length", "must be greater than 0"));
    }
    Ok(s.chunks(length as usize).map(|c| PhpString(c.to_vec())).collect())
}

/// levenshtein() with the default costs: bytes, not characters.
pub fn levenshtein(a: &[u8], b: &[u8]) -> i64 {
    levenshtein_costs(a, b, 1, 1, 1)
}

/// levenshtein($a, $b, $insertion_cost, $replacement_cost, $deletion_cost).
pub fn levenshtein_costs(a: &[u8], b: &[u8], ins: i64, rep: i64, del: i64) -> i64 {
    if a.is_empty() {
        return b.len() as i64 * ins;
    }
    if b.is_empty() {
        return a.len() as i64 * del;
    }
    let mut p1: Vec<i64> = (0..=b.len() as i64).map(|i| i * ins).collect();
    let mut p2 = vec![0i64; b.len() + 1];
    for (i1, &c1) in a.iter().enumerate() {
        p2[0] = p1[0] + del;
        for (i2, &c2) in b.iter().enumerate() {
            let mut c0 = p1[i2] + if c1 == c2 { 0 } else { rep };
            let c1v = p1[i2 + 1] + del;
            if c1v < c0 {
                c0 = c1v;
            }
            let c2v = p2[i2] + ins;
            if c2v < c0 {
                c0 = c2v;
            }
            p2[i2 + 1] = c0;
        }
        std::mem::swap(&mut p1, &mut p2);
        let _ = i1;
    }
    p1[b.len()]
}

fn similar_char(a: &[u8], b: &[u8]) -> usize {
    let (mut pos1, mut pos2, mut max, mut count) = (0, 0, 0, 0);
    for p in 0..a.len() {
        for q in 0..b.len() {
            let mut l = 0;
            while p + l < a.len() && q + l < b.len() && a[p + l] == b[q + l] {
                l += 1;
            }
            if l > max {
                max = l;
                count += 1;
                pos1 = p;
                pos2 = q;
            }
        }
    }
    let mut sum = max;
    if sum > 0 {
        if pos1 > 0 && pos2 > 0 && count > 1 {
            sum += similar_char(&a[..pos1], &b[..pos2]);
        }
        if pos1 + max < a.len() && pos2 + max < b.len() {
            sum += similar_char(&a[pos1 + max..], &b[pos2 + max..]);
        }
    }
    sum
}

/// similar_text(): the number of matching bytes and the percentage.
pub fn similar_text(a: &[u8], b: &[u8]) -> (i64, f64) {
    if a.len() + b.len() == 0 {
        return (0, 0.0);
    }
    let sim = similar_char(a, b);
    (sim as i64, sim as f64 * 2.0 * 100.0 / (a.len() + b.len()) as f64)
}

fn group(digits: &[u8], sep: &[u8]) -> Vec<u8> {
    let mut out = Vec::with_capacity(digits.len() + digits.len() / 3 * sep.len());
    for (i, &d) in digits.iter().enumerate() {
        if i > 0 && (digits.len() - i).is_multiple_of(3) {
            out.extend_from_slice(sep);
        }
        out.push(d);
    }
    out
}

fn number_format_long(num: i64, dec: i64, dec_point: &[u8], sep: &[u8]) -> Vec<u8> {
    const POWERS: [u64; 20] = [
        1,
        10,
        100,
        1_000,
        10_000,
        100_000,
        1_000_000,
        10_000_000,
        100_000_000,
        1_000_000_000,
        10_000_000_000,
        100_000_000_000,
        1_000_000_000_000,
        10_000_000_000_000,
        100_000_000_000_000,
        1_000_000_000_000_000,
        10_000_000_000_000_000,
        100_000_000_000_000_000,
        1_000_000_000_000_000_000,
        10_000_000_000_000_000_000,
    ];
    let mut negative = num < 0;
    let mut n = num.unsigned_abs();
    if dec < 0 {
        if dec < -(POWERS.len() as i64 - 1) {
            n = 0;
        } else {
            let power = POWERS[(-dec) as usize];
            let rest = n % power;
            n /= power;
            n = if rest >= power / 2 { n.wrapping_mul(power).wrapping_add(power) } else { n.wrapping_mul(power) };
        }
        if n == 0 {
            negative = false;
        }
    }
    let mut out = Vec::new();
    if negative {
        out.push(b'-');
    }
    out.extend(group(n.to_string().as_bytes(), sep));
    if dec > 0 {
        out.extend_from_slice(dec_point);
        out.extend(std::iter::repeat_n(b'0', dec as usize));
    }
    out
}

/// number_format($num, $decimals, $decimal_separator, $thousands_separator).
pub fn number_format(num: Num, decimals: i64, dec_point: &[u8], sep: &[u8]) -> Result<Vec<u8>, PhpError> {
    let v = php_version();
    let d = match num {
        Num::Int(i) if v >= 80300 => return Ok(number_format_long(i, decimals, dec_point, sep)),
        Num::Int(i) => i as f64,
        Num::Float(f) => f,
    };
    if v >= 80400 && (d >= 4503599627370496.0 || d <= -4503599627370496.0) && conv::fits_long(d) {
        return Ok(number_format_long(d as i64, decimals, dec_point, sep));
    }
    let mut dec = decimals.clamp(i32::MIN as i64, i32::MAX as i64);
    if v < 80300 {
        dec = dec.max(0);
    }
    let negative = d < 0.0;
    let rounded = math::round(d.abs(), dec)?;
    let dec = dec.max(0) as usize;
    let tmp = format!("{rounded:.dec$}");
    if !rounded.is_finite() {
        // printf's own spelling, returned as is (the sign was already dropped).
        return Ok(if rounded.is_nan() { b"nan".to_vec() } else { b"inf".to_vec() });
    }
    let negative = negative && rounded != 0.0;
    let (int_part, frac) = match tmp.split_once('.') {
        Some((i, f)) => (i, Some(f)),
        None => (tmp.as_str(), None),
    };
    let mut out = Vec::new();
    if negative {
        out.push(b'-');
    }
    out.extend(group(int_part.as_bytes(), sep));
    if dec > 0 {
        out.extend_from_slice(dec_point);
        out.extend_from_slice(frac.unwrap_or("").as_bytes());
    }
    Ok(out)
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn basics() {
        assert_eq!(trim(b"\x0c a \0"), b"\x0c a");
        assert_eq!(trim_chars(b"abcxcba", b"a..c").unwrap(), b"x");
        assert!(trim_chars(b"x", b"..a").is_err());
        assert_eq!(strtolower("ÀB".as_bytes()), "Àb".as_bytes());
        assert_eq!(substr(b"hello", -3, Some(-1)), b"ll");
        assert_eq!(substr(b"hello", 9, None), b"");
        assert_eq!(str_pad(b"5", 3, b"0", 0).unwrap(), b"005");
        let e = explode(b",", b"a,b,c", -1).unwrap();
        assert_eq!(e, vec![PhpString(b"a".to_vec()), PhpString(b"b".to_vec())]);
        assert_eq!(explode(b",", b"a,b,c", 2).unwrap().len(), 2);
        assert_eq!(levenshtein("José".as_bytes(), b"Jose"), 2);
        assert_eq!(similar_text(b"World", b"Word").0, 4);
        assert_eq!(number_format(Num::Float(1234.5678), 2, b".", b",").unwrap(), b"1,234.57");
        assert_eq!(number_format(Num::Float(-0.4), 0, b".", b",").unwrap(), b"0");
        assert_eq!(strrpos(b"0123456789a123456789b", b"7", -5).unwrap(), Some(7));
        assert_eq!(strrpos(b"0123456789a123456789b", b"7", -4).unwrap(), Some(17));
    }
}
