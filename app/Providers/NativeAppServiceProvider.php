<?php

namespace App\Providers;

use Native\Desktop\Contracts\ProvidesPhpIni;
use Native\Desktop\Facades\Window;

class NativeAppServiceProvider implements ProvidesPhpIni
{
    /**
     * Executed once the native application has been booted.
     * Use this method to open windows, register global shortcuts, etc.
     */
    public function boot(): void
    {
        Window::open()->title('GitoBob')->width(980)->height(720)->showDevTools(false);
    }

    /**
     * The system folder picker may stay open as long as the user needs.
     *
     * @return array<string, string>
     */
    public function phpIni(): array
    {
        return ['max_execution_time' => '0'];
    }
}
