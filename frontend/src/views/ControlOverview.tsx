import {
  Area,
  AreaChart,
  Bar,
  BarChart,
  CartesianGrid,
  Cell,
  Pie,
  PieChart,
  ResponsiveContainer,
  Tooltip,
  XAxis,
  YAxis,
} from 'recharts';
import { Link } from 'react-router-dom';
import type { AdminAuditLog, ProductRecord } from '../services/api';
import { Icon, type IconName } from '../components/Icon';
import { StatisticalInsights } from '../components/StatisticalInsights';
import {
  AnimatedMetric,
  CHART_EASING,
  MOTION_TIMING,
  usePrefersReducedMotion,
} from '../components/AnimatedMetric';

type DataRow = Record<string, unknown>;
type HealthTone = 'good' | 'watch' | 'attention' | 'neutral';

interface ControlOverviewProps {
  organisation: DataRow;
  stats: DataRow;
  finance: DataRow;
  operations: DataRow;
  products: ProductRecord[];
  risk: DataRow;
  trend: DataRow;
  documents: DataRow[];
  activity: AdminAuditLog[];
  permissions: string[];
  lastUpdatedAt: number | null;
  syncStatus: 'syncing' | 'current' | 'interrupted' | 'paused';
  onRefresh: () => void;
}

const chartColors = [
  'var(--chart-category-1)',
  'var(--chart-category-2)',
  'var(--chart-category-3)',
  'var(--chart-category-4)',
  'var(--chart-category-5)',
  'var(--chart-category-6)',
  'var(--chart-category-7)',
  'var(--chart-category-8)',
];

const businessEvents: Record<string, { label: string; route: string; reference?: string }> = {
  'sale.posted': { label: 'Sale recorded', route: '/finance', reference: 'sale_id' },
  'sale.refunded': { label: 'Sale refunded', route: '/finance', reference: 'sale_id' },
  'invoice.issued': { label: 'Invoice issued', route: '/finance', reference: 'invoice_id' },
  'payment.recorded': { label: 'Payment received', route: '/finance', reference: 'payment_id' },
  'invoice.credit_issued': { label: 'Credit note issued', route: '/finance', reference: 'credit_note_id' },
  'expense.created': { label: 'Expense recorded', route: '/finance', reference: 'expense_id' },
  'product.created': { label: 'Product added', route: '/operations', reference: 'product_id' },
  'inventory.movement_recorded': { label: 'Stock movement recorded', route: '/operations', reference: 'movement_id' },
  'purchase_order.created': { label: 'Purchase order created', route: '/operations', reference: 'purchase_order_id' },
  'purchase_order.submitted': { label: 'Purchase order submitted', route: '/operations', reference: 'purchase_order_id' },
  'purchase_order.received': { label: 'Goods received', route: '/operations', reference: 'purchase_order_id' },
  'customer.created': { label: 'Customer added', route: '/finance', reference: 'customer_id' },
  'supplier.created': { label: 'Supplier added', route: '/operations', reference: 'supplier_id' },
  'document.uploaded': { label: 'Document uploaded', route: '/data/documents', reference: 'document_id' },
  'document.reviewed': { label: 'Document reviewed', route: '/data/documents', reference: 'document_id' },
  'branch.created': { label: 'Branch added', route: '/control/structure' },
  'department.created': { label: 'Department added', route: '/control/structure' },
};

function row(value: unknown): DataRow {
  return typeof value === 'object' && value !== null ? value as DataRow : {};
}

function list<T>(value: unknown): T[] {
  return Array.isArray(value) ? value as T[] : [];
}

function numeric(value: unknown): number | null {
  if (value === null || value === undefined || value === '') return null;
  const parsed = Number(value);
  return Number.isFinite(parsed) ? parsed : null;
}

function currency(value: unknown): string {
  const amount = numeric(value);
  return amount === null ? 'Unavailable' : new Intl.NumberFormat('en-GH', {
    style: 'currency',
    currency: 'GHS',
    maximumFractionDigits: 0,
  }).format(amount);
}

function compactCurrency(value: unknown): string {
  const amount = numeric(value);
  return amount === null ? '' : new Intl.NumberFormat('en-GH', {
    notation: 'compact',
    maximumFractionDigits: 1,
  }).format(amount);
}

