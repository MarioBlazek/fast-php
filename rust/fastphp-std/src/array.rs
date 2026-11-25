//! A few ext/standard/array.c functions whose details matter.

use crate::conv::{self, Num};
use crate::math;
use crate::{PhpArray, PhpError, PhpKey, PhpValue, fallback, php_version};
use std::collections::HashSet;

/// range($start, $end, $step) for ints. Negative steps are declined (their rules changed in 8.3).
pub fn range(start: i64, end: i64, step: i64) -> Result<Vec<i64>, PhpError> {
    if step == 0 {
        return Err(PhpError::new("ValueError", "range(): Argument #3 ($step) cannot be 0"));
    }
    if step < 0 {
        return fallback();
    }
    let step = step as u64;
    let span = start.abs_diff(end);
    if step > span && start != end {
        return Err(PhpError::new(
            "ValueError",
            if php_version() >= 80300 {
                "range(): Argument #3 ($step) must be less than the range spanned by argument #1 ($start) and argument #2 ($end)"
            } else {
                "range(): Argument #3 ($step) must not exceed the specified range"
            },
        ));
    }
    let n = span / step;
    if n >= 1 << 27 {
        return fallback(); // PHP refuses huge ranges with its own error
    }
    Ok((0..=n).map(|i| if start <= end { start + (i * step) as i64 } else { start - (i * step) as i64 }).collect())
}

/// array_sum() over ints and floats; anything else is declined.
pub fn array_sum(values: &[PhpValue]) -> Result<Num, PhpError> {
    let mut acc = Num::Int(0);
    for v in values {
        acc = match v {
            PhpValue::Int(i) => math::add_num(acc, Num::Int(*i)),
            PhpValue::Float(f) => math::add_num(acc, Num::Float(*f)),
            _ => return fallback(),
        };
    }
    Ok(acc)
}

/// array_unique($a) with the default SORT_STRING: the first key of each string form survives.
pub fn array_unique(a: &PhpArray) -> Result<PhpArray, PhpError> {
    let mut seen = HashSet::new();
    let mut out = Vec::new();
    for (k, v) in a.iter() {
        if seen.insert(conv::to_string(v)?.0) {
            out.push((k.clone(), v.clone()));
        }
    }
    Ok(PhpArray::from_entries(out))
}

/// array_slice($a, $offset, $length, $preserve_keys).
pub fn array_slice(a: &PhpArray, offset: i64, length: Option<i64>, preserve_keys: bool) -> PhpArray {
    let n = a.len() as i64;
    let mut from = if offset < 0 { (n + offset).max(0) } else { offset };
    if from > n {
        from = n;
    }
    let to = match length {
        None => n,
        Some(l) if l < 0 => (n + l).max(from),
        Some(l) => from.saturating_add(l).min(n),
    };
    let mut out = PhpArray::new();
    for (k, v) in a.iter().skip(from as usize).take((to - from).max(0) as usize) {
        match k {
            PhpKey::Int(_) if !preserve_keys => out.push(v.clone()),
            _ => out.insert(k.clone(), v.clone()),
        }
    }
    out
}
