//! Exact PHP values in Rust.
//!
//! JSON can't carry a PHP value faithfully: it loses int vs string array keys, gaps in lists,
//! `3` vs `3.0` and binary strings. These types keep all of that, so a port sees exactly what
//! the PHP code saw and hands back exactly what PHP expects.

use ext_php_rs::boxed::ZBox;
use ext_php_rs::convert::{FromZval, IntoZval};
use ext_php_rs::error::Result;
use ext_php_rs::exception::PhpException;
use ext_php_rs::flags::DataType;
use ext_php_rs::types::{ArrayKey, ZendHashTable, ZendStr, Zval};
use ext_php_rs::zend::ClassEntry;
use indexmap::IndexMap;
use std::fmt;

/// A PHP string: bytes, not necessarily UTF-8.
#[derive(Clone, Default, PartialEq, Eq, Hash, PartialOrd, Ord)]
pub struct PhpString(pub Vec<u8>);

impl PhpString {
    pub fn as_bytes(&self) -> &[u8] {
        &self.0
    }

    pub fn to_str(&self) -> Option<&str> {
        std::str::from_utf8(&self.0).ok()
    }
}

impl fmt::Debug for PhpString {
    fn fmt(&self, f: &mut fmt::Formatter<'_>) -> fmt::Result {
        write!(f, "{:?}", String::from_utf8_lossy(&self.0))
    }
}

impl From<&str> for PhpString {
    fn from(s: &str) -> Self {
        PhpString(s.as_bytes().to_vec())
    }
}

impl From<String> for PhpString {
    fn from(s: String) -> Self {
        PhpString(s.into_bytes())
    }
}

impl From<Vec<u8>> for PhpString {
    fn from(b: Vec<u8>) -> Self {
        PhpString(b)
    }
}

/// An array key, as PHP stores it: an int, or a string that isn't a canonical decimal int.
#[derive(Clone, PartialEq, Eq, Hash, PartialOrd, Ord)]
pub enum PhpKey {
    Int(i64),
    Str(PhpString),
}

impl fmt::Debug for PhpKey {
    fn fmt(&self, f: &mut fmt::Formatter<'_>) -> fmt::Result {
        match self {
            PhpKey::Int(i) => write!(f, "{i}"),
            PhpKey::Str(s) => write!(f, "{s:?}"),
        }
    }
}

/// PHP's ZEND_HANDLE_NUMERIC_STR: "12" and "-3" are int keys, "012", "+1", "-0" and "1.0" are not.
pub fn numeric_key(bytes: &[u8]) -> Option<i64> {
    let digits = bytes.strip_prefix(b"-").unwrap_or(bytes);
    let canonical = match digits {
        [b'0'] => bytes.len() == 1,
        [b'1'..=b'9', rest @ ..] => rest.iter().all(u8::is_ascii_digit),
        _ => false,
    };
    if !canonical {
        return None;
    }
    std::str::from_utf8(bytes).ok()?.parse().ok()
}

impl PhpKey {
    /// A string key, turned into an int key the way PHP does (`$a["1"]` is `$a[1]`).
    pub fn from_bytes(bytes: impl Into<Vec<u8>>) -> PhpKey {
        let bytes = bytes.into();
        match numeric_key(&bytes) {
            Some(i) => PhpKey::Int(i),
            None => PhpKey::Str(PhpString(bytes)),
        }
    }
}

impl From<i64> for PhpKey {
    fn from(i: i64) -> Self {
        PhpKey::Int(i)
    }
}

impl From<&str> for PhpKey {
    fn from(s: &str) -> Self {
        PhpKey::from_bytes(s.as_bytes())
    }
}

/// A PHP array: ordered, int or string keys, remembers the next free int key.
#[derive(Clone, Default, PartialEq)]
pub struct PhpArray {
    entries: IndexMap<PhpKey, PhpValue>,
    next: i64,
}

impl fmt::Debug for PhpArray {
    fn fmt(&self, f: &mut fmt::Formatter<'_>) -> fmt::Result {
        f.debug_map().entries(self.entries.iter()).finish()
    }
}

