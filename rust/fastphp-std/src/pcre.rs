//! preg_* on PCRE2, the engine PHP itself uses, with ext/pcre/php_pcre.c's own rules around
//! it: pattern delimiters and modifiers, how empty matches advance, preg_split's flags,
//! replacement references and which groups end up in $matches.
//!
//! Anything PHP answers with a warning or with false (a malformed pattern, an unknown
//! modifier, invalid UTF-8 under /u, a backtracking limit) is declined, so PHP handles it.

use crate::{PhpArray, PhpError, PhpKey, PhpString, PhpValue, fallback};
use pcre2::bytes::{CaptureLocations, Regex as Re, RegexBuilder};
use std::cell::RefCell;
use std::collections::HashMap;
use std::rc::Rc;

pub const PREG_SPLIT_NO_EMPTY: i64 = 1;
pub const PREG_SPLIT_DELIM_CAPTURE: i64 = 2;
pub const PREG_SPLIT_OFFSET_CAPTURE: i64 = 4;

/// A compiled PHP pattern.
pub struct Regex {
    re: Re,
    /// The same pattern, anchored at the start offset and required to be non-empty there:
    /// PCRE2_NOTEMPTY_ATSTART | PCRE2_ANCHORED, which PHP retries with after an empty match.
    retry: Re,
    utf: bool,
    names: Vec<Option<String>>,
    /// Match data reused across calls, like PHP does (allocating it per match costs more than
    /// a simple match).
    locs: RefCell<CaptureLocations>,
    retry_locs: RefCell<CaptureLocations>,
}

thread_local! {
    static CACHE: RefCell<HashMap<Vec<u8>, Rc<Regex>>> = RefCell::new(HashMap::new());
    /// The last few patterns, checked before hashing: a hot loop uses one or two.
    static RECENT: RefCell<Vec<(Vec<u8>, Rc<Regex>)>> = const { RefCell::new(Vec::new()) };
}

fn is_space(b: u8) -> bool {
    matches!(b, b' ' | b'\t' | b'\n' | b'\r' | 0x0b | 0x0c)
}

/// Compiles a PHP pattern like "/\s+/u", cached per thread like PHP's regex cache.
pub fn compile(pattern: &[u8]) -> Result<Rc<Regex>, PhpError> {
    if let Some(r) = RECENT.with_borrow(|c| c.iter().find(|(p, _)| p == pattern).map(|(_, r)| r.clone())) {
        return Ok(r);
    }
    let hit = CACHE.with_borrow(|c| c.get(pattern).cloned());
    let r = match hit {
        Some(r) => r,
        None => compile_new(pattern)?,
    };
    RECENT.with_borrow_mut(|c| {
        if c.len() == 4 {
            c.remove(0);
        }
        c.push((pattern.to_vec(), r.clone()));
    });
    Ok(r)
}

fn compile_new(pattern: &[u8]) -> Result<Rc<Regex>, PhpError> {
    let r = Rc::new(build(pattern)?);
    CACHE.with_borrow_mut(|c| {
        if c.len() > 4096 {
            c.clear();
        }
        c.insert(pattern.to_vec(), r.clone());
    });
    Ok(r)
}

fn build(pattern: &[u8]) -> Result<Regex, PhpError> {
    let mut p = pattern;
    while let [first, rest @ ..] = p
        && is_space(*first)
    {
        p = rest;
    }
    let Some(&delim) = p.first() else { return fallback() };
    if delim.is_ascii_alphanumeric() || delim == b'\\' || delim == 0 {
        return fallback();
    }
    let end_delim = match delim {
        b'(' => b')',
        b'[' => b']',
        b'{' => b'}',
        b'<' => b'>',
        d => d,
    };
    let mut i = 1;
    let mut depth = 1;
    let end = loop {
        if i >= p.len() {
            return fallback(); // no ending delimiter
        }
        let c = p[i];
        if c == b'\\' && i + 1 < p.len() {
            i += 2;
            continue;
        }
        if end_delim != delim {
            if c == end_delim {
                depth -= 1;
                if depth == 0 {
                    break i;
                }
            } else if c == delim {
                depth += 1;
            }
        } else if c == delim {
            break i;
        }
        i += 1;
    };
    let body = &p[1..end];
    let (mut caseless, mut multi, mut dotall, mut extended, mut utf) = (false, false, false, false, false);
    let mut prefix = String::new();
    let mut anchored = false;
    for &m in &p[end + 1..] {
        match m {
            b'i' => caseless = true,
            b'm' => multi = true,
            b's' => dotall = true,
            b'x' => extended = true,
            b'u' => utf = true,
            b'U' => prefix.push_str("(?U)"),
            b'n' => prefix.push_str("(?n)"),
            b'J' => prefix.push_str("(?J)"),
            b'A' => anchored = true,
            b'S' | b'X' | b' ' | b'\n' | b'\r' => {}
            _ => return fallback(), // D, r, e and unknown modifiers
        }
    }
    if body.contains(&0) {
        return fallback();
    }
    let Ok(body) = std::str::from_utf8(body) else { return fallback() };
    // A comment in /x mode runs to the end of the line: close groups on a new line.
    let close = if extended { "\n)" } else { ")" };
    let main = if anchored { format!("{prefix}\\G(?:{body}{close}") } else { format!("{prefix}{body}") };
    let retry = format!("{prefix}\\G(?:{body}{close}(?!\\G)");
    let mut b = RegexBuilder::new();
    b.caseless(caseless).multi_line(multi).dotall(dotall).extended(extended).utf(utf).ucp(utf).jit_if_available(true);
    let (Ok(re), Ok(retry)) = (b.build(&main), b.build(&retry)) else { return fallback() };
    let names = re.capture_names().to_vec();
    let locs = RefCell::new(re.capture_locations());
    let retry_locs = RefCell::new(retry.capture_locations());
    Ok(Regex { re, retry, utf, names, locs, retry_locs })
}

