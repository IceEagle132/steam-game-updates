<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth.session', \Pterodactyl\Http\Middleware\AdminAuthenticate::class])
    ->post('/settings', function (Request $request) {
    $user = $request->user();

    abort_unless($user && $user->root_admin, 403);

    $validated = $request->validate([
        'interval_minutes' => 'required|integer|in:5,10,15,30,60,120,360,1440',
    ]);

    $directory = storage_path('app/steam-update-checker');
    $file = $directory . '/admin.json';

    if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
        abort(500, 'Unable to create settings directory.');
    }

    $settings = [
        'interval_minutes' => (int) $validated['interval_minutes'],
    ];

    $temp = tempnam($directory, '.admin-');

    if ($temp === false) {
        abort(500, 'Unable to create temporary settings file.');
    }

    try {
        chmod($temp, 0640);

        if (file_put_contents(
            $temp,
            json_encode($settings, JSON_PRETTY_PRINT),
            LOCK_EX
        ) === false || !rename($temp, $file)) {
            abort(500, 'Unable to save update settings.');
        }
    } finally {
        if (is_file($temp)) {
            unlink($temp);
        }
    }

    return redirect()->back()->with(
        'success',
        'Global update-check interval saved successfully.'
    );
});
