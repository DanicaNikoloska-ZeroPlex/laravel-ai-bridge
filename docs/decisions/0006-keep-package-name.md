---
status: accepted
date: 2026-10-16
supersedes:
superseded_by:
revisit_when:
---
# The package keeps the name tetrixdev/laravel-ai-bridge after merging the fork's 0.18

## Context

A downstream fork released 0.18.0 (MCP App cards) under another package name,
replacing `tetrixdev/laravel-ai-bridge` (commit f4dd52d); the namespace stayed `Tetrix\AiBridge`.
PR #41 (jfbauer) merged that work back here.

## Decision

The rename is not taken: `composer.json` keeps `tetrixdev/laravel-ai-bridge` without a `replace`,
and the README install line and badge point here (commit fb11e7a). The namespace `Tetrix\AiBridge`
is unchanged.

## Consequences

- An application on the fork switches back by requiring `tetrixdev/laravel-ai-bridge` again; no
  code changes, since the namespace never changed.
- CHANGELOG's 0.18 heading says where 0.18 came from.

## Note

The wording of this record was anonymised on 2026-11-21. The decision and its reasoning
are unchanged.
