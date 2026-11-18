import type {
  BusinessAlert,
  CommandOverviewData,
  KpiMetric,
  TrendPoint,
} from './CommandOverviewBento';

type UnknownMap = Record<string, unknown>;

type FinancialReportLike = {
  from: string;
  to: string;
  revenue_by_day: Array<{ period: string; revenue: number; sale_count: number }>;
  expenses_by_day: Array<{ period: string; expenses: number; expense_count: number }>;
};

type SaleLike = {
  sale_date: string;
  total: number;
  status: string;
};

export type CommandOverviewAdapterInput = {
  finance: UnknownMap;
  operations: UnknownMap;
  risk: UnknownMap;
  trend: FinancialReportLike | null;
  previousTrend: FinancialReportLike | null;
  sales: { sales: SaleLike[] } | null;
  period: string;
  lastUpdatedAt: number | null;
};

function mapObject(value: unknown): UnknownMap {
  return typeof value === 'object' && value !== null ? value as UnknownMap : {};
}

function arrayValue<T>(value: unknown): T[] {
  return Array.isArray(value) ? value as T[] : [];
}

function finite(value: unknown): number | null {
  const parsed = Number(value);
  return Number.isFinite(parsed) ? parsed : null;
}

function sum(rows: Array<{ revenue?: number; expenses?: number }>, key: 'revenue' | 'expenses'): number {
  return rows.reduce((total, row) => total + (Number(row[key]) || 0), 0);
}

function percentageChange(current: number, previous: number): number | null {
  if (previous === 0) return null;
  return ((current - previous) / Math.abs(previous)) * 100;
}

function histogram(sales: SaleLike[], from: string, to: string): Array<{ label: string; count: number }> {
  const inRange = sales.filter((sale) =>
    sale.status === 'posted'
    && sale.sale_date.slice(0, 10) >= from
    && sale.sale_date.slice(0, 10) <= to,
  );
  if (!inRange.length) return [];

  const values = inRange.map((sale) => Math.max(0, Number(sale.total) || 0));
  const minimum = Math.min(...values);
  const maximum = Math.max(...values);
  const binCount = Math.min(6, Math.max(3, Math.ceil(Math.sqrt(values.length))));
  const width = maximum > minimum ? (maximum - minimum) / binCount : 1;

  return Array.from({ length: binCount }, (_, index) => {
    const lower = minimum + index * width;
    const upper = index === binCount - 1 ? maximum : lower + width;
    const count = values.filter((value) =>
      value >= lower && (index === binCount - 1 ? value <= upper : value < upper),
    ).length;
    return {
      label: index === binCount - 1
        ? `${lower.toLocaleString(undefined, { maximumFractionDigits: 0 })}+`
        : `${lower.toLocaleString(undefined, { maximumFractionDigits: 0 })}-${upper.toLocaleString(undefined, { maximumFractionDigits: 0 })}`,
      count,
    };
  });
}

