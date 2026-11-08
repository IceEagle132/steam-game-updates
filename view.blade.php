@php
    // Cache is written by the host checker and never exposed as a public file.
    $cachePath = storage_path('app/steam-update-checker/status.json');
    $result = null;
    $cacheError = null;
    if (is_readable($cachePath)) {
        $contents = @file_get_contents($cachePath);
        $decoded = $contents === false ? null : json_decode($contents, true);
        if (is_array($decoded) && isset($decoded['servers']) && is_array($decoded['servers'])) {
            $result = $decoded;
        } else {
            $cacheError = 'The update status cache is invalid.';
        }
    } else {
        $cacheError = 'No cached update results yet. Run the checker on the VPS.';
    }
    $lastCheck = is_array($result) ? ($result['checked_at'] ?? 'Unknown') : 'Never';
    $stale = is_file($cachePath) && (time() - filemtime($cachePath)) > 30 * 60;
@endphp
@php
    $adminSettingsPath = storage_path('app/steam-update-checker/admin.json');
    $adminSettings = is_readable($adminSettingsPath)
        ? json_decode((string) file_get_contents($adminSettingsPath), true)
        : [];
    $globalInterval = (int) (($adminSettings['interval_minutes'] ?? 30));
    $intervalOptions = [
        5 => 'Every 5 minutes',
        10 => 'Every 10 minutes',
        15 => 'Every 15 minutes',
        30 => 'Every 30 minutes',
        60 => 'Every hour',
        120 => 'Every 2 hours',
        360 => 'Every 6 hours',
        1440 => 'Every 24 hours',
    ];
@endphp

<div class="box box-primary" style="margin-top: 16px;">
    <div class="box-header with-border">
        <h3 class="box-title">Global Update Settings</h3>
    </div>
    <div class="box-body">
        @if (session('success'))
            <div class="alert alert-success">{{ session('success') }}</div>
        @endif

        <form method="POST" action="/extensions/steamupdates/settings">
            @csrf

            <div class="form-group">
                <label for="interval_minutes">Check for game updates</label>

                <select
                    name="interval_minutes"
                    id="interval_minutes"
                    class="form-control"
                >
                    @foreach ($intervalOptions as $minutes => $label)
                        <option value="{{ $minutes }}"
                            {{ $globalInterval === $minutes ? 'selected' : '' }}>
                            {{ $label }}
                        </option>
                    @endforeach
                </select>

                <p class="help-block">
                    Controls how often Steam checks for game updates
                    across all configured game servers.
                </p>
            </div>

            <button type="submit" class="btn btn-primary">
                <i class="fa fa-save"></i> Save Settings
            </button>
        </form>
    </div>
</div>

<div class="box box-primary" style="margin-top: 16px;">
    <div class="box-header with-border">
        <h3 class="box-title">Steam Game Update Checker</h3>
        <div class="box-tools pull-right"><span class="label label-primary">Read-only</span></div>
    </div>
    <div class="box-body">
        <p>Compares installed Steam app manifest build IDs against Steam's public branch. Checking never stops or updates game servers.</p>
        <p><strong>Last check:</strong> {{ $lastCheck }}
           @if ($stale) <span class="label label-warning">Results may be stale</span> @endif
        </p>
        @if ($cacheError)
            <div class="alert alert-warning">{{ $cacheError }}</div>
        @elseif (empty($result['servers']))
            <div class="alert alert-info">No game servers configured.</div>
        @else
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead><tr><th>Game server</th><th>Steam App ID</th><th>Installed build</th><th>Latest public build</th><th>Status</th></tr></thead>
                    <tbody>
                        @foreach ($result['servers'] as $server)
                            @php
                                $state = $server['status'] ?? 'unknown';
                                $cls = $state === 'up-to-date' ? 'success' : ($state === 'update-available' ? 'warning' : 'default');
                                $statusLabel = $state === 'up-to-date' ? 'Up to date' : ($state === 'update-available' ? 'Update available' : 'Unknown');
                            @endphp
                            <tr>
                                <td>{{ $server['name'] ?? 'Unknown' }}</td>
                                <td>{{ $server['app_id'] ?? '—' }}</td>
                                <td>{{ $server['installed_build'] ?? '—' }}</td>
                                <td>{{ $server['latest_build'] ?? '—' }}</td>
                                <td>
                                    <span class="label label-{{ $cls }}">{{ $statusLabel }}</span>
                                    @if ($state === 'unknown' && !empty($server['error']))
                                        <span title="{{ $server['error'] }}">(check failed)</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
        <p class="text-muted">Refresh this page after the scheduled check runs. Build differences are detected; installations happen separately when you restart a server through its normal Pterodactyl workflow.</p>
    </div>
</div>
