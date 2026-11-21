---
status: accepted
date: 2026-11-21
supersedes:
superseded_by:
revisit_when: a bridge's machine secrets need to be readable by the app (a value coming back), or the prompt cap has to grow past 16 KB
---
# Machine secrets only for a bridge whose operator opted in; the prompt cap negotiated by its own field

## Context

An app may want to manage the secret store on a person's machine for them: list it, add a
secret, describe one, remove one. The bridge can do this by running a command on the machine,
which only the machine's operator can decide to allow. A first draft let the bridge announce the
feature whenever it found a particular tool on the machine, and tied a larger `bridge_prompt.text`
cap (16 KB instead of 8 KB) to the same hello field, so a server could only send a longer prompt
to a bridge that also managed secrets.

## Decision

- The server sends `secret_*` frames only to a bridge whose hello carries `machine_secrets`, which
  ai-bridge sends only when its operator started it with `--secret-command`. Every other bridge is
  answered `machine_secrets_unsupported` without a frame being sent. The frames keep the shape of
  the first draft (`secret_list`, `secret_put`, `secret_describe`, `secret_remove`,
  `secret_result`), so a host built on it keeps working.
- A value only travels in: on `secret_put`. No answer, error or log here carries one. Requests are
  checked in the web process and again in the serve process (name `\z`-anchored, UTF-8,
  descriptions not starting with `-`), so a relay token alone cannot get a malformed frame to a
  machine.
- A write that was sent and not answered is reported as `result_unknown`, never as failed.
- The prompt cap is its own hello field, `bridge_prompt_max_bytes`: 16 KB only to a bridge that
  announces at least 16384, 8 KB otherwise, refused before sending (`bridge_prompt_too_large`).

## Consequences

- A machine whose operator did nothing exposes nothing; an app checks
  `AiBridge::secrets()->supported()` (or `machine_secrets` on the connection status) before
  offering it.
- The value passes through the serve process's internal API as JSON: `AI_BRIDGE_RELAY_URL` should
  be local or https, and HTTP recorders should exclude `/api/secrets`.
- A longer prompt does not depend on secrets, and a bridge can raise its cap later by announcing a
  larger number.

## Options considered

- Announce `machine_secrets` whenever the machine has a known secret tool: rejected, because
  whether a server may change secrets on a machine is the operator's choice, like `--local-tools`.
- Keep the 16 KB cap tied to `machine_secrets`: rejected, because the two have nothing to do with
  each other, and a bridge without secrets would be held to 8 KB for no reason.
