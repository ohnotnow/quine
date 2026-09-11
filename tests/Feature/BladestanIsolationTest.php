<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;

it('names Bladestan in one adapter class only, so its rewrite is a one-file change', function () {
    $naming = [];

    foreach ((new Filesystem)->allFiles(dirname(__DIR__, 2).'/src') as $file) {
        if (str_contains($file->getContents(), 'Bladestan\\')) {
            $naming[] = $file->getRelativePathname();
        }
    }

    expect($naming)->toBe(['Support/Bladestan.php']);
});
