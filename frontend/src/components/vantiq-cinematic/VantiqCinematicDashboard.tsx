import { useEffect, useState, type CSSProperties } from "react";
import { AnimatePresence, motion, useReducedMotion } from "motion/react";
import "./VantiqCinematicDashboard.css";

export type VantiqMetric = {
  id: string; label: string; value: number | null; display?: (value: number) => string;
  change: string; statusText?: string; trend: "up" | "down" | "neutral"; icon: string; sparkline?: number[];
};
export type VantiqApplication = { id: string; name: string; sharePercent: number; icon: string; tone?: "blue" | "cyan" | "amber" };
export type VantiqAlert = { id: string; title: string; detail: string; severity: "critical" | "warning" | "info" };
export type VantiqDashboardData = {
  metrics: VantiqMetric[]; activitySeries: number[]; activityTotal: number | null;
  applications: VantiqApplication[]; alerts: VantiqAlert[];
  systemHealth: number | null; lastUpdated?: string;
};
export type VantiqCinematicDashboardProps = {
  data: VantiqDashboardData | null; loading?: boolean; error?: string | null;
  onRefresh?: () => void | Promise<void>; onOpenInsights?: () => void; onOpenReports?: () => void;
  onOpenOperations?: () => void; onOpenAlert?: (alert: VantiqAlert) => void;
  onPeriodChange?: (period: string) => void; selectedPeriod?: string; organisationName?: string;
};

const fmt = (m: VantiqMetric, v: number) => m.display ? m.display(v) : Math.round(v).toLocaleString();

function AnimatedValue({ metric, reduced }: { metric: VantiqMetric; reduced: boolean }) {
  const [value,setValue] = useState(0);
  useEffect(() => {
    const end = metric.value;
    if (end === null) { setValue(0); return; }
    if (reduced) { setValue(end); return; }
    const start = performance.now(), from = value, duration = 950;
    let frame = 0;
    const tick = (now:number) => {
      const p = Math.min(1,(now-start)/duration), eased = 1-Math.pow(1-p,4);
      setValue(from+(end-from)*eased);
      if (p<1) frame=requestAnimationFrame(tick);
    };
    frame=requestAnimationFrame(tick);
    return () => cancelAnimationFrame(frame);
  // eslint-disable-next-line react-hooks/exhaustive-deps
  },[metric.value,reduced]);
  return <>{metric.value === null ? "—" : fmt(metric,value)}</>;
}
function Sparkline({values}:{values?:number[]}) {
  if (!values || values.length < 2) return null;
  const max=Math.max(...values,1);
  const pts=values.map((v,i)=>`${i/Math.max(values.length-1,1)*100},${34-v/max*29}`).join(" ");
  return <svg className="vc-spark" viewBox="0 0 100 38" aria-hidden="true"><polyline points={pts}/></svg>;
}
function NetworkArt() {
  const nodes=[[5,76,0],[17,63,1],[29,81,2],[42,52,0],[54,69,1],[66,38,2],[78,57,0],[91,26,1],[36,24,2],[73,12,0]];
  return <div className="vc-network" aria-hidden="true">
    <div className="vc-globe"/>
    <svg viewBox="0 0 1000 300" preserveAspectRatio="none">
      <path className="vc-orbit blue" d="M-20 245 C150 20 250 300 420 128 S700 18 1030 112"/>
      <path className="vc-orbit amber" d="M-10 175 C140 290 220 20 390 130 S650 260 1020 40"/>
      <path className="vc-orbit blue delayed" d="M40 285 C220 130 320 210 510 80 S790 180 1010 5"/>
      {nodes.map(([x,y,t],i)=><g key={i} className={`vc-node n${t}`} style={{animationDelay:`${i*.2}s`}}><circle cx={x*10} cy={y*3} r="4"/><circle className="halo" cx={x*10} cy={y*3} r="12"/></g>)}
      <circle cx="520" cy="153" r="34" className="vc-core-halo"/><circle cx="520" cy="153" r="24" className="vc-core"/>
      <text x="520" y="161" textAnchor="middle" className="vc-core-mark">V</text>
    </svg>
    <i className="vc-orb orb-one"/><i className="vc-orb orb-two"/>
  </div>;
}
function TrendChart({values}:{values:number[]}) {
  if (!values.length) return <p className="vc-chart-empty">No recorded transaction counts are available for this period.</p>;
  const data=values, max=Math.max(...data,1);
  const pts=data.map((v,i)=>`${i/Math.max(data.length-1,1)*600},${145-v/max*122}`).join(" ");
  return <svg className="vc-event-chart" viewBox="0 0 600 160" preserveAspectRatio="none" role="img" aria-label="Business activity trend">
    <defs><linearGradient id="vc-fill" x1="0" x2="0" y1="0" y2="1"><stop stopColor="#168aff" stopOpacity=".35"/><stop offset="1" stopColor="#168aff" stopOpacity="0"/></linearGradient></defs>
    {[35,75,115].map(y=><line key={y} x1="0" x2="600" y1={y} y2={y} className="vc-gridline"/>)}
    <polygon points={`${pts} 600,155 0,155`} fill="url(#vc-fill)" className="vc-area"/>
    <polyline points={pts} className="vc-chart-line"/>
  </svg>;
}
function HealthRing({value}:{value:number | null}) {
  const v=value === null ? null : Math.max(0,Math.min(100,value));
  return <div className={`vc-health-ring${v === null ? " is-unavailable" : ""}`}
    style={v === null ? undefined : {"--health":`${v*3.6}deg`} as CSSProperties}>
    <div><strong>{v === null ? "—" : `${v.toFixed(1)}%`}</strong><span>{v === null ? "Not scored" : "System health"}</span></div>
  </div>;
}

