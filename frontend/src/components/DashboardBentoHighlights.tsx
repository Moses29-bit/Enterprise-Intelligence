import type { ReactNode } from 'react';
import type { FinancialReport, PurchaseOrderRecord } from '../services/api';
import { AnimatedMetric } from './AnimatedMetric';

type DataRecord = Record<string, unknown>;

export type BentoDashboardModule = 'finance' | 'operations' | 'forecast' | 'risk' | 'decisions' | 'reports' | 'connect';

interface DashboardBentoHighlightsProps {
  module: BentoDashboardModule;
  finance?: DataRecord;
  operations?: DataRecord;
  purchaseOrders?: PurchaseOrderRecord[];
  forecast?: DataRecord;
  riskAlerts?: DataRecord[];
  scenarios?: DataRecord[];
  imports?: DataRecord[];
  report?: FinancialReport | null;
}

function record(value: unknown): DataRecord {
  return typeof value === 'object' && value !== null && !Array.isArray(value)
    ? value as DataRecord
    : {};
}

function records(value: unknown): DataRecord[] {
  return Array.isArray(value)
    ? value.filter((item): item is DataRecord =>
      typeof item === 'object' && item !== null && !Array.isArray(item))
    : [];
}

function numericValue(value: unknown): number | null {
  if (value === null || value === undefined || value === '') return null;
  const parsed = Number(value);
  return Number.isFinite(parsed) ? parsed : null;
}

function formatNumber(value: number): string {
  return new Intl.NumberFormat('en-GH', { maximumFractionDigits: 0 }).format(value);
}

function formatMoney(value: unknown): string {
  const amount = numericValue(value);
  if (amount === null) return '—';
  return new Intl.NumberFormat('en-GH', {
    style: 'currency',
    currency: 'GHS',
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  }).format(amount);
}

function totalOf<T>(items: T[], read: (item: T) => unknown): number {
  return items.reduce((total, item) => total + (numericValue(read(item)) ?? 0), 0);
}

function BentoCard({
  title,
  detail,
  size = 'half',
  children,
}: {
  title: string;
  detail?: string;
  size?: 'third' | 'half' | 'wide';
  children: ReactNode;
}) {
  return <article className={`module-bento-card module-bento-card--${size}`}>
    <div className="module-bento-card-heading">
      <h2>{title}</h2>
      {detail && <p>{detail}</p>}
    </div>
    {children}
  </article>;
}

function BentoBars({ items, total }: {
  items: Array<{ label: string; count: number }>;
  total: number;
}) {
  return <ul className="module-bento-bars">
    {items.map((item) => {
      const percentage = total > 0 ? Math.round(item.count / total * 100) : 0;
      return <li key={item.label}>
        <div className="module-bento-bar-label"><span>{item.label}</span>
          <strong><AnimatedMetric value={item.count} formatter={formatNumber} /></strong></div>
        <div className="module-bento-bar-track" aria-hidden="true">
          <span style={{ width: `${percentage}%` }} />
        </div>
      </li>;
    })}
  </ul>;
}

function ReportHighlights({ report }: { report: FinancialReport }) {
  const revenue = totalOf(report.revenue_by_day, (row) => row.revenue);
  const expenses = totalOf(report.expenses_by_day, (row) => row.expenses);
  const refunds = totalOf(report.refunds_by_day, (row) => row.refunds);

  return <section className="module-bento module-bento--reports" aria-label="Financial report highlights">
    <BentoCard title="Revenue" detail={`${report.from} to ${report.to}`} size="third">
      <AnimatedMetric className="module-bento-value" value={revenue} formatter={formatMoney} />
      <span className="module-bento-caption">Posted sales in the selected range</span>
    </BentoCard>
    <BentoCard title="Expenses" detail="Recorded entries" size="third">
      <AnimatedMetric className="module-bento-value" value={expenses} formatter={formatMoney} />
      <span className="module-bento-caption">Recorded expenses in the selected range</span>
    </BentoCard>
    <BentoCard title="Refunds" detail="Posted refunds" size="third">
      <AnimatedMetric className="module-bento-value" value={refunds} formatter={formatMoney} />
      <span className="module-bento-caption">Refunds in the selected range</span>
    </BentoCard>
  </section>;
}

