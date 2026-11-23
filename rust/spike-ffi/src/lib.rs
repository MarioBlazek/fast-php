//! The bridge fast-anything generates for PHP (src/native.rs glue_rs, MIT), with four functions
//! behind it, so the crossing cost can be measured against fast-php's extension.
#![allow(clippy::missing_safety_doc)]

#[path = "../../spike-ext/src/fuzzy.rs"]
mod fuzzy;

use serde_json::{Value, json};

fn arg<T: serde::de::DeserializeOwned>(args: &[Value], i: usize) -> Result<T, String> {
    T::deserialize(args.get(i).ok_or_else(|| format!("missing argument {}", i + 1))?).map_err(|e| e.to_string())
}

fn run(input: &[u8], f: fn(&[Value]) -> Result<Value, String>, out_len: *mut usize) -> *mut u8 {
    let reply = match std::panic::catch_unwind(|| {
        let args: Vec<Value> = serde_json::from_slice(input).map_err(|e| e.to_string())?;
        f(&args)
    }) {
        Ok(Ok(v)) => json!({ "ok": v }),
        Ok(Err(e)) => json!({ "error": e }),
        Err(_) => json!({ "error": "panic" }),
    };
    let bytes = serde_json::to_vec(&reply).unwrap_or_default().into_boxed_slice();
    unsafe { *out_len = bytes.len() };
    Box::into_raw(bytes) as *mut u8
}

macro_rules! entry {
    ($name:ident, $body:expr) => {
        #[unsafe(no_mangle)]
        pub unsafe extern "C" fn $name(input: *const u8, len: usize, out_len: *mut usize) -> *mut u8 {
            let input = unsafe { std::slice::from_raw_parts(input, len) };
            run(input, $body, out_len)
        }
    };
}

entry!(fa_noop, |_| Ok(json!(0)));
entry!(fa_sum, |a| Ok(json!(arg::<Vec<i64>>(a, 0)?.iter().sum::<i64>())));
entry!(fa_levenshtein, |a| {
    let (x, y): (String, String) = (arg(a, 0)?, arg(a, 1)?);
    let (x, y): (Vec<char>, Vec<char>) = (x.chars().collect(), y.chars().collect());
    Ok(json!(fuzzy::distance(&x, &y, &mut Vec::new())))
});
entry!(fa_findDuplicates, |a| Ok(json!(fuzzy::find_duplicates(&arg::<Vec<String>>(a, 0)?, arg(a, 1)?))));

#[unsafe(no_mangle)]
pub unsafe extern "C" fn fa_free(ptr: *mut u8, len: usize) {
    if !ptr.is_null() {
        drop(unsafe { Box::from_raw(std::ptr::slice_from_raw_parts_mut(ptr, len)) });
    }
}
