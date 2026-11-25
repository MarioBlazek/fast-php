//! Reference port of Invoice\Report::summary for `fast-php port Invoice_Report_summary --reference`.
//! It is what a coding agent is expected to write from TASK.md: every PHP builtin goes through
//! fastphp_std, so trim, strtolower, the regexes, (int)/(float), round(), ksort() and
//! number_format() behave exactly like the PHP that runs it, version differences included.

#[allow(unused_imports)]
use fastphp_std::{array, cmp, conv, hash, math, mb, pcre, string};
use fastphp_value::*;

pub fn summary(lines: Vec<String>, this_currency: String, this_decimals: i64, this_tax_rate: f64) -> Result<String, PhpError> {
    // $totals[$key] = ($totals[$key] ?? 0) + $amount: keys as PHP makes them, amounts are floats.
    let mut totals: Map<f64> = Map::new();
    for line in &lines {
        let parts = string::explode(b";", line.as_bytes(), i64::MAX)?;
        if parts.len() < 4 {
            // [$sku, $qty, $price, $customer] = ... warns about the missing keys: let PHP do it.
            return Err(PhpError::fallback());
        }
        if !sku_valid(&parts[0].0) {
            continue;
        }
        let key = slug_make(&parts[3].0)?;
        let qty = conv::str_to_int(&parts[1].0);
        let price = conv::str_to_float(&parts[2].0);
        // (int) $qty * (float) $price * (1 + $this->taxRate): int * float is a float in PHP.
        let amount = math::round(qty as f64 * price * (1.0 + this_tax_rate), this_decimals)?;
        let k = PhpKey::from_bytes(key);
        let sum = totals.get(&k).copied().unwrap_or(0.0) + amount;
        totals.insert(k, sum);
    }
    let sorted = cmp::ksort(&PhpArray::from_entries(totals.into_iter().map(|(k, v)| (k, PhpValue::Float(v)))), false)?;
    let mut out = Vec::new();
    for (customer, total) in sorted.iter() {
        let name = match customer {
            PhpKey::Int(i) => i.to_string().into_bytes(),
            PhpKey::Str(s) => s.0.clone(),
        };
        let PhpValue::Float(total) = total else { return Err(PhpError::fallback()) };
        out.extend(string::str_pad(&name, 24, b" ", 1)?);
        out.push(b' ');
        out.extend(string::number_format(Num::Float(*total), this_decimals, b".", b",")?);
        out.push(b' ');
        out.extend_from_slice(this_currency.as_bytes());
        out.push(b'\n');
    }
    String::from_utf8(out).or(Err(PhpError::fallback()))
}

use fastphp_std::Num;

/// Invoice\Sku::valid. `$c < '0' || $c > '9'` on one-character strings is a byte comparison
/// here: digits compare as numbers, anything else with strcmp, and both agree for single bytes.
fn sku_valid(sku: &[u8]) -> bool {
    let mut sum: i64 = 0;
    let mut double = false;
    for &c in sku.iter().rev() {
        if !c.is_ascii_digit() {
            continue;
        }
        let mut d = i64::from(c - b'0');
        if double {
            d *= 2;
            if d > 9 {
                d -= 9;
            }
        }
        sum += d;
        double = !double;
    }
    sum % 10 == 0
}

/// Invoice\Text\Slug::make
fn slug_make(name: &[u8]) -> Result<Vec<u8>, PhpError> {
    let mut s = string::strtolower(string::trim(name));
    if pcre::is_match(br"/[^\x00-\x7F]/", &s)? {
        s = ascii_fold(&s);
    }
    let (s, _) = pcre::preg_replace(b"/[^a-z0-9]+/", b"-", &s, -1)?;
    Ok(string::trim_chars(&s.0, b"-")?.to_vec())
}

/// Invoice\Text\Ascii::fold: str_replace() with arrays, one pair after another.
fn ascii_fold(s: &[u8]) -> Vec<u8> {
    const FOLD: [(&str, &str); 14] = [
        ("á", "a"),
        ("à", "a"),
        ("ä", "a"),
        ("é", "e"),
        ("è", "e"),
        ("ë", "e"),
        ("í", "i"),
        ("ó", "o"),
        ("ö", "o"),
        ("ú", "u"),
        ("ü", "u"),
        ("ñ", "n"),
        ("ç", "c"),
        ("ß", "ss"),
    ];
    let search: Vec<&[u8]> = FOLD.iter().map(|(a, _)| a.as_bytes()).collect();
    let replace: Vec<&[u8]> = FOLD.iter().map(|(_, b)| b.as_bytes()).collect();
    string::str_replace_many(&search, &replace, s).0
}
