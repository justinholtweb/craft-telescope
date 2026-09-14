<?php

declare(strict_types=1);

use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Twig\Source;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * Parse every shipped template.
 *
 * Twig syntax errors only surface when a screen is actually rendered, which no
 * other test here does — a stray `{# #}` inside a hash literal is a parse
 * error, and one of those took the whole overview screen down once.
 *
 * Craft's own filters and functions are stubbed rather than booted: this checks
 * syntax, not behaviour, and parsing does not care what a filter returns.
 */
function twigParser(): Environment
{
    $env = new Environment(new ArrayLoader([]));

    foreach (['t', 'translate', 'values', 'raw', 'explodeClass', 'parseAttr'] as $filter) {
        $env->addFilter(new TwigFilter($filter, static fn(): string => ''));
    }

    foreach (['url', 'tag', 'constant', 'expression'] as $function) {
        $env->addFunction(new TwigFunction($function, static fn(): string => ''));
    }

    return $env;
}

/**
 * @return list<array{0: string}>
 */
function telescopeTemplates(): array
{
    $dir = dirname(__DIR__, 2) . '/src/templates';
    $templates = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir)) as $file) {
        if (!$file->isDir() && $file->getExtension() === 'twig') {
            $templates[] = [substr($file->getPathname(), strlen($dir) + 1)];
        }
    }

    sort($templates);

    return $templates;
}

it('parses as valid Twig', function(string $template) {
    $path = dirname(__DIR__, 2) . "/src/templates/{$template}";
    $env = twigParser();

    $env->parse($env->tokenize(new Source((string)file_get_contents($path), $path)));
})->with(telescopeTemplates())->throwsNoExceptions();

it('ships the templates every control panel screen renders', function() {
    // A guard against a rename that leaves a controller pointing at nothing.
    expect(array_merge(...telescopeTemplates()))
        ->toContain('overview.twig', 'entry.twig', 'print.twig', '_settings.twig')
        ->toContain('_components/report.twig', '_components/breakdown.twig');
});
