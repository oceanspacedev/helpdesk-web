<?php

namespace App\Providers\Filament;

use App\Filament\Livewire\PersonalInfo;
use App\Filament\Livewire\UpdatePassword;
use App\Filament\Pages\Dashboard;
use App\Filament\Pages\MyProfile;
use Apriansyahrs\MekayaTheme\MekayaPlugin;
use Awcodes\Overlook\OverlookPlugin;
use Awcodes\Overlook\Widgets\OverlookWidget;
use BezhanSalleh\FilamentExceptions\FilamentExceptionsPlugin;
use BezhanSalleh\FilamentShield\FilamentShieldPlugin;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Support\Enums\Width;
use Filament\View\PanelsRenderHook;
use Filament\Widgets;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Jeffgreco13\FilamentBreezy\BreezyCore;
use Leandrocfe\FilamentApexCharts\FilamentApexChartsPlugin;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->brandName('Helpdesk')
            ->favicon(asset('images/icon.svg'))
            ->login()
            ->renderHook(
                PanelsRenderHook::AUTH_LOGIN_FORM_AFTER,
                fn () => view('auth.login-extra'),
            )
            ->spa()
            ->unsavedChangesAlerts()
            ->colors([
                'primary' => Color::Orange,
                'gray' => Color::Gray,
                'danger' => Color::Rose,
                'warning' => Color::Amber,
                'success' => Color::Emerald,
                'info' => Color::Blue,
            ])
            ->sidebarCollapsibleOnDesktop()
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\\Filament\\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\\Filament\\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\\Filament\\Widgets')
            ->widgets([
                Widgets\AccountWidget::class,
                OverlookWidget::class,
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ])
            ->plugins([
                MekayaPlugin::make()
                    ->colors(['primary' => Color::Orange]),
                BreezyCore::make()
                    ->myProfile(
                        shouldRegisterUserMenu: true,
                        shouldRegisterNavigation: false,
                    )
                    ->customMyProfilePage(MyProfile::class)
                    ->myProfileComponents([
                        'personal_info' => PersonalInfo::class,
                        'update_password' => UpdatePassword::class,
                    ])
                    ->enableTwoFactorAuthentication((bool) config('filament-breezy.enable_2fa', false))
                    ->enableSanctumTokens(
                        (bool) config('filament-breezy.enable_sanctum', false),
                        config('filament-breezy.sanctum_permissions', ['create', 'read', 'update', 'delete']),
                    ),
                FilamentApexChartsPlugin::make(),
                FilamentExceptionsPlugin::make(),
                FilamentShieldPlugin::make()
                    ->gridColumns([
                        'default' => 1,
                        'sm' => 2,
                        'lg' => 3,
                    ])
                    ->sectionColumnSpan(1)
                    ->checkboxListColumns([
                        'default' => 1,
                        'sm' => 2,
                        'lg' => 2,
                    ])
                    ->resourceCheckboxListColumns([
                        'default' => 1,
                        'sm' => 2,
                    ]),
                OverlookPlugin::make()
                    ->includes(config('overlook.includes', []))
                    ->excludes(config('overlook.excludes', []))
                    ->abbreviateCount((bool) config('overlook.should_convert_count', true))
                    ->tooltips((bool) config('overlook.enable_convert_tooltip', true))
                    ->sort(2)
                    ->columns([
                        'default' => 1,
                        'sm' => 2,
                        'md' => 3,
                        'lg' => 4,
                        'xl' => 4,
                        '2xl' => null,
                    ]),
            ])
            // Mekaya mengaktifkan dua route ini secara implisit. Helpdesk memakai
            // OTP WhatsApp sampai tersedia verifikasi email yang terpisah.
            ->passwordReset(null, null)
            ->profile(null)
            ->viteTheme('resources/css/admin/theme.css')
            ->maxContentWidth(Width::Full);
    }
}
