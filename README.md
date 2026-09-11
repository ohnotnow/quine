# Quine

Shows you what your code edits might affect at a distance.

## What?

You make a foreign key nullable for a good practical reason. Tests stay green, because every factory and test sets the key based on the original migration. A week later a template that references the relation without a null check blows up when someone visits a rarely used report page. But the test for that report (which was buried halfway through another test about something only semi-related), set the key so there was no null trap to go red.

Quine is a dev-only Laravel package that builds a graph of the inter-relations you don't spot by reading a few files you think are affected (model event closures, queued listeners, an AppServiceProvider gate that a template checks, etc etc), then runs some fanciness over it to say what to check before or after you or an agent edit the code.

## Still a WIP

This is the first test release.  Treat it as 'it worked ok for the original developer' for now until v1 is tagged.

## Install

```bash
composer require --dev ohffs/quine
```

Requires PHP 8.3+ and Laravel 12+. Quine pulls in larastan, PHPStan and Pest v5 itself. If you also have [bladestan](https://github.com/bladestan/bladestan) installed, quine indexes what your Blade templates read too; without it `quine:update` says `templates: not indexed` and templates are in the graph only by name. At the time of writing bladestan 0.11 breaks a plain `vendor/bin/phpstan` run on PHPStan 2.2 ([bladestan#191](https://github.com/bladestan/bladestan/issues/191)). Build the graph when you feel there's enough churn.

For Best Results(TM), give your relation methods the larastan generics (`/** @return BelongsTo<Author, $this> */`). Without them larastan types `$post->author` as a bare `Model`, and quine can only tell you "something over there reads a relation" rather than which one. If you would rather not do that by hand, there is a [Claude skill for working through it](https://github.com/ohnotnow/agentic-stuff/tree/master/skills/larastan).

```bash
vendor/bin/pest --tia
php artisan quine:update
```

`quine:update` prints a summary of the models, hidden edges and pages, a line counting who calls and reads what (`symbols: 9 calls, 13 fetches from 18 files; templates: 2 indexed`), and ends with the nudges the recipes have for the whole app. On the package's own fixture app that ends like this:

```
  NUDGES ....................................... what to check before you edit
  workbench/database/migrations/0001_01_01_000003_create_comments_table.php:13  Comment->author can be null: $table->foreignId('author_id')->nullable()->constrained()->nullOnDelete();
  workbench/resources/views/posts/comments.blade.php:4  reads $comment->author->name without null-safety, but Comment->author can be null (variable matched by name, heuristic)
  workbench/app/Models/Comment.php  no factory, seeder or test ever creates a Comment with a null author: a green suite proves nothing about that path
```

## Ask about something

Going to edit that model that shouldn't really break anything, ask what's near it and how it's related. A class name, a class basename or a file path all work.

```bash
php artisan quine:ask Post
```

```
  NEIGHBOURHOOD .................................... Workbench\App\Models\Post
    <- [relation: posts() HasMany] Workbench\App\Models\Author  workbench/app/Models/Author.php:23
    <- [relation: post() BelongsTo] Workbench\App\Models\Comment  workbench/app/Models/Comment.php:31
    -> [relation: author() BelongsTo] Workbench\App\Models\Author  workbench/app/Models/Post.php:37
    -> [relation: comments() HasMany] Workbench\App\Models\Comment  workbench/app/Models/Post.php:43
    -> [relation: tags() BelongsToMany] Workbench\App\Models\Tag  workbench/app/Models/Post.php:49
    <- [relation: posts() BelongsToMany] Workbench\App\Models\Tag  workbench/app/Models/Tag.php:25
    -> [model-event: created] closure workbench/app/Models/Post.php:23
    -> [model-event: saving] Workbench\App\Observers\PostObserver@saving
    -> [policy: policy] Workbench\App\Policies\PostPolicy
    <- author fetched from 3 places (quine:ask Post::author for them)
    <- isPublished() called from 2 places (quine:ask Post::isPublished for them)
    <- title fetched from 2 places (quine:ask Post::title for them)
    <- announcement() called from 1 place (quine:ask Post::announcement for them)
    <- referenced by 6 classes (uses; --full lists them)
    -> references 2 classes (uses; --full lists them)
    via Workbench\App\Console\Commands\AnnouncePosts:
        <- [schedule: 0 * * * *] schedule
    via Workbench\App\Http\Controllers\PostController:
        <- [route: show web] route GET|HEAD /posts/{post} [posts.show]
        -> [renders: posts.show] workbench/resources/views/posts/show.blade.php  workbench/app/Http/Controllers/PostController.php:12
    via Workbench\App\Events\PostPublished:
        -> [event: queued listener] Workbench\App\Listeners\NotifyEditors@handle
        <- referenced by 2 classes (uses; --full lists them)

  NUDGES ....................................... what to check before you edit
    workbench/database/migrations/0001_01_01_000003_create_comments_table.php:13  Comment->author can be null: ...
    workbench/app/Models/Comment.php  no factory, seeder or test ever creates a Comment with a null author: a green suite proves nothing about that path
```

(Trimmed: a few of the "fetched from" lines and one `via` block are left out.) The surprising kinds come first; plain `use Blah` references are just given as a count, run with `--full` to see them. Note: for now the nudges only cover models one relation away.

Ask about one member with `Class::member` (or `Class->member`, same thing) and it lists the consumers, templates included:

```bash
php artisan quine:ask 'Comment::author'
```

```
  CONSUMERS ............................. Workbench\App\Models\Comment::author
    <- [fetches: fetches author] workbench/resources/views/posts/comments.blade.php  workbench/resources/views/posts/comments.blade.php:4
    <- [fetches: fetches author] workbench/resources/views/posts/comments.blade.php  workbench/resources/views/posts/comments.blade.php:5
```

## Nudge on an edit

`quine:nudge <file>` says what an edit to one file can reach. It prints nothing for a file the graph does not know. Delete `author()` from the fixture's Comment model and it says:

```
Quine: hang on. workbench/app/Models/Comment.php
Comment::author() removed; called from workbench/resources/views/posts/comments.blade.php:4, workbench/resources/views/posts/comments.blade.php:5
reaches workbench/resources/views/posts/comments.blade.php: no test renders this
no test covers this file
```

Touch the fixture's Post model somewhere near its observer, policy and event and it says:

```
Quine: hang on. workbench/app/Models/Post.php
reaches workbench/resources/views/posts/comments.blade.php: no test renders this
on saving -> Workbench\App\Observers\PostObserver@saving
policy -> Workbench\App\Policies\PostPolicy
dispatches Workbench\App\Events\PostPublished -> Workbench\App\Listeners\NotifyEditors@handle (queued listener)
templates reached: workbench/resources/views/posts/show.blade.php (a test renders each; whether it exercises your change is yours to check)
1 test file covers this file: vendor/bin/pest --tia runs it
```

Make `post_id` nullable on the fixture's comments table and it says:

```
Quine: hang on. workbench/database/migrations/0001_01_01_000003_create_comments_table.php
workbench/database/migrations/0001_01_01_000003_create_comments_table.php:14  Comment->post can be null: $table->foreignId('post_id')->nullable()->constrained()->cascadeOnDelete();
workbench/app/Models/Comment.php  no factory, seeder or test ever creates a Comment with a null post: a green suite proves nothing about that path
```

"Hang on" is earned by a gap, a hidden edge or a recipe; when everything reached is tested and nothing is hidden, the opener is "fyi". It rebuilds the graph first if the app's shape has changed since the last build.

## Claude Code hook

The package ships a PostToolUse hook for Claude Code. After every Write or Edit inside a Laravel app that has quine installed, it runs `quine:nudge` on the edited file and if there's something to note, hands that back to the agent as additional context.

Add this to `~/.claude/settings.json`, or to `.claude/settings.local.json` in one app to keep it local:

```json
{
    "hooks": {
        "PostToolUse": [
            {
                "matcher": "Write|Edit",
                "hooks": [
                    {
                        "type": "command",
                        "command": "php /path/to/your/app/vendor/ohffs/quine/hooks/claude-code/post-edit.php"
                    }
                ]
            }
        ]
    }
}
```

One copy serves every project: copy `hooks/claude-code/post-edit.php` somewhere permanent and point the command at that instead.

## Configuration

```bash
php artisan vendor:publish --tag="quine-config"
```

The published file sets the paths quine scans, where the graph is written, where the Pest tia cache lives, and the list of recipes. The only recipe so far is the nullable belongsTo one. A recipe is a class implementing `Ohffs\Quine\Recipes\Recipe`.

## What it is not

Not a coverage/quality/whatever tool - it's just a helpful nudge about... you know... that thing _over there_ that you forgot about.

## Contributing

Clone the repository, run `composer install`, then `composer test`. The fixture app in `workbench/` is what every source and recipe is tested against, so a bug report that comes with a fixture change reproducing it is the easiest kind to fix.

## Licence

MIT. See [LICENSE.md](LICENSE.md).
