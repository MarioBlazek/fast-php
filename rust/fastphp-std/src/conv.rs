//! Conversions between strings and numbers, the way the Zend engine does them
//! (Zend/zend_operators.c, Zend/zend_strtod.c).

use crate::{PhpError, PhpString, PhpValue, fallback, php_version};

/// An int or a float: what PHP arithmetic and numeric strings give.
#[derive(Clone, Copy, Debug, PartialEq)]
pub enum Num {
    Int(i64),
    Float(f64),
}

impl Num {
    pub fn to_f64(self) -> f64 {
        match self {
            Num::Int(i) => i as f64,
            Num::Float(f) => f,
        }
    }
}

impl From<Num> for PhpValue {
    fn from(n: Num) -> Self {
        match n {
            Num::Int(i) => PhpValue::Int(i),
            Num::Float(f) => PhpValue::Float(f),
        }
    }
}

/// Significant digits and the decimal point position of |v| (v finite, non-zero), like
/// zend_dtoa: `ndigit` None is mode 0 (shortest round-trip), Some(n) mode 2 (n digits, rounded,
/// trailing zeros dropped). The value is 0.d1d2… × 10^decpt.
fn dtoa(v: f64, ndigit: Option<usize>) -> (Vec<u8>, i32) {
    let s = match ndigit {
        None => format!("{:e}", v.abs()),
        Some(n) => format!("{:.*e}", n.saturating_sub(1), v.abs()),
    };
    let (mant, exp) = s.split_once('e').expect("exponent");
    let mut digits: Vec<u8> = mant.bytes().filter(|b| b.is_ascii_digit()).collect();
    while digits.len() > 1 && digits.last() == Some(&b'0') {
        digits.pop();
    }
    (digits, exp.parse::<i32>().expect("exponent") + 1)
}

/// zend_gcvt: `ndigit` < 0 means shortest round-trip (serialize_precision -1).
pub fn gcvt(v: f64, ndigit: i32, exponent: u8) -> String {
    if v.is_nan() {
        return "NAN".into();
    }
    if v.is_infinite() {
        return if v < 0.0 { "-INF".into() } else { "INF".into() };
    }
    let (digits, decpt, ndigit) = if v == 0.0 {
        (vec![b'0'], 1, if ndigit < 0 { 17 } else { ndigit })
    } else if ndigit < 0 {
        let (d, p) = dtoa(v, None);
        (d, p, 17)
    } else {
        let (d, p) = dtoa(v, Some(ndigit.max(1) as usize));
        (d, p, ndigit)
    };
    let mut out = String::with_capacity(24);
    if v.is_sign_negative() {
        out.push('-');
    }
    if (decpt >= 0 && decpt > ndigit) || decpt < -3 {
        out.push(digits[0] as char);
        out.push('.');
        if digits.len() == 1 {
            out.push('0');
        } else {
            out.extend(digits[1..].iter().map(|&d| d as char));
        }
        out.push(exponent as char);
        let e = decpt - 1;
        out.push(if e < 0 { '-' } else { '+' });
        out.push_str(&e.abs().to_string());
    } else if decpt < 0 {
        out.push_str("0.");
        for _ in decpt..0 {
            out.push('0');
        }
        out.extend(digits.iter().map(|&d| d as char));
    } else {
        let decpt = decpt as usize;
        for i in 0..decpt {
            out.push(digits.get(i).map_or('0', |&d| d as char));
        }
        if digits.len() > decpt {
            if decpt == 0 {
                out.push('0');
            }
            out.push('.');
            out.extend(digits[decpt..].iter().map(|&d| d as char));
        }
    }
    out
}

/// A float as `(string)`, `echo`, `.` and `implode()` print it (precision = 14).
/// NAN is declined from PHP 8.5 on, where converting it warns.
pub fn float_to_string(v: f64) -> Result<String, PhpError> {
    if v.is_nan() && php_version() >= 80500 {
        return fallback();
    }
    Ok(gcvt(v, 14, b'E'))
}

/// A float as var_dump() and json_encode() see it (serialize_precision = -1), with an upper-case E.
pub fn float_repr(v: f64) -> String {
    gcvt(v, -1, b'E')
}

/// A float as var_export() writes it: like float_repr, with ".0" on whole numbers.
pub fn float_export(v: f64) -> String {
    let mut s = gcvt(v, -1, b'E');
    if v.is_finite() && !s.contains('.') {
        s.push_str(".0");
    }
    s
}

