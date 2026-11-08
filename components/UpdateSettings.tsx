import React, { useEffect, useState } from 'react';
import http from '@/api/http';

type Settings = {
    discord_enabled: boolean;
    webhook_url: string;
};

const serverId = () => window.location.pathname.match(/^\/server\/([^/]+)/)?.[1];

export default function UpdateSettings() {
    const [settings, setSettings] = useState<Settings>({
        discord_enabled: false,
        webhook_url: '',
    });

    const [webhook, setWebhook] = useState('');
    const [message, setMessage] = useState('');
    const [busy, setBusy] = useState(false);

    useEffect(() => {
        const id = serverId();
        if (!id) return;

        http.get(`/api/client/extensions/steamupdates/servers/${encodeURIComponent(id)}`)
            .then(({ data }) => {
                setSettings(data.settings);
                setWebhook(data.settings.webhook_url || '');
            })
            .catch(() => setMessage('Cannot load update settings (server owner/admin only).'));
    }, []);

    const save = async () => {
        const id = serverId();
        if (!id) return;

        setBusy(true);
        setMessage('');

        try {
            const { data } = await http.post(
                `/api/client/extensions/steamupdates/servers/${encodeURIComponent(id)}/settings`,
                {
                    discord_enabled: settings.discord_enabled,
                    webhook_url: webhook,
                }
            );

            setSettings(data.settings);
            setWebhook(data.settings.webhook_url || '');
            setMessage('Settings saved.');
        } catch (e: any) {
            setMessage(e?.response?.data?.message || 'Could not save settings.');
        } finally {
            setBusy(false);
        }
    };

    const wrapper: React.CSSProperties = {
        display: 'flex',
        flexWrap: 'wrap',
        gap: 24,
        marginBottom: 24,
    };

    const box: React.CSSProperties = {
        background: '#202330',
        color: '#e5e7eb',
        padding: 20,
        borderRadius: 8,
        flex: '0 0 calc(50% - 12px)',
        maxWidth: 'calc(50% - 12px)',
        minWidth: 380,
        boxSizing: 'border-box',
    };

    const field: React.CSSProperties = {
        width: '100%',
        padding: 10,
        background: '#363b4b',
        border: '1px solid #4b5563',
        borderRadius: 6,
        color: '#f9fafb',
        boxSizing: 'border-box',
    };

    return (
        <div style={wrapper}>
            <section style={box}>
                <h3 style={{ fontSize: 18, fontWeight: 700, marginBottom: 12 }}>
                    Steam Update Checker Settings
                </h3>

                <p style={{ fontSize: 13, color: '#9ca3af', marginBottom: 18 }}>
                    Configure Discord notifications for this game server. Update check frequency is managed by the server administrator.
                </p>

                <label
                    style={{
                        display: 'flex',
                        alignItems: 'center',
                        gap: 10,
                        marginTop: 20,
                        marginBottom: 12,
                    }}
                >
                    <input
                        type="checkbox"
                        checked={settings.discord_enabled}
                        onChange={e =>
                            setSettings({ ...settings, discord_enabled: e.target.checked })
                        }
                    />
                    Notify Discord when an update is available
                </label>

                <label style={{ display: 'block', marginBottom: 6 }}>
                    Discord webhook URL
                </label>

                <input
                    style={field}
                    type="text"
                    autoComplete="off"
                    spellCheck={false}
                    placeholder="https://discord.com/api/webhooks/..."
                    value={webhook}
                    onChange={e => setWebhook(e.target.value)}
                />

                <div style={{ fontSize: 12, color: '#9ca3af', marginTop: 6 }}>
                    Clear the URL and save to remove the stored webhook.
                </div>

                <button
                    type="button"
                    disabled={busy}
                    onClick={save}
                    style={{
                        marginTop: 18,
                        background: '#2563eb',
                        color: 'white',
                        border: 0,
                        padding: '10px 22px',
                        borderRadius: 6,
                        cursor: 'pointer',
                    }}
                >
                    {busy ? 'Saving…' : 'Save update settings'}
                </button>

                {message && <div style={{ marginTop: 10, fontSize: 13 }}>{message}</div>}
            </section>
        </div>
    );
}
