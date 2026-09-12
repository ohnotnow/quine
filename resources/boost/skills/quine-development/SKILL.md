---
name: quine-development
description: >
  Use quine in a Laravel app: build its graph of hidden edges, ask what an edit reaches before making it, and wire the post-edit nudge into Claude Code.
license: MIT
metadata:
  author: Ohffs
---

# Quine

Use this skill in a Laravel application that has `ohffs/quine` installed, or when asked to add it. Quine is dev-only: it builds a graph of the edges you cannot see from one file (relations, model events, observers, policies, gates, listeners, routes, templates, the schedule, and who calls or reads which member), then says what to check before or after an edit.

## Primary Goal

- ask quine before editing a model, migration, listener or template, and act on what it says, instead of guessing what the change reaches

## Workflow

### 1. Install and build the graph

```bash
composer require --dev ohffs/quine
vendor/bin/pest --tia
php artisan quine:update
```

- `pest --tia` fills the cache quine reads to know which tests reach which files; without it coverage lines say the cache is missing
- `quine:update` writes `storage/app/quine/graph.json` and prints the models, hidden edges, pages, a `symbols:` line for the member index, and the recipe nudges; rerun it after adding relations, listeners, routes or templates
- relation methods need larastan generics (`/** @return BelongsTo<Author, $this> */`) for the member index to know which model a relation returns; add them if they are missing
- `tomasvotruba/bladestan` is optional; when installed, `quine:update` also indexes what templates read, otherwise it prints `templates: not indexed`

### 2. Ask before editing

```bash
php artisan quine:ask Post              # class name, basename or file path
php artisan quine:ask Post --full       # list the plain `use` references too
php artisan quine:ask 'Post::author'    # consumers of one member; 'Post->author' is the same
```

- read the AROUND IT block top to bottom: surprising kinds (relations, model events, policies, listeners, routes, rendered templates) come first, plain references are a count
- the `fetched from` / `called from` lines say which members are consumed and by how many places; ask for the member to see each site with its file and line
- CHECK BEFORE EDITING lists what the recipes found: a nullable foreign key, a template reading it without null-safety, whether any factory, seeder or test creates the null case, and a nullable column or relation that feeds a cache key, storage path, URL, config key or queue name

### 3. Nudge after an edit

```bash
php artisan quine:nudge app/Models/Post.php
```

- opens `Quine, after your edit to <file>:` and then one line per thing worth knowing: a removed or changed method and who still calls it, the route, page, template, listener, job or MCP tool your change is used by and what test runs that file, a template no test renders, an observer, policy or listener that runs outside this file, a recipe finding; nothing when there is nothing to say. Every line says what it costs to check and where quine is guessing
- the callers line names every resolved consumer of a removed or changed method, templates on their own line; treat it as the checklist
- `--edit` reads `{"old": ..., "new": ...}` from stdin to judge one edit rather than the working-tree diff, for replaying an edit by hand; `--session=<id>` nudges every file changed since that session last asked, which is what the hook uses

### 4. Wire the Claude Code hook

Add to `~/.claude/settings.json` or the app's `.claude/settings.local.json`:

```json
{
    "hooks": {
        "PostToolUse": [
            {
                "matcher": "Bash|Write|Edit|MultiEdit|NotebookEdit",
                "hooks": [
                    {
                        "type": "command",
                        "command": "php /path/to/app/vendor/ohffs/quine/hooks/claude-code/post-edit.php"
                    }
                ]
            }
        ]
    }
}
```

- the hook runs `quine:nudge --session=<session id>` after any tool that can change files, Bash included, and returns the output as additional context; it is silent when quine has nothing to say
- when the app has changed shape or gained files since the last build, it starts `quine:update` in the background and the next edit answers from the fresh graph

### 5. Configure only when the defaults are wrong

```bash
php artisan vendor:publish --tag="quine-config"
```

Keys in `config/quine.php`: `base_path`, `graph_path`, `namespace` (default `App\`), `paths.app`, `paths.migrations`, `paths.views` (null means `config('view.paths')`), `paths.tests`, `paths.fixtures` (factories and seeders), `tia_graph` (null means discover Pest's cache), `reach.depth` (how many member hops the nudge walks, default 6), `recipes`.

### 6. Add a recipe when a kind of change keeps biting

Implement `Ohffs\Quine\Recipes\Recipe` (`nudges(Change $change, Graph $graph): array` returning one string per nudge) and add the class to `quine.recipes`. The bundled `NullableBelongsTo` is the pattern.

## Rules, References, and Templates

Read before executing:

- `README.md` in the package for the full output examples from its fixture app
- `config/quine.php` (published) for every key and its default

## Examples

- Before making `comments.author_id` nullable: `php artisan quine:ask 'Comment::author'` lists the template lines that read it; the ones without `?->` need a null check or a test that creates a comment with no author.
- After deleting a static method from a model: `quine:nudge` names every call site, including tests; update or remove each before moving on.
- A rarely fired mailable sent from a scheduled command: `php artisan quine:ask ThatCommand` shows the `<- [schedule: ...]` edge, and `quine:ask 'Post::announcement'` shows the command as the method's only consumer.

## Anti-patterns

- do not treat a green suite as proof after a nullability change; quine's `no factory, seeder or test ever creates ... with a null ...` line means the path was never walked
- do not add `tomasvotruba/bladestan` to `require`; keep it a dev dependency the app chooses
- do not document package internals here; keep the skill focused on adoption in Laravel apps
