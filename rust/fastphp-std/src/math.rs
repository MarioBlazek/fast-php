//! Arithmetic with PHP's int/float rules (Zend/zend_operators.c) and ext/standard/math.c.

pub use crate::conv::Num;
use crate::{PhpError, fallback, php_version};

/// `$a + $b` on ints: overflows into a float instead of wrapping.
pub fn add(a: i64, b: i64) -> Num {
    a.checked_add(b).map_or(Num::Float(a as f64 + b as f64), Num::Int)
}

pub fn sub(a: i64, b: i64) -> Num {
    a.checked_sub(b).map_or(Num::Float(a as f64 - b as f64), Num::Int)
}

pub fn mul(a: i64, b: i64) -> Num {
    a.checked_mul(b).map_or(Num::Float(a as f64 * b as f64), Num::Int)
}

/// `$a + $b` on any numbers.
pub fn add_num(a: Num, b: Num) -> Num {
    match (a, b) {
        (Num::Int(a), Num::Int(b)) => add(a, b),
        _ => Num::Float(a.to_f64() + b.to_f64()),
    }
}

pub fn sub_num(a: Num, b: Num) -> Num {
    match (a, b) {
        (Num::Int(a), Num::Int(b)) => sub(a, b),
        _ => Num::Float(a.to_f64() - b.to_f64()),
    }
}

pub fn mul_num(a: Num, b: Num) -> Num {
    match (a, b) {
        (Num::Int(a), Num::Int(b)) => mul(a, b),
        _ => Num::Float(a.to_f64() * b.to_f64()),
    }
}

fn division_by_zero() -> PhpError {
    PhpError::new("DivisionByZeroError", "Division by zero")
}

/// `$a / $b`: an int when both are ints and the division is exact, else a float.
pub fn div(a: Num, b: Num) -> Result<Num, PhpError> {
    if b.to_f64() == 0.0 {
        return Err(division_by_zero());
    }
    Ok(match (a, b) {
        (Num::Int(a), Num::Int(b)) => {
            if b == -1 && a == i64::MIN {
                Num::Float(a as f64 / -1.0)
            } else if a % b == 0 {
                Num::Int(a / b)
            } else {
                Num::Float(a as f64 / b as f64)
            }
        }
        _ => Num::Float(a.to_f64() / b.to_f64()),
    })
}

/// `$a % $b` on ints: the sign follows $a.
pub fn rem(a: i64, b: i64) -> Result<i64, PhpError> {
    match b {
        0 => Err(PhpError::new("DivisionByZeroError", "Modulo by zero")),
        -1 => Ok(0),
        _ => Ok(a % b),
    }
}

pub fn intdiv(a: i64, b: i64) -> Result<i64, PhpError> {
    if b == 0 {
        return Err(division_by_zero());
    }
    if b == -1 && a == i64::MIN {
        return Err(PhpError::new("ArithmeticError", "Division of PHP_INT_MIN by -1 is not an integer"));
    }
    Ok(a / b)
}

/// `$a ** $b` on ints, with Zend's overflow switch to floats mid-way.
/// 0 ** negative is deprecated from PHP 8.4 on (and is INF): declined.
pub fn pow_int(base: i64, exp: i64) -> Result<Num, PhpError> {
    if exp < 0 && base == 0 {
        return fallback();
    }
    Ok(pow_int_unchecked(base, exp))
}

fn pow_int_unchecked(base: i64, exp: i64) -> Num {
    if exp < 0 {
        return Num::Float((base as f64).powf(exp as f64));
    }
    if exp == 0 {
        return Num::Int(1);
    }
    if base == 0 {
        return Num::Int(0);
    }
    let (mut l1, mut l2, mut i) = (1i64, base, exp);
    while i >= 1 {
        if i % 2 != 0 {
            i -= 1;
            match l1.checked_mul(l2) {
                Some(v) => l1 = v,
                None => return Num::Float(l1 as f64 * l2 as f64 * (l2 as f64).powf(i as f64)),
            }
        } else {
            i /= 2;
            match l2.checked_mul(l2) {
                Some(v) => l2 = v,
                None => return Num::Float(l1 as f64 * (l2 as f64 * l2 as f64).powf(i as f64)),
            }
        }
    }
    Num::Int(l1)
}

/// abs(): PHP_INT_MIN becomes a float.
pub fn abs(n: Num) -> Num {
    match n {
        Num::Int(i64::MIN) => Num::Float(-(i64::MIN as f64)),
        Num::Int(i) => Num::Int(i.abs()),
        Num::Float(f) => Num::Float(f.abs()),
    }
}

fn intpow10(power: i32) -> f64 {
    const POWERS: [f64; 23] = [
        1e0, 1e1, 1e2, 1e3, 1e4, 1e5, 1e6, 1e7, 1e8, 1e9, 1e10, 1e11, 1e12, 1e13, 1e14, 1e15, 1e16, 1e17, 1e18, 1e19,
        1e20, 1e21, 1e22,
    ];
    if !(0..=22).contains(&power) { 10f64.powf(power as f64) } else { POWERS[power as usize] }
}

