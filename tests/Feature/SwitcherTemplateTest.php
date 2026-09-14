<?php

declare(strict_types=1);

/**
 * Craft's `forms.select` macro applies a passed `class` to the wrapping
 * `<div class="select">` rather than to the `<select>` itself. The period and
 * site switchers are driven by a delegated `change` listener that reads the
 * class off the changed element, so a hook passed as `class` silently does
 * nothing — which is exactly how both dropdowns ended up inert.
 */

function telescopeTemplate(string $name): string
{
    return (string)file_get_contents(dirname(__DIR__, 2) . "/src/templates/{$name}");
}

it('puts the switcher hooks on the select, not the macro container', function(string $template) {
    $source = telescopeTemplate($template);

    expect($source)->not->toMatch("/^\s*class: '(js-telescope-(?:period|site))'/m");
})->with(['overview.twig', 'entry.twig']);

it('still carries both switcher hooks', function() {
    $overview = telescopeTemplate('overview.twig');

    expect($overview)->toContain("inputAttributes: { class: ['js-telescope-site'] }")
        ->and($overview)->toContain("inputAttributes: { class: ['js-telescope-period'] }")
        ->and(telescopeTemplate('entry.twig'))
        ->toContain("inputAttributes: { class: ['js-telescope-period'] }");
});

it('matches the hook on either the select or its container', function() {
    // Belt and braces: even if a hook drifts back onto the container, the
    // listener walks up from the changed <select> and still finds it.
    $js = (string)file_get_contents(dirname(__DIR__, 2) . '/src/web/assets/cp/dist/js/telescope.js');

    expect($js)->toContain("closest('.js-telescope-period, .js-telescope-site')");
});
