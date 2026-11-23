//! Phase 0 spike: can a Rust extension take and return exact PHP values, throw PHP's own
//! exception classes, and how much does a call into it cost?
#![cfg_attr(windows, feature(abi_vectorcall))]
#![allow(non_snake_case)]

mod fuzzy;

use ext_php_rs::exception::PhpException;
use ext_php_rs::prelude::*;
use ext_php_rs::types::Zval;
use fastphp_value::{PhpError, PhpValue, Ret, arg};

/// Takes any value and gives it back: proves the conversions are lossless.
#[php_function]
pub fn fastphp_spike_roundtrip(v: PhpValue) -> PhpValue {
    v
}

/// The cheapest possible call: measures the crossing alone.
#[php_function]
pub fn fastphp_spike_noop() -> i64 {
    0
}

/// A typed list in, an int out.
#[php_function]
pub fn fastphp_spike_sum(xs: Vec<i64>) -> i64 {
    xs.iter().sum()
}

/// Ports throw PHP's own exception classes.
#[php_function]
pub fn fastphp_spike_throw(class: String) -> Result<i64, PhpException> {
    Err(PhpError::new(class, "thrown from rust").into())
}

/// Fuzzy::levenshtein (by character), one call per pair: the "port the inner function" case.
#[php_function]
pub fn fastphp_spike_levenshtein(a: String, b: String) -> i64 {
    let (a, b): (Vec<char>, Vec<char>) = (a.chars().collect(), b.chars().collect());
    fuzzy::distance(&a, &b, &mut Vec::new()) as i64
}

/// Fuzzy::findDuplicates, the whole outer loop: the "port the outermost loop" case.
#[php_function]
#[php(name = "fastphp_spike_findDuplicates")]
pub fn fastphp_spike_findDuplicates(names: Vec<String>, maxDistance: i64) -> Vec<Vec<i64>> {
    fuzzy::find_duplicates(&names, maxDistance)
}

// ---- the same functions the way fast-php's generated glue writes them: raw zval reads, no generic conversions

#[php_function]
#[php(name = "fastphp_glue_sum")]
pub fn glue_sum(xs: &Zval) -> Result<i64, PhpException> {
    let xs: Vec<i64> = arg(xs, 1, "xs", "array")?;
    Ok(xs.iter().sum())
}

#[php_function]
#[php(name = "fastphp_glue_levenshtein")]
pub fn glue_levenshtein(a: &Zval, b: &Zval) -> Result<i64, PhpException> {
    let a: String = arg(a, 1, "a", "string")?;
    let b: String = arg(b, 2, "b", "string")?;
    let (a, b): (Vec<char>, Vec<char>) = (a.chars().collect(), b.chars().collect());
    Ok(fuzzy::distance(&a, &b, &mut Vec::new()) as i64)
}

#[php_function]
#[php(name = "fastphp_glue_findDuplicates")]
pub fn glue_find_duplicates(names: &Zval, max: &Zval) -> Result<Ret<Vec<[i64; 2]>>, PhpException> {
    let names: Vec<String> = arg(names, 1, "names", "array")?;
    let max: i64 = arg(max, 2, "maxDistance", "int")?;
    Ok(Ret(fuzzy::find_duplicates(&names, max).into_iter().map(|p| [p[0], p[1]]).collect()))
}

#[php_function]
#[php(name = "fastphp_glue_roundtrip")]
pub fn glue_roundtrip(v: &Zval) -> Result<Ret<PhpValue>, PhpException> {
    Ok(Ret(arg(v, 1, "v", "mixed")?))
}

#[php_module]
pub fn get_module(module: ModuleBuilder) -> ModuleBuilder {
    module
        .function(wrap_function!(fastphp_spike_roundtrip))
        .function(wrap_function!(fastphp_spike_noop))
        .function(wrap_function!(fastphp_spike_sum))
        .function(wrap_function!(fastphp_spike_throw))
        .function(wrap_function!(fastphp_spike_levenshtein))
        .function(wrap_function!(fastphp_spike_findDuplicates))
        .function(wrap_function!(glue_sum))
        .function(wrap_function!(glue_levenshtein))
        .function(wrap_function!(glue_find_duplicates))
        .function(wrap_function!(glue_roundtrip))
}