/// Any scalar as a string, like `(string)$v`. Arrays are declined (PHP warns "Array to string conversion").
pub fn to_string(v: &PhpValue) -> Result<PhpString, PhpError> {
    Ok(PhpString(match v {
        PhpValue::Null | PhpValue::Bool(false) => Vec::new(),
        PhpValue::Bool(true) => b"1".to_vec(),
        PhpValue::Int(i) => i.to_string().into_bytes(),
        PhpValue::Float(f) => float_to_string(*f)?.into_bytes(),
        PhpValue::Str(s) => s.0.clone(),
        PhpValue::Array(_) => return fallback(),
    }))
}

fn is_ws(b: u8) -> bool {
    matches!(b, b' ' | b'\t' | b'\n' | b'\r' | 0x0b | 0x0c)
}

/// The longest prefix zend_strtod accepts, parsed (correctly rounded, like zend_strtod).
fn strtod(s: &[u8]) -> (f64, usize) {
    let mut i = 0;
    if matches!(s.first(), Some(b'+' | b'-')) {
        i = 1;
    }
    let int_start = i;
    while i < s.len() && s[i].is_ascii_digit() {
        i += 1;
    }
    let mut digits = i - int_start;
    if i < s.len() && s[i] == b'.' {
        let f = i + 1;
        let mut j = f;
        while j < s.len() && s[j].is_ascii_digit() {
            j += 1;
        }
        digits += j - f;
        if digits > 0 {
            i = j;
        }
    }
    if digits == 0 {
        return (0.0, 0);
    }
    if i < s.len() && (s[i] == b'e' || s[i] == b'E') {
        let mut j = i + 1;
        if j < s.len() && (s[j] == b'+' || s[j] == b'-') {
            j += 1;
        }
        let e = j;
        while j < s.len() && s[j].is_ascii_digit() {
            j += 1;
        }
        if j > e {
            i = j;
        }
    }
    let text = std::str::from_utf8(&s[..i]).expect("ascii");
    (text.parse::<f64>().unwrap_or(0.0), i)
}

/// What _is_numeric_string_ex found.
#[derive(Clone, Copy, Debug, PartialEq)]
pub struct Numeric {
    pub value: Num,
    /// 1 or -1 when an integer string overflowed i64 (and became a float).
    pub overflow: i8,
    /// Something other than whitespace followed the number.
    pub trailing: bool,
}

/// PHP 8's numeric-string rules: leading and trailing whitespace allowed, then an optional
/// sign, digits, a fraction and an exponent. No hex, no "inf". With `allow_errors`, trailing
/// junk is allowed ("12abc" is 12, flagged as trailing).
pub fn numeric(s: &[u8], allow_errors: bool) -> Option<Numeric> {
    const MAX_LENGTH_OF_LONG: usize = 20;
    let at = |i: usize| s.get(i).copied().unwrap_or(0);
    let mut start = 0;
    while start < s.len() && is_ws(s[start]) {
        start += 1;
    }
    let str_ = &s[start..];
    let at_s = |i: usize| str_.get(i).copied().unwrap_or(0);
    let mut ptr = 0usize;
    let neg = at_s(0) == b'-';
    if neg || at_s(0) == b'+' {
        ptr += 1;
    }
    let _ = at;
    let mut is_double = false;
    let mut overflow: i8 = 0;
    let mut digits = 0usize;
    let mut tmp: u64 = 0;
    if at_s(ptr).is_ascii_digit() {
        while at_s(ptr) == b'0' {
            ptr += 1;
        }
        loop {
            if digits >= MAX_LENGTH_OF_LONG {
                break;
            }
            let c = at_s(ptr);
            if c.is_ascii_digit() {
                tmp = tmp.wrapping_mul(10).wrapping_add(u64::from(c - b'0'));
                digits += 1;
                ptr += 1;
                continue;
            } else if c == b'.' {
                is_double = true;
                break;
            } else if c == b'e' || c == b'E' {
                let mut e = ptr + 1;
                let sign = at_s(e) == b'-' || at_s(e) == b'+';
                if sign {
                    e += 1;
                }
                if at_s(e).is_ascii_digit() {
                    is_double = true;
                    break;
                }
                if sign {
                    ptr += 1; // C leaves ptr on the sign
                }
            }
            break;
        }
        if !is_double && digits >= MAX_LENGTH_OF_LONG {
            overflow = if neg { -1 } else { 1 };
            is_double = true;
        }
    } else if at_s(ptr) == b'.' && at_s(ptr + 1).is_ascii_digit() {
        is_double = true;
    } else {
        return None;
    }
    let mut dval = 0.0;
    if is_double {
        let (d, end) = strtod(str_);
        dval = d;
        ptr = end;
    }
    let mut trailing = false;
    if ptr != str_.len() {
        let mut end = ptr;
        while end < str_.len() && is_ws(str_[end]) {
            end += 1;
        }
        if end != str_.len() {
            if !allow_errors {
                return None;
            }
            trailing = true;
        }
    }
    if !is_double {
        if digits == MAX_LENGTH_OF_LONG - 1 {
            // strcmp(&ptr[-digits], "9223372036854775808"), C-string style.
            let rest = &str_[ptr - digits..];
            let rest = &rest[..rest.iter().position(|&b| b == 0).unwrap_or(rest.len())];
            let cmp = rest.cmp(b"9223372036854775808".as_slice());
            if !(cmp.is_lt() || (cmp.is_eq() && neg)) {
                let (d, _) = strtod(str_);
                return Some(Numeric { value: Num::Float(d), overflow: if neg { -1 } else { 1 }, trailing });
            }
        }
        let v = if neg { tmp.wrapping_neg() } else { tmp } as i64;
        return Some(Numeric { value: Num::Int(v), overflow: 0, trailing });
    }
    Some(Numeric { value: Num::Float(dval), overflow, trailing })
}