impl PhpArray {
    pub fn new() -> Self {
        Self::default()
    }

    pub fn with_capacity(n: usize) -> Self {
        PhpArray { entries: IndexMap::with_capacity(n), next: 0 }
    }

    pub fn len(&self) -> usize {
        self.entries.len()
    }

    pub fn is_empty(&self) -> bool {
        self.entries.is_empty()
    }

    /// `$a[] = $v`
    pub fn push(&mut self, v: impl Into<PhpValue>) {
        let k = self.next;
        self.insert(PhpKey::Int(k), v);
    }

    /// `$a[$k] = $v` (keeps the position of an existing key, like PHP).
    pub fn insert(&mut self, k: impl Into<PhpKey>, v: impl Into<PhpValue>) {
        let k = k.into();
        if let PhpKey::Int(i) = k
            && i >= self.next
        {
            self.next = i.saturating_add(1);
        }
        self.entries.insert(k, v.into());
    }

    pub fn get(&self, k: &PhpKey) -> Option<&PhpValue> {
        self.entries.get(k)
    }

    pub fn iter(&self) -> impl Iterator<Item = (&PhpKey, &PhpValue)> {
        self.entries.iter()
    }

    pub fn values(&self) -> impl Iterator<Item = &PhpValue> {
        self.entries.values()
    }

    pub fn into_entries(self) -> impl Iterator<Item = (PhpKey, PhpValue)> {
        self.entries.into_iter()
    }

    /// `array_is_list()`: keys are 0, 1, 2, ... in order.
    pub fn is_list(&self) -> bool {
        self.entries.keys().enumerate().all(|(i, k)| *k == PhpKey::Int(i as i64))
    }
}

impl<V: Into<PhpValue>> FromIterator<V> for PhpArray {
    fn from_iter<T: IntoIterator<Item = V>>(iter: T) -> Self {
        let mut a = PhpArray::new();
        for v in iter {
            a.push(v);
        }
        a
    }
}

/// Any PHP value a port can take or return. Objects and resources aren't ports' business.
#[derive(Clone, Debug, PartialEq, Default)]
pub enum PhpValue {
    #[default]
    Null,
    Bool(bool),
    Int(i64),
    Float(f64),
    Str(PhpString),
    Array(PhpArray),
}

impl PhpValue {
    /// PHP's `===` (floats compare like PHP: NAN !== NAN).
    pub fn identical(&self, other: &PhpValue) -> bool {
        match (self, other) {
            (PhpValue::Array(a), PhpValue::Array(b)) => {
                a.len() == b.len() && a.iter().zip(b.iter()).all(|((ka, va), (kb, vb))| ka == kb && va.identical(vb))
            }
            (PhpValue::Float(a), PhpValue::Float(b)) => a == b,
            _ => self == other,
        }
    }
}

macro_rules! from_for_value {
    ($($t:ty => $variant:ident $(as $cast:ty)?),* $(,)?) => {$(
        impl From<$t> for PhpValue {
            fn from(v: $t) -> Self {
                PhpValue::$variant((v $(as $cast)?).into())
            }
        }
    )*};
}

from_for_value! {
    bool => Bool, i64 => Int, i32 => Int as i64, usize => Int as i64, f64 => Float,
    PhpString => Str, &str => Str, String => Str, PhpArray => Array,
}

impl<T: Into<PhpValue>> From<Option<T>> for PhpValue {
    fn from(v: Option<T>) -> Self {
        v.map_or(PhpValue::Null, Into::into)
    }
}

impl<T: Into<PhpValue>> From<Vec<T>> for PhpValue {
    fn from(v: Vec<T>) -> Self {
        PhpValue::Array(v.into_iter().collect())
    }
}

// ------------------------------------------------------------------ zval conversions

fn key_of(k: ArrayKey<'_>) -> PhpKey {
    match k {
        ArrayKey::Long(i) => PhpKey::Int(i),
        ArrayKey::String(s) => PhpKey::Str(PhpString(s.into_bytes())),
        ArrayKey::Str(s) => PhpKey::Str(PhpString(s.as_bytes().to_vec())),
        ArrayKey::ZendString(s) => PhpKey::Str(PhpString(s.as_bytes().to_vec())),
    }
}

