<?php

use App\Filament\Resources\Lessons\Resources\LessonPages\Pages\EditLessonPage;
use Livewire\Livewire;

test('filament admin theme imports Filament base and sources panel utilities', function () {
    $theme = file_get_contents(resource_path('css/filament/admin/theme.css'));

    expect($theme)->toContain("vendor/filament/filament/resources/css/theme.css")
        ->and($theme)->toContain("@source '../../../../app/Filament/**/*'")
        ->and($theme)->toContain("@source '../../../../resources/views/filament/**/*'")
        ->and($theme)->not->toContain('resources/css/app.css')
        ->and($theme)->not->toContain("../**/*.blade.php");
});

test('AdminPanelProvider registers exactly the admin vite theme entry', function () {
    $provider = file_get_contents(app_path('Providers/Filament/AdminPanelProvider.php'));

    expect(substr_count($provider, 'viteTheme('))->toBe(1)
        ->and($provider)->toContain("viteTheme('resources/css/filament/admin/theme.css')");

    $panel = filament()->getPanel('admin');
    expect($panel->getViteTheme())->toBe('resources/css/filament/admin/theme.css');
});

test('vite config keeps player entries and adds the admin theme', function () {
    $config = file_get_contents(base_path('vite.config.js'));

    expect($config)->toContain("'resources/css/app.css'")
        ->and($config)->toContain("'resources/js/app.js'")
        ->and($config)->toContain("'resources/js/authoring/hotspot-editor-register.js'")
        ->and($config)->toContain("'resources/css/filament/admin/theme.css'");

    preg_match("/input:\\s*\\[([\\s\\S]*?)\\]/", $config, $match);
    expect($match)->not->toBeEmpty();
    $inputs = $match[1];
    expect(substr_count($inputs, 'resources/css/app.css'))->toBe(1)
        ->and(substr_count($inputs, 'resources/js/app.js'))->toBe(1)
        ->and(substr_count($inputs, 'hotspot-editor-register.js'))->toBe(1)
        ->and(substr_count($inputs, 'filament/admin/theme.css'))->toBe(1);
});

test('image labeling editor renders both Add hotspot controls sharing one action', function () {
    $teacher = asTeacher();
    $lesson = createOwnedLessonWithAllBlockTypes($teacher);
    $page = $lesson->pages()
        ->whereHas('blocks', fn ($q) => $q->where('type', 'image_labeling'))
        ->firstOrFail();

    $html = Livewire::actingAs($teacher)
        ->test(EditLessonPage::class, [
            'record' => $page->getKey(),
            'parentRecord' => $lesson,
        ])
        ->assertSuccessful()
        ->html();

    expect(str_contains($html, 'data-testid="add-hotspot-top"'))->toBeTrue()
        ->and(str_contains($html, 'data-testid="add-hotspot-bottom"'))->toBeTrue()
        ->and(str_contains($html, 'data-testid="hotspot-canvas"'))->toBeTrue()
        ->and(str_contains($html, 'teched-add-hotspot'))->toBeTrue()
        ->and(
            str_contains($html, 'fi-sc-grid')
            || str_contains($html, 'fi-grid')
            || str_contains($html, 'grid-cols')
            || str_contains($html, 'lg:col-span')
            || preg_match('/class="[^"]*grid[^"]*"/', $html) === 1
        )->toBeTrue('Expected a responsive grid wrapper around the hotspot editor');

    // Top Add calls addHotspot(); bottom dispatches the window event the map listens for.
    expect($html)->toContain('x-on:click="addHotspot()"')
        ->and($html)->toContain("x-on:teched-add-hotspot.window=\"addHotspot()\"");
});
