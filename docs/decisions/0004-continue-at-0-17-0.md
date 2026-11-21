---
status: accepted
date: 2026-10-03
supersedes:
superseded_by:
revisit_when:
---
# After merging a fork's 0.16 back, numbering continues at 0.17.0

## Context

A downstream fork published 0.16.0-RC1, 0.16.0 and 0.16.1 while its changes waited to go
upstream. Upstream had its own 0.16.0 (the desired bridge version, PR #38). PR #39 merged the fork
back with its history and authorship intact.

## Decision

The two 0.16.x lines are different releases sharing version numbers, so the merged package
continues at 0.17.0 (tagged 2026-10-03). The fork's release notes stay in CHANGELOG.md under their
own versions, marked as published from the fork.

## Consequences

- `0.16.0` means different code depending on where it was installed from; anyone on either line
  moves to 0.17.0 or later.

## Note

On 2026-11-21 the names of the fork, its owner, its people and its host application, and its
internal ticket numbers, were taken out of this record at the repository owner's request. The
decision and its reasoning are unchanged.