export function adaptCommandOverview(input: CommandOverviewAdapterInput): CommandOverviewData {
  const finance = mapObject(input.finance);
  const summary = mapObject(finance.summary);
  const operations = mapObject(input.operations);
  const stockHealth = mapObject(operations.stockHealth);
  const risk = mapObject(input.risk);

  const trend = input.trend;
  const previous = input.previousTrend;
  const revenueRows = trend?.revenue_by_day ?? [];
  const expenseRows = trend?.expenses_by_day ?? [];
  const currentRevenue = sum(revenueRows, 'revenue');
  const currentExpenses = sum(expenseRows, 'expenses');
  const previousRevenue = previous ? sum(previous.revenue_by_day, 'revenue') : 0;
  const previousExpenses = previous ? sum(previous.expenses_by_day, 'expenses') : 0;
  const revenueChange = previous ? percentageChange(currentRevenue, previousRevenue) : null;
  const expenseChange = previous ? percentageChange(currentExpenses, previousExpenses) : null;

  const revenueTrend: TrendPoint[] = revenueRows.map((row) => ({ label: row.period.slice(5), value: Number(row.revenue) || 0 }));
  const expenseTrend: TrendPoint[] = expenseRows.map((row) => ({ label: row.period.slice(5), value: Number(row.expenses) || 0 }));
  const activityByPeriod = new Map<string, number>();
  for (const row of revenueRows) {
    activityByPeriod.set(row.period, (activityByPeriod.get(row.period) ?? 0) + (Number(row.sale_count) || 0));
  }
  for (const row of expenseRows) {
    activityByPeriod.set(row.period, (activityByPeriod.get(row.period) ?? 0) + (Number(row.expense_count) || 0));
  }
  const activitySeries = Array.from(activityByPeriod)
    .sort(([left], [right]) => left.localeCompare(right))
    .map(([period, value]) => ({ label: period.slice(5), value }));

  const kpis: KpiMetric[] = [
    {
      id: 'net-revenue',
      label: 'Net revenue',
      value: trend ? currentRevenue : finite(summary.totalRevenue),
      format: 'currency',
      changePercent: revenueChange,
      comparisonLabel: previous ? 'vs previous period' : 'No comparison',
      series: revenueRows.map((row) => Number(row.revenue) || 0),
      direction: revenueChange === null ? 'flat' : revenueChange >= 0 ? 'up' : 'down',
    },
    {
      id: 'total-expenses',
      label: 'Total expenses',
      value: trend ? currentExpenses : finite(summary.totalExpenses),
      format: 'currency',
      changePercent: expenseChange,
      comparisonLabel: previous ? 'vs previous period' : 'No comparison',
      series: expenseRows.map((row) => Number(row.expenses) || 0),
      direction: expenseChange === null ? 'flat' : expenseChange <= 0 ? 'up' : 'down',
    },
    {
      id: 'operating-profit',
      label: 'Operating profit',
      value: finite(summary.operatingProfit),
      format: 'currency',
      changePercent: null,
      comparisonLabel: 'current summary',
      statusText: 'Not available by selected period',
    },
    {
      id: 'outstanding-receivables',
      label: 'Outstanding receivables',
      value: finite(summary.outstandingReceivables),
      format: 'currency',
      changePercent: null,
      comparisonLabel: 'current balance',
      statusText: 'Open invoices',
    },
  ];

  const revenueComposition = arrayValue<{ label: string; value: number }>(finance.branchBreakdown).map((item) => ({
    label: item.label,
    value: Number(item.value) || 0,
  }));
  const expenseComposition = [
    { label: 'Operating', value: Number(summary.operatingExpenses) || 0 },
    { label: 'Other', value: Number(summary.otherExpenses) || 0 },
    { label: 'Unclassified', value: Number(summary.unclassifiedExpenses) || 0 },
  ].filter((item) => item.value > 0);

  const branchPerformance = arrayValue<{ label: string; value: number }>(finance.branchBreakdown).map((item) => ({
    label: item.label,
    actual: Number(item.value) || 0,
  }));

  const activeAlerts = arrayValue<UnknownMap>(risk.alerts).filter((alert) =>
    !['resolved', 'dismissed'].includes(String(alert.status)),
  );
  const alerts: BusinessAlert[] = activeAlerts.slice(0, 4).map((alert) => {
    const severity = alert.severity === 'critical' || alert.severity === 'warning'
      || alert.severity === 'success'
      ? alert.severity
      : 'info';
    return {
      id: String(alert.id),
      title: String(alert.category ?? 'Business alert'),
      description: String(alert.explanation ?? 'Review required'),
      severity,
      timestamp: alert.created_at ? new Date(String(alert.created_at)).toLocaleDateString() : undefined,
      href: `/risk`,
    };
  });

  const lowStockCount = finite(stockHealth.lowStockCount);
  const hasInsight = activeAlerts.length > 0 || (lowStockCount !== null && lowStockCount > 0);
  const executiveInsight = hasInsight
    ? {
      title: activeAlerts.length > 0 ? 'Management attention is required' : 'Inventory review is recommended',
      description: activeAlerts.length > 0
        ? `${activeAlerts.length} active ${activeAlerts.length === 1 ? 'risk alert requires' : 'risk alerts require'} review.`
        : `${lowStockCount} products are at or below reorder level.`,
      supportingMetric: activeAlerts.length > 0
        ? `${activeAlerts.filter((alert) => alert.severity === 'critical').length} critical`
        : 'Operations',
    }
    : null;

  const sales = input.sales?.sales ?? [];
  const salesDistribution = trend
    ? histogram(sales, trend.from, trend.to)
    : [];

  return {
    currency: 'GHS',
    reportingPeriod: input.period,
    lastUpdated: input.lastUpdatedAt === null
      ? undefined
      : new Date(input.lastUpdatedAt).toLocaleString(),
    kpis,
    activitySeries,
    activityTotal: activitySeries.length
      ? activitySeries.reduce((total, point) => total + point.value, 0)
      : null,
    revenueExpenseTrend: { revenue: revenueTrend, expenses: expenseTrend },
    revenueComposition,
    expenseComposition,
    salesDistribution,
    branchPerformance,
    alerts,
    executiveInsight,
  };
}
