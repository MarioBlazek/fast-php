<?php
declare(strict_types=1);

namespace FastPhp\Workload;

/** One run of a workload: how long it took, and what it printed or answered. */
final class Run
{
    /**
     * @param list<string> $outputs   stdout (CLI), or each response's status and body (web)
     * @param list<string> $labels    what each output is: the script, or "GET /path"
     * @param list<float>  $latencies seconds per request (web)
     */
    public function __construct(
        public readonly float $seconds,
        public readonly array $outputs,
        public readonly array $labels,
        public readonly array $latencies = [],
    ) {
    }

    /** The index of the first output that differs from $other's, or null when all match. */
    public function firstDifference(Run $other): ?int
    {
        foreach ($this->outputs as $i => $out) {
            if (($other->outputs[$i] ?? null) !== $out) {
                return $i;
            }
        }
        return count($other->outputs) > count($this->outputs) ? count($this->outputs) : null;
    }
}
