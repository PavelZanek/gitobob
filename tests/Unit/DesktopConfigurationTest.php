<?php

use App\Providers\NativeAppServiceProvider;

it('allows an open system dialog to wait for a person', function (): void {
    expect((new NativeAppServiceProvider)->phpIni())->toHaveKey('max_execution_time', '0');
});
