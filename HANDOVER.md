# Handover

Read this first when picking quine up cold. The durable record lives in the
`ait` issue tracker and the `ant` notebook (both local, gitignored); this file
is the front door to them.

## Open a session with

```
Run `ant foundation`, then `ant show quine-mjBCN` (the handover note), then `ait ready`.
Tell me the open questions in the handover note before doing anything.
```

## Where things stand (morning of 2026-09-04)

- Phases 1 to 3 of initiative `quine-UkLWZ` are done: `quine:update`,
  `quine:ask`, `quine:nudge`, the nullable belongsTo recipe and the post-edit
  hook script all exist, built one failing test at a time against the
  workbench fixture app. 76 tests, PHPStan level 7, Pint and 100% type
  coverage, all green at commit `bd9d706`.
- Published at https://github.com/ohnotnow/quine on branch `spike/1`.
- Next, in order: the user runs `composer test`; install into the sister app
  and compare with `reference/devnotes-spike-report.txt` (task 5.1); the
  hook's manual check (3.2, blocked on 5.1); the URL change (5.3); the README
  rewrite (5.2). Exact commands and traps are in the handover note.

## Decisions made late on 2026-09-03

- Repository URLs (composer homepage, README badges, CHANGELOG links) change to
  `github.com/ohnotnow/quine`. The package name and namespace stay
  `ohwhatnow/quine`. Task 5.3.
- README example output comes from the package's own fixture app, never from a
  real app. Noted on 5.2.
- The hook's first nudge after a migration edit rebuilds the whole graph
  inside a 20 second timeout. Measure `php artisan quine:update` on the sister
  app before deciding what to do about it. Noted on 3.2.
- The `quine:update` report now says `tia cache is fresh: N test files` when
  the coverage cache is up to date, instead of saying nothing.

## Working rules that bit us

- Agents cannot run composer; the user runs every composer command.
- The agent claiming issues is `twitchy-nose`; keep the name.
- Strict TDD, one failing test at a time. The spike in `reference/` is the
  spec, never code to copy.
- Every closed task carries a note with what was built and where it deviated
  from its spec: `ait show <id>` before touching that area.