/// Reads a zval. None for objects, resources and anything else a port can't take.
pub fn from_zval(zv: &Zval) -> Option<PhpValue> {
    raw::FromPhp::from_php(zv)
}

pub fn array_from_ht(ht: &ZendHashTable) -> Option<PhpArray> {
    let mut a = PhpArray::with_capacity(ht.len());
    for (k, v) in ht.iter() {
        a.insert(key_of(k), from_zval(v)?);
    }
    Some(a)
}

pub fn array_into_ht(a: PhpArray) -> Result<ZBox<ZendHashTable>> {
    let mut ht = ZendHashTable::with_capacity(u32::try_from(a.len()).unwrap_or(u32::MAX));
    for (k, v) in a.entries {
        match k {
            PhpKey::Int(i) => ht.insert_at_index(i, v)?,
            PhpKey::Str(s) => {
                let key = ZendStr::new(&s.0, false);
                ht.insert(ArrayKey::ZendString(&key), v)?;
            }
        }
    }
    Ok(ht)
}

impl<'a> FromZval<'a> for PhpValue {
    const TYPE: DataType = DataType::Mixed;
    // mixed already includes null; "?mixed" isn't a valid PHP type.
    const NULLABLE: bool = false;

    fn from_zval(zv: &'a Zval) -> Option<Self> {
        from_zval(zv)
    }
}

impl IntoZval for PhpValue {
    const TYPE: DataType = DataType::Mixed;
    // mixed already includes null; "?mixed" isn't a valid PHP type.
    const NULLABLE: bool = false;

    fn set_zval(self, zv: &mut Zval, _persistent: bool) -> Result<()> {
        match self {
            PhpValue::Null => zv.set_null(),
            PhpValue::Bool(b) => zv.set_bool(b),
            PhpValue::Int(i) => zv.set_long(i),
            PhpValue::Float(f) => zv.set_double(f),
            PhpValue::Str(s) => zv.set_zend_string(ZendStr::new(&s.0, false)),
            PhpValue::Array(a) => zv.set_hashtable(array_into_ht(a)?),
        }
        Ok(())
    }
}

impl<'a> FromZval<'a> for PhpString {
    const TYPE: DataType = DataType::String;

    fn from_zval(zv: &'a Zval) -> Option<Self> {
        Some(PhpString(zv.dereference().zend_str()?.as_bytes().to_vec()))
    }
}

impl IntoZval for PhpString {
    const TYPE: DataType = DataType::String;
    const NULLABLE: bool = false;

    fn set_zval(self, zv: &mut Zval, _persistent: bool) -> Result<()> {
        zv.set_zend_string(ZendStr::new(&self.0, false));
        Ok(())
    }
}

impl<'a> FromZval<'a> for PhpArray {
    const TYPE: DataType = DataType::Array;

    fn from_zval(zv: &'a Zval) -> Option<Self> {
        array_from_ht(zv.dereference().array()?)
    }
}

impl IntoZval for PhpArray {
    const TYPE: DataType = DataType::Array;
    const NULLABLE: bool = false;

    fn set_zval(self, zv: &mut Zval, _persistent: bool) -> Result<()> {
        zv.set_hashtable(array_into_ht(self)?);
        Ok(())
    }
}

/// An error a port returns: thrown in PHP as `class` (an existing Throwable class) with `message`.
#[derive(Debug, Clone, PartialEq)]
pub struct PhpError {
    pub class: String,
    pub message: String,
}

impl PhpError {
    pub fn new(class: impl Into<String>, message: impl Into<String>) -> Self {
        PhpError { class: class.into(), message: message.into() }
    }

    pub fn value(message: impl Into<String>) -> Self {
        Self::new("ValueError", message)
    }

    /// Not an error: the port declines this input and the original PHP runs instead. Only
    /// generated glue with a fallback (functions that never return null) can honor it.
    pub fn fallback() -> Self {
        Self::new("", "")
    }

    pub fn is_fallback(&self) -> bool {
        self.class.is_empty()
    }

