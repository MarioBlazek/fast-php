//! PHP 8 comparison (`<=>`, `==`, sort()) after zend_compare in Zend/zend_operators.c.

use crate::conv::{self, Num, numeric};
use crate::{PhpArray, PhpError, PhpKey, PhpValue, fallback};
use std::cmp::Ordering;

fn ord(i: i32) -> Ordering {
    i.cmp(&0)
}

/// ZEND_THREEWAY_COMPARE: NAN compares as greater.
fn three_way(a: f64, b: f64) -> i32 {
    if a == b {
        0
    } else if a < b {
        -1
    } else {
        1
    }
}

fn normalize(o: Ordering) -> i32 {
    o as i32
}

/// zendi_smart_strcmp: numeric strings compare as numbers.
pub fn smart_strcmp(a: &[u8], b: &[u8]) -> i32 {
    if let (Some(x), Some(y)) = (numeric(a, false), numeric(b, false)) {
        if x.overflow != 0 && x.overflow == y.overflow && x.value.to_f64() - y.value.to_f64() == 0.0 {
            return normalize(a.cmp(b));
        }
        return match (x.value, y.value) {
            (Num::Int(p), Num::Int(q)) => normalize(p.cmp(&q)),
            (Num::Int(p), Num::Float(q)) => {
                if y.overflow != 0 {
                    -i32::from(y.overflow)
                } else {
                    sign(p as f64 - q)
                }
            }
            (Num::Float(p), Num::Int(q)) => {
                if x.overflow != 0 {
                    i32::from(x.overflow)
                } else {
                    sign(p - q as f64)
                }
            }
            (Num::Float(p), Num::Float(q)) => {
                if p == q && !p.is_finite() {
                    normalize(a.cmp(b))
                } else {
                    sign(p - q)
                }
            }
        };
    }
    normalize(a.cmp(b))
}

/// ZEND_NORMALIZE_BOOL on a double difference (NAN is 0).
fn sign(d: f64) -> i32 {
    if d > 0.0 {
        1
    } else if d < 0.0 {
        -1
    } else {
        0
    }
}

fn long_to_string(l: i64, s: &[u8]) -> i32 {
    match numeric(s, false).map(|n| n.value) {
        Some(Num::Int(x)) => normalize(l.cmp(&x)),
        Some(Num::Float(x)) => three_way(l as f64, x),
        None => normalize(l.to_string().as_bytes().cmp(s)),
    }
}

fn double_to_string(d: f64, s: &[u8]) -> Result<i32, PhpError> {
    Ok(match numeric(s, false).map(|n| n.value) {
        Some(Num::Int(x)) => three_way(d, x as f64),
        Some(Num::Float(x)) => three_way(d, x),
        None => normalize(conv::float_to_string(d)?.as_bytes().cmp(s)),
    })
}

/// (bool)$v
pub fn truthy(v: &PhpValue) -> bool {
    match v {
        PhpValue::Null => false,
        PhpValue::Bool(b) => *b,
        PhpValue::Int(i) => *i != 0,
        PhpValue::Float(f) => *f != 0.0,
        PhpValue::Str(s) => !(s.0.is_empty() || s.0 == b"0"),
        PhpValue::Array(a) => !a.is_empty(),
    }
}

fn arrays(a: &PhpArray, b: &PhpArray) -> Result<i32, PhpError> {
    if a.len() != b.len() {
        return Ok(if a.len() > b.len() { 1 } else { -1 });
    }
    for (k, v) in a.iter() {
        let Some(w) = b.get(k) else { return Ok(1) };
        let r = compare(v, w)?;
        if r != Ordering::Equal {
            return Ok(normalize(r));
        }
    }
    Ok(0)
}

/// `$a <=> $b`. NAN cases and other oddities PHP keeps for compatibility are declined.
pub fn compare(a: &PhpValue, b: &PhpValue) -> Result<Ordering, PhpError> {
    use PhpValue::*;
    let nan = |v: &PhpValue| matches!(v, Float(f) if f.is_nan());
    let r = match (a, b) {
        (Int(x), Int(y)) => normalize(x.cmp(y)),
        (Float(x), Int(y)) => three_way(*x, *y as f64),
        (Int(x), Float(y)) => three_way(*x as f64, *y),
        (Float(x), Float(y)) => three_way(*x, *y),
        (Array(x), Array(y)) => arrays(x, y)?,
        (Null, Null)
        | (Null, Bool(false))
        | (Bool(false), Null)
        | (Bool(false), Bool(false))
        | (Bool(true), Bool(true)) => 0,
        (Null, Bool(true)) => -1,
        (Bool(true), Null) => 1,
        (Str(x), Str(y)) => smart_strcmp(&x.0, &y.0),
        (Null, Str(y)) => {
            if y.0.is_empty() {
                0
            } else {
                -1
            }
        }
        (Str(x), Null) => {
            if x.0.is_empty() {
                0
            } else {
                1
            }
        }
        (Int(x), Str(y)) => long_to_string(*x, &y.0),
        (Str(x), Int(y)) => -long_to_string(*y, &x.0),
        (Float(x), Str(y)) => {
            if x.is_nan() {
                1
            } else {
                double_to_string(*x, &y.0)?
            }
        }
        (Str(x), Float(y)) => {
            if y.is_nan() {
                1
            } else {
                -double_to_string(*y, &x.0)?
            }
        }
        _ if nan(a) || nan(b) => return fallback(),
        (Null | Bool(false), _) => {
            if truthy(b) {
                -1
            } else {
                0
            }
        }
        (Bool(true), _) => {
            if truthy(b) {
                0
            } else {
                1
            }
        }
        (_, Null | Bool(false)) => {
            if truthy(a) {
                1
            } else {
                0
            }
        }
        (_, Bool(true)) => {
            if truthy(a) {
                0
            } else {
                -1
            }
        }
        (Array(_), _) => 1,
        (_, Array(_)) => -1,
    };
    Ok(ord(r))
}

