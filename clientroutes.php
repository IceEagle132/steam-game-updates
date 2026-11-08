<?php
use Illuminate\Support\Facades\Route;
use Illuminate\Http\Request;
use Pterodactyl\Models\Server;

// This API is explicitly restricted to the server owner or a panel root administrator.
// It deliberately never returns stored webhook URLs.
function steamupdates_server(Request $request, string $short): Server {
    $server = Server::query()->where('uuidShort', $short)->firstOrFail();
    $user = $request->user();
    abort_unless($user && ($user->root_admin || (int)$server->owner_id === (int)$user->id), 403);
    return $server;
}
function steamupdates_paths(): array {
    $dir = storage_path('app/steam-update-checker');
    return [$dir.'/status.json', $dir.'/settings.json'];
}
function steamupdates_settings(): array {
    [, $p] = steamupdates_paths();
    if (!is_file($p)) return [];
    $obj = json_decode((string)@file_get_contents($p), true);
    return is_array($obj) ? $obj : [];
}
function steamupdates_public_settings(array $all, string $uuid): array {
    $s = $all[$uuid] ?? [];
    return [
        'discord_enabled' => (bool)($s['discord_enabled'] ?? false),
        'webhook_url' => (string)($s['webhook_url'] ?? ''),
    ];
}
Route::get('/servers/{short}', function(Request $request, string $short) {
    $server = steamupdates_server($request, $short);
    [$cache] = steamupdates_paths();
    $all = is_file($cache) ? json_decode((string)@file_get_contents($cache), true) : null;
    $rows = (is_array($all) && is_array($all['servers'] ?? null)) ? $all['servers'] : [];
    $matching = null;
    foreach($rows as $row) {
        if (($row['server_uuid'] ?? '') === $server->uuid) { $matching=$row; break; }
    }
    // Prior checker versions lack UUID. Allow unique legacy match by exact server name.
    if (!$matching) {
        $candidates = array_values(array_filter($rows, fn($r) => ($r['name'] ?? '') === $server->name));
        if (count($candidates) === 1) $matching = $candidates[0];
    }
    return response()->json([
        'status' => $matching,
        'checked_at' => $all['checked_at'] ?? null,
        'settings' => steamupdates_public_settings(steamupdates_settings(), $server->uuid),
    ])->header('Cache-Control','private, no-store');
});
Route::post('/servers/{short}/settings', function(Request $request, string $short) {
    $server = steamupdates_server($request, $short);
    $v = $request->validate([
        'discord_enabled' => 'required|boolean',
        'webhook_url' => 'nullable|string|max:2048',
    ]);
    [$cache,$file] = steamupdates_paths();
    $settings=steamupdates_settings();
    $current=$settings[$server->uuid] ?? [];
    $webhook=trim((string)($v['webhook_url'] ?? ''));
    if ($webhook !== '') {
        $parsed=parse_url($webhook);
        $host=strtolower((string)($parsed['host'] ?? ''));
        $path=(string)($parsed['path'] ?? '');
        $valid=($parsed['scheme'] ?? '')==='https' && in_array($host,['discord.com','discordapp.com'],true)
            && preg_match('~^/api/webhooks/[0-9]{10,25}/[A-Za-z0-9_-]{20,}$~',$path)
            && empty($parsed['user']) && empty($parsed['pass']) && empty($parsed['port']) && empty($parsed['query']) && empty($parsed['fragment']);
        abort_unless($valid, 422, 'Enter a valid Discord webhook URL.');
        $current['webhook_url']=$webhook;
    }
    if ($webhook === '') unset($current['webhook_url']);
    $current['discord_enabled']=(bool)$v['discord_enabled'];
    $settings[$server->uuid]=$current;
    $dir=dirname($file);
    if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) abort(500);
    // Protect secrets from other users; www-data must be able to update them.
    $temp=tempnam($dir, '.settings-');
    if (!$temp) abort(500);
    try {
        @chmod($temp,0600);
        if (file_put_contents($temp, json_encode($settings,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),LOCK_EX)===false || !@rename($temp,$file)) abort(500);
    } finally { if(is_file($temp)) @unlink($temp); }
    return response()->json(['settings'=>steamupdates_public_settings($settings,$server->uuid)]);
});
