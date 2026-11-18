import type { CommandOverviewData } from '../CommandOverviewBento';
import type { VantiqDashboardData, VantiqMetric } from './VantiqCinematicDashboard';

function formatCurrency(value: number, currency: string): string {
  return new Intl.NumberFormat('en-GH', {
    style: 'currency',
    currency,
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  }).format(value);
}

function mapMetric(metric: CommandOverviewData['kpis'][number], currency: string): VantiqMetric {
  const metricCurrency = metric.currency ?? currency;
  const display = metric.format === 'currency'
    ? (value: number) => formatCurrency(value, metricCurrency)
    : metric.format === 'percent'
      ? (value: number) => `${value.toFixed(1)}%`
      : (value: number) => new Intl.NumberFormat('en-GH', { maximumFractionDigits: 1 }).format(value);
  const change = metric.changePercent === null || metric.changePercent === undefined
    ? 'No prior-period comparison'
    : `${metric.changePercent > 0 ? '+' : ''}${metric.changePercent.toFixed(1)}% ${metric.comparisonLabel ?? 'vs previous period'}`;
  const icon = metric.id === 'net-revenue'
    ? 'payments'
    : metric.id === 'total-expenses'
      ? 'account_balance_wallet'
      : metric.id === 'operating-profit'
        ? 'trending_up'
        : 'receipt_long';

  return {
    id: metric.id,
    label: metric.label,
    value: metric.value,
    display,
    change,
    statusText: metric.statusText,
    trend: metric.direction === 'up' ? 'up' : metric.direction === 'down' ? 'down' : 'neutral',
    icon,
    sparkline: metric.series,
  };
}

export function adaptCinematicCommandOverview(
  data: CommandOverviewData | null,
): VantiqDashboardData | null {
  if (!data) return null;

  const currency = data.currency ?? 'GHS';
  const revenueMixTotal = data.revenueComposition.reduce((total, item) => total + Math.max(0, item.value), 0);
  const expenseMixTotal = data.expenseComposition.reduce((total, item) => total + Math.max(0, item.value), 0);
  const composition = revenueMixTotal > 0
    ? { items: data.revenueComposition, total: revenueMixTotal, icon: 'account_balance' }
    : { items: data.expenseComposition, total: expenseMixTotal, icon: 'payments' };

  return {
    metrics: data.kpis.map((metric) => mapMetric(metric, currency)),
    activitySeries: data.activitySeries.map((point) => point.value),
    activityTotal: data.activityTotal,
    applications: composition.total > 0
      ? composition.items.filter((item) => item.value > 0).slice(0, 4).map((item, index) => ({
        id: `${item.label}-${index}`,
        name: item.label,
        sharePercent: Math.max(0, item.value) / composition.total * 100,
        icon: composition.icon,
        tone: index === 1 ? 'cyan' : index === 2 ? 'amber' : 'blue',
      }))
      : [],
    alerts: data.alerts.map((alert) => ({
      id: alert.id,
      title: alert.title,
      detail: alert.description,
      severity: alert.severity === 'critical' ? 'critical' : alert.severity === 'warning' ? 'warning' : 'info',
    })),
    systemHealth: null,
    lastUpdated: data.lastUpdated,
  };
}
