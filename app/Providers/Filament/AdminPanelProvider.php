<?php

namespace App\Providers\Filament;

use Filament\FontProviders\BunnyFontProvider;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationItem;
use App\Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Support\Icons\Heroicon;
use Filament\View\PanelsRenderHook;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Blade;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    /**
     * The "steel" neutral ramp that replaces Filament's default gray.
     *
     * Every lightness value is held at Filament's own Gray ramp so no
     * foreground/background pair loses contrast; only hue (251deg) and chroma
     * move, which is what gives the panel its cool steel cast. Shades 400 and
     * 500 are nudged slightly darker to pay for the darker steel page
     * background applied in the theme CSS. The ramp passes through the design
     * anchors: 700 lands near steel-700 #3c4a59, 800 near steel-800 #1d242c,
     * and 900 near steel-950 #12161b.
     *
     * @var array<int, string>
     */
    protected const STEEL = [
        50 => 'oklch(0.985 0.004 251)',  // #f8fafd
        100 => 'oklch(0.967 0.006 251)', // #f1f4f8
        200 => 'oklch(0.928 0.010 251)', // #e2e8ee
        300 => 'oklch(0.872 0.015 251)', // #ced6df
        400 => 'oklch(0.695 0.026 251)', // #919ead
        500 => 'oklch(0.535 0.031 251)', // #606f7f
        600 => 'oklch(0.446 0.033 251)', // #475666
        700 => 'oklch(0.373 0.032 251)', // #344251
        800 => 'oklch(0.278 0.026 251)', // #1f2935
        900 => 'oklch(0.210 0.020 251)', // #111921
        950 => 'oklch(0.130 0.014 251)', // #04080d
    ];

    /**
     * The arc ramp, built from Color::hex('#ff5a1f').
     *
     * Filament's generator applies fixed lightness constants, so a hot orange
     * lands at only 4.30:1 against white on shade 600. That is below
     * WCAG_AA_TEXT, which makes ButtonComponentColorMap give up on the vivid
     * background and fall back to the pale 400 tint with dark text — a washed
     * out primary button. Darkening 500 and 600 (hue and chroma untouched)
     * takes shade 600 to 5.03:1 so the map keeps the arc background and white
     * text.
     *
     * @return array<int, string>
     */
    protected static function arcPalette(): array
    {
        $palette = Color::hex('#ff5a1f');

        foreach ([500 => 0.640, 600 => 0.560] as $shade => $lightness) {
            [, $chroma, $hue] = sscanf($palette[$shade], 'oklch(%f %f %f)');

            $palette[$shade] = "oklch({$lightness} {$chroma} {$hue})";
        }

        return $palette;
    }

    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->viteTheme('resources/css/filament/admin/theme.css')
            ->login()
            ->favicon(asset('favicon.png'))
            ->brandName('Tech Learning System')
            ->brandLogo(asset('images/logo.png'))
            ->brandLogoHeight('2.75rem')
            ->colors([
                'primary' => self::arcPalette(),
                // Color::hex() is v5's hex entry point — it normalises the hue
                // and runs it through Filament's own 11-shade ramp, so the
                // accessible-shade picking that buttons and badges rely on
                // keeps working.
                'warning' => Color::hex('#ffc400'), // hazard — caution states only
                'gray' => self::STEEL,
            ])
            // The body font is deliberately left at Filament's default, which
            // already resolves to Inter Variable served from this app's own
            // public/fonts directory. Calling ->font('Inter') would swap that
            // for a Bunny CDN request and drop the woff2 preload link.
            ->monoFont('IBM Plex Mono', provider: BunnyFontProvider::class)
            // Bebas Neue ships a single 400 weight, so the URL is pinned rather
            // than left to the provider's default 400,500,600,700 request. The
            // serif slot is the panel's third font-registration slot; Filament
            // itself never applies font-serif, so the theme CSS is free to use
            // it as the display family.
            ->serifFont(
                'Bebas Neue',
                url: 'https://fonts.bunny.net/css?family=bebas-neue:400&display=swap',
                provider: BunnyFontProvider::class,
            )
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->widgets([])
            ->navigationItems([
                NavigationItem::make('Class progress')
                    ->url(fn (): string => route('staff.classes.index'), shouldOpenInNewTab: false)
                    ->icon(Heroicon::OutlinedAcademicCap)
                    ->sort(50)
                    ->visible(fn (): bool => Auth::user()?->isTeacher() || Auth::user()?->isAdmin()),
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
                \App\Http\Middleware\EnsureActiveUser::class,
            ])
            ->renderHook(
                PanelsRenderHook::SCRIPTS_AFTER,
                fn (): string => Blade::render("@vite('resources/js/authoring/hotspot-editor-register.js')"),
            );
    }
}
