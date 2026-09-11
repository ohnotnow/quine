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
- The member index holds only members the app declares: `Note::factory()`, `Note::where()`, `withTrashed()` and the rest of Eloquent's magic are no longer recorded against the model, and a scope is recorded under the name it is called by whether called on the model or on a builder.
- A `calls` or `fetches` edge from app code starts at the method the call sits in (`App\Models\Note::scopeInChannelsOf`), closures included, so the member index is member-to-member; `quine:ask Class::member` names the consuming method.
- The nudge walks outward from a method the edit removed, changed or rewrote, member to member through classes that only pass the call on, and names where it ends up: a route, a template, a Livewire component, a listener, a scheduled command, a job, a mailable, a notification, an MCP tool, each with the trail that got there and the coverage of that file. A class nothing uses is named as the frontier. Bounded by `quine.reach.depth` (default 6); the hook prints ten lines per method then points at `quine:ask Class::member`, whose `REACHES` section lists them all. The hook's edit differ now writes a hunk header carrying the enclosing method, so a body-only edit walks too.
- A scope or accessor is looked up under the name it is consumed by (`scopePublished` as `published`, `getTitleLabelAttribute` and `titleLabel(): Attribute` as `title_label`), so removing one names its real consumers instead of "no caller found". The walk treats every method of a Livewire full-page component as its route's entry, and follows a method nobody calls (a resource's `toArray`, a job's `handle`) through whoever constructs or dispatches the class.
- A member reading or calling another member of its own class is an edge, so the walk follows `Note::searchScoped -> Note::inChannelsOf` on to what uses the search. Each reach line names where the edited member is consumed on the first hop, the templates one trail reaches share a line naming the test files that render each, a constructed class prints as `new NoteResource`, and a namespace guess says `by namespace`.
- An edit that only adds or changes comments stays silent, even when the editor's anchor text repeats the line below the docblock: lines the old and new text share are context, not a change.

## [v0.1.0](https://github.com/ohnotnow/quine/compare/...v0.1.0) - 202x-xx-xx

Initial pre-release.