function recordTrend(trend: DataRow) {
  const rows = new Map<string, { period: string; revenue: number; expenses: number }>();
  for (const item of list<DataRow>(trend.revenue_by_day)) {
    const period = String(item.period ?? '');
    if (period) rows.set(period, { period, revenue: numeric(item.revenue) ?? 0, expenses: 0 });
  }
  for (const item of list<DataRow>(trend.expenses_by_day)) {
    const period = String(item.period ?? '');
    if (!period) continue;
    const current = rows.get(period) ?? { period, revenue: 0, expenses: 0 };
    current.expenses = numeric(item.expenses) ?? 0;
    rows.set(period, current);
  }
  const cutoff = new Date();
  cutoff.setDate(cutoff.getDate() - 29);
  const earliest = cutoff.toISOString().slice(0, 10);
  return [...rows.values()]
    .filter((item) => item.period >= earliest)
    .sort((left, right) => left.period.localeCompare(right.period));
}

function revenueMovement(trend: DataRow): { current: number; previous: number; changePercent: number | null } | null {
  const revenue = list<DataRow>(trend.revenue_by_day);
  if (revenue.length === 0) return null;
  const now = new Date();
  const currentStart = new Date(now);
  currentStart.setDate(currentStart.getDate() - 29);
  const previousStart = new Date(now);
  previousStart.setDate(previousStart.getDate() - 59);
  const previousEnd = new Date(now);
  previousEnd.setDate(previousEnd.getDate() - 30);
  const date = (value: Date) => value.toISOString().slice(0, 10);
  const start = date(currentStart);
  const priorStart = date(previousStart);
  const priorEnd = date(previousEnd);
  const current = revenue.reduce((sum, item) => {
    const period = String(item.period ?? '');
    return period >= start ? sum + (numeric(item.revenue) ?? 0) : sum;
  }, 0);
  const previous = revenue.reduce((sum, item) => {
    const period = String(item.period ?? '');
    return period >= priorStart && period <= priorEnd ? sum + (numeric(item.revenue) ?? 0) : sum;
  }, 0);
  return { current, previous, changePercent: previous > 0 ? ((current - previous) / previous) * 100 : null };
}

function periodMovement(
  entries: DataRow[],
  valueKey: string,
): { current: number; previous: number } | null {
  if (entries.length === 0) return null;
  const now = new Date();
  const currentStart = new Date(now);
  currentStart.setDate(currentStart.getDate() - 29);
  const previousStart = new Date(now);
  previousStart.setDate(previousStart.getDate() - 59);
  const previousEnd = new Date(now);
  previousEnd.setDate(previousEnd.getDate() - 30);
  const date = (value: Date) => value.toISOString().slice(0, 10);
  const start = date(currentStart);
  const priorStart = date(previousStart);
  const priorEnd = date(previousEnd);
  return entries.reduce<{ current: number; previous: number }>((totals, item) => {
    const period = String(item.period ?? '');
    const amount = numeric(item[valueKey]) ?? 0;
    if (period >= start) totals.current += amount;
    else if (period >= priorStart && period <= priorEnd) totals.previous += amount;
    return totals;
  }, { current: 0, previous: 0 });
}

function signalTone(tone: HealthTone) {
  return `control-health-signal ${tone}`;
}

function HealthSignal({ icon, title, tone, status, detail }: {
  icon: IconName;
  title: string;
  tone: HealthTone;
  status: string;
  detail: string;
}) {
  return <article className={signalTone(tone)}>
    <span className="control-health-ring" aria-hidden="true"><span /></span>
    <div><h3 className="control-health-title"><Icon name={icon} />{title}</h3><strong>{status}</strong><p>{detail}</p></div>
  </article>;
}

function ChartEmpty({ children }: { children: string }) {
  return <div className="control-chart-empty" role="status">{children}</div>;
}

