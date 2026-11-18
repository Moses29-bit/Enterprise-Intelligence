import { useEffect, useMemo, useRef, useState } from "react";
import "./CommandOverviewBento.css";

/**
 * VANTIQ Command Overview — reusable Bento Grid reference implementation.
 *
 * This component intentionally contains no fabricated business data.
 * Supply values from your authenticated VANTIQ API through the `data` prop.
 * Missing or unavailable values render as "—" or an empty-state message.
 */

export type TrendPoint = { label: string; value: number };
export type BreakdownItem = { label: string; value: number; color?: string };
export type HistogramBin = { label: string; count: number };
export type PerformanceItem = { label: string; actual: number; target?: number };
export type BusinessAlert = {
  id: string;
  title: string;
  description: string;
  severity: "critical" | "warning" | "info" | "success";
  timestamp?: string;
  href?: string;
};

export type KpiMetric = {
  id: string;
  label: string;
  value: number | null;
  format?: "currency" | "number" | "percent";
  currency?: string;
  changePercent?: number | null;
  comparisonLabel?: string;
  series?: number[];
  direction?: "up" | "down" | "flat";
  /** Optional business interpretation, e.g. "Above target". */
  statusText?: string;
};

export type CommandOverviewData = {
  currency?: string;
  reportingPeriod?: string;
  lastUpdated?: string;
  kpis: KpiMetric[];
  activitySeries: TrendPoint[];
  activityTotal: number | null;
  revenueExpenseTrend: {
    revenue: TrendPoint[];
    expenses: TrendPoint[];
  };
  revenueComposition: BreakdownItem[];
  expenseComposition: BreakdownItem[];
  salesDistribution: HistogramBin[];
  branchPerformance: PerformanceItem[];
  alerts: BusinessAlert[];
  /** Optional concise, evidence-based insight from the backend. */
  executiveInsight?: {
    title: string;
    description: string;
    supportingMetric?: string;
  } | null;
};

export type CommandOverviewBentoProps = {
  data: CommandOverviewData | null;
  loading?: boolean;
  error?: string | null;
  onRefresh?: () => void | Promise<void>;
  onOpenFinance?: () => void;
  onOpenOperations?: () => void;
  onOpenRisk?: () => void;
  onOpenAlert?: (alert: BusinessAlert) => void;
  onPeriodChange?: (period: string) => void;
  selectedPeriod?: string;
  className?: string;
};

const PALETTE = ["#103B30", "#C6A66B", "#69766F", "#8EA99B", "#B7C6BE", "#344B43"];

function formatValue(value: number | null, format: KpiMetric["format"], currency = "GHS") {
  if (value === null || !Number.isFinite(value)) return "—";
  if (format === "currency") {
    return new Intl.NumberFormat(undefined, {
      style: "currency",
      currency,
      maximumFractionDigits: 0,
    }).format(value);
  }
  if (format === "percent") return `${value.toFixed(1)}%`;
  return new Intl.NumberFormat(undefined, { maximumFractionDigits: 1 }).format(value);
}

function formatCompact(value: number) {
  return new Intl.NumberFormat(undefined, { notation: "compact", maximumFractionDigits: 1 }).format(value);
}

function MaterialIcon({ name, className = "" }: { name: string; className?: string }) {
  return <span className={`material-symbols-rounded ${className}`} aria-hidden="true">{name}</span>;
}

function Sparkline({ values, positive }: { values?: number[]; positive?: boolean }) {
  if (!values || values.length < 2) return <div className="vb-spark-empty" aria-hidden="true" />;
  const min = Math.min(...values);
  const max = Math.max(...values);
  const range = max - min || 1;
  const points = values.map((v, i) => {
    const x = (i / (values.length - 1)) * 100;
    const y = 28 - ((v - min) / range) * 23;
    return `${x},${y}`;
  }).join(" ");
  return (
    <svg className={`vb-spark ${positive ? "is-positive" : ""}`} viewBox="0 0 100 32" preserveAspectRatio="none" role="img" aria-label="Recent trend">
      <polyline points={points} fill="none" stroke="currentColor" strokeWidth="2.5" strokeLinecap="round" strokeLinejoin="round" />
    </svg>
  );
}