    /// A port panicked: report it as a PHP Error instead of unwinding into the engine.
    pub fn panicked(port: &str, payload: Box<dyn std::any::Any + Send>) -> Self {
        let msg = payload
            .downcast_ref::<&str>()
            .map(|s| s.to_string())
            .or_else(|| payload.downcast_ref::<String>().cloned())
            .unwrap_or_else(|| "unknown panic".into());
        Self::new("Error", format!("fast-php: the port of {port} panicked: {msg}"))
    }

    pub fn type_error(message: impl Into<String>) -> Self {
        Self::new("TypeError", message)
    }
}

/// Thrown as the original class when PHP knows it, else as `Exception` with the class in the message.
impl From<PhpError> for PhpException {
    fn from(e: PhpError) -> Self {
        match ClassEntry::try_find(&e.class) {
            Some(ce) => PhpException::new(e.message, 0, ce),
            None => PhpException::from_message(format!("{}: {}", e.class, e.message)),
        }
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn numeric_keys_like_php() {
        assert_eq!(numeric_key(b"0"), Some(0));
        assert_eq!(numeric_key(b"12"), Some(12));
        assert_eq!(numeric_key(b"-3"), Some(-3));
        for not in [&b"-0"[..], b"012", b"+1", b"1.0", b" 1", b"", b"-", b"99999999999999999999"] {
            assert_eq!(numeric_key(not), None, "{:?}", String::from_utf8_lossy(not));
        }
        assert_eq!(PhpKey::from("7"), PhpKey::Int(7));
        assert_eq!(PhpKey::from("07"), PhpKey::Str("07".into()));
    }

    #[test]
    fn arrays_behave_like_php() {
        let mut a = PhpArray::new();
        a.insert(5, 1i64);
        a.push(2i64);
        a.insert("x", 3i64);
        a.insert(5, 9i64);
        let keys: Vec<_> = a.iter().map(|(k, _)| k.clone()).collect();
        assert_eq!(keys, vec![PhpKey::Int(5), PhpKey::Int(6), PhpKey::from("x")]);
        assert_eq!(a.get(&PhpKey::Int(5)), Some(&PhpValue::Int(9)));
        assert!(!a.is_list());
        assert!(PhpArray::from_iter([1i64, 2, 3]).is_list());
    }

    #[test]
    fn identical_is_strict() {
        assert!(!PhpValue::Int(3).identical(&PhpValue::Float(3.0)));
        assert!(!PhpValue::Float(f64::NAN).identical(&PhpValue::Float(f64::NAN)));
        let ab: PhpArray = [("a", 1i64), ("b", 2)].into_iter().fold(PhpArray::new(), |mut a, (k, v)| {
            a.insert(k, v);
            a
        });
        let ba: PhpArray = [("b", 2i64), ("a", 1)].into_iter().fold(PhpArray::new(), |mut a, (k, v)| {
            a.insert(k, v);
            a
        });
        assert!(!PhpValue::Array(ab.clone()).identical(&PhpValue::Array(ba)));
        assert!(PhpValue::Array(ab.clone()).identical(&PhpValue::Array(ab)));
    }
}

pub mod out;
pub mod raw;
pub use out::{IntoPhp, Ret};
pub use raw::FromPhp;

/// Reads argument `n` (1-based, for the message) of a port, or throws PHP's TypeError.
pub fn arg<T: FromPhp>(zv: &Zval, n: usize, name: &str, want: &str) -> std::result::Result<T, PhpException> {
    T::from_php(zv).ok_or_else(|| {
        PhpError::type_error(format!("Argument #{n} (${name}) must be of type {want}, {} given", type_name(zv))).into()
    })
}

/// PHP's name for a value's type, as TypeError messages print it.
pub fn type_name(zv: &Zval) -> &'static str {
    match raw::deref(zv).get_type() {
        DataType::Null | DataType::Undef => "null",
        DataType::False | DataType::True | DataType::Bool => "bool",
        DataType::Long => "int",
        DataType::Double => "float",
        DataType::String => "string",
        DataType::Array => "array",
        DataType::Object(_) => "object",
        DataType::Resource => "resource",
        _ => "mixed",
    }
}