function ActivityTimeline({ entries }: { entries: AdminAuditLog[] }) {
  const visible = entries.flatMap((entry) => {
    const event = businessEvents[entry.action_type];
    if (!event) return [];
    const reference = event.reference
      ? numeric(entry.metadata[event.reference])
      : null;
    return [{ entry, event, reference }];
  }).slice(0, 8);

  return <article className="control-visual-card control-wide-card control-activity-card">
    <div className="control-visual-heading">
      <div><span className="section-eyebrow">RECENT CHANGE</span><h3>Business activity</h3></div>
      <Link to="/control/audit-logs">Audit history</Link>
    </div>
    {visible.length === 0
      ? <ChartEmpty>No recent business activity is available in the organisation audit trail.</ChartEmpty>
      : <ol className="control-business-timeline">{visible.map(({ entry, event, reference }) => (
        <li key={entry.id}>
          <span className="control-timeline-node" aria-hidden="true" />
          <div className="control-timeline-copy">
            <strong>{event.label}</strong>
            <span>{entry.actor ?? 'System'}{reference === null ? '' : ` · Reference ${reference}`}</span>
          </div>
          <time dateTime={entry.created_at}>{new Date(entry.created_at).toLocaleString()}</time>
          <Link to={event.route} aria-label={`Open ${event.label} records`}>Open</Link>
        </li>
      ))}</ol>}
    <p className="control-visual-footnote">Only recognised business actions are shown; authentication and technical audit events are excluded.</p>
  </article>;
}

