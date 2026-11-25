//! fastphp-std exposed to PHP for tests/std/fuzz.php: `fastphp_std\call('trim', [$s])` runs the
//! Rust version of a builtin, so the fuzzer can compare it with PHP's own on the same inputs.
//! A declined call (PhpError::fallback()) throws an Exception with the message "DECLINED".
#![cfg_attr(windows, feature(abi_vectorcall))]

use ext_php_rs::exception::PhpException;
use ext_php_rs::prelude::*;
use ext_php_rs::types::Zval;
use fastphp_std::{Num, PhpArray, PhpError, PhpString, PhpValue, array, cmp, conv, hash, math, mb, pcre, string};
use fastphp_value::{FromPhp, Ret};

type R = Result<PhpValue, PhpError>;

struct Args(Vec<PhpValue>);

fn bad(what: &str) -> PhpError {
    PhpError::new("TypeError", format!("std-check: bad argument {what}"))
}

impl Args {
    fn get(&self, i: usize) -> Option<&PhpValue> {
        self.0.get(i).filter(|v| !matches!(v, PhpValue::Null))
    }
    fn bytes(&self, i: usize) -> Result<&[u8], PhpError> {
        match self.0.get(i) {
            Some(PhpValue::Str(s)) => Ok(&s.0),
            _ => Err(bad("string")),
        }
    }
    fn int(&self, i: usize) -> Result<i64, PhpError> {
        match self.0.get(i) {
            Some(PhpValue::Int(n)) => Ok(*n),
            _ => Err(bad("int")),
        }
    }
    fn opt_int(&self, i: usize) -> Result<Option<i64>, PhpError> {
        self.get(i).map(|_| self.int(i)).transpose()
    }
    fn float(&self, i: usize) -> Result<f64, PhpError> {
        match self.0.get(i) {
            Some(PhpValue::Float(f)) => Ok(*f),
            _ => Err(bad("float")),
        }
    }
    fn num(&self, i: usize) -> Result<Num, PhpError> {
        match self.0.get(i) {
            Some(PhpValue::Int(n)) => Ok(Num::Int(*n)),
            Some(PhpValue::Float(f)) => Ok(Num::Float(*f)),
            _ => Err(bad("number")),
        }
    }
    fn array(&self, i: usize) -> Result<&PhpArray, PhpError> {
        match self.0.get(i) {
            Some(PhpValue::Array(a)) => Ok(a),
            _ => Err(bad("array")),
        }
    }
    fn list(&self, i: usize) -> Result<Vec<PhpValue>, PhpError> {
        Ok(self.array(i)?.values().cloned().collect())
    }
    fn value(&self, i: usize) -> Result<&PhpValue, PhpError> {
        self.0.get(i).ok_or_else(|| bad("value"))
    }
}

fn s(b: impl Into<Vec<u8>>) -> PhpValue {
    PhpValue::Str(PhpString(b.into()))
}

fn strs(v: Vec<PhpString>) -> PhpValue {
    PhpValue::Array(v.into_iter().map(PhpValue::Str).collect())
}

fn pos(p: Option<usize>) -> PhpValue {
    p.map_or(PhpValue::Bool(false), |p| PhpValue::Int(p as i64))
}

