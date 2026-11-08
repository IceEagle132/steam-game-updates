#!/usr/bin/env python3
"""Read-only Steam update checker. Per-server rate limits and optional Discord notifications.
No server restarts or Steam app updates. Designed for root cron, config managed by admin.
"""
import argparse, datetime as dt, json, pathlib, re, urllib.request, urllib.error, time, os, tempfile

def now(): return dt.datetime.now(dt.timezone.utc)
def stamp(t): return t.isoformat()
def read_json(path, default):
    try:
        v=json.loads(pathlib.Path(path).read_text())
        return v if isinstance(v, type(default)) else default
    except (OSError,ValueError): return default

def safe_write(path, data):
    path=pathlib.Path(path);path.parent.mkdir(parents=True,exist_ok=True)
    fd,tmp=tempfile.mkstemp(prefix='.update-',dir=path.parent)
    try:
        os.fchmod(fd,0o644)
        with os.fdopen(fd,'w') as f:
            json.dump(data,f,indent=2); f.write('\n'); f.flush(); os.fsync(f.fileno())
        os.replace(tmp,path)
    finally:
        if os.path.exists(tmp): os.unlink(tmp)

def installed(path):
    t=pathlib.Path(path).read_text(errors='replace')
    m=re.search(r'^\s*"buildid"\s+"(\d+)"',t,re.M|re.I)
    if not m: raise ValueError('buildid not present in installed manifest')
    return m.group(1)

def public(app_id):
    req=urllib.request.Request(f'https://api.steamcmd.net/v1/info/{int(app_id)}', headers={'User-Agent':'SteamUpdates/0.2'})
    with urllib.request.urlopen(req,timeout=12) as r: data=json.load(r)
    val=data.get('data',{}).get(str(app_id),{}).get('depots',{}).get('branches',{}).get('public',{}).get('buildid')
    if not val or not str(val).isdigit(): raise ValueError('Steam public build unavailable')
    return str(val)

def notify_discord(url, name, installed_build, latest_build):
    # URL is read from panel-private settings; never print it.
    from urllib.parse import urlsplit
    u=urlsplit(url)
    if u.scheme!='https' or u.hostname not in ('discord.com','discordapp.com') or not re.fullmatch(r'/api/webhooks/\d{10,25}/[A-Za-z0-9_-]{20,}',u.path) or u.port or u.username or u.password or u.query or u.fragment:
        raise ValueError('Invalid Discord webhook URL')
    payload=json.dumps({'embeds':[{'title':'Steam game update available',
      'description':f'**{name}** has a newer Steam public build.', 'color':16753920,
      'fields':[{'name':'Installed build','value':installed_build,'inline':True},
                {'name':'Latest public build','value':latest_build,'inline':True}],
      'footer':{'text':'Steam Game Updates · Pterodactyl · Manual restart required'}}]}).encode()
    req=urllib.request.Request(url,data=payload,headers={'Content-Type':'application/json','User-Agent':'SteamUpdates/0.2'},method='POST')
    class NoRedirect(urllib.request.HTTPRedirectHandler):
        def redirect_request(self,*args,**kwargs): return None
    with urllib.request.build_opener(NoRedirect()).open(req,timeout=10) as r:
        if r.status not in (200,204): raise ValueError('Discord webhook response '+str(r.status))