export function ControlOverview({
  organisation,
  stats,
  finance,
  operations,
  products,
  risk,
  trend,
  documents,
  activity,
  permissions,
  lastUpdatedAt,
  syncStatus,
  onRefresh,
}: ControlOverviewProps) {
  const prefersReducedMotion = usePrefersReducedMotion();
  const animateCharts = !prefersReducedMotion;
  const granted = new Set(permissions);
  const financeAvailable = granted.has('finance.read');
  const operationsAvailable = granted.has('operations.read');
  const riskAvailable = granted.has('risks.read');
  const reportsAvailable = granted.has('reports.read');
  const documentsAvailable = granted.has('documents.read') || granted.has('documents.upload');
  const financeSummary = row(finance.summary);
  const operationsHealth = row(operations.stockHealth);
  const riskAlerts = list<DataRow>(risk.alerts);
  const recentTrend = recordTrend(trend);
  const revenueChange = revenueMovement(trend);
  const expenseChange = periodMovement(list<DataRow>(trend.expenses_by_day), 'expenses');
  const lowStockCount = numeric(operationsHealth.lowStockCount);
  const inventoryValue = numeric(operations.inventoryValue);
  const operatingProfit = numeric(financeSummary.operatingProfit);
  const operatingExpenses = numeric(financeSummary.operatingExpenses);
  const otherExpenses = numeric(financeSummary.otherExpenses);
  const unclassifiedExpenses = numeric(financeSummary.unclassifiedExpenses);
  const expenseMix = [
    { name: 'Operating', value: operatingExpenses },
    { name: 'Other', value: otherExpenses },
    { name: 'Unclassified', value: unclassifiedExpenses },
  ].filter((item) => item.value !== null && item.value > 0) as Array<{ name: string; value: number }>;
  const branches = list<DataRow>(finance.branchBreakdown)
    .map((item) => ({ name: String(item.label ?? 'Unassigned'), value: numeric(item.value) ?? 0 }))
    .filter((item) => item.value > 0)
    .slice(0, 6);
  const departments = list<DataRow>(finance.departmentBreakdown)
    .map((item) => ({ name: String(item.label ?? 'Unassigned'), value: numeric(item.value) ?? 0 }))
    .filter((item) => item.value > 0)
    .slice(0, 6);
  const purchaseOrders = list<DataRow>(operations.purchaseOrders);
  const orderStatus = ['draft', 'submitted', 'received', 'closed'].map((status) => ({
    status,
    count: purchaseOrders.filter((item) => String(item.status) === status).length,
  }));
  const lowStock = list<DataRow>(operations.lowStock);
  const stockMovements = list<DataRow>(operations.recentMovements);
  const pendingDocuments = documents.filter((document) => document.reviewed_at === null).length;
  const extractionFailures = documents.filter((document) => document.extraction_status === 'failed').length;
  const highRiskAlerts = riskAlerts.filter((alert) =>
    ['critical', 'high'].includes(String(alert.severity).toLowerCase())
      && !['resolved', 'dismissed'].includes(String(alert.status).toLowerCase()));
  const recentPeriodStart = new Date();
  recentPeriodStart.setDate(recentPeriodStart.getDate() - 29);
  const recentPeriodDate = recentPeriodStart.toISOString().slice(0, 10);
  const periodRevenueCount = list<DataRow>(trend.revenue_by_day)
    .filter((item) => String(item.period ?? '') >= recentPeriodDate)
    .reduce((total, item) => total + (numeric(item.sale_count) ?? 0), 0);

  const narrative: string[] = [];
  if (financeAvailable && operatingProfit !== null) {
    narrative.push(operatingProfit < 0
      ? `The recorded finance summary shows an operating loss of ${currency(Math.abs(operatingProfit))}.`
      : operatingProfit > 0
        ? `The recorded finance summary shows positive operating profit of ${currency(operatingProfit)}.`
        : 'The recorded finance summary is at operating break-even.');
  }
  if (reportsAvailable && revenueChange !== null && revenueChange.current !== revenueChange.previous) {
    narrative.push(revenueChange.current > revenueChange.previous
      ? 'Recorded revenue was higher in the latest 30 days than in the previous 30 days.'
      : 'Recorded revenue was lower in the latest 30 days than in the previous 30 days.');
  }
  if (reportsAvailable && expenseChange !== null && expenseChange.current > expenseChange.previous) {
    narrative.push('Recorded expenses were higher in the latest 30 days than in the previous 30 days.');
  }
  if (operationsAvailable && lowStockCount !== null && lowStockCount > 0) {
    narrative.push(`${lowStockCount} products are at or below their reorder level.`);
  }
  if (riskAvailable && highRiskAlerts.length > 0) {
    narrative.push(`${highRiskAlerts.length} of the latest available risk alerts are high or critical and remain open.`);
  }
  if (documentsAvailable && (pendingDocuments > 0 || extractionFailures > 0)) {
    narrative.push(`${pendingDocuments} of the latest ${documents.length} documents await review; ${extractionFailures} have extraction failures.`);
  }

  const financeStatus: { tone: HealthTone; status: string; detail: string } = !financeAvailable
    ? { tone: 'neutral', status: 'Restricted', detail: 'Finance permission is not assigned.' }
    : operatingProfit === null
      ? { tone: 'neutral', status: 'Unavailable', detail: 'No finance summary was returned.' }
      : recentTrend.length === 0
        ? { tone: 'neutral', status: 'No recent activity', detail: 'No revenue or expense entries were returned for the 30-day reporting window.' }
        : operatingProfit < 0
          ? { tone: 'attention', status: 'Operating loss recorded', detail: `Current summary: ${currency(Math.abs(operatingProfit))} operating loss.` }
          : operatingProfit > 0
            ? { tone: 'good', status: 'Positive operating profit', detail: 'Based on the existing finance summary; not a composite score.' }
            : { tone: 'watch', status: 'At break-even', detail: 'Recorded operating profit is zero.' };
  const salesStatus: { tone: HealthTone; status: string; detail: string } = !reportsAvailable
    ? { tone: 'neutral', status: 'Restricted', detail: 'Reports permission is not assigned.' }
    : revenueChange === null
      ? { tone: 'neutral', status: 'Insufficient history', detail: 'Two 30-day revenue windows are needed for a supported comparison.' }
      : revenueChange.current > revenueChange.previous
        ? { tone: 'good', status: revenueChange.changePercent === null ? 'Revenue increased' : `Revenue up ${revenueChange.changePercent.toFixed(1)}%`,
          detail: `${periodRevenueCount} sales in the latest 30 days; compared with the previous 30 days.` }
        : revenueChange.current < revenueChange.previous
          ? { tone: 'watch', status: revenueChange.changePercent === null ? 'Revenue decreased' : `Revenue down ${Math.abs(revenueChange.changePercent).toFixed(1)}%`,
            detail: `${periodRevenueCount} sales in the latest 30 days; compared with the previous 30 days.` }
          : { tone: 'neutral', status: 'Revenue unchanged', detail: 'The two 30-day revenue totals are equal.' };
  const inventoryStatus: { tone: HealthTone; status: string; detail: string } = !operationsAvailable
    ? { tone: 'neutral', status: 'Restricted', detail: 'Operations permission is not assigned.' }
    : lowStockCount === null
      ? { tone: 'neutral', status: 'Unavailable', detail: 'Inventory health was not returned.' }
      : products.length === 0
        ? { tone: 'neutral', status: 'No product records', detail: 'Inventory status cannot be assessed without products.' }
        : lowStockCount > 0
          ? { tone: 'watch', status: `${lowStockCount} at reorder level`, detail: 'The operations summary counts products at or below their reorder threshold.' }
          : { tone: 'good', status: 'No reorder alerts', detail: `No reorder-level products among ${products.length} catalogue records.` };
  const riskStatus: { tone: HealthTone; status: string; detail: string } = !riskAvailable
    ? { tone: 'neutral', status: 'Restricted', detail: 'Risk permission is not assigned.' }
    : highRiskAlerts.length > 0
      ? { tone: 'attention', status: `${highRiskAlerts.length} high / critical`, detail: 'Open items among the latest risk alerts returned.' }
      : { tone: 'good', status: 'No high-severity alerts', detail: `Based on ${riskAlerts.length} latest alert records.` };
  const dataQualityStatus: { tone: HealthTone; status: string; detail: string } = !documentsAvailable
    ? { tone: 'neutral', status: 'Restricted', detail: 'Document permission is not assigned.' }
    : extractionFailures > 0 || pendingDocuments > 0
      ? { tone: 'watch', status: 'Review required', detail: `${pendingDocuments} awaiting review; ${extractionFailures} extraction failures in the latest ${documents.length} documents.` }
      : { tone: 'good', status: documents.length > 0 ? 'No visible review issues' : 'No documents yet', detail: 'Document status covers the latest 100 library entries.' };
  const operationsStatus: { tone: HealthTone; status: string; detail: string } = !operationsAvailable
    ? { tone: 'neutral', status: 'Restricted', detail: 'Operations permission is not assigned.' }
    : purchaseOrders.length === 0
      ? { tone: 'neutral', status: 'No recent orders', detail: 'No purchase orders were returned in the latest operations summary.' }
      : purchaseOrders.some((order) => ['draft', 'submitted'].includes(String(order.status)))
        ? { tone: 'watch', status: `${purchaseOrders.filter((order) => ['draft', 'submitted'].includes(String(order.status))).length} in progress`,
          detail: `Status distribution is limited to the latest ${purchaseOrders.length} purchase orders.` }
        : { tone: 'good', status: 'Latest orders received', detail: `All ${purchaseOrders.length} orders in the returned set are complete.` };

  return <div className="control-overview">
    <section className="control-overview-banner" aria-label="Organisation status">
      <div>
        <span className="section-eyebrow">ORGANISATION HEALTH</span>
        <h3>{String(organisation.name ?? 'Organisation')}</h3>
        <p>Business indicators are evidence-based signals, not an unexplained overall score.</p>
      </div>
      <div className={`control-refresh-state ${syncStatus}`} role="status">
        <span aria-hidden="true" />
        <div>
          <strong>{syncStatus === 'current' ? 'Auto-refresh active' : syncStatus === 'syncing' ? 'Updating data' : syncStatus === 'paused' ? 'Refresh paused' : 'Connection interrupted'}</strong>
          <small>{lastUpdatedAt === null ? 'No successful update yet' : `Updated ${new Date(lastUpdatedAt).toLocaleTimeString()}; checks every 30 seconds`}</small>
        </div>
        <button type="button" className="ghost-button" onClick={onRefresh} disabled={syncStatus === 'syncing'}>Refresh</button>
      </div>
    </section>

    <section className="control-health-grid" aria-label="Business health indicators">
      <HealthSignal icon="finance" title="Financial" {...financeStatus} />
      <HealthSignal icon="reports" title="Sales performance" {...salesStatus} />
      <HealthSignal icon="operations" title="Inventory" {...inventoryStatus} />
      <HealthSignal icon="control" title="Operations" {...operationsStatus} />
      <HealthSignal icon="risk" title="Risk exposure" {...riskStatus} />
      <HealthSignal icon="connect" title="Data quality" {...dataQualityStatus} />
    </section>

    <section className="control-narrative" aria-labelledby="control-narrative-title">
      <div className="control-narrative-mark" aria-hidden="true"><span /></div>
      <div>
        <span className="section-eyebrow">EXECUTIVE BRIEFING</span>
        <h3 id="control-narrative-title">What needs attention</h3>
        {narrative.length > 0
          ? <ul>{narrative.slice(0, 4).map((item) => <li key={item}>{item}</li>)}</ul>
          : <p>There is not enough permissioned business data to generate an evidence-based summary. Restricted or missing data is not treated as zero.</p>}
      </div>
    </section>

    <div className="control-visual-grid">
      <article className="control-visual-card control-wide-card">
        <div className="control-visual-heading">
          <div><span className="section-eyebrow">FINANCE · LAST 30 DAYS</span><h3>Revenue and expenses</h3>
            <p>Daily recorded values from the financial report API · GHS.</p></div>
          <Link to="/reports">Open reports</Link>
        </div>
        {!reportsAvailable
          ? <ChartEmpty>Financial trend is restricted because Reports permission is not assigned.</ChartEmpty>
          : recentTrend.length === 0
            ? <ChartEmpty>No revenue or expense entries were returned for this reporting window.</ChartEmpty>
            : <figure className="control-chart-figure">
              <figcaption className="visually-hidden">Daily revenue and expense totals for the latest 30-day period.</figcaption>
              <div role="img" aria-label="Area chart comparing daily recorded revenue and expenses">
                <ResponsiveContainer width="100%" height={260}>
                  <AreaChart data={recentTrend} margin={{ top: 8, right: 12, bottom: 0, left: 2 }}>
                    <CartesianGrid stroke="var(--color-border)" strokeDasharray="3 5" vertical={false} />
                    <XAxis dataKey="period" tick={{ fill: 'var(--color-text-muted)', fontSize: 'var(--type-chart-label-size)' }} tickLine={false} axisLine={false}
                      tickFormatter={(value: string) => value.slice(5)} minTickGap={24} />
                    <YAxis tick={{ fill: 'var(--color-text-muted)', fontSize: 'var(--type-chart-label-size)' }} tickLine={false} axisLine={false}
                      tickFormatter={compactCurrency} width={52} />
                    <Tooltip
                      formatter={(value, name) => [currency(value), String(name)]}
                      labelFormatter={(value) => `Date: ${String(value)}`}
                      contentStyle={{ borderColor: 'var(--color-border)', borderRadius: 4, fontFamily: 'var(--font-body)', fontSize: 'var(--type-chart-label-size)' }}
                    />
                    <Area type="monotone" dataKey="revenue" name="Revenue" stroke="var(--chart-category-1)"
                      fill="var(--chart-sequential-2)" fillOpacity={0.75} strokeWidth={2} isAnimationActive={animateCharts}
                      animationBegin={MOTION_TIMING.chartDelay} animationDuration={MOTION_TIMING.chart}
                      animationEasing={CHART_EASING} />
                    <Area type="monotone" dataKey="expenses" name="Expenses" stroke="var(--chart-category-2)"
                      fill="var(--color-gold-accent)" fillOpacity={0.15} strokeWidth={2} isAnimationActive={animateCharts}
                      animationBegin={MOTION_TIMING.chartSecondaryDelay} animationDuration={MOTION_TIMING.chart}
                      animationEasing={CHART_EASING} />
                  </AreaChart>
                </ResponsiveContainer>
              </div>
              <p className="control-chart-summary">The chart shows only dates returned by the financial report; it does not infer a future trend.</p>
            </figure>}
        <div className="control-chart-legend"><span><i className="revenue" />Revenue</span><span><i className="expenses" />Expenses</span></div>
      </article>

      <StatisticalInsights
        revenueByDay={trend.revenue_by_day}
        expensesByDay={trend.expenses_by_day}
        allowed={reportsAvailable}
      />

      <article className="control-visual-card control-bento-medium">
        <div className="control-visual-heading"><div><span className="section-eyebrow">FINANCIAL HEALTH</span><h3>Expense composition</h3>
          <p>Server-calculated finance summary.</p></div><Link to="/finance">Finance</Link></div>
        {!financeAvailable
          ? <ChartEmpty>Finance data is restricted.</ChartEmpty>
          : expenseMix.length === 0
            ? <ChartEmpty>No classified expense totals are available.</ChartEmpty>
            : <>
              <div className="control-donut-wrap" role="img" aria-label="Donut chart showing operating, other, and unclassified expenses">
                <ResponsiveContainer width="100%" height={210}>
                  <PieChart>
                    <Pie data={expenseMix} dataKey="value" nameKey="name" innerRadius={56} outerRadius={82} paddingAngle={2}
                      isAnimationActive={animateCharts} animationBegin={MOTION_TIMING.chartDelay}
                      animationDuration={MOTION_TIMING.chart} animationEasing={CHART_EASING}>
                      {expenseMix.map((item, index) => <Cell key={item.name} fill={chartColors[index % chartColors.length]} />)}
                    </Pie>
                    <Tooltip formatter={(value) => currency(value)} contentStyle={{ borderColor: 'var(--color-border)', borderRadius: 4, fontSize: 'var(--type-chart-label-size)' }} />
                  </PieChart>
                </ResponsiveContainer>
              </div>
              <ul className="control-chart-key">{expenseMix.map((item, index) => <li key={item.name}>
                <span><i style={{ background: chartColors[index % chartColors.length] }} />{item.name}</span><strong>{currency(item.value)}</strong>
              </li>)}</ul>
            </>}
        <p className="control-chart-summary">Cash-flow timing and budget-versus-actual data are not available in the current feeds.</p>
      </article>

      <article className="control-visual-card control-bento-large">
        <div className="control-visual-heading"><div><span className="section-eyebrow">COMMERCIAL PERFORMANCE</span><h3>Revenue by business unit</h3>
          <p>Recorded revenue grouped by branch or department.</p></div><Link to="/finance">Sales records</Link></div>
        {!financeAvailable
          ? <ChartEmpty>Finance data is restricted.</ChartEmpty>
          : branches.length === 0 && departments.length === 0
            ? <ChartEmpty>No branch or department revenue has been recorded.</ChartEmpty>
            : <div className="control-contribution-charts">
              {branches.length > 0 && <div>
                <h4>Branches</h4>
                <ResponsiveContainer width="100%" height={Math.max(150, branches.length * 38)}>
                  <BarChart data={branches} layout="vertical" margin={{ left: 4, right: 12, top: 4, bottom: 4 }}>
                    <CartesianGrid stroke="var(--color-border)" strokeDasharray="3 5" horizontal={false} />
                    <XAxis type="number" tickFormatter={compactCurrency} tick={{ fill: 'var(--color-text-muted)', fontSize: 'var(--type-chart-label-size)' }} axisLine={false} tickLine={false} />
                    <YAxis type="category" dataKey="name" width={94} tick={{ fill: 'var(--color-text-muted)', fontSize: 'var(--type-chart-label-size)' }} axisLine={false} tickLine={false} />
                    <Tooltip formatter={(value) => currency(value)} />
                    <Bar dataKey="value" name="Revenue" fill="var(--chart-category-1)" radius={[0, 2, 2, 0]}
                      isAnimationActive={animateCharts} animationBegin={MOTION_TIMING.chartDelay}
                      animationDuration={MOTION_TIMING.chart} animationEasing={CHART_EASING} />
                  </BarChart>
                </ResponsiveContainer>
              </div>}
              {departments.length > 0 && <div>
                <h4>Departments</h4>
                <ResponsiveContainer width="100%" height={Math.max(150, departments.length * 38)}>
                  <BarChart data={departments} layout="vertical" margin={{ left: 4, right: 12, top: 4, bottom: 4 }}>
                    <CartesianGrid stroke="var(--color-border)" strokeDasharray="3 5" horizontal={false} />
                    <XAxis type="number" tickFormatter={compactCurrency} tick={{ fill: 'var(--color-text-muted)', fontSize: 'var(--type-chart-label-size)' }} axisLine={false} tickLine={false} />
                    <YAxis type="category" dataKey="name" width={94} tick={{ fill: 'var(--color-text-muted)', fontSize: 'var(--type-chart-label-size)' }} axisLine={false} tickLine={false} />
                    <Tooltip formatter={(value) => currency(value)} />
                    <Bar dataKey="value" name="Revenue" fill="var(--chart-category-3)" radius={[0, 2, 2, 0]}
                      isAnimationActive={animateCharts} animationBegin={MOTION_TIMING.chartDelay}
                      animationDuration={MOTION_TIMING.chart} animationEasing={CHART_EASING} />
                  </BarChart>
                </ResponsiveContainer>
              </div>}
            </div>}
      </article>

      <article className="control-visual-card control-bento-small">
        <div className="control-visual-heading"><div><span className="section-eyebrow">INVENTORY INTELLIGENCE</span><h3>Stock at a glance</h3>
          <p>Valuation and reorder exposure from current product records.</p></div><Link to="/operations">Operations</Link></div>
        {!operationsAvailable
          ? <ChartEmpty>Operations data is restricted.</ChartEmpty>
          : <>
            <div className="control-inventory-valuation"><span>Inventory valuation</span>
              <strong><AnimatedMetric value={inventoryValue} formatter={currency} /></strong></div>
            <div className="control-stock-meter" aria-label={`${lowStockCount ?? 0} products at or below reorder level out of ${products.length} catalogue products`}>
              <span style={{ width: `${products.length === 0 || lowStockCount === null ? 0 : Math.min(100, (lowStockCount / products.length) * 100)}%` }} />
            </div>
            <p className="control-chart-summary">{products.length === 0
              ? 'No catalogue data is available to assess stock distribution.'
              : `${lowStockCount ?? 'Unavailable'} products at or below their reorder level across ${products.length} catalogue records.`}</p>
            {lowStock.length > 0 && <ul className="control-low-stock-list">{lowStock.slice(0, 5).map((item) => (
              <li key={String(item.sku ?? item.name)}>
                <span><strong>{String(item.name ?? 'Product')}</strong><small>{String(item.sku ?? '')}</small></span>
                <span className="control-stock-quantity">{String(item.stock_level ?? '—')} / {String(item.reorder_level ?? '—')}</span>
              </li>
            ))}</ul>}
          </>}
      </article>

      <article className="control-visual-card control-bento-small">
        <div className="control-visual-heading"><div><span className="section-eyebrow">OPERATIONAL FLOW</span><h3>Procurement progress</h3>
          <p>Latest purchase orders returned by Operations.</p></div><Link to="/operations">Open operations</Link></div>
        {!operationsAvailable
          ? <ChartEmpty>Operations data is restricted.</ChartEmpty>
          : purchaseOrders.length === 0
            ? <ChartEmpty>No purchase orders are available in the current summary.</ChartEmpty>
            : <>
              <div className="control-order-flow" role="img" aria-label={`Purchase-order status distribution for the latest ${purchaseOrders.length} records`}>
                {orderStatus.map(({ status, count }) => <div key={status} className={`control-order-step ${status}`}>
                  <span>{status}</span><strong>{count}</strong><i style={{ width: `${(count / purchaseOrders.length) * 100}%` }} />
                </div>)}
              </div>
              <p className="control-chart-summary">This is a distribution of the latest {purchaseOrders.length} orders, not a complete backlog count.</p>
            </>}
        {stockMovements.length > 0 && <div className="control-movement-summary">
          <strong>Recent stock movements</strong><span>Latest {stockMovements.length} recorded</span>
          <ul>{stockMovements.slice(0, 4).map((item, index) => <li key={`${String(item.created_at)}-${index}`}>
            <span>{String(item.type ?? 'Movement')} · {String(item.quantity ?? '—')} units</span><time>{String(item.created_at ?? '')}</time>
          </li>)}</ul>
        </div>}
      </article>

      <article className="control-visual-card control-bento-medium">
        <div className="control-visual-heading"><div><span className="section-eyebrow">RISK & EXCEPTIONS</span><h3>Management attention</h3>
          <p>Prioritised alerts and recommended next steps.</p></div><Link to="/risk">Open Risk</Link></div>
        {!riskAvailable
          ? <ChartEmpty>Risk data is restricted.</ChartEmpty>
          : riskAlerts.length === 0
            ? <ChartEmpty>No risk alerts were returned.</ChartEmpty>
            : <ul className="control-risk-list">{riskAlerts.slice(0, 4).map((alert) => (
              <li key={String(alert.id)}>
                <span className={`control-risk-severity ${String(alert.severity ?? 'low').toLowerCase()}`}>{String(alert.severity ?? 'Unrated')}</span>
                <div><strong>{String(alert.explanation ?? alert.category ?? 'Risk signal')}</strong>
                  <p>{String(alert.suggested_action ?? 'Review the supporting evidence in Risk.')}</p>
                  <small>{String(alert.business_area ?? 'Business')} · {String(alert.detected_at ?? '')}</small></div>
              </li>
            ))}</ul>}
      </article>

      <ActivityTimeline entries={activity} />
    </div>

    <article className="control-visual-card control-organisation-card">
      <div className="control-visual-heading"><div><span className="section-eyebrow">GOVERNANCE</span><h3>Organisation profile</h3></div>
        <Link to="/control/users">Manage access</Link></div>
      <dl>
        <div><dt>Organisation</dt><dd>{String(organisation.name ?? 'Unavailable')}</dd></div>
        <div><dt>Status</dt><dd>{String(organisation.status ?? 'Unavailable')}</dd></div>
        <div><dt>Active users</dt><dd>{String(stats.activeUsers ?? 'Unavailable')} of {String(stats.users ?? 'Unavailable')}</dd></div>
        <div><dt>Branches & departments</dt><dd>{String(stats.branches ?? 'Unavailable')} branches · {String(stats.departments ?? 'Unavailable')} departments</dd></div>
      </dl>
      <p className="control-visual-footnote">Business data is shown only when the signed-in user also has the relevant module permission.</p>
    </article>
  </div>;
}
