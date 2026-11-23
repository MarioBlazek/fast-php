//! fast-php's profiler, loaded for one run with  php -d extension=fastphp_runtime.so.
//!
//! It hooks every user function through the Zend observer API: no source is edited, and the
//! cost per call is two clock reads plus a few vector operations. Stats build up per process
//! (php -S and FPM workers serve many requests) and are written at the end of every request
//! to $FASTPHP_PROF_DIR/prof-<pid>.json, replacing the previous write.
//!
//! Without FASTPHP_PROF_DIR the extension observes nothing, so loading it costs nothing.
#![cfg_attr(windows, feature(abi_vectorcall))]

use ext_php_rs::ffi::{zend_function, zend_string};
use ext_php_rs::prelude::*;
use ext_php_rs::types::Zval;
use ext_php_rs::zend::ExecuteData;
use std::cell::RefCell;
use std::collections::HashMap;
use std::fmt::Write as _;
use std::sync::OnceLock;
use std::time::Instant;

fn out_dir() -> Option<&'static str> {
    static DIR: OnceLock<Option<String>> = OnceLock::new();
    DIR.get_or_init(|| std::env::var("FASTPHP_PROF_DIR").ok().filter(|d| !d.is_empty())).as_deref()
}

fn internal_too() -> bool {
    static ON: OnceLock<bool> = OnceLock::new();
    *ON.get_or_init(|| std::env::var("FASTPHP_PROF_INTERNAL").is_ok_and(|v| v == "1"))
}

#[derive(Default)]
struct Stat {
    name: String,
    class: Option<String>,
    file: Option<String>,
    line: u32,
    internal: bool,
    calls: u64,
    /// Time inside the function itself, observed callees excluded.
    own_ns: u64,
    /// Time inside the function, callees included; recursive calls count once.
    cum_ns: u64,
    active: u32,
}

struct Frame {
    id: usize,
    start: Instant,
    children_ns: u64,
}

#[derive(Default)]
struct Profile {
    stats: Vec<Stat>,
    /// Function name + file + line -> index into stats. Stable across requests.
    ids: HashMap<(Option<String>, String, Option<String>, u32), usize>,
    /// zend_function pointer -> index. Cleared after each request: without opcache, a function's
    /// memory is freed at the end of the request and may hold another function in the next one.
    by_ptr: HashMap<usize, usize>,
    stack: Vec<Frame>,
    request_start: Option<Instant>,
    wall_ns: u64,
    requests: u64,
}

thread_local! {
    static PROFILE: RefCell<Profile> = RefCell::new(Profile::default());
}

unsafe fn zstr(s: *mut zend_string) -> Option<String> {
    if s.is_null() {
        return None;
    }
    let bytes = unsafe { std::slice::from_raw_parts((*s).val.as_ptr().cast::<u8>(), (*s).len) };
    Some(String::from_utf8_lossy(bytes).into_owned())
}

/// Index of the function an execute_data runs, registering it on first sight.
fn id_of(p: &mut Profile, func: *const zend_function) -> usize {
    if let Some(&id) = p.by_ptr.get(&(func as usize)) {
        return id;
    }
    // SAFETY: func comes from a live execute_data.
    let (name, class, file, line, internal) = unsafe {
        let common = &(*func).common;
        let internal = u32::from(common.type_) == ext_php_rs::ffi::ZEND_INTERNAL_FUNCTION;
        let name = zstr(common.function_name).unwrap_or_else(|| "{main}".into());
        let class = if common.scope.is_null() { None } else { zstr((*common.scope).name) };
        let (file, line) =
            if internal { (None, 0) } else { (zstr((*func).op_array.filename), (*func).op_array.line_start) };
        (name, class, file, line, internal)
    };
    let key = (class.clone(), name.clone(), file.clone(), line);
    let id = match p.ids.get(&key) {
        Some(&id) => id,
        None => {
            p.stats.push(Stat { name, class, file, line, internal, ..Stat::default() });
            let id = p.stats.len() - 1;
            p.ids.insert(key, id);
            id
        }
    };
    p.by_ptr.insert(func as usize, id);
    id
}

struct Profiler;

impl FcallObserver for Profiler {
    fn should_observe(&self, info: &FcallInfo) -> bool {
        out_dir().is_some() && (!info.is_internal || internal_too()) && info.function_name.is_some()
    }

