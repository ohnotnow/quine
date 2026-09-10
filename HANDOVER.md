# Handover

Read this first when picking quine up cold. The durable record lives in the
`ait` issue tracker and the `ant` notebook (both local, gitignored); this file
is the front door to them.

## Open a session with

```
Run `ant foundation`, then `ant show quine-mjBCN` (the handover note, read to the end: dated updates are appended), then `ant show quine-sXVTv` (the pivot), then `ait ready`.
Tell me the open questions in the handover note before doing anything.
```

## Where things stand (night of 2026-09-10)

- Phases 1 to 3, 5.1, 6 and 7 are done and committed on `spike/1`. 93 tests,
  PHPStan level 7, Pint and 100% type coverage, all green. The user pushes.
- The package is `ohffs/quine`, namespace `Ohffs\Quine`, repository
  `github.com/ohnotnow/quine`. It is installed in `../devnotes` as a
  symlinked path repository and the editor hook is registered there in
  `.claude/settings.local.json`. Run the artisan commands from
  `/Users/billy/Documents/code/devnotes` to see real output.
- The hook has been verified live twice in devnotes, once per output shape.
- `README.md` is written and in the user's voice. Do not restyle it.
- Next, in order: finish 5.2 (`CHANGELOG.md` and the bundled Boost skill
  under `resources/boost/skills`, both still describe the old package); then
  the v0.1.0 release (a conversation first: tag from master after a merge, or
  tag `spike/1`); 7.3 (schedule edges to the command class); the MCP shape
  conversation (4.1); a technical overview document when the user says so.

## Decisions made on 2026-09-10

- The nudge after an edit is a digest of the graph around the edited file,
  not just recipe output (`ant show quine-sXVTv`). `Quine: hang on.` is
  earned by a gap, a hidden edge outside the file, or a recipe; `Quine: fyi.`
  otherwise; silence when the graph does not know the file.
- A template having a test never silences it. The developer wants the map,
  and "has a test" is not "tested for this change". An agent reviewer argued
  for dropping the tested-templates line; the user overruled it on purpose.
- Coverage prints as a count plus `vendor/bin/pest --tia runs them`, never a
  list of names.
- The word for a Blade file is "template". Not page, screen or view.
- `quine:ask` prints nudges for the model and every model one relation hop
  away; framework edges first; `uses` edges collapsed unless `--full`.
- A config `ignore` list for known-noise files is parked, not scheduled
  (`ant show quine-VXQvH`).
- The hook's 20 second timeout stays: a full rebuild on devnotes takes about
  a second.
- README example output comes from the package's own fixture app, never a
  real app.

## Working rules that bit us

- The user runs composer in this repo unless they say otherwise; on
  2026-09-10 they allowed the agent to run composer and artisan in devnotes.
- Any scratch edit the agent makes in devnotes is restored by copying a
  backup, never with `git checkout` (blocked). Check `git diff --quiet -- app`
  there before finishing.
- The agent claiming issues is `twitchy-nose`; keep the name.
- Strict TDD, one failing test at a time. The spike in `reference/` is the
  spec, never code to copy. Before building from a spec a previous session
  wrote, run the `ait-amnesia-check` agent over it; it found ten holes in
  7.1's first draft.
- Every closed task carries a note with what was built and where it deviated
  from its spec: `ait show <id>` before touching that area.
- `ait note add <id> @file` stores the literal text; use `"$(cat file)"`.
- Under `set -e`, a pipeline such as `pest | tail` does not stop the script
  when pest fails. Capture to a file and check the exit code.
- Ask a fresh Claude session in devnotes for cold feedback on output, and
  restart it between rounds so it is not pattern-matching on the last one.
  Its input is a consumer's; the user decides.
