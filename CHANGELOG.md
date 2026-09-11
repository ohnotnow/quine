# Release Notes

## [Unreleased](https://github.com/ohnotnow/quine/compare/v0.1.0...master)

Everything so far, in one place, since nothing is tagged yet:

- `quine:update` builds the graph of an app's hidden edges from the framework's own registries (relations, model events, observers, policies, gates, listeners, routes, rendered templates, includes, the schedule) plus Larastan for migration nullability and Pest's `--tia` cache for which tests reach which files, and writes it to `storage/app/quine/graph.json`.
- A member index: a PHPStan collector riding on Larastan records who calls which method and who reads which property, as `calls` and `fetches` edges to `Class::member` nodes, with the file and line and whether a read of a nullable receiver is null-safe. Templates are indexed too when `tomasvotruba/bladestan` is installed (a `suggest`, detected at runtime).
- `quine:ask <class|file>` prints the neighbourhood, surprising kinds first, `uses` references collapsed to a count (`--full` lists them), plus the recipe nudges for the node and the models one relation away. `quine:ask Class::member` (or `Class->member`) prints the consumers of one member.
- `quine:nudge <file>` says what an edit to one file reaches: resolved callers of a removed or changed method (templates on their own line), hidden edges the edit touches, templates reached and whether a test renders them, test coverage of the file, and recipe nudges. Opens with `Quine: hang on.` for a gap or a hidden edge, `Quine: fyi.` otherwise, and prints nothing for a file the graph does not know. `--edit` takes the edit itself on stdin, which is what the hook sends.
- One recipe, `NullableBelongsTo`: a foreign key that can be null, the templates that dereference the relation without null-safety, and whether any factory, seeder or test ever creates the null case.
- A Claude Code PostToolUse hook, `hooks/claude-code/post-edit.php`, that runs `quine:nudge` after every Write or Edit and hands the result back as additional context.
- Scheduled artisan commands resolve to their command class, so `quine:ask` on a command shows it runs on a schedule.
- An edit that only adds or changes comments stays silent, even when the editor's anchor text repeats the line below the docblock: lines the old and new text share are context, not a change.

## [v0.1.0](https://github.com/ohnotnow/quine/compare/...v0.1.0) - 202x-xx-xx

Initial pre-release.
