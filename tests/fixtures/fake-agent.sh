#!/usr/bin/env sh
# A stand-in coding agent for tests/e2e.sh (fast-php go --agent "sh tests/fixtures/fake-agent.sh").
# Attempt 1 writes a wrong port and also edits the generated glue.rs, which fast-php must put
# back. Later attempts must have been handed FEEDBACK.md with the failed calls; they write the
# right port.
set -eu
dir=$(dirname "$(dirname "$FASTPHP_PORT")")
test -f "$FASTPHP_TASK"
case "$FASTPHP_PROMPT" in *"$FASTPHP_ID"*) ;; *) echo "the prompt doesn't name the function" >&2; exit 1 ;; esac
if [ "$FASTPHP_ATTEMPT" = 1 ]; then
    cp "$FAKE_AGENT_WRONG" "$FASTPHP_PORT"
    echo "// changed by the agent" >> "$dir/src/glue.rs"
else
    grep -q "expected" "$FASTPHP_FEEDBACK"
    cp "$FAKE_AGENT_RIGHT" "$FASTPHP_PORT"
fi
