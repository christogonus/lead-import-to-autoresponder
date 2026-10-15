<?php

use App\Enums\IntegrationProvider;
use App\Models\User;
use App\View\Components\Marketing\Photo;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

dataset('public pages', [
    'home' => ['home'],
    'pricing' => ['pricing'],
    'integrations' => ['marketing.integrations'],
    'terms' => ['legal.terms'],
    'privacy' => ['legal.privacy'],
    'refunds' => ['legal.refunds'],
    'cookies' => ['legal.cookies'],
    'data processing' => ['legal.dpa'],
    'accessibility' => ['legal.accessibility'],
]);

test('public pages render for guests', function (string $route) {
    $response = $this->get(route($route));

    $response->assertOk()
        ->assertSee('Skip to main content')
        ->assertSee('<main id="main"', false);

    // One top-level heading per page, so screen reader users know where they are.
    expect(substr_count($response->getContent(), '<h1'))->toBe(1);
})->with('public pages');

test('public pages load no scripts, styles or fonts from other companies', function (string $route) {
    $html = $this->get(route($route))->getContent();

    preg_match_all('/<(?:script|link)[^>]+(?:src|href)="(https?:\/\/[^"]+)"/i', $html, $matches);

    $external = collect($matches[1])
        ->reject(fn (string $url): bool => str_starts_with($url, config('app.url')))
        ->values()
        ->all();

    expect($external)->toBe([]);
})->with('public pages');

test('the homepage lists every integration', function () {
    $response = $this->get(route('home'));

    foreach (IntegrationProvider::cases() as $provider) {
        $response->assertSee($provider->label());
    }
});

test('pricing shows the one plan on both billing cycles', function () {
    $this->get(route('pricing'))
        ->assertSee('$24')
        ->assertSee('$17')
        ->assertSee('$204 billed once a year')
        ->assertSee('not refunded');
});

test('the refund policy says there are no refunds', function () {
    $this->get(route('legal.refunds'))->assertSee('does not offer refunds');
});

test('the cookie policy lists the session cookie by its real name', function () {
    $this->get(route('legal.cookies'))
        ->assertSee(config('session.cookie'))
        ->assertSee('We do not track you');
});

test('signed-in visitors are offered their workspace', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('home'))
        ->assertSee('Open your workspace');
});

test('photos are served locally once fetched', function () {
    Http::fake(['images.pexels.com/*' => Http::response('jpeg-bytes', 200, ['Content-Type' => 'image/jpeg'])]);

    $directory = public_path('images/marketing');
    $existed = File::isDirectory($directory);

    try {
        $this->artisan('marketing:fetch-photos')->assertSuccessful();

        expect(File::exists(public_path(Photo::localPath('hero', 1024))))->toBeTrue();

        $this->get(route('home'))->assertSee(asset(Photo::localPath('hero', 1024)), false);
    } finally {
        if (! $existed) {
            File::deleteDirectory($directory);
        }
    }
});