def discover(config):
    """Discover Steam servers from Pterodactyl volumes."""
    root = pathlib.Path('/var/lib/pterodactyl/volumes')
    excluded = {'1007'}
    known = {
        str(e.get('server_uuid')): e
        for e in config.get('servers', [])
        if e.get('server_uuid')
    }
    found = {}

    uuid_pattern = re.compile(
        r'^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$',
        re.I
    )

    if root.is_dir():
        for volume in sorted(root.iterdir()):
            uuid = volume.name
            if not volume.is_dir() or not uuid_pattern.fullmatch(uuid):
                continue

            manifests = list(volume.glob('steamapps/appmanifest_*.acf'))
            manifests = [
                m for m in manifests
                if re.fullmatch(r'appmanifest_[0-9]+[.]acf', m.name)
                and m.stem.split('_')[-1] not in excluded
            ]

            if not manifests:
                continue

            preferred = None

            # Prefer the App ID explicitly specified by the game.
            appid_file = volume / 'steam_appid.txt'
            try:
                if appid_file.is_file():
                    preferred = appid_file.read_text().strip()
            except OSError:
                pass

            # Existing manual configuration is also a valid hint.
            if not preferred and uuid in known:
                preferred = str(known[uuid].get('app_id', ''))

            if preferred:
                matches = [
                    m for m in manifests
                    if m.stem.split('_')[-1] == preferred
                ]
                if not matches:
                    continue
                manifest = matches[0]
            elif len(manifests) == 1:
                manifest = manifests[0]
            else:
                # Avoid guessing when multiple game manifests exist.
                continue

            appid = int(manifest.stem.split('_')[-1])

            try:
                content = manifest.read_text(errors='replace')
                name_match = re.search(
                    r'^\s*"name"\s+"([^"]+)"',
                    content,
                    re.M | re.I
                )
                name = name_match.group(1) if name_match else f'Steam App {appid}'
            except OSError:
                name = f'Steam App {appid}'

            if uuid in known:
                name = known[uuid].get('name') or name

            found[uuid] = {
                'name': name,
                'server_uuid': uuid,
                'app_id': appid,
                'manifest': str(manifest)
            }

    # Retain manually configured servers not found by discovery.
    for entry in config.get('servers', []):
        uuid = str(entry.get('server_uuid') or '')
        if uuid and uuid not in found:
            found[uuid] = entry

    return list(found.values())

def check(config,old,settings,with_notify,global_interval):
    timestamp=now(); older={str(r.get('server_uuid') or r.get('name')):r for r in old.get('servers',[])}
    results=[]
    for entry in discover(config):
        uuid=str(entry.get('server_uuid',''))
        key=uuid or str(entry['name'])
        previous=older.get(key,{})
        opts=settings.get(uuid,{}) if uuid else {}
        interval=global_interval
        due=True
        if previous.get('checked_at'):
            try:
                last=dt.datetime.fromisoformat(previous['checked_at'])
                due=(timestamp-last).total_seconds()>=interval*60
            except (ValueError,TypeError): pass
        if not due:
            results.append(previous)
            continue
        row={'name':entry['name'],'server_uuid':uuid,'app_id':int(entry['app_id']),
            'checked_at':stamp(timestamp),'installed_build':None,'latest_build':None,'status':'unknown'}
        try:
            row['installed_build']=installed(entry['manifest'])
            row['latest_build']=public(row['app_id'])
            row['status']='up-to-date' if row['installed_build']==row['latest_build'] else 'update-available'
        except Exception as exc: row['error']=str(exc)
        # Notify when a newly observed available build differs from last alerted build.
        # First observation never sends; prevents surprising alerts immediately after installation.
        if with_notify and uuid and row['status']=='update-available' and opts.get('discord_enabled') and opts.get('webhook_url'):
            alerted=previous.get('last_notified_build')
            if (previous.get('status')=='up-to-date' or (previous.get('status')=='update-available' and previous.get('latest_build') != row['latest_build'])) and alerted!=row['latest_build']:
                try:
                    notify_discord(opts['webhook_url'],row['name'],row['installed_build'],row['latest_build'])
                    row['last_notified_build']=row['latest_build']
                    print('Discord notification sent for '+row['name'])
                except Exception as exc: print('Discord notification failed for '+row['name']+': '+exc.__class__.__name__)
        if 'last_notified_build' not in row and previous.get('last_notified_build'):
            row['last_notified_build']=previous['last_notified_build']
        results.append(row)
    return {'checked_at':stamp(timestamp),'servers':results}

if __name__=='__main__':
    p=argparse.ArgumentParser()
    p.add_argument('--config',required=True)
    p.add_argument('--output')
    p.add_argument('--settings',default='/var/www/pterodactyl/storage/app/steam-update-checker/settings.json')
    p.add_argument('--notify',action='store_true')
    p.add_argument('--admin-settings',default='/var/www/pterodactyl/storage/app/steam-update-checker/admin.json')
    a=p.parse_args()
    config=read_json(a.config,{})
    if not isinstance(config.get('servers'),list): p.error('Invalid server configuration')
    previous=read_json(a.output,{'servers':[]}) if a.output else {'servers':[]}
    admin=read_json(a.admin_settings,{})
    raw_interval=admin.get('interval_minutes',30)
    global_interval=int(raw_interval) if str(raw_interval).isdigit() else 30
    global_interval=global_interval if global_interval in (5,10,15,30,60,120,360,1440) else 30
    outcome=check(config,previous,read_json(a.settings,{}),a.notify,global_interval)
    if a.output: safe_write(a.output,outcome)
    else: print(json.dumps(outcome,indent=2))