fn dispatch(name: &str, a: &Args) -> R {
    Ok(match name {
        "trim" => s(match a.get(1) {
            Some(_) => string::trim_chars(a.bytes(0)?, a.bytes(1)?)?,
            None => string::trim(a.bytes(0)?),
        }),
        "ltrim" => s(match a.get(1) {
            Some(_) => string::ltrim_chars(a.bytes(0)?, a.bytes(1)?)?,
            None => string::ltrim(a.bytes(0)?),
        }),
        "rtrim" => s(match a.get(1) {
            Some(_) => string::rtrim_chars(a.bytes(0)?, a.bytes(1)?)?,
            None => string::rtrim(a.bytes(0)?),
        }),
        "strtolower" => s(string::strtolower(a.bytes(0)?)),
        "strtoupper" => s(string::strtoupper(a.bytes(0)?)),
        "ucfirst" => s(string::ucfirst(a.bytes(0)?)),
        "lcfirst" => s(string::lcfirst(a.bytes(0)?)),
        "ucwords" => {
            s(string::ucwords(a.bytes(0)?, if a.get(1).is_some() { a.bytes(1)? } else { string::UCWORDS_DEFAULT })?)
        }
        "str_pad" => s(string::str_pad(a.bytes(0)?, a.int(1)?, a.bytes(2)?, a.int(3)?)?),
        "substr" => s(string::substr(a.bytes(0)?, a.int(1)?, a.opt_int(2)?)),
        "strrev" => s(string::strrev(a.bytes(0)?)),
        "str_repeat" => s(string::str_repeat(a.bytes(0)?, a.int(1)?)?),
        "str_replace" => s(string::str_replace(a.bytes(0)?, a.bytes(1)?, a.bytes(2)?).0),
        "str_ireplace" => s(string::str_ireplace(a.bytes(0)?, a.bytes(1)?, a.bytes(2)?).0),
        "explode" => strs(string::explode(a.bytes(0)?, a.bytes(1)?, a.int(2)?)?),
        "implode" => PhpValue::Str(string::implode(a.bytes(0)?, &a.list(1)?)?),
        "strpos" => pos(string::strpos(a.bytes(0)?, a.bytes(1)?, a.int(2)?)?),
        "stripos" => pos(string::stripos(a.bytes(0)?, a.bytes(1)?, a.int(2)?)?),
        "strrpos" => pos(string::strrpos(a.bytes(0)?, a.bytes(1)?, a.int(2)?)?),
        "substr_count" => PhpValue::Int(string::substr_count(a.bytes(0)?, a.bytes(1)?)?),
        "str_contains" => PhpValue::Bool(string::str_contains(a.bytes(0)?, a.bytes(1)?)),
        "str_starts_with" => PhpValue::Bool(string::str_starts_with(a.bytes(0)?, a.bytes(1)?)),
        "str_ends_with" => PhpValue::Bool(string::str_ends_with(a.bytes(0)?, a.bytes(1)?)),
        "str_split" => strs(string::str_split(a.bytes(0)?, a.int(1)?)?),
        "levenshtein" => PhpValue::Int(string::levenshtein(a.bytes(0)?, a.bytes(1)?)),
        "similar_text" => {
            let (n, p) = string::similar_text(a.bytes(0)?, a.bytes(1)?);
            PhpValue::Array([PhpValue::Int(n), PhpValue::Float(p)].into_iter().collect())
        }
        "number_format" => s(string::number_format(a.num(0)?, a.int(1)?, a.bytes(2)?, a.bytes(3)?)?),
        "float_to_string" => s(conv::float_to_string(a.float(0)?)?),
        "float_export" => s(conv::float_export(a.float(0)?)),
        "is_numeric" => PhpValue::Bool(conv::is_numeric(a.bytes(0)?)),
        "str_to_int" => PhpValue::Int(conv::str_to_int(a.bytes(0)?)),
        "str_to_float" => PhpValue::Float(conv::str_to_float(a.bytes(0)?)),
        "float_to_int" => PhpValue::Int(conv::float_to_int(a.float(0)?)?),
        "round" => PhpValue::Float(math::round(a.float(0)?, a.int(1)?)?),
        "add" => math::add_num(a.num(0)?, a.num(1)?).into(),
        "sub" => math::sub_num(a.num(0)?, a.num(1)?).into(),
        "mul" => math::mul_num(a.num(0)?, a.num(1)?).into(),
        "div" => math::div(a.num(0)?, a.num(1)?)?.into(),
        "rem" => PhpValue::Int(math::rem(a.int(0)?, a.int(1)?)?),
        "intdiv" => PhpValue::Int(math::intdiv(a.int(0)?, a.int(1)?)?),
        "pow" => math::pow_int(a.int(0)?, a.int(1)?)?.into(),
        "abs" => math::abs(a.num(0)?).into(),
        "compare" => PhpValue::Int(cmp::compare(a.value(0)?, a.value(1)?)? as i64),
        "loose_eq" => PhpValue::Bool(cmp::loose_eq(a.value(0)?, a.value(1)?)?),
        "sort" => {
            let mut v = a.list(0)?;
            cmp::sort(&mut v)?;
            PhpValue::Array(v.into_iter().collect())
        }
        "asort" => PhpValue::Array(cmp::asort(a.array(0)?, false)?),
        "ksort" => PhpValue::Array(cmp::ksort(a.array(0)?, false)?),
        "mb_strlen" => PhpValue::Int(mb::mb_strlen(a.bytes(0)?)?),
        "mb_substr" => PhpValue::Str(mb::mb_substr(a.bytes(0)?, a.int(1)?, a.opt_int(2)?)?),
        "mb_strtolower" => PhpValue::Str(mb::mb_strtolower(a.bytes(0)?)?),
        "mb_strtoupper" => PhpValue::Str(mb::mb_strtoupper(a.bytes(0)?)?),
        "mb_str_split" => strs(mb::mb_str_split(a.bytes(0)?, a.int(1)?)?),
        "preg_match" => pcre::preg_match(a.bytes(0)?, a.bytes(1)?)?.map_or(PhpValue::Bool(false), PhpValue::Array),
        "preg_match_all" => {
            let (n, m) = pcre::preg_match_all(a.bytes(0)?, a.bytes(1)?)?;
            PhpValue::Array([PhpValue::Int(n), PhpValue::Array(m)].into_iter().collect())
        }
        "preg_replace" => {
            let (r, n) = pcre::preg_replace(a.bytes(0)?, a.bytes(1)?, a.bytes(2)?, a.int(3)?)?;
            PhpValue::Array([PhpValue::Str(r), PhpValue::Int(n)].into_iter().collect())
        }
        "preg_split" => strs(pcre::preg_split(a.bytes(0)?, a.bytes(1)?, a.int(2)?, a.int(3)?)?),
        "preg_quote" => {
            let d = a.get(1).map(|_| a.bytes(1)).transpose()?.and_then(|d| d.first().copied());
            s(pcre::preg_quote(a.bytes(0)?, d))
        }
        "crc32" => PhpValue::Int(hash::crc32(a.bytes(0)?)),
        "md5" => s(hash::md5(a.bytes(0)?)),
        "sha1" => s(hash::sha1(a.bytes(0)?)),
        "range" => PhpValue::Array(array::range(a.int(0)?, a.int(1)?, a.int(2)?)?.into_iter().collect()),
        "array_sum" => array::array_sum(&a.list(0)?)?.into(),
        "array_unique" => PhpValue::Array(array::array_unique(a.array(0)?)?),
        "array_slice" => PhpValue::Array(array::array_slice(
            a.array(0)?,
            a.int(1)?,
            a.opt_int(2)?,
            matches!(a.value(3)?, PhpValue::Bool(true)),
        )),
        _ => return Err(PhpError::new("Error", format!("std-check: no function {name}"))),
    })
}

#[php_function]
#[php(name = "fastphp_std\\call")]
pub fn std_call(name: &Zval, args: &Zval) -> Result<Ret<PhpValue>, PhpException> {
    let name = String::from_php(name).ok_or_else(|| PhpException::from(bad("name")))?;
    let args = Vec::<PhpValue>::from_php(args).ok_or_else(|| PhpException::from(bad("args")))?;
    match dispatch(&name, &Args(args)) {
        Ok(v) => Ok(Ret(v)),
        Err(e) if e.is_fallback() => Err(PhpException::from_message("DECLINED".to_string())),
        Err(e) => Err(e.into()),
    }
}

#[php_module]
pub fn get_module(module: ModuleBuilder) -> ModuleBuilder {
    fastphp_std::set_php_version(fastphp_std::version_from_api(ext_php_rs::ffi::ZEND_MODULE_API_NO));
    module.function(wrap_function!(std_call))
}