function KpiCard({ metric, currency, index }: { metric: KpiMetric; currency: string; index: number }) {
  const [display, setDisplay] = useState(0);
  const displayRef = useRef(0);
  const previousValueRef = useRef<number | null>(null);
  const [updated, setUpdated] = useState(false);
  const hasValue = metric.value !== null && Number.isFinite(metric.value);

  useEffect(() => {
    if (!hasValue) return;
    const end = metric.value as number;
    const previousValue = previousValueRef.current;
    previousValueRef.current = end;
    const reducedMotion = window.matchMedia("(prefers-reduced-motion: reduce)").matches;
    if (reducedMotion) {
      displayRef.current = end;
      setDisplay(end);
      return;
    }

    const start = displayRef.current;
    const duration = 650;
    let raf = 0;
    let highlightTimeout = 0;
    const begin = performance.now();
    if (previousValue !== null && previousValue !== end) {
      setUpdated(true);
      highlightTimeout = window.setTimeout(() => setUpdated(false), 900);
    }
    const animate = (now: number) => {
      const progress = Math.min((now - begin) / duration, 1);
      const eased = 1 - Math.pow(1 - progress, 3);
      const next = start + (end - start) * eased;
      displayRef.current = next;
      setDisplay(next);
      if (progress < 1) raf = requestAnimationFrame(animate);
    };
    raf = requestAnimationFrame(animate);
    return () => {
      cancelAnimationFrame(raf);
      window.clearTimeout(highlightTimeout);
    };
    // Intentionally animate only when the incoming metric value changes.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [metric.value]);

  const change = metric.changePercent;
  const changeClass = change === null || change === undefined || change === 0
    ? "neutral"
    : (metric.direction === "down" ? "negative" : metric.direction === "up" ? "positive" : change > 0 ? "positive" : "negative");

  return (
    <article className={`vb-card vb-kpi vb-kpi-${index + 1}`}>
      <div className="vb-kpi-top">
        <span className="vb-kpi-label">{metric.label}</span>
        <span className="vb-kpi-icon"><MaterialIcon name={index === 0 ? "payments" : index === 1 ? "account_balance_wallet" : index === 2 ? "trending_up" : "inventory_2"} /></span>
      </div>
      <div className={`vb-kpi-value${updated ? " is-updated" : ""}`}>
        {hasValue ? formatValue(display, metric.format, metric.currency || currency) : "—"}
      </div>
      <div className="vb-kpi-bottom">
        <span className={`vb-change ${changeClass}`}>
          <MaterialIcon name={changeClass === "positive" ? "trending_up" : changeClass === "negative" ? "trending_down" : "remove"} />
          {change === null || change === undefined ? "No comparison" : `${Math.abs(change).toFixed(1)}%`}
        </span>
        <span className="vb-kpi-comparison">{metric.comparisonLabel || "vs previous period"}</span>
      </div>
      <Sparkline values={metric.series} positive={changeClass !== "negative"} />
      {metric.statusText && <span className="vb-kpi-status">{metric.statusText}</span>}
    </article>
  );
}

function EmptyChart({ title }: { title: string }) {
  return <div className="vb-empty-chart"><MaterialIcon name="query_stats" /><span>No data available for {title.toLowerCase()}.</span></div>;
}

function TrendChart({ revenue, expenses, currency }: { revenue: TrendPoint[]; expenses: TrendPoint[]; currency: string }) {
  const labels = revenue.map(p => p.label);
  const values = [...revenue.map(p => p.value), ...expenses.map(p => p.value)];
  if (!revenue.length || !expenses.length || !values.length) return <EmptyChart title="revenue and expenses" />;
  const max = Math.max(...values, 1) * 1.12;
  const x = (i: number) => 44 + (i / Math.max(labels.length - 1, 1)) * 590;
  const y = (v: number) => 176 - (v / max) * 145;
  const path = (series: TrendPoint[]) => series.map((p, i) => `${i === 0 ? "M" : "L"} ${x(i)} ${y(p.value)}`).join(" ");
  const gridValues = [0, 0.25, 0.5, 0.75, 1];
  return (
    <div className="vb-chart-wrap">
      <div className="vb-legend">
        <span><i className="vb-legend-dot revenue" />Revenue</span>
        <span><i className="vb-legend-dot expenses" />Expenses</span>
      </div>
      <svg className="vb-trend-svg" viewBox="0 0 660 218" role="img" aria-label="Revenue and expenses over time">
        {gridValues.map((g, i) => <g key={i}><line x1="44" x2="634" y1={176 - g * 145} y2={176 - g * 145} className="vb-grid-line" /><text x="36" y={180 - g * 145} textAnchor="end" className="vb-axis-label">{formatCompact(max * g)}</text></g>)}
        <path d={path(revenue)} className="vb-line vb-line-revenue" pathLength="1" />
        <path d={path(expenses)} className="vb-line vb-line-expenses" pathLength="1" />
        {revenue.map((p, i) => <circle key={`r${i}`} cx={x(i)} cy={y(p.value)} r="3.5" className="vb-point-revenue"><title>{`${p.label}: ${formatValue(p.value, "currency", currency)} revenue`}</title></circle>)}
        {expenses.map((p, i) => <circle key={`e${i}`} cx={x(i)} cy={y(p.value)} r="3.5" className="vb-point-expenses"><title>{`${p.label}: ${formatValue(p.value, "currency", currency)} expenses`}</title></circle>)}
        {labels.map((label, i) => <text key={label} x={x(i)} y="204" textAnchor="middle" className="vb-axis-label">{label}</text>)}
      </svg>
    </div>
  );
}

function DonutChart({ items, currency, title }: { items: BreakdownItem[]; currency: string; title: string }) {
  const total = items.reduce((sum, item) => sum + Math.max(0, item.value), 0);
  if (!items.length || total <= 0) return <EmptyChart title={title} />;
  let offset = 0;
  const segments = items.map((item, i) => {
    const value = Math.max(0, item.value);
    const length = (value / total) * 100;
    const segment = { ...item, color: item.color || PALETTE[i % PALETTE.length], length, offset };
    offset += length;
    return segment;
  });
  return (
    <div className="vb-donut-layout">
      <div className="vb-donut-graphic">
        <svg viewBox="0 0 140 140" role="img" aria-label={`${title} breakdown`}>
          <circle cx="70" cy="70" r="52" className="vb-donut-track" />
          {segments.map((s, i) => <circle key={`${s.label}-${s.value}`} cx="70" cy="70" r="52" fill="none" stroke={s.color} strokeWidth="16" strokeDasharray={`${s.length} ${100 - s.length}`} strokeDashoffset={-s.offset} pathLength="100" transform="rotate(-90 70 70)" className="vb-donut-segment" style={{ animationDelay: `${i * 100}ms` }}><title>{`${s.label}: ${formatValue(s.value, "currency", currency)}`}</title></circle>)}
          <text x="70" y="66" textAnchor="middle" className="vb-donut-total">{formatCompact(total)}</text>
          <text x="70" y="82" textAnchor="middle" className="vb-donut-caption">TOTAL</text>
        </svg>
      </div>
      <div className="vb-donut-legend">
        {segments.slice(0, 5).map(s => <div className="vb-donut-row" key={s.label}><span className="vb-donut-name"><i style={{ background: s.color }} />{s.label}</span><strong>{((s.value / total) * 100).toFixed(1)}%</strong></div>)}
      </div>
    </div>
  );
}

function Histogram({ bins }: { bins: HistogramBin[] }) {
  if (!bins.length) return <EmptyChart title="sales distribution" />;
  const max = Math.max(...bins.map(b => b.count), 1);
  return (
    <div className="vb-histogram" role="img" aria-label="Sales frequency distribution histogram">
      {bins.map((bin, i) => <div className="vb-hist-column" key={`${bin.label}-${bin.count}-${i}`} title={`${bin.label}: ${bin.count}`}>
        <div className="vb-hist-count">{bin.count}</div>
        <div className="vb-hist-track"><div className="vb-hist-bar" style={{ height: `${(bin.count / max) * 100}%`, animationDelay: `${i * 65}ms` }} /></div>
        <span>{bin.label}</span>
      </div>)}
    </div>
  );
}

function BranchPerformance({ items }: { items: PerformanceItem[] }) {
  if (!items.length) return <EmptyChart title="branch performance" />;
  const max = Math.max(...items.flatMap(i => [i.actual, i.target ?? 0]), 1);
  return (
    <div className="vb-branch-list">
      {items.slice(0, 5).map(item => <div className="vb-branch-row" key={`${item.label}-${item.actual}-${item.target ?? ""}`}>
        <div className="vb-branch-label"><span>{item.label}</span><strong>{item.actual.toFixed(0)}{item.target ? ` / ${item.target.toFixed(0)}` : ""}</strong></div>
        <div className="vb-branch-track"><div className="vb-branch-fill" style={{ width: `${Math.min(100, (item.actual / max) * 100)}%` }} /></div>
      </div>)}
    </div>
  );
}

function AlertRow({ alert, onClick }: { alert: BusinessAlert; onClick?: (alert: BusinessAlert) => void }) {
  return (
    <button className="vb-alert-row" type="button" onClick={() => onClick?.(alert)}>
      <span className={`vb-alert-symbol ${alert.severity}`}><MaterialIcon name={alert.severity === "critical" ? "error" : alert.severity === "warning" ? "warning" : alert.severity === "success" ? "check_circle" : "info"} /></span>
      <span className="vb-alert-copy"><strong>{alert.title}</strong><small>{alert.description}</small></span>
      {alert.timestamp && <time>{alert.timestamp}</time>}
      <MaterialIcon name="chevron_right" className="vb-alert-chevron" />
    </button>
  );
}

function SkeletonCard({ className = "" }: { className?: string }) {
  return <div className={`vb-card vb-skeleton ${className}`}><div /><div /><div /></div>;
}

export default function CommandOverviewBento({
  data, loading = false, error = null, onRefresh, onOpenFinance, onOpenOperations,
  onOpenRisk, onOpenAlert, onPeriodChange, selectedPeriod = "This month", className = "",
}: CommandOverviewBentoProps) {
  const [period, setPeriod] = useState(selectedPeriod);
  const [refreshing, setRefreshing] = useState(false);
  useEffect(() => setPeriod(selectedPeriod), [selectedPeriod]);
  const currency = data?.currency || "GHS";
  const kpis = useMemo(() => data?.kpis || [], [data?.kpis]);
  const periodOptions = ["This week", "This month", "This quarter", "This year"];

  const changePeriod = (value: string) => {
    setPeriod(value);
    onPeriodChange?.(value);
  };
  const refresh = async () => {
    if (!onRefresh || refreshing) return;
    setRefreshing(true);
    try {
      await onRefresh();
    } finally {
      setRefreshing(false);
    }
  };

  if (loading && !data) {
    return <main className={`vantiq-bento ${className}`}><div className="vb-header"><div><span className="vb-eyebrow">BUSINESS INTELLIGENCE</span><h1>Command Overview</h1><p>Your organisation at a glance.</p></div></div><div className="vb-grid">{Array.from({ length: 8 }).map((_, i) => <SkeletonCard key={i} />)}</div></main>;
  }

  if (error && !data) {
    return <main className={`vantiq-bento ${className}`}><div className="vb-error"><MaterialIcon name="cloud_off" /><strong>We couldn't load your business overview.</strong><span>{error}</span><button onClick={onRefresh} type="button">Try again</button></div></main>;
  }

  if (!data) {
    return <main className={`vantiq-bento ${className}`}><div className="vb-empty-state"><MaterialIcon name="insights" /><h2>Your command centre is ready</h2><p>Connect the dashboard to your organisation's metrics to see its performance here.</p></div></main>;
  }

  return (
    <main className={`vantiq-bento ${className}`}>
      <header className="vb-header">
        <div>
          <span className="vb-eyebrow">VANTIQ / BUSINESS INTELLIGENCE</span>
          <h1>Command Overview</h1>
          <p>A clear view of performance, operations and emerging priorities.</p>
        </div>
        <div className="vb-header-actions">
          <label className="vb-period-select"><MaterialIcon name="calendar_month" /><select value={period} onChange={e => changePeriod(e.target.value)} aria-label="Reporting period">{periodOptions.map(p => <option key={p}>{p}</option>)}</select><MaterialIcon name="expand_more" /></label>
          <button type="button" className={`vb-icon-button${refreshing ? " is-refreshing" : ""}`} onClick={() => void refresh()} aria-label="Refresh dashboard" disabled={refreshing}>
            <MaterialIcon name="refresh" />
          </button>
        </div>
      </header>

      {error && <div className="vb-inline-warning"><MaterialIcon name="warning" />Some dashboard information may be out of date. {error}</div>}

      <section className="vb-grid" aria-label="Command overview dashboard">
        <div className="vb-kpi-grid">
          {kpis.slice(0, 4).map((metric, i) => <KpiCard key={metric.id} metric={metric} currency={currency} index={i} />)}
          {!kpis.length && <div className="vb-card vb-empty-kpi">KPI data has not been connected yet.</div>}
        </div>

        <article className="vb-card vb-card-trend">
          <div className="vb-card-heading"><div><span className="vb-card-kicker">FINANCIAL PERFORMANCE</span><h2>Revenue vs expenses</h2><p>Compare income and spending over the selected period.</p></div><button className="vb-text-action" onClick={onOpenFinance} type="button">View finance <MaterialIcon name="arrow_outward" /></button></div>
          <TrendChart
            key={`${data.revenueExpenseTrend.revenue.map(point => `${point.label}:${point.value}`).join("|")}_${data.revenueExpenseTrend.expenses.map(point => `${point.label}:${point.value}`).join("|")}`}
            revenue={data.revenueExpenseTrend.revenue}
            expenses={data.revenueExpenseTrend.expenses}
            currency={currency}
          />
        </article>

        <article className="vb-card vb-card-revenue">
          <div className="vb-card-heading"><div><span className="vb-card-kicker">REVENUE MIX</span><h2>Where revenue comes from</h2></div></div>
          <DonutChart key={data.revenueComposition.map(item => `${item.label}:${item.value}`).join("|")} items={data.revenueComposition} currency={currency} title="revenue composition" />
        </article>

        <article className="vb-card vb-card-expenses">
          <div className="vb-card-heading"><div><span className="vb-card-kicker">COST STRUCTURE</span><h2>Expense composition</h2></div></div>
          <DonutChart key={data.expenseComposition.map(item => `${item.label}:${item.value}`).join("|")} items={data.expenseComposition} currency={currency} title="expense composition" />
        </article>

        <article className="vb-card vb-card-histogram">
          <div className="vb-card-heading"><div><span className="vb-card-kicker">STATISTICAL DISTRIBUTION</span><h2>Sales distribution</h2><p>Frequency of sales across value intervals.</p></div><span className="vb-card-tag">HISTOGRAM</span></div>
          <Histogram key={data.salesDistribution.map(bin => `${bin.label}:${bin.count}`).join("|")} bins={data.salesDistribution} />
        </article>

        <article className="vb-card vb-card-branches">
          <div className="vb-card-heading"><div><span className="vb-card-kicker">OPERATING PERFORMANCE</span><h2>Branch performance</h2><p>Actual performance against target, where available.</p></div><button className="vb-text-action" onClick={onOpenOperations} type="button">Operations <MaterialIcon name="arrow_outward" /></button></div>
          <BranchPerformance key={data.branchPerformance.map(item => `${item.label}:${item.actual}:${item.target ?? ""}`).join("|")} items={data.branchPerformance} />
        </article>

        <article className="vb-card vb-card-insights">
          <div className="vb-card-heading"><div><span className="vb-card-kicker">INTELLIGENCE BRIEF</span><h2>What needs your attention</h2></div><span className="vb-insight-icon"><MaterialIcon name="auto_awesome" /></span></div>
          {data.executiveInsight ? <div className="vb-insight-content"><strong>{data.executiveInsight.title}</strong><p>{data.executiveInsight.description}</p>{data.executiveInsight.supportingMetric && <span>{data.executiveInsight.supportingMetric}</span>}</div> : <div className="vb-insight-empty">No verified executive insight is available for this period.</div>}
          <button className="vb-text-action" onClick={onOpenRisk} type="button">Explore intelligence <MaterialIcon name="arrow_outward" /></button>
        </article>

        <article className="vb-card vb-card-alerts">
          <div className="vb-card-heading"><div><span className="vb-card-kicker">LIVE BUSINESS SIGNALS</span><h2>Alerts & priorities</h2></div><span className="vb-alert-count">{data.alerts.length} items</span></div>
          {data.alerts.length ? <div className="vb-alert-list">{data.alerts.slice(0, 4).map(alert => <AlertRow key={alert.id} alert={alert} onClick={onOpenAlert} />)}</div> : <div className="vb-insight-empty">No active alerts. Your monitored areas have no reported priorities.</div>}
        </article>
      </section>

      <footer className="vb-footer">
        <span><i className="vb-live-dot" />Connected to organisational data</span>
        <span>{data.lastUpdated ? `Last updated ${data.lastUpdated}` : "Update time unavailable"}</span>
        <span>{data.reportingPeriod || period}</span>
      </footer>
    </main>
  );
}
