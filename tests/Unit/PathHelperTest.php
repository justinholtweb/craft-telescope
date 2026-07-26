<?php

declare(strict_types=1);

use justinholtweb\telescope\helpers\PathHelper;

it('extracts the path from a full URL', function() {
    expect(PathHelper::pathFromUrl('https://example.com/blog/hello'))->toBe('/blog/hello');
});

it('treats the site root as "/"', function() {
    expect(PathHelper::pathFromUrl('https://example.com'))->toBe('/')
        ->and(PathHelper::pathFromUrl('https://example.com/'))->toBe('/');
});

it('strips a trailing slash so an EXACT match still hits', function() {
    // GA4 records "/about"; a Craft URL ending in "/" would otherwise miss.
    expect(PathHelper::pathFromUrl('https://example.com/about/'))->toBe('/about');
});

it('drops the query string by default', function() {
    expect(PathHelper::pathFromUrl('https://example.com/blog?page=2'))->toBe('/blog');
});

it('keeps the query string when asked to', function() {
    expect(PathHelper::pathFromUrl('https://example.com/blog?page=2', true))->toBe('/blog?page=2');
});

it('drops the fragment', function() {
    expect(PathHelper::pathFromUrl('https://example.com/about#team'))->toBe('/about');
});

it('handles a URL with no scheme', function() {
    expect(PathHelper::pathFromUrl('example.com/about'))->toBe('/about');
});

it('handles a bare path', function() {
    expect(PathHelper::pathFromUrl('/about'))->toBe('/about');
});

it('handles a subfolder installation', function() {
    expect(PathHelper::pathFromUrl('https://example.com/en/products/widget'))->toBe('/en/products/widget');
});

it('decodes percent-encoded characters, the way GA4 stores them', function() {
    expect(PathHelper::pathFromUrl('https://example.com/caf%C3%A9'))->toBe('/café');
});

it('collapses duplicate slashes', function() {
    expect(PathHelper::pathFromUrl('https://example.com//blog///hello'))->toBe('/blog/hello');
});

it('returns null for an unusable URL', function(?string $url) {
    expect(PathHelper::pathFromUrl($url))->toBeNull();
})->with([null, '', '   ']);

it('normalises paths idempotently', function() {
    $once = PathHelper::normalize('/about/');
    $twice = PathHelper::normalize($once);

    expect($once)->toBe('/about')->and($twice)->toBe('/about');
});

it('normalises an empty path to the root', function() {
    expect(PathHelper::normalize(''))->toBe('/')
        ->and(PathHelper::normalize('/'))->toBe('/');
});

it('extracts and lowercases hostnames, dropping www', function() {
    expect(PathHelper::hostFromUrl('https://WWW.Example.com/about'))->toBe('example.com')
        ->and(PathHelper::hostFromUrl('http://sub.example.com'))->toBe('sub.example.com');
});

it('extracts a hostname from a URL with no scheme', function() {
    expect(PathHelper::hostFromUrl('example.com/about'))->toBe('example.com');
});

it('returns null when there is no hostname to find', function(?string $url) {
    expect(PathHelper::hostFromUrl($url))->toBeNull();
})->with([null, '', '/about']);

it('recognises referrers from our own hosts as internal', function() {
    $hosts = ['example.com', 'shop.example.org'];

    expect(PathHelper::isInternalReferrer('https://example.com/blog', $hosts))->toBeTrue()
        ->and(PathHelper::isInternalReferrer('https://www.example.com/blog', $hosts))->toBeTrue()
        ->and(PathHelper::isInternalReferrer('https://shop.example.org/cart', $hosts))->toBeTrue();
});

it('treats a subdomain of a known host as internal', function() {
    expect(PathHelper::isInternalReferrer('https://blog.example.com/post', ['example.com']))->toBeTrue();
});

it('does not mistake a lookalike domain for our own', function() {
    // "notexample.com" ends with "example.com" as a string but is a different site.
    expect(PathHelper::isInternalReferrer('https://notexample.com/', ['example.com']))->toBeFalse();
});

it('treats search engines and empty referrers as external', function() {
    expect(PathHelper::isInternalReferrer('https://www.google.com/', ['example.com']))->toBeFalse()
        ->and(PathHelper::isInternalReferrer('', ['example.com']))->toBeFalse()
        ->and(PathHelper::isInternalReferrer('(not set)', ['example.com']))->toBeFalse();
});

it('ignores empty entries in the known-host list', function() {
    expect(PathHelper::isInternalReferrer('https://example.com/', ['', '  ']))->toBeFalse();
});

it('labels an internal referrer with just its path', function() {
    expect(PathHelper::referrerLabel('https://example.com/blog/hello', ['example.com']))->toBe('/blog/hello');
});

it('labels an external referrer with host and path', function() {
    expect(PathHelper::referrerLabel('https://google.com/search', ['example.com']))->toBe('google.com/search');
});

it('drops the path from an external referrer that has none', function() {
    expect(PathHelper::referrerLabel('https://google.com/', ['example.com']))->toBe('google.com');
});

it('labels missing referrers as direct', function(string $referrer) {
    expect(PathHelper::referrerLabel($referrer, ['example.com']))->toBe('Direct / none');
})->with(['', '   ', '(not set)', '(direct)']);