/// One match: the offsets of every group (None when unset) and PCRE's count (highest set + 1).
#[derive(Default)]
struct Found {
    groups: Vec<Option<(usize, usize)>>,
    count: usize,
}

impl Found {
    fn span(&self) -> (usize, usize) {
        self.groups[0].expect("group 0")
    }
}

impl Regex {
    /// Under /u, PHP checks the subject is valid UTF-8 once and returns false if not.
    fn check(&self, subject: &[u8]) -> Result<(), PhpError> {
        if self.utf && std::str::from_utf8(subject).is_err() {
            return fallback();
        }
        Ok(())
    }

    /// Fills `out` and returns true when there is a match at or after `at`.
    fn find_into(&self, subject: &[u8], at: usize, retry: bool, out: &mut Found) -> Result<bool, PhpError> {
        let (re, cell) = if retry { (&self.retry, &self.retry_locs) } else { (&self.re, &self.locs) };
        let mut locs = cell.borrow_mut();
        match re.captures_read_at(&mut locs, subject, at) {
            Err(_) => fallback(), // bad UTF-8, match limit: PHP returns false
            Ok(None) => Ok(false),
            Ok(Some(_)) => {
                out.groups.clear();
                out.groups.extend((0..locs.len()).map(|i| locs.get(i)));
                out.count = out.groups.iter().rposition(Option::is_some).map_or(0, |i| i + 1);
                Ok(true)
            }
        }
    }

    fn find(&self, subject: &[u8], at: usize, retry: bool) -> Result<Option<Found>, PhpError> {
        let mut f = Found::default();
        Ok(self.find_into(subject, at, retry, &mut f)?.then_some(f))
    }

    /// Length of the unit at `at`: a UTF-8 character under /u, else a byte.
    fn unit(&self, subject: &[u8], at: usize) -> usize {
        if !self.utf {
            return 1;
        }
        let b = subject[at];
        let n = match b {
            0x00..=0x7f => 1,
            0xc0..=0xdf => 2,
            0xe0..=0xef => 3,
            _ => 4,
        };
        n.min(subject.len() - at)
    }

    /// Every match the way PHP's global loops find them: after an empty match, try a non-empty
    /// one anchored at the same place, else move on by one unit. `f` returns false to stop.
    fn each(&self, subject: &[u8], mut f: impl FnMut(&Found) -> bool) -> Result<(), PhpError> {
        self.check(subject)?;
        let mut pos = 0;
        let mut retry = false;
        let mut m = Found::default();
        loop {
            if self.find_into(subject, pos, retry, &mut m)? {
                if !f(&m) {
                    return Ok(());
                }
                let (s, e) = m.span();
                pos = e;
                retry = s == e;
            } else if retry && pos < subject.len() {
                pos += self.unit(subject, pos);
                retry = false;
            } else {
                return Ok(());
            }
        }
    }

    fn group_count(&self) -> usize {
        self.names.len()
    }
}

fn piece(subject: &[u8], span: Option<(usize, usize)>) -> PhpValue {
    PhpValue::Str(PhpString(span.map_or(Vec::new(), |(s, e)| subject[s..e].to_vec())))
}

/// preg_match($pattern, $subject, $matches): None when nothing matches, else $matches.
pub fn preg_match(pattern: &[u8], subject: &[u8]) -> Result<Option<PhpArray>, PhpError> {
    let re = compile(pattern)?;
    re.check(subject)?;
    let Some(m) = re.find(subject, 0, false)? else { return Ok(None) };
    let mut out = PhpArray::new();
    for i in 0..m.count {
        let v = piece(subject, m.groups[i]);
        if let Some(Some(name)) = re.names.get(i) {
            out.insert(PhpKey::from_bytes(name.as_bytes()), v.clone());
        }
        out.insert(PhpKey::Int(i as i64), v);
    }
    Ok(Some(out))
}