    fn begin(&self, ex: &ExecuteData) {
        let func = ex.func;
        PROFILE.with_borrow_mut(|p| {
            let id = id_of(p, func);
            p.stats[id].active += 1;
            p.stack.push(Frame { id, start: Instant::now(), children_ns: 0 });
        });
    }

    fn end(&self, _ex: &ExecuteData, _retval: Option<&Zval>) {
        let now = Instant::now();
        PROFILE.with_borrow_mut(|p| {
            let Some(frame) = p.stack.pop() else { return };
            let took = now.duration_since(frame.start).as_nanos() as u64;
            let s = &mut p.stats[frame.id];
            s.calls += 1;
            s.own_ns += took.saturating_sub(frame.children_ns);
            s.active -= 1;
            if s.active == 0 {
                s.cum_ns += took;
            }
            if let Some(parent) = p.stack.last_mut() {
                parent.children_ns += took;
            }
        });
    }
}

fn json_str(out: &mut String, s: &str) {
    out.push('"');
    for c in s.chars() {
        match c {
            '"' => out.push_str("\\\""),
            '\\' => out.push_str("\\\\"),
            c if (c as u32) < 0x20 => {
                let _ = write!(out, "\\u{:04x}", c as u32);
            }
            c => out.push(c),
        }
    }
    out.push('"');
}

fn opt_str(out: &mut String, s: Option<&str>) {
    match s {
        Some(s) => json_str(out, s),
        None => out.push_str("null"),
    }
}

fn dump(p: &Profile, dir: &str) {
    let mut out = String::with_capacity(256 + p.stats.len() * 160);
    let _ = write!(
        out,
        "{{\"pid\":{},\"requests\":{},\"wall\":{},\"functions\":[",
        std::process::id(),
        p.requests,
        p.wall_ns as f64 / 1e9
    );
    for (i, s) in p.stats.iter().filter(|s| s.calls > 0).enumerate() {
        if i > 0 {
            out.push(',');
        }
        out.push_str("{\"name\":");
        json_str(&mut out, &s.name);
        out.push_str(",\"class\":");
        opt_str(&mut out, s.class.as_deref());
        out.push_str(",\"file\":");
        opt_str(&mut out, s.file.as_deref());
        let _ = write!(
            out,
            ",\"line\":{},\"internal\":{},\"calls\":{},\"own\":{},\"cum\":{}}}",
            s.line,
            s.internal,
            s.calls,
            s.own_ns as f64 / 1e9,
            s.cum_ns as f64 / 1e9
        );
    }
    out.push_str("]}\n");
    let path = format!("{dir}/prof-{}.json", std::process::id());
    let tmp = format!("{path}.tmp");
    if std::fs::write(&tmp, out).is_ok() {
        let _ = std::fs::rename(&tmp, &path);
    }
}

unsafe extern "C" fn request_startup(_type: i32, _module: i32) -> i32 {
    if out_dir().is_some() {
        PROFILE.with_borrow_mut(|p| p.request_start = Some(Instant::now()));
    }
    0
}

unsafe extern "C" fn request_shutdown(_type: i32, _module: i32) -> i32 {
    if let Some(dir) = out_dir() {
        PROFILE.with_borrow_mut(|p| {
            if let Some(start) = p.request_start.take() {
                p.wall_ns += start.elapsed().as_nanos() as u64;
            }
            p.requests += 1;
            // Frames left open by exit() or a fatal error: close them at the end of the request.
            while let Some(frame) = p.stack.pop() {
                let s = &mut p.stats[frame.id];
                s.active = s.active.saturating_sub(1);
            }
            dump(p, dir);
            p.by_ptr.clear();
        });
    }
    0
}

/// True when the profiler is recording this process (lets fast-php check the extension loaded).
#[php_function]
pub fn fastphp_runtime_profiling() -> bool {
    out_dir().is_some()
}

#[php_module]
pub fn get_module(module: ModuleBuilder) -> ModuleBuilder {
    module
        .fcall_observer(|| Profiler)
        .request_startup_function(request_startup)
        .request_shutdown_function(request_shutdown)
        .function(wrap_function!(fastphp_runtime_profiling))
}
