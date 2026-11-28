<?php

namespace Tests\Concerns;

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;

/**
 * "Ingat saya": setelah laptop dimatikan lalu dinyalakan, cookie sesi (dan sesi di server, yang hanya berlaku
 * SESSION_LIFETIME) sudah hilang. Yang tersisa hanya cookie remember_web_*, dan itu harus cukup untuk tetap masuk.
 */
trait AssertsRememberedLogin
{
    protected function assertStaysSignedInAfterBrowserRestart(User $user): void
    {
        $recaller = Auth::guard('web')->getRecallerName();
        $this->assertStringStartsWith('remember_web_', $recaller);

        $cookie = Cookie::queued($recaller);
        $this->assertNotNull($cookie, 'Login tidak membuat cookie "Ingat saya".');
        $this->assertGreaterThan(now()->addYear()->getTimestamp(), $cookie->getExpiresTime());

        $this->restartBrowser();

        $this->withCookie($recaller, $cookie->getValue())
            ->get('/admin/login')
            ->assertRedirect('/admin');
        $this->assertAuthenticatedAs($user);
    }

    protected function assertNotRemembered(): void
    {
        $this->assertNull(Cookie::queued(Auth::guard('web')->getRecallerName()));

        $this->restartBrowser();

        $this->get('/admin/login')->assertOk();
        $this->assertGuest();
    }

    private function restartBrowser(): void
    {
        foreach (Cookie::getQueuedCookies() as $queued) {
            Cookie::unqueue($queued->getName(), $queued->getPath());
        }
        $this->app['auth']->forgetGuards();
        $this->app['session']->driver()->flush();
        $this->flushSession();
    }
}
