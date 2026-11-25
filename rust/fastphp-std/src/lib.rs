//! PHP's builtins in Rust, matching PHP exactly.
//!
//! Rust's own `trim()`, `to_lowercase()`, float formatting and regex engine all differ from
//! PHP's in small ways, and a port that uses them fails verification on exactly the inputs
//! that matter. These functions follow php-src (ext/standard, Zend) and are checked against
//! the running PHP by a differential fuzz suite (`sh tests/std/run.sh`) on every PHP version.
//!
//! Conventions:
//! - Strings are bytes (`&[u8]`, `PhpString`), like in PHP. `mb` works on UTF-8 characters.
//! - Errors PHP throws come back as `Err(PhpError)` with PHP's class and message.
//! - Where PHP would only emit a warning or a deprecation and carry on, these functions return
//!   `Err(PhpError::fallback())`: the generated glue then lets PHP run the call, warning and all.
//! - Behaviour that changed between PHP versions follows [`php_version`], which generated
//!   glue sets from the PHP the extension was built for.

pub mod array;
pub mod cmp;
pub mod conv;
pub mod hash;
pub mod math;
pub mod mb;
pub mod pcre;
pub mod string;

pub use conv::Num;
pub use fastphp_value::{PhpArray, PhpError, PhpKey, PhpString, PhpValue};

use std::sync::atomic::{AtomicU32, Ordering};

static VERSION: AtomicU32 = AtomicU32::new(80500);

/// PHP_VERSION_ID-style: 80500 for 8.5.
pub fn php_version() -> u32 {
    VERSION.load(Ordering::Relaxed)
}

pub fn set_php_version(id: u32) {
    VERSION.store(id, Ordering::Relaxed);
}

/// The PHP version an extension built against ZEND_MODULE_API_NO runs in (major.minor).
pub fn version_from_api(api: u32) -> u32 {
    match api {
        ..=20220829 => 80200,
        20220830..=20230831 => 80300,
        20230901..=20240924 => 80400,
        _ => 80500,
    }
}

pub(crate) fn fallback<T>() -> Result<T, PhpError> {
    Err(PhpError::fallback())
}