fn intlog10abs(value: f64) -> i32 {
    let value = value.abs();
    if !(1e-8..=1e22).contains(&value) {
        return value.log10().floor() as i32;
    }
    const VALUES: [f64; 31] = [
        1e-8, 1e-7, 1e-6, 1e-5, 1e-4, 1e-3, 1e-2, 1e-1, 1e0, 1e1, 1e2, 1e3, 1e4, 1e5, 1e6, 1e7, 1e8, 1e9, 1e10, 1e11,
        1e12, 1e13, 1e14, 1e15, 1e16, 1e17, 1e18, 1e19, 1e20, 1e21, 1e22,
    ];
    let mut r: i32 = 15;
    for step in [8, 4, 2, 1] {
        if value < VALUES[r as usize] { r -= step } else { r += step }
    }
    if value < VALUES[r as usize] {
        r -= 1;
    }
    r - 8
}

/// The "%15fe%d" → strtod path PHP uses for |places| >= 23.
fn via_string(tmp: f64, places: i32, value: f64) -> f64 {
    let s = format!("{tmp:15.6}e{}", -places);
    match s.trim().parse::<f64>() {
        Ok(v) if v.is_finite() => v,
        _ => value,
    }
}

/// round($value, $places) with PHP_ROUND_HALF_UP. PHP 8.4 rewrote it; both versions are here.
///
/// From 2^52 up, where a float has no fractional digits left, 8.4/8.5 patch releases disagree
/// (some round 2^52 up to 2^52 + 1), so those values are declined.
pub fn round(value: f64, places: i64) -> Result<f64, PhpError> {
    let places = places.clamp(i32::MIN as i64 + 1, i32::MAX as i64) as i32;
    if php_version() < 80400 {
        return Ok(round_82(value, places));
    }
    if value.is_finite() && value != 0.0 {
        let exponent = intpow10(places.abs());
        let scaled = if places > 0 { value * exponent } else { value / exponent };
        if scaled.abs() >= 4503599627370496.0 && scaled.abs() < 1e16 {
            return fallback();
        }
    }
    Ok(round_84(value, places))
}

fn round_84(value: f64, places: i32) -> f64 {
    if !value.is_finite() || value == 0.0 {
        return value;
    }
    if places == 0 && value == value.trunc() {
        return value;
    }
    let exponent = intpow10(places.abs());
    let scaled = if places > 0 { value * exponent } else { value / exponent };
    let (mut tmp, tmp2) =
        if value >= 0.0 { (scaled.floor(), scaled.floor() + 1.0) } else { (scaled.ceil(), scaled.ceil() - 1.0) };
    if (if places > 0 { tmp2 / exponent } else { tmp2 * exponent }) == value {
        tmp = tmp2;
    }
    if tmp.abs() >= 1e16 {
        return value;
    }
    // HALF_UP: away from zero when |value| reaches the midpoint.
    let edge = if places > 0 {
        ((tmp + 0.5f64.copysign(tmp)) / exponent).abs()
    } else {
        ((tmp + 0.5f64.copysign(tmp)) * exponent).abs()
    };
    if value.abs() >= edge {
        tmp += 1f64.copysign(tmp);
    }
    if places.abs() < 23 {
        if places > 0 { tmp / exponent } else { tmp * exponent }
    } else {
        via_string(tmp, places, value)
    }
}

fn round_helper_82(v: f64) -> f64 {
    if v >= 0.0 { (v + 0.5).floor() } else { (v - 0.5).ceil() }
}

fn round_82(value: f64, places: i32) -> f64 {
    if !value.is_finite() || value == 0.0 {
        return value;
    }
    let precision_places = 14 - intlog10abs(value);
    let f1 = intpow10(places.abs());
    let mut tmp;
    if precision_places > places && precision_places - 15 < places {
        let use_precision = precision_places.max(i32::MIN + 1);
        let f2 = intpow10(use_precision.abs());
        tmp = if use_precision >= 0 { value * f2 } else { value / f2 };
        tmp = round_helper_82(tmp);
        let use_precision = (places - precision_places).max(i32::MIN + 1);
        let f2 = intpow10(use_precision.abs());
        tmp /= f2;
    } else {
        tmp = if places >= 0 { value * f1 } else { value / f1 };
        if tmp.abs() >= 1e15 {
            return value;
        }
    }
    tmp = round_helper_82(tmp);
    if places.abs() < 23 { if places > 0 { tmp / f1 } else { tmp * f1 } } else { via_string(tmp, places, value) }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn rounding() {
        for v in [80300, 80500] {
            crate::set_php_version(v);
            assert_eq!(round(1.955, 2).unwrap(), 1.96);
            assert_eq!(round(0.285, 2).unwrap(), 0.29);
            assert_eq!(round(5.045, 2).unwrap(), 5.05);
            assert_eq!(round(5.055, 2).unwrap(), 5.06);
            assert_eq!(round(-1.5, 0).unwrap(), -2.0);
            assert_eq!(round(2.5, 0).unwrap(), 3.0);
            assert_eq!(round(1234567.891, -3).unwrap(), 1235000.0);
        }
        crate::set_php_version(80500);
    }

    #[test]
    fn overflow_goes_to_float() {
        assert_eq!(add(i64::MAX, 1), Num::Float(9223372036854775808.0));
        assert_eq!(mul(i64::MAX, 2), Num::Float(i64::MAX as f64 * 2.0));
        assert_eq!(pow_int(2, 62).unwrap(), Num::Int(1 << 62));
        assert_eq!(pow_int(2, 64).unwrap(), Num::Float(18446744073709551616.0));
        assert_eq!(div(Num::Int(6), Num::Int(3)).unwrap(), Num::Int(2));
        assert_eq!(div(Num::Int(7), Num::Int(2)).unwrap(), Num::Float(3.5));
    }
}