/// `$a == $b`.
pub fn loose_eq(a: &PhpValue, b: &PhpValue) -> Result<bool, PhpError> {
    // == on two numeric-looking strings and the rest follow <=>, except that NAN is never equal.
    let nan = |v: &PhpValue| matches!(v, PhpValue::Float(f) if f.is_nan());
    if nan(a) || nan(b) {
        // NAN is truthy: against a bool it compares as true; against anything else it is unequal.
        let other = if nan(a) { b } else { a };
        return Ok(matches!(other, PhpValue::Bool(true)));
    }
    Ok(compare(a, b)? == Ordering::Equal)
}

/// Can sort() order these without depending on its algorithm? PHP's comparison isn't
/// transitive across types ("10" < "9a" < "9" < "10"), so only lists of one kind qualify:
/// all ints/floats (no NAN), or all strings that are either all numeric or all non-numeric.
fn sortable(values: &[PhpValue]) -> bool {
    let all_num =
        values.iter().all(|v| matches!(v, PhpValue::Int(_)) || matches!(v, PhpValue::Float(f) if !f.is_nan()));
    if all_num {
        return true;
    }
    let mut numeric_count = 0;
    for v in values {
        match v {
            PhpValue::Str(s) => numeric_count += usize::from(conv::is_numeric(&s.0)),
            _ => return false,
        }
    }
    numeric_count == 0 || numeric_count == values.len()
}

/// sort($list) with SORT_REGULAR: stable since PHP 8.
pub fn sort(values: &mut [PhpValue]) -> Result<(), PhpError> {
    if !sortable(values) {
        return fallback();
    }
    values.sort_by(|a, b| compare(a, b).unwrap_or(Ordering::Equal));
    Ok(())
}

/// rsort($list).
pub fn rsort(values: &mut [PhpValue]) -> Result<(), PhpError> {
    if !sortable(values) {
        return fallback();
    }
    values.sort_by(|a, b| compare(b, a).unwrap_or(Ordering::Equal));
    Ok(())
}

/// asort()/arsort()/ksort() on an array: stable, keys kept.
pub fn asort(a: &PhpArray, reverse: bool) -> Result<PhpArray, PhpError> {
    let values: Vec<PhpValue> = a.values().cloned().collect();
    if !sortable(&values) {
        return fallback();
    }
    let mut entries: Vec<(PhpKey, PhpValue)> = a.iter().map(|(k, v)| (k.clone(), v.clone())).collect();
    entries.sort_by(|x, y| {
        let o = compare(&x.1, &y.1).unwrap_or(Ordering::Equal);
        if reverse { o.reverse() } else { o }
    });
    Ok(PhpArray::from_entries(entries))
}

/// ksort(): int keys and string keys compared the PHP 8 way.
pub fn ksort(a: &PhpArray, reverse: bool) -> Result<PhpArray, PhpError> {
    let keys: Vec<PhpValue> = a
        .iter()
        .map(|(k, _)| match k {
            PhpKey::Int(i) => PhpValue::Int(*i),
            PhpKey::Str(s) => PhpValue::Str(s.clone()),
        })
        .collect();
    if !sortable(&keys) {
        return fallback();
    }
    let mut entries: Vec<(PhpKey, PhpValue, PhpValue)> =
        a.iter().zip(keys).map(|((k, v), kv)| (k.clone(), v.clone(), kv)).collect();
    entries.sort_by(|x, y| {
        let o = compare(&x.2, &y.2).unwrap_or(Ordering::Equal);
        if reverse { o.reverse() } else { o }
    });
    Ok(PhpArray::from_entries(entries.into_iter().map(|(k, v, _)| (k, v))))
}

#[cfg(test)]
mod tests {
    use super::*;

    fn s(x: &str) -> PhpValue {
        PhpValue::Str(x.into())
    }

    #[test]
    fn php8_rules() {
        assert_eq!(compare(&s("10"), &s("9")).unwrap(), Ordering::Greater);
        assert_eq!(compare(&s("abc"), &s("abd")).unwrap(), Ordering::Less);
        assert_eq!(compare(&PhpValue::Int(0), &s("a")).unwrap(), Ordering::Less); // "0" < "a"
        assert_eq!(compare(&s("1e3"), &PhpValue::Int(1000)).unwrap(), Ordering::Equal);
        assert_eq!(compare(&PhpValue::Null, &PhpValue::Int(-1)).unwrap(), Ordering::Less);
        let mut v = vec![s("10"), s("9"), s("1.5")];
        sort(&mut v).unwrap();
        assert_eq!(v, vec![s("1.5"), s("9"), s("10")]);
        let mut mixed = vec![s("10"), s("9a"), s("9")];
        assert!(sort(&mut mixed).is_err());
    }
}
