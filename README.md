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

Requires PHP 8.3+, Laravel 12+, and your app's own larastan and Pest v5+. Larastan reads your migrations for nullability; Pest's `--tia` cache is what tells quine which tests reach which files. Build the graph when you feel there's enough churn.

```bash
vendor/bin/pest --tia
php artisan quine:update
```

`quine:update` prints a summary of the models, hidden edges and pages, and ends with the nudges the recipes have for the whole app. On the package's own fixture app that ends like this:

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
    <- [relation: posts() HasMany] Workbench\App\Models\Author  workbench/app/Models/Author.php:22
    <- [relation: post() BelongsTo] Workbench\App\Models\Comment  workbench/app/Models/Comment.php:29
    -> [relation: author() BelongsTo] Workbench\App\Models\Author  workbench/app/Models/Post.php:30
    -> [relation: comments() HasMany] Workbench\App\Models\Comment  workbench/app/Models/Post.php:35
    -> [relation: tags() BelongsToMany] Workbench\App\Models\Tag  workbench/app/Models/Post.php:40
    <- [relation: posts() BelongsToMany] Workbench\App\Models\Tag  workbench/app/Models/Tag.php:24
    -> [model-event: created] closure workbench/app/Models/Post.php:22
    -> [model-event: saving] Workbench\App\Observers\PostObserver@saving
    -> [policy: policy] Workbench\App\Policies\PostPolicy
    <- referenced by 4 classes (uses; --full lists them)
    -> references 1 class (uses; --full lists them)
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

The surprising kinds come first; plain `use Blah` references are just given as a count, run with `--full` to see them. Note: for now the nudges only cover models one relation away.

## Nudge on an edit

`quine:nudge <file>` says what an edit to one file can reach: the observers, listeners and policies registered somewhere else, the templates the change can arrive at and whether any test renders them, how many tests cover the file, and whatever the quine graph has to say about the uncommitted diff. It prints nothing for a file the graph does not know. Touch the fixture's Post model and it says:

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

The package ships a PostToolUse hook for Claude Code. After every Write or Edit inside a Laravel app that has quine installed, it runs `quine:nudge` on the edited file and if there's something to note, hands that back to the agent as additional context opening with `Quine: hang on.` when there is a gap or a hidden edge, or `Quine: fyi.` when there is not

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
