//! Reference port of Shop\Bundles::cheapest for `fast-php port Shop_Bundles_cheapest --reference`.
//! It is what a coding agent is expected to write from TASK.md: the same dynamic programming,
//! the same order of states and deals and the same strict `<`, so ties go to the same deal; the
//! same exceptions with the same messages; and where PHP's int arithmetic would overflow into
//! a float, it declines and lets PHP run.

#[allow(unused_imports)]
use fastphp_std::{array, cmp, conv, hash, math, mb, pcre, string};
use fastphp_value::*;

const MAX_ITEMS: usize = 20;

pub fn cheapest(prices: Vec<i64>, offers: Vec<(i64, Vec<i64>)>) -> Result<(i64, Vec<i64>), PhpError> {
    let n = prices.len();
    if n > MAX_ITEMS {
        return Err(PhpError::new(
            "LengthException",
            format!("A cart of {n} items is too large to price deals for (at most {MAX_ITEMS})"),
        ));
    }
    // Per item, the deals starting there as (mask, price, index), side by side in memory.
    let mut by_item: Vec<Vec<(u32, i64, i64)>> = vec![Vec::new(); n];
    let mut masks: Vec<u32> = Vec::with_capacity(offers.len());
    for (k, (price, items)) in offers.iter().enumerate() {
        let mut mask = 0u32;
        for &item in items {
            if item < 0 || item >= n as i64 {
                return Err(PhpError::new(
                    "OutOfRangeException",
                    format!("Deal {k} covers item {item}, but the cart has {n} items"),
                ));
            }
            mask |= 1 << item;
        }
        masks.push(mask);
        if mask != 0 {
            by_item[mask.trailing_zeros() as usize].push((mask, *price, k as i64));
        }
    }

    let full: u32 = (1u32 << n) - 1;
    let mut cost = vec![i64::MAX; full as usize + 1];
    let mut via = vec![0i64; full as usize + 1];
    cost[0] = 0;
    for paid in 0..full {
        let c = cost[paid as usize];
        if c == i64::MAX {
            continue;
        }
        let i = (!paid & full).trailing_zeros() as usize;
        let next = (paid | (1 << i)) as usize;
        // PHP turns an overflowing int sum into a float: leave that to PHP.
        let t = c.checked_add(prices[i]).ok_or_else(PhpError::fallback)?;
        if t < cost[next] {
            cost[next] = t;
            via[next] = -1 - i as i64;
        }
        for &(mask, price, k) in &by_item[i] {
            if mask & paid != 0 {
                continue;
            }
            let next = (paid | mask) as usize;
            let t = c.checked_add(price).ok_or_else(PhpError::fallback)?;
            if t < cost[next] {
                cost[next] = t;
                via[next] = k;
            }
        }
    }

    let mut used = Vec::new();
    let mut paid = full;
    while paid != 0 {
        let v = via[paid as usize];
        if v >= 0 {
            used.push(v);
            paid &= !masks[v as usize];
        } else {
            paid &= !(1u32 << (-1 - v));
        }
    }
    used.sort_unstable();
    Ok((cost[full as usize], used))
}