/// preg_match($pattern, $subject) as a bool.
pub fn is_match(pattern: &[u8], subject: &[u8]) -> Result<bool, PhpError> {
    let re = compile(pattern)?;
    re.check(subject)?;
    re.re.is_match_at(subject, 0).or_else(|_| fallback())
}

/// preg_match_all($pattern, $subject, $matches) with PREG_PATTERN_ORDER.
pub fn preg_match_all(pattern: &[u8], subject: &[u8]) -> Result<(i64, PhpArray), PhpError> {
    let re = compile(pattern)?;
    let n = re.group_count();
    let mut sets: Vec<Vec<PhpValue>> = vec![Vec::new(); n];
    let mut matches = 0;
    re.each(subject, |m| {
        matches += 1;
        for (i, set) in sets.iter_mut().enumerate() {
            set.push(if i < m.count { piece(subject, m.groups[i]) } else { PhpValue::Str(PhpString::default()) });
        }
        true
    })?;
    let mut out = PhpArray::new();
    for (i, set) in sets.into_iter().enumerate() {
        let list: PhpArray = set.into_iter().collect();
        if let Some(Some(name)) = re.names.get(i) {
            out.insert(PhpKey::from_bytes(name.as_bytes()), PhpValue::Array(list.clone()));
        }
        out.insert(PhpKey::Int(i as i64), PhpValue::Array(list));
    }
    Ok((matches, out))
}

/// The replacement with $1, \1 and ${1} filled in, after php_pcre_replace_impl.
fn expand(replacement: &[u8], subject: &[u8], m: &Found, out: &mut Vec<u8>) {
    let r = replacement;
    let mut i = 0;
    let mut last = 0u8;
    while i < r.len() {
        let c = r[i];
        if c == b'\\' || c == b'$' {
            if last == b'\\' {
                *out.last_mut().expect("previous backslash") = c;
                i += 1;
                last = 0;
                continue;
            }
            if let Some((n, len)) = backref(&r[i..]) {
                if n < m.count
                    && let Some((s, e)) = m.groups[n]
                {
                    out.extend_from_slice(&subject[s..e]);
                }
                i += len;
                continue;
            }
        }
        out.push(c);
        last = c;
        i += 1;
    }
}

fn backref(r: &[u8]) -> Option<(usize, usize)> {
    let at = |i: usize| r.get(i).copied().unwrap_or(0);
    if at(1) == 0 {
        return None;
    }
    let mut i = 0;
    let brace = r[0] == b'$' && at(1) == b'{';
    if brace {
        i += 1;
    }
    i += 1;
    if !at(i).is_ascii_digit() {
        return None;
    }
    let mut n = usize::from(at(i) - b'0');
    i += 1;
    if at(i).is_ascii_digit() {
        n = n * 10 + usize::from(at(i) - b'0');
        i += 1;
    }
    if brace {
        if at(i) != b'}' {
            return None;
        }
        i += 1;
    }
    Some((n, i))
}

/// preg_replace($pattern, $replacement, $subject, $limit = -1): the result and the count.
pub fn preg_replace(
    pattern: &[u8],
    replacement: &[u8],
    subject: &[u8],
    limit: i64,
) -> Result<(PhpString, i64), PhpError> {
    let re = compile(pattern)?;
    let mut out = Vec::with_capacity(subject.len());
    let mut last = 0;
    let mut count = 0;
    let mut left = limit;
    if left == 0 {
        return Ok((PhpString(subject.to_vec()), 0));
    }
    re.each(subject, |m| {
        let (s, e) = m.span();
        out.extend_from_slice(&subject[last..s]);
        expand(replacement, subject, m, &mut out);
        last = e;
        count += 1;
        left -= 1;
        left != 0
    })?;
    out.extend_from_slice(&subject[last..]);
    Ok((PhpString(out), count))
}

/// preg_replace_callback(): `f` gets $matches (as preg_match builds it) and returns the replacement.
pub fn preg_replace_callback(
    pattern: &[u8],
    subject: &[u8],
    limit: i64,
    mut f: impl FnMut(&PhpArray) -> Result<PhpString, PhpError>,
) -> Result<(PhpString, i64), PhpError> {
    let re = compile(pattern)?;
    let mut out = Vec::with_capacity(subject.len());
    let mut last = 0;
    let mut count = 0;
    let mut left = limit;
    let mut err = None;
    if left == 0 {
        return Ok((PhpString(subject.to_vec()), 0));
    }
    re.each(subject, |m| {
        let (s, e) = m.span();
        out.extend_from_slice(&subject[last..s]);
        let mut groups = PhpArray::new();
        for i in 0..m.count {
            let v = piece(subject, m.groups[i]);
            if let Some(Some(name)) = re.names.get(i) {
                groups.insert(PhpKey::from_bytes(name.as_bytes()), v.clone());
            }
            groups.insert(PhpKey::Int(i as i64), v);
        }
        match f(&groups) {
            Ok(r) => out.extend_from_slice(&r.0),
            Err(e) => {
                err = Some(e);
                return false;
            }
        }
        last = e;
        count += 1;
        left -= 1;
        left != 0
    })?;
    if let Some(e) = err {
        return Err(e);
    }
    out.extend_from_slice(&subject[last..]);
    Ok((PhpString(out), count))
}

