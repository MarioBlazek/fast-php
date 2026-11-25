//! Reading zvals without detours.
//!
//! ext-php-rs's generic array conversions build an `ArrayKey` (often a heap String) for every
//! element; for a list of 1,000 ints that costs more than the PHP loop it replaces. This reads
//! the zend_array storage directly: packed arrays are a plain zval vector (PHP >= 8.2), hash
//! arrays a Bucket vector. Holes (unset elements) are skipped like PHP's foreach does.

use crate::{PhpArray, PhpKey, PhpString, PhpValue};
use ext_php_rs::ffi::{Bucket, zend_array, zend_reference, zend_string};
use ext_php_rs::types::Zval;

const IS_UNDEF: u8 = 0;
const IS_NULL: u8 = 1;
const IS_FALSE: u8 = 2;
const IS_TRUE: u8 = 3;
const IS_LONG: u8 = 4;
const IS_DOUBLE: u8 = 5;
const IS_STRING: u8 = 6;
const IS_ARRAY: u8 = 7;
const IS_REFERENCE: u8 = 10;
const IS_INDIRECT: u8 = 12;
/// HASH_FLAG_PACKED (Zend/zend_hash.h).
const HASH_FLAG_PACKED: u32 = 1 << 2;

#[inline(always)]
fn tag(zv: &Zval) -> u8 {
    // SAFETY: every zval has a type byte.
    unsafe { zv.u1.v.type_ }
}

/// Follows references and indirections to the value itself.
#[inline(always)]
pub fn deref(mut zv: &Zval) -> &Zval {
    loop {
        match tag(zv) {
            // SAFETY: the tag says which union member is live.
            IS_REFERENCE => zv = unsafe { &(*zv.value.ref_.cast::<zend_reference>()).val },
            IS_INDIRECT => zv = unsafe { &*zv.value.zv },
            _ => return zv,
        }
    }
}

#[inline(always)]
unsafe fn bytes<'a>(s: *const zend_string) -> &'a [u8] {
    unsafe { std::slice::from_raw_parts((*s).val.as_ptr().cast::<u8>(), (*s).len) }
}

/// A key as stored: an int, or the bytes of a string key.
pub enum RawKey<'a> {
    Int(i64),
    Str(&'a [u8]),
}

/// The array behind a zval, if it is one.
#[inline(always)]
pub fn array(zv: &Zval) -> Option<&zend_array> {
    let zv = deref(zv);
    // SAFETY: tag checked.
    (tag(zv) == IS_ARRAY).then(|| unsafe { &*zv.value.arr })
}

/// Calls f for every element in order, stopping early when f returns false.
#[inline(always)]
pub fn each<'a>(ht: &'a zend_array, mut f: impl FnMut(RawKey<'a>, &'a Zval) -> bool) {
    let used = ht.nNumUsed as usize;
    // SAFETY: flags, arPacked/arData and nNumUsed describe the live storage of the array.
    unsafe {
        if ht.u.flags & HASH_FLAG_PACKED != 0 {
            let slots = std::slice::from_raw_parts(ht.__bindgen_anon_1.arPacked as *const Zval, used);
            for (i, zv) in slots.iter().enumerate() {
                if tag(zv) != IS_UNDEF && !f(RawKey::Int(i as i64), zv) {
                    return;
                }
            }
        } else {
            let slots = std::slice::from_raw_parts(ht.__bindgen_anon_1.arData as *const Bucket, used);
            for b in slots {
                let zv: &Zval = &b.val;
                if tag(zv) == IS_UNDEF {
                    continue;
                }
                let key = if b.key.is_null() { RawKey::Int(b.h as i64) } else { RawKey::Str(bytes(b.key)) };
                if !f(key, zv) {
                    return;
                }
            }
        }
    }
}

/// Rust values a port can take, read straight from a zval. None means the value doesn't fit
/// the type exactly; the glue then lets the original PHP handle the call (or throws a TypeError
/// naming the argument when it has no fallback). Conversions never coerce: an int is not a
/// float, an array with string keys or gaps is not a Vec.
pub trait FromPhp: Sized {
    fn from_php(zv: &Zval) -> Option<Self>;
}

impl FromPhp for i64 {
    #[inline(always)]
    #[allow(clippy::unnecessary_lazy_evaluations)] // read the union only once the tag says it's an int
    fn from_php(zv: &Zval) -> Option<Self> {
        let zv = deref(zv);
        (tag(zv) == IS_LONG).then(|| unsafe { zv.value.lval })
    }
}

