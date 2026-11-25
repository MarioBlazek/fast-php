//! mbstring on UTF-8. Invalid UTF-8 is declined: mbstring's error handling for it is its own.

use crate::{PhpError, PhpString, fallback, php_version};

fn utf8(s: &[u8]) -> Result<&str, PhpError> {
    std::str::from_utf8(s).or_else(|_| fallback())
}

/// mb_strlen($s): characters.
pub fn mb_strlen(s: &[u8]) -> Result<i64, PhpError> {
    Ok(utf8(s)?.chars().count() as i64)
}

/// mb_substr($s, $start, $length): characters, PHP's negative rules.
pub fn mb_substr(s: &[u8], start: i64, length: Option<i64>) -> Result<PhpString, PhpError> {
    let chars: Vec<char> = utf8(s)?.chars().collect();
    let len = chars.len() as i64;
    let mut from = start;
    if from < 0 {
        from = (len + from).max(0);
    }
    if from > len {
        return Ok(PhpString::default());
    }
    let to = match length {
        None => len,
        Some(l) if l < 0 => (len + l).max(from),
        Some(l) => from.saturating_add(l).min(len),
    };
    Ok(PhpString::from(chars[from as usize..to.max(from) as usize].iter().collect::<String>()))
}

/// Unicode lower case. PHP before 8.3 has no final-sigma rule, so Σ is declined there.
pub fn mb_strtolower(s: &[u8]) -> Result<PhpString, PhpError> {
    let s = utf8(s)?;
    if php_version() < 80300 && s.contains('Σ') {
        return fallback();
    }
    Ok(PhpString::from(s.to_lowercase()))
}

/// Unicode upper case, full mappings ("ß" becomes "SS").
pub fn mb_strtoupper(s: &[u8]) -> Result<PhpString, PhpError> {
    Ok(PhpString::from(utf8(s)?.to_uppercase()))
}

/// mb_str_split($s, $length = 1).
pub fn mb_str_split(s: &[u8], length: i64) -> Result<Vec<PhpString>, PhpError> {
    if length <= 0 {
        return Err(PhpError::new("ValueError", "mb_str_split(): Argument #2 ($length) must be greater than 0"));
    }
    let chars: Vec<char> = utf8(s)?.chars().collect();
    Ok(chars.chunks(length as usize).map(|c| PhpString::from(c.iter().collect::<String>())).collect())
}
