//! Writing port results back as PHP values.

use crate::{PhpArray, PhpKey, PhpString, PhpValue};
use ext_php_rs::boxed::ZBox;
use ext_php_rs::types::{ArrayKey, ZendHashTable, ZendStr, Zval};

/// Rust values a port can return.
pub trait IntoPhp {
    fn into_php(self, zv: &mut Zval);
}

fn zval(v: impl IntoPhp) -> Zval {
    let mut zv = Zval::new();
    v.into_php(&mut zv);
    zv
}

impl IntoPhp for i64 {
    #[inline(always)]
    fn into_php(self, zv: &mut Zval) {
        zv.set_long(self);
    }
}

impl IntoPhp for usize {
    /// Indexes and counts: PHP ints.
    #[inline(always)]
    fn into_php(self, zv: &mut Zval) {
        zv.set_long(self as i64);
    }
}

impl IntoPhp for f64 {
    #[inline(always)]
    fn into_php(self, zv: &mut Zval) {
        zv.set_double(self);
    }
}

impl IntoPhp for bool {
    #[inline(always)]
    fn into_php(self, zv: &mut Zval) {
        zv.set_bool(self);
    }
}

impl IntoPhp for () {
    fn into_php(self, zv: &mut Zval) {
        zv.set_null();
    }
}

impl IntoPhp for PhpString {
    fn into_php(self, zv: &mut Zval) {
        zv.set_zend_string(ZendStr::new(&self.0, false));
    }
}

impl IntoPhp for String {
    fn into_php(self, zv: &mut Zval) {
        zv.set_zend_string(ZendStr::new(self.as_bytes(), false));
    }
}

impl IntoPhp for &str {
    fn into_php(self, zv: &mut Zval) {
        zv.set_zend_string(ZendStr::new(self.as_bytes(), false));
    }
}

impl<T: IntoPhp> IntoPhp for Option<T> {
    fn into_php(self, zv: &mut Zval) {
        match self {
            Some(v) => v.into_php(zv),
            None => zv.set_null(),
        }
    }
}

fn list<T: IntoPhp>(items: impl ExactSizeIterator<Item = T>) -> ZBox<ZendHashTable> {
    let mut ht = ZendHashTable::with_capacity(u32::try_from(items.len()).unwrap_or(u32::MAX));
    for v in items {
        // push only fails on a full array (2^31 elements).
        let _ = ht.push(zval(v));
    }
    ht
}

impl<T: IntoPhp> IntoPhp for Vec<T> {
    /// A list: keys 0..n.
    fn into_php(self, zv: &mut Zval) {
        zv.set_hashtable(list(self.into_iter()));
    }
}

impl<T: IntoPhp, const N: usize> IntoPhp for [T; N] {
    fn into_php(self, zv: &mut Zval) {
        zv.set_hashtable(list(self.into_iter()));
    }
}

macro_rules! tuple_into_php {
    ($($t:ident $i:tt),+) => {
        impl<$($t: IntoPhp),+> IntoPhp for ($($t,)+) {
            fn into_php(self, zv: &mut Zval) {
                let mut ht = ZendHashTable::new();
                $( let _ = ht.push(zval(self.$i)); )+
                zv.set_hashtable(ht);
            }
        }
    };
}
tuple_into_php!(A 0, B 1);
tuple_into_php!(A 0, B 1, C 2);
tuple_into_php!(A 0, B 1, C 2, D 3);

impl IntoPhp for PhpArray {
    fn into_php(self, zv: &mut Zval) {
        let mut ht = ZendHashTable::with_capacity(u32::try_from(self.len()).unwrap_or(u32::MAX));
        for (k, v) in self.into_entries() {
            let v = zval(v);
            let _ = match k {
                PhpKey::Int(i) => ht.insert_at_index(i, v),
                PhpKey::Str(s) => {
                    let key = ZendStr::new(&s.0, false);
                    ht.insert(ArrayKey::ZendString(&key), v)
                }
            };
        }
        zv.set_hashtable(ht);
    }
}

impl IntoPhp for PhpValue {
    fn into_php(self, zv: &mut Zval) {
        match self {
            PhpValue::Null => zv.set_null(),
            PhpValue::Bool(b) => zv.set_bool(b),
            PhpValue::Int(i) => zv.set_long(i),
            PhpValue::Float(f) => zv.set_double(f),
            PhpValue::Str(s) => s.into_php(zv),
            PhpValue::Array(a) => a.into_php(zv),
        }
    }
}

/// A port's result as ext-php-rs returns it: written with IntoPhp, no generic conversions.
pub struct Ret<T>(pub T);

impl<T: IntoPhp> ext_php_rs::convert::IntoZval for Ret<T> {
    const TYPE: ext_php_rs::flags::DataType = ext_php_rs::flags::DataType::Mixed;
    const NULLABLE: bool = false;

    fn set_zval(self, zv: &mut Zval, _persistent: bool) -> ext_php_rs::error::Result<()> {
        self.0.into_php(zv);
        Ok(())
    }
}