impl FromPhp for f64 {
    /// Floats only: an int would come back as a float where PHP keeps an int.
    #[inline(always)]
    #[allow(clippy::unnecessary_lazy_evaluations)]
    fn from_php(zv: &Zval) -> Option<Self> {
        let zv = deref(zv);
        (tag(zv) == IS_DOUBLE).then(|| unsafe { zv.value.dval })
    }
}

impl FromPhp for bool {
    #[inline(always)]
    fn from_php(zv: &Zval) -> Option<Self> {
        match tag(deref(zv)) {
            IS_TRUE => Some(true),
            IS_FALSE => Some(false),
            _ => None,
        }
    }
}

impl FromPhp for PhpString {
    #[inline(always)]
    fn from_php(zv: &Zval) -> Option<Self> {
        let zv = deref(zv);
        (tag(zv) == IS_STRING).then(|| PhpString(unsafe { bytes(zv.value.str_) }.to_vec()))
    }
}

impl FromPhp for String {
    /// Only for UTF-8 strings; use PhpString for bytes.
    #[inline(always)]
    fn from_php(zv: &Zval) -> Option<Self> {
        let zv = deref(zv);
        if tag(zv) != IS_STRING {
            return None;
        }
        std::str::from_utf8(unsafe { bytes(zv.value.str_) }).ok().map(str::to_owned)
    }
}

impl<T: FromPhp> FromPhp for Option<T> {
    #[inline(always)]
    fn from_php(zv: &Zval) -> Option<Self> {
        if tag(deref(zv)) == IS_NULL { Some(None) } else { T::from_php(zv).map(Some) }
    }
}

impl<T: FromPhp> FromPhp for Vec<T> {
    /// A list only (keys 0, 1, 2, … in order, as array_is_list() says): dropping other keys
    /// would change what PHP code that reads them sees.
    fn from_php(zv: &Zval) -> Option<Self> {
        let ht = array(zv)?;
        let mut out = Vec::with_capacity(ht.nNumOfElements as usize);
        let mut ok = true;
        each(ht, |k, v| match (k, T::from_php(v)) {
            (RawKey::Int(i), Some(x)) if i == out.len() as i64 => {
                out.push(x);
                true
            }
            _ => {
                ok = false;
                false
            }
        });
        ok.then_some(out)
    }
}

impl FromPhp for PhpValue {
    fn from_php(zv: &Zval) -> Option<Self> {
        let zv = deref(zv);
        // SAFETY: each arm reads the union member its tag names.
        Some(match tag(zv) {
            IS_UNDEF | IS_NULL => PhpValue::Null,
            IS_FALSE => PhpValue::Bool(false),
            IS_TRUE => PhpValue::Bool(true),
            IS_LONG => PhpValue::Int(unsafe { zv.value.lval }),
            IS_DOUBLE => PhpValue::Float(unsafe { zv.value.dval }),
            IS_STRING => PhpValue::Str(PhpString(unsafe { bytes(zv.value.str_) }.to_vec())),
            IS_ARRAY => PhpValue::Array(PhpArray::from_php(zv)?),
            _ => return None,
        })
    }
}

impl FromPhp for PhpArray {
    fn from_php(zv: &Zval) -> Option<Self> {
        let ht = array(zv)?;
        let mut a = PhpArray::with_capacity(ht.nNumOfElements as usize);
        let mut ok = true;
        each(ht, |k, v| match PhpValue::from_php(v) {
            Some(v) => {
                // Keys come from a real PHP array, so string keys are never numeric: no re-check.
                let key = match k {
                    RawKey::Int(i) => PhpKey::Int(i),
                    RawKey::Str(s) => PhpKey::Str(PhpString(s.to_vec())),
                };
                a.insert(key, v);
                true
            }
            None => {
                ok = false;
                false
            }
        });
        ok.then_some(a)
    }
}

/// Tuples for fixed-shape rows like [$i, $j] or [$name, $score]: lists of exactly that length.
macro_rules! tuple_from_php {
    ($($t:ident),+) => {
        impl<$($t: FromPhp),+> FromPhp for ($($t,)+) {
            fn from_php(zv: &Zval) -> Option<Self> {
                let ht = array(zv)?;
                let mut vals: [Option<&Zval>; { [$(stringify!($t)),+].len() }] = Default::default();
                let mut n = 0;
                let mut list = true;
                each(ht, |k, v| {
                    list &= matches!(k, RawKey::Int(i) if i == n as i64);
                    if n < vals.len() { vals[n] = Some(v); }
                    n += 1;
                    list && n <= vals.len()
                });
                if !list || n != vals.len() { return None; }
                let mut it = vals.into_iter();
                Some(($($t::from_php(it.next()??)?,)+))
            }
        }
    };
}
tuple_from_php!(A, B);
tuple_from_php!(A, B, C);
tuple_from_php!(A, B, C, D);