export default function VantiqCinematicDashboard({
  data,loading=false,error=null,onRefresh,onOpenInsights,onOpenReports,onOpenOperations,onOpenAlert,
  onPeriodChange,selectedPeriod="This month",organisationName="Organisation"
}:VantiqCinematicDashboardProps) {
  const reduced=!!useReducedMotion();
  const [period,setPeriod]=useState(selectedPeriod);
  const [refreshing,setRefreshing]=useState(false);
  useEffect(()=>setPeriod(selectedPeriod),[selectedPeriod]);
  const refresh=async()=>{
    if (!onRefresh || refreshing) return;
    setRefreshing(true);
    try { await onRefresh(); }
    finally { setRefreshing(false); }
  };
  return <section className="vantiq-cinematic" aria-label="Command Overview">
    <div className="vc-main">
      <section className="vc-hero">
        <NetworkArt/>
        <div className="vc-hero-copy">
          <span className="vc-eyebrow">VANTIQ / {organisationName}</span>
          <AnimatePresence mode="wait"><motion.h1
            initial={reduced?false:{opacity:0,y:18}} animate={{opacity:1,y:0}}
            exit={reduced?undefined:{opacity:0,y:-10}} transition={{duration:.4}}>
            From insight<br/>to <em>action.</em>
          </motion.h1></AnimatePresence>
          <p>Organisation data, refreshed as available.<br/>A clearer view of your business.</p>
          <div className="vc-hero-actions">
            <button className="vc-primary" onClick={onOpenInsights} type="button"><span className="material-symbols-rounded" aria-hidden="true">shield</span>Explore risk signals</button>
            <button className="vc-text-button" onClick={onOpenReports} type="button">View reports →</button>
            <label className="vc-period-select"><span className="material-symbols-rounded" aria-hidden="true">calendar_month</span>
              <select aria-label="Reporting period" value={period} onChange={(event)=>{setPeriod(event.target.value);onPeriodChange?.(event.target.value);}}>
                {["This week","This month","This quarter","This year"].map(option=><option key={option}>{option}</option>)}
              </select>
            </label>
            {onRefresh&&<button className={`vc-refresh${refreshing?" is-refreshing":""}`} onClick={()=>void refresh()} aria-label="Refresh dashboard" disabled={refreshing} type="button"><span className="material-symbols-rounded" aria-hidden="true">refresh</span></button>}
          </div>
        </div>
        <div className="vc-event-live"><span className="vc-live-dot"/><b>PERIOD ACTIVITY</b><strong>{data?.activityTotal === null || !data ? "—" : data.activityTotal.toLocaleString()}</strong><small>recorded transactions</small><Sparkline values={data?.activitySeries}/></div>
      </section>
      {loading && !data ? <div className="vc-loading" role="status">Loading organisation data…</div>
      : error && !data ? <div className="vc-loading vc-error" role="alert">{error}<button type="button" onClick={()=>void refresh()}>Try again</button></div>
      : !data ? <div className="vc-loading">No Command Overview data is available for your permissions.</div>
      : loading ? <div className="vc-loading" role="status">Updating business intelligence…</div> : <>
        <section className="vc-metrics" aria-label="Business performance metrics">
          {data.metrics.slice(0,4).map((m,i)=><motion.article key={m.id} className="vc-metric-card"
            initial={reduced?false:{opacity:0,y:20}} animate={{opacity:1,y:0}} transition={{delay:i*.08}}>
            <div className={`vc-metric-icon tone-${i}`}><span className="material-symbols-rounded">{m.icon}</span></div>
            <div className="vc-metric-copy"><span>{m.label}</span><strong><AnimatedValue metric={m} reduced={reduced}/></strong><small className={`trend-${m.trend}`}>{m.statusText ?? m.change}</small></div>
            <Sparkline values={m.sparkline}/>
          </motion.article>)}
        </section>
        <section className="vc-lower-grid">
          <motion.article className="vc-panel vc-flow-panel" initial={reduced?false:{opacity:0,y:18}} animate={{opacity:1,y:0}} transition={{delay:.3}}>
            <div className="vc-panel-heading"><h2><i className="vc-live-dot"/>Recorded business activity</h2><span>REPORT DATA</span></div>
            <div className="vc-flow-art"><NetworkArt/></div><TrendChart values={data.activitySeries}/>
          </motion.article>
          <motion.article className="vc-panel" initial={reduced?false:{opacity:0,y:18}} animate={{opacity:1,y:0}} transition={{delay:.4}}>
            <div className="vc-panel-heading"><h2>Business composition</h2><button onClick={onOpenOperations} type="button">View operations →</button></div>
            {data.applications.length ? <div className="vc-app-list">{data.applications.slice(0,4).map((a,i)=><div className="vc-app-row" key={a.id}>
              <div className={`vc-app-icon tone-${a.tone||"blue"}`}><span className="material-symbols-rounded">{a.icon}</span></div>
              <div className="vc-app-info"><div><b>{a.name}</b><span>{a.sharePercent.toFixed(1)}%</span></div><div className="vc-progress"><motion.i initial={reduced?false:{width:0}} animate={{width:`${Math.max(0,Math.min(100,a.sharePercent))}%`}} transition={{duration:1,delay:.5+i*.1}}/></div></div>
            </div>)}</div> : <p className="vc-panel-empty">No categorized financial breakdown is available.</p>}
          </motion.article>
          <motion.article className="vc-panel vc-health-panel" initial={reduced?false:{opacity:0,y:18}} animate={{opacity:1,y:0}} transition={{delay:.5}}>
            <div className="vc-panel-heading"><h2>Risk watch</h2><span>REVIEW</span></div>
            <HealthRing value={data.systemHealth}/>
            <div className="vc-alert-list">{data.alerts.slice(0,2).map(a=><button key={a.id} type="button" onClick={()=>onOpenAlert?.(a)} className={`vc-alert severity-${a.severity}`}><i/><span><b>{a.title}</b><span>{a.detail}</span></span></button>)}</div>
            {!data.alerts.length && <p className="vc-panel-empty">No active risk alerts were returned.</p>}
          </motion.article>
        </section>
      </>}
      <footer className="vc-footer"><span>VANTIQ · ENTERPRISE INTELLIGENCE</span><span>{data?.lastUpdated ? `Updated ${data.lastUpdated}` : "Update time unavailable"}</span></footer>
      <p className="vc-disclaimer">Network artwork is illustrative. Activity and risk indicators reflect the available organisation reports.</p>
    </div>
  </section>;
}