/// is_numeric($s) for a string.
pub fn is_numeric(s: &[u8]) -> bool {
    numeric(s, false).is_some()
}

/// `(int)$s`: the leading number, floats saturating at the i64 range, 0 when there is none.
pub fn str_to_int(s: &[u8]) -> i64 {
    match numeric(s, true) {
        None => 0,
        Some(Numeric { value: Num::Int(i), .. }) => i,
        Some(Numeric { value: Num::Float(d), .. }) => {
            if !d.is_finite() {
                0
            } else if !fits_long(d) {
                if d > 0.0 { i64::MAX } else { i64::MIN }
            } else {
                d as i64
            }
        }
    }
}

/// `(float)$s`: zend_strtod after leading whitespace ("-0" is -0.0), 0.0 when there is no number.
pub fn str_to_float(s: &[u8]) -> f64 {
    let start = s.iter().position(|&b| !is_ws(b)).unwrap_or(s.len());
    strtod(&s[start..]).0
}

/// ZEND_DOUBLE_FITS_LONG on 64-bit.
pub fn fits_long(d: f64) -> bool {
    d >= i64::MIN as f64 && d < i64::MAX as f64
}

/// `(int)$f`. Out-of-range and non-finite values are declined: PHP's result there is
/// modular and, from 8.5 on, comes with a warning.
pub fn float_to_int(d: f64) -> Result<i64, PhpError> {
    if !d.is_finite() || !fits_long(d) {
        return fallback();
    }
    Ok(d as i64)
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn floats_print_like_php() {
        let cases = [
            (1e14, "1.0E+14"),
            (1e13, "10000000000000"),
            (123456789012345.0, "1.2345678901234E+14"),
            (0.0001, "0.0001"),
            (0.00001, "1.0E-5"),
            (-0.0, "-0"),
            (0.1 + 0.2, "0.3"),
            (1.0 / 3.0, "0.33333333333333"),
            (100.0, "100"),
            (2.5e20, "2.5E+20"),
        ];
        for (v, want) in cases {
            assert_eq!(float_to_string(v).unwrap(), want, "{v}");
        }
        assert_eq!(float_export(1e16), "10000000000000000.0");
        assert_eq!(float_export(1e17), "1.0E+17");
        assert_eq!(float_export(0.1 + 0.2), "0.30000000000000004");
        assert_eq!(float_export(-0.0), "-0.0");
    }

    #[test]
    fn numeric_strings() {
        assert_eq!(numeric(b" 12 ", false).unwrap().value, Num::Int(12));
        assert_eq!(numeric(b"1e3", false).unwrap().value, Num::Float(1000.0));
        assert_eq!(numeric(b".5", false).unwrap().value, Num::Float(0.5));
        assert!(numeric(b"12abc", false).is_none());
        assert_eq!(numeric(b"12abc", true).unwrap().value, Num::Int(12));
        assert!(numeric(b"0x1A", false).is_none());
        assert!(numeric(b"", false).is_none());
        assert_eq!(numeric(b"9223372036854775808", false).unwrap().value, Num::Float(9223372036854775808.0));
        assert_eq!(numeric(b"-9223372036854775808", false).unwrap().value, Num::Int(i64::MIN));
        assert_eq!(str_to_int(b"99999999999999999999"), i64::MAX);
        assert_eq!(str_to_int(b"1e3"), 1000);
    }
}
