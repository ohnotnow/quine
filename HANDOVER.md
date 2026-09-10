# Handover

Read this first when picking quine up cold. The durable record lives in the
`ait` issue tracker and the `ant` notebook (both local, gitignored); this file
is the front door to them.

## Open a session with

```
Run `ant foundation`, then `ant show quine-mjBCN` (the handover note, read to the end: it has dated updates appended), then `ait ready`.
Tell me the open questions in the handover note before doing anything.
```

## Where things stand (evening of 2026-09-10)

- Phases 1 to 3 and the devnotes comparison (5.1) are done. `quine:update`,
  `quine:ask`, `quine:nudge`, the nullable belongsTo recipe and the post-edit
  hook script all exist. 85 tests green.
- quine is installed in `../devnotes` as a symlinked path repository, so
  edits here show up there at once. Run the artisan commands from
  `/Users/billy/Documents/code/devnotes` to see real output.
- Phase 6 is committed (f13e645): `quine:ask` prints nudges for the model
  and its relation neighbours, orders edges by how hidden they are, collapses
  `uses` edges to a count unless `--full`.
- The hook is verified live in devnotes (3.2 closed). The package is renamed
  to `ohffs/quine`, namespace `Ohffs\Quine`, GitHub `ohnotnow/quine`, and the
  "an Activity" wording is fixed. 85 tests green.
- Next, in order: the README rewrite (5.2), then the MCP shape conversation
  (4.1). Exact commands and traps are in the handover note.

## Decisions made on 2026-09-10

- `quine:ask` must print nudges for the asked-for model and every model one
  relation hop away. Without that the tool misses its whole point (6.1).
- Default `quine:ask` output puts framework-registered edges first and
  collapses `uses` edges to a count; `--full` lists them all, so a developer
  can still see every spot to check in one command (6.2).
- A config `ignore` list for known-noise files is parked, not scheduled
  (`ant show quine-VXQvH`).
- The hook's 20 second timeout stays: a full rebuild on devnotes took 0.8s.
- The package is `ohffs/quine` (the user's packagist handle), namespace
  `Ohffs\Quine`, repository `github.com/ohnotnow/quine`. Decided today; it
  replaces the 2026-09-03 decision to keep the old vendor name.
- README example output comes from the package's own fixture app, never a
  real app (5.2).

## Working rules that bit us

- The user runs every composer command in this repo; check whether the agent
  may run them in `../devnotes` (the user restarted the session on
  2026-09-10 to allow it).
- The agent claiming issues is `twitchy-nose`; keep the name.
- Strict TDD, one failing test at a time. The spike in `reference/` is the
  spec, never code to copy.
- Every closed task carries a note with what was built and where it deviated
  from its spec: `ait show <id>` before touching that area.
- `ait note add <id> @file` stores the literal text; it does not read the
  file. Use `"$(cat file)"`.