/// preg_split($pattern, $subject, $limit = -1, $flags = 0). PREG_SPLIT_OFFSET_CAPTURE is declined.
pub fn preg_split(pattern: &[u8], subject: &[u8], limit: i64, flags: i64) -> Result<Vec<PhpString>, PhpError> {
    if flags & PREG_SPLIT_OFFSET_CAPTURE != 0 {
        return fallback();
    }
    let no_empty = flags & PREG_SPLIT_NO_EMPTY != 0;
    let delim = flags & PREG_SPLIT_DELIM_CAPTURE != 0;
    let re = compile(pattern)?;
    let mut out: Vec<PhpString> = Vec::new();
    let mut limit = if limit == 0 { -1 } else { limit };
    let mut last = 0usize;
    if limit == -1 || limit > 1 {
        re.each(subject, |m| {
            let (s, e) = m.span();
            if !no_empty || s != last {
                out.push(PhpString(subject[last..s].to_vec()));
                if limit != -1 {
                    limit -= 1;
                }
            }
            if delim {
                for g in &m.groups[1..m.count] {
                    let (gs, ge) = g.unwrap_or((0, 0));
                    if !no_empty || gs != ge {
                        out.push(PhpString(subject[gs..ge].to_vec()));
                    }
                }
            }
            last = e;
            !(limit != -1 && limit <= 1)
        })?;
    }
    if !no_empty || last < subject.len() {
        out.push(PhpString(subject[last..].to_vec()));
    }
    Ok(out)
}

/// preg_quote($str, $delimiter).
pub fn preg_quote(s: &[u8], delimiter: Option<u8>) -> Vec<u8> {
    let mut out = Vec::with_capacity(s.len() * 2);
    for &c in s {
        match c {
            b'.' | b'\\' | b'+' | b'*' | b'?' | b'[' | b'^' | b']' | b'$' | b'(' | b')' | b'{' | b'}' | b'=' | b'!'
            | b'<' | b'>' | b'|' | b':' | b'-' | b'#' => {
                out.push(b'\\');
                out.push(c);
            }
            0 => out.extend_from_slice(b"\\000"),
            _ if Some(c) == delimiter => {
                out.push(b'\\');
                out.push(c);
            }
            _ => out.push(c),
        }
    }
    out
}

#[cfg(test)]
mod tests {
    use super::*;

    fn strs(v: Vec<PhpString>) -> Vec<String> {
        v.into_iter().map(|s| String::from_utf8(s.0).unwrap()).collect()
    }

    #[test]
    fn split_like_php() {
        assert_eq!(strs(preg_split(b"//u", "Zoë".as_bytes(), -1, PREG_SPLIT_NO_EMPTY).unwrap()), ["Z", "o", "ë"]);
        assert_eq!(strs(preg_split(b"/\\s+/", b" a  b ", -1, 0).unwrap()), ["", "a", "b", ""]);
        assert_eq!(strs(preg_split(b"/\\s+/", b" a  b ", -1, PREG_SPLIT_NO_EMPTY).unwrap()), ["a", "b"]);
        assert_eq!(strs(preg_split(b"/(-)/", b"a-b", -1, PREG_SPLIT_DELIM_CAPTURE).unwrap()), ["a", "-", "b"]);
        assert_eq!(strs(preg_split(b"/,/", b"a,b,c", 2, 0).unwrap()), ["a", "b,c"]);
    }

    #[test]
    fn replace_and_match() {
        assert_eq!(
            preg_replace(b"/(\\w+) (\\w+)/", b"$2 \\1 ${1}x", b"hello world", -1).unwrap().0,
            PhpString::from("world hello hellox")
        );
        assert_eq!(preg_replace(b"/x*/", b"-", b"abc", -1).unwrap().0, PhpString::from("-a-b-c-"));
        let m = preg_match(b"/(a)(b)?(c)?/", b"a").unwrap().unwrap();
        assert_eq!(m.len(), 2);
        assert!(preg_match(b"/a/z", b"a").is_err());
        assert!(preg_match(b"/a/u", b"\xff").is_err());
    }
}