export function DashboardBentoHighlights({
  module,
  finance = {},
  operations = {},
  purchaseOrders = [],
  forecast = {},
  riskAlerts = [],
  scenarios = [],
  imports = [],
  report,
}: DashboardBentoHighlightsProps) {
  if (module === 'finance') {
    const period = report ? `${report.from} to ${report.to}` : 'Current financial summary';
    return <section className="module-bento module-bento--finance" aria-label="Finance overview">
      <BentoCard title="Net revenue" detail={period} size="third">
        <AnimatedMetric className="module-bento-value" value={numericValue(finance.totalRevenue)} formatter={formatMoney} />
        <span className="module-bento-caption">Posted sales</span>
      </BentoCard>
      <BentoCard title="Cost of goods sold" detail="Recorded product costs" size="third">
        <AnimatedMetric className="module-bento-value" value={numericValue(finance.costOfGoodsSold)} formatter={formatMoney} />
        <span className="module-bento-caption">Cost associated with sales</span>
      </BentoCard>
      <BentoCard title="Gross profit" detail="Revenue less cost of goods sold" size="third">
        <AnimatedMetric className="module-bento-value" value={numericValue(finance.grossProfit)} formatter={formatMoney} />
        <span className="module-bento-caption">Current financial summary</span>
      </BentoCard>
      <BentoCard title="Operating profit" detail="Current financial summary" size="third">
        <AnimatedMetric className="module-bento-value" value={numericValue(finance.operatingProfit)} formatter={formatMoney} />
        <span className="module-bento-caption">After recorded operating expenses</span>
      </BentoCard>
      <BentoCard title="Outstanding receivables" detail="Open invoice balances" size="third">
        <AnimatedMetric className="module-bento-value" value={numericValue(finance.outstandingReceivables)} formatter={formatMoney} />
        <span className="module-bento-caption">Unsettled customer invoices</span>
      </BentoCard>
    </section>;
  }

  if (module === 'operations') {
    const stockHealth = record(operations.stockHealth);
    const orderCounts = ['draft', 'submitted', 'received', 'closed'].map((status) => ({
      label: status,
      count: purchaseOrders.filter((order) => order.status === status).length,
    }));
    return <section className="module-bento module-bento--operations" aria-label="Operations overview">
      <BentoCard title="Inventory valuation" detail="Current stock at recorded unit cost" size="half">
        <AnimatedMetric className="module-bento-value" value={numericValue(operations.inventoryValue)} formatter={formatMoney} />
        <div className="module-bento-secondary-stat">
          <span>Items at or below reorder level</span>
          <strong><AnimatedMetric value={numericValue(stockHealth.lowStockCount)} formatter={formatNumber} /></strong>
        </div>
      </BentoCard>
      <BentoCard title="Purchase orders" detail={`${purchaseOrders.length} returned by the current list`} size="half">
        <BentoBars items={orderCounts} total={purchaseOrders.length} />
      </BentoCard>
    </section>;
  }

  if (module === 'forecast') {
    const forecastModel = record(forecast.forecast);
    const results = records(forecast.results);
    const latest = results[0] ?? {};
    const projectedValue = numericValue(latest.projected_value);
    const actualValue = numericValue(latest.actual_value);
    return <section className="module-bento module-bento--forecast" aria-label="Forecast highlights">
      <BentoCard title="Latest projected period" detail={String(latest.period_label ?? 'No period generated')} size="half">
        <AnimatedMetric className="module-bento-value" value={projectedValue} formatter={formatMoney} />
        {actualValue !== null && <div className="module-bento-secondary-stat">
          <span>Recorded actual</span><strong><AnimatedMetric value={actualValue} formatter={formatMoney} /></strong>
        </div>}
      </BentoCard>
      <BentoCard title="Model context" detail={String(forecast.methodology ?? 'Methodology not provided')} size="half">
        <dl className="module-bento-facts">
          <div><dt>Model version</dt><dd>{String(forecastModel.model_version ?? '—')}</dd></div>
          <div><dt>Periods returned</dt><dd>{formatNumber(results.length)}</dd></div>
        </dl>
      </BentoCard>
    </section>;
  }

  if (module === 'risk') {
    const severityCounts = ['critical', 'high', 'medium', 'low'].map((severity) => ({
      label: severity,
      count: riskAlerts.filter((alert) => String(alert.severity ?? 'low').toLowerCase() === severity).length,
    }));
    const openCount = riskAlerts.filter((alert) =>
      !['resolved', 'dismissed'].includes(String(alert.status ?? 'open').toLowerCase())).length;
    return <section className="module-bento module-bento--risk" aria-label="Risk alert overview">
      <BentoCard title="Open review alerts" detail={`${riskAlerts.length} latest alerts returned`} size="third">
        <AnimatedMetric className="module-bento-value" value={openCount} formatter={formatNumber} />
        <span className="module-bento-caption">Require investigation or follow-up</span>
      </BentoCard>
      <BentoCard title="Severity distribution" detail="Among the latest alerts returned" size="wide">
        <BentoBars items={severityCounts} total={riskAlerts.length} />
      </BentoCard>
    </section>;
  }

  if (module === 'decisions') {
    const statusCounts = Array.from(new Set(scenarios.map((scenario) =>
      String(scenario.status ?? 'unknown'))))
      .sort()
      .map((status) => ({
        label: status,
        count: scenarios.filter((scenario) => String(scenario.status ?? 'unknown') === status).length,
      }));
    const resultCount = totalOf(scenarios, (scenario) => scenario.result_count);
    return <section className="module-bento module-bento--decisions" aria-label="Decision scenario overview">
      <BentoCard title="Saved scenarios" detail="Latest scenarios returned" size="third">
        <AnimatedMetric className="module-bento-value" value={scenarios.length} formatter={formatNumber} />
        <div className="module-bento-secondary-stat">
          <span>Calculated outcomes</span><strong><AnimatedMetric value={resultCount} formatter={formatNumber} /></strong>
        </div>
      </BentoCard>
      <BentoCard title="Scenario status" detail="Current saved scenario records" size="wide">
        <BentoBars items={statusCounts} total={scenarios.length} />
        {statusCounts.length === 0 && <p className="module-bento-empty">No scenarios have been saved.</p>}
      </BentoCard>
    </section>;
  }

  if (module === 'reports') {
    return report ? <ReportHighlights report={report} /> : null;
  }

  const importCounts = Array.from(new Set(imports.map((item) => String(item.status ?? 'unknown'))))
    .sort()
    .map((status) => ({
      label: status,
      count: imports.filter((item) => String(item.status ?? 'unknown') === status).length,
    }));
  return <section className="module-bento module-bento--connect" aria-label="Import activity overview">
    <BentoCard title="Import batches" detail="Latest batches returned" size="third">
      <AnimatedMetric className="module-bento-value" value={imports.length} formatter={formatNumber} />
      <span className="module-bento-caption">Organisation import history</span>
    </BentoCard>
    <BentoCard title="Batch status" detail="Current import records" size="wide">
      <BentoBars items={importCounts} total={imports.length} />
      {importCounts.length === 0 && <p className="module-bento-empty">No import batches are available.</p>}
    </BentoCard>
  </section>;
}
