<?php

declare(strict_types=1);

namespace Ohffs\Quine\Support;

use Illuminate\Contracts\View\Factory;
use Illuminate\View\Compilers\BladeCompiler;
use Illuminate\View\Compilers\ComponentTagCompiler;
use InvalidArgumentException;

/**
 * Where a <x-name> tag ends up. The framework's own tag compiler walks the
 * aliases, class namespaces, guessed class names and anonymous component
 * paths; quine only turns its answer into a template file.
 */
final class ComponentResolver
{
    public function __construct(
        private readonly BladeCompiler $blade,
        private readonly Factory $views,
    ) {}

    /**
     * The absolute template file for an anonymous component, or '' for a
     * class-based or unknown one.
     */
    public function resolve(string $name): string
    {
        $compiler = new ComponentTagCompiler(
            $this->blade->getClassComponentAliases(),
            $this->blade->getClassComponentNamespaces(),
            $this->blade,
        );

        try {
            $resolved = $compiler->componentClass($name);
        } catch (InvalidArgumentException) {
            return '';
        }

        if (class_exists($resolved)) {
            return '';
        }

        try {
            return $this->views->getFinder()->find($resolved);
        } catch (InvalidArgumentException) {
            return '';
        }
    }
}
