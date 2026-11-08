import React, { useEffect, useState } from 'react';
import http from '@/api/http';

type Report = { status: string; installed_build?: string | null; latest_build?: string | null; checked_at?: string; error?: string };
const serverId = () => window.location.pathname.match(/^\/server\/([^/]+)/)?.[1];
export default function UpdateStatus() {
    const [report, setReport] = useState<Report | null>(null);
    const [checked, setChecked] = useState('');
    const [failure, setFailure] = useState('');
    useEffect(() => {
        const id=serverId();
        if (!id) return;
        let active=true;
        const load = () => http.get(`/api/client/extensions/steamupdates/servers/${encodeURIComponent(id)}`)
            .then(({data}) => { if(active) {setReport(data.status || null);setChecked(data.checked_at || '');setFailure('');} })
            .catch(() => { if(active) setFailure('Update status unavailable or access denied.'); });
        load(); const timer=window.setInterval(load,60000);
        return () => {active=false;window.clearInterval(timer);};
    },[]);
    const color = report?.status==='up-to-date'?'#22c55e':report?.status==='update-available'?'#f59e0b':'#9ca3af';
    const label = report?.status==='up-to-date'?'Up to date':report?.status==='update-available'?'Update available':'Not checked';
    return <div style={{padding:'14px 18px',marginBottom:16,borderRadius:8,background:'#202330',color:'#e5e7eb',borderLeft:`4px solid ${color}`}}>
        <div style={{display:'flex',justifyContent:'space-between',gap:12,alignItems:'center',flexWrap:'wrap'}}>
            <strong>Steam game update</strong><span style={{fontWeight:700,color}}>{label}</span>
        </div>
        {report && <div style={{fontSize:13,marginTop:8}}>Installed: {report.installed_build || '—'} · Latest: {report.latest_build || '—'}</div>}
        <div style={{color:'#9ca3af',fontSize:12,marginTop:5}}>{failure || (checked?`Last checked: ${checked}`:'No update data for this server.')} · Read-only; updates install on restart.</div>
    </div>;
}
