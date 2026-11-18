import { FormEvent, lazy, Suspense, useCallback, useEffect, useMemo, useRef, useState, type CSSProperties, type ReactNode } from 'react';
import { Link, useLocation, useNavigate } from 'react-router-dom';
import {
  getErrorMessage,
  moduleApi,
  type AdminAuditLog,
  type AdminRole,
  type AuthUser,
  type CustomerRecord,
  type ExpenseRecord,
  type FinancialReport,
  type InvoiceRecord,
  type ProductRecord,
  type PurchaseOrderRecord,
  type SaleRecord,
  type SupplierRecord,
} from '../services/api';
import { DashboardBentoHighlights } from '../components/DashboardBentoHighlights';
import { AnimatedMetric, MOTION_TIMING } from '../components/AnimatedMetric';
import { Sparkline } from '../components/Sparkline';
import { adaptCinematicCommandOverview } from '../components/vantiq-cinematic/adaptCommandOverview';
import { adaptCommandOverview } from '../components/CommandOverviewBentoAdapter';

export type WorkspaceModuleKey =
  | 'command' | 'finance' | 'operations' | 'forecast' | 'risk'
  | 'decisions' | 'reports' | 'connect' | 'control' | 'design-system';

interface WorkspaceModuleProps {
  module: WorkspaceModuleKey;
  user: AuthUser;
  searchQuery?: string;
}

type DataMap = Record<string, unknown>;

const LazyControlOverview = lazy(async () => ({
  default: (await import('./ControlOverview')).ControlOverview,
}));
const LazyStatisticalInsights = lazy(async () => ({
  default: (await import('../components/StatisticalInsights')).StatisticalInsights,
}));
const LazyCinematicDashboard = lazy(() => import('../components/vantiq-cinematic/VantiqCinematicDashboard'));

const permissionForModule: Record<WorkspaceModuleKey, string[]> = {
  command: ['finance.read', 'operations.read', 'risks.read', 'reports.read'],
  finance: ['finance.read'],
  operations: ['operations.read'],
  forecast: ['operations.read'],
  risk: ['risks.read'],
  decisions: ['decisions.manage'],
  reports: ['reports.read'],
  connect: ['connect.manage'],
  control: ['users.manage'],
  'design-system': ['finance.read', 'operations.read', 'reports.read', 'users.manage'],
};

function arrayValue<T>(value: unknown): T[] {
  return Array.isArray(value) ? value as T[] : [];
}

function objectValue(value: unknown): DataMap {
  return typeof value === 'object' && value !== null ? value as DataMap : {};
}

function money(value: unknown): string {
  const amount = Number(value);

  if (!Number.isFinite(amount)) {
    return '—';
  }

  return new Intl.NumberFormat('en-GH', {
    style: 'currency',
    currency: 'GHS',
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  }).format(amount);
}

function finiteValue(value: unknown): number | null {
  if (value === null || value === undefined || value === '') return null;
  const amount = Number(value);
  return Number.isFinite(amount) ? amount : null;
}

function formatPercent(value: number): string {
  return `${value.toFixed(1)}%`;
}

function dateInput(daysAgo: number): string {
  const value = new Date();
  value.setDate(value.getDate() - daysAgo);
  return value.toISOString().slice(0, 10);
}

function reportingPeriodRange(period: string): { from: string; to: string; previousFrom: string; previousTo: string } {
  const now = new Date();
  const to = now.toISOString().slice(0, 10);
  const start = new Date(now);
  let previousStart = new Date(now);
  let previousEnd = new Date(now);

  if (period === 'This week') {
    start.setDate(now.getDate() - 6);
    previousEnd.setDate(now.getDate() - 7);
    previousStart.setDate(previousEnd.getDate() - 6);
  } else if (period === 'This quarter') {
    start.setMonth(now.getMonth() - 2, 1);
    previousEnd = new Date(start);
    previousEnd.setDate(previousEnd.getDate() - 1);
    previousStart = new Date(previousEnd);
    previousStart.setMonth(previousEnd.getMonth() - 2, 1);
  } else if (period === 'This year') {
    start.setMonth(0, 1);
    previousStart = new Date(now.getFullYear() - 1, 0, 1);
    previousEnd = new Date(now.getFullYear() - 1, 11, 31);
  } else {
    start.setDate(1);
    previousEnd = new Date(start);
    previousEnd.setDate(previousEnd.getDate() - 1);
    previousStart = new Date(previousEnd);
    previousStart.setDate(1);
  }

  return {
    from: start.toISOString().slice(0, 10),
    to,
    previousFrom: previousStart.toISOString().slice(0, 10),
    previousTo: previousEnd.toISOString().slice(0, 10),
  };
}

function DesignSystemPreview() {
  return (
    <div className="design-system-page">
      <header className="design-system-header">
        <div>
          <p className="eyebrow">Design system</p>
          <h2>Enterprise interface foundations</h2>
        </div>
        <button type="button" className="primary-button">Preview tokens</button>
      </header>

      <div className="design-system-grid">
        <article className="panel-surface">
          <div className="panel-header"><h3>Typography</h3><span>Approved type stack</span></div>
          <div className="design-system-type">
            <p className="display-sample">Display · Plus Jakarta Sans</p>
            <p className="text-h1 type-sample-heading">H1 · Page title</p>
            <p className="text-h2 type-sample-heading">H2 · Section title</p>
            <p className="type-sample-body"><strong>IBM Plex Sans</strong> · UI, body, labels, and data remain clear and easy to scan.</p>
            <p className="type-sample-body">See beyond the <em className="accent">numbers.</em></p>
            <small>Caption / metadata</small>
          </div>
        </article>

        <article className="panel-surface">
          <div className="panel-header"><h3>Colour system</h3><span>Brand and semantic palette</span></div>
          <div className="design-system-swatches">
            {[
              ['Brand primary', '--color-brand-primary'],
              ['Gold accent', '--color-gold-accent'],
              ['Primary text', '--color-text'],
              ['Success', '--color-success-text'],
              ['Warning', '--color-warning-text'],
              ['Danger', '--color-danger-text'],
            ].map(([name, value]) => (
              <div className="design-system-swatch" key={name}>
                <span className="swatch" style={{ background: `var(${value})` }} />
                <strong>{name}</strong>
                <small>{value}</small>
              </div>
            ))}
          </div>
        </article>
      </div>

      <div className="design-system-grid">
        <article className="panel-surface">
          <div className="panel-header"><h3>Buttons</h3><span>Actions and emphasis</span></div>
          <div className="design-system-actions">
            <button type="button" className="primary-button">Primary action</button>
            <button type="button" className="ghost-button">Secondary action</button>
            <button type="button" className="text-button">Text action</button>
          </div>
        </article>

        <article className="panel-surface">
          <div className="panel-header"><h3>Form controls</h3><span>Input states</span></div>
          <div className="design-system-form">
            <label>Organisation name<input defaultValue="VANTIQ Holdings" /></label>
            <label>Department<select defaultValue="Operations"><option>Operations</option><option>Finance</option></select></label>
            <label>Notes<textarea defaultValue="Quarterly forecast review guidance." rows={3} /></label>
          </div>
        </article>
      </div>

      <article className="panel-surface">
        <div className="panel-header"><h3>Data table</h3><span>Tabular values with numeric alignment</span></div>
        <div className="table-scroll">
          <table className="data-table design-system-table">
            <thead>
              <tr>
                <th>Metric</th>
                <th>Current</th>
                <th>Target</th>
                <th>Status</th>
              </tr>
            </thead>
            <tbody>
              <tr>
                <td>Revenue</td>
                <td>GHS 2,860,240.00</td>
                <td>GHS 2,950,000.00</td>
                <td><span className="severity low">On track</span></td>
              </tr>
              <tr>
                <td>Gross profit</td>
                <td>GHS 1,104,820.00</td>
                <td>GHS 1,180,000.00</td>
                <td><span className="severity medium">Watch</span></td>
              </tr>
              <tr>
                <td>Inventory days</td>
                <td>16.4</td>
                <td>14.0</td>
                <td><span className="severity high">Action</span></td>
              </tr>
            </tbody>
          </table>
        </div>
      </article>

      <div className="design-system-grid">
        <article className="panel-surface">
          <div className="panel-header"><h3>KPI cards</h3><span>Executive performance context</span></div>
          <div className="kpi-grid small-grid">
            <div className="metric-card">
              <span className="metric-label">Revenue</span>
              <span className="metric-value">GHS 2.86M</span>
              <small className="metric-delta positive">+12.4% vs last month</small>
            </div>
            <div className="metric-card">
              <span className="metric-label">Expenses</span>
              <span className="metric-value">GHS 1.28M</span>
              <small className="metric-delta neutral">Stable</small>
            </div>
            <div className="metric-card">
              <span className="metric-label">Profit</span>
              <span className="metric-value">GHS 1.58M</span>
              <small className="metric-delta positive">+4.6%</small>
            </div>
          </div>
        </article>

        <article className="panel-surface">
          <div className="panel-header"><h3>Trend bars</h3><span>Operational signals</span></div>
          <div className="bar-chart">
            {[48, 62, 55, 76, 88, 70, 94].map((value, index) => (
              <div className="bar-group" key={String(index)}>
                <div className="bar" style={{ height: `${value}%` }} />
                <span>{['M', 'T', 'W', 'T', 'F', 'S', 'S'][index]}</span>
              </div>
            ))}
          </div>
        </article>
      </div>

      <article className="panel-surface">
        <div className="panel-header"><h3>States</h3><span>Empty, warning, and alert treatment</span></div>
        <div className="design-system-states">
          <div className="empty-state">No actionable records are available yet.</div>
          <div className="notice">Data freshness is being validated before publishing to the dashboard.</div>
          <div className="error">Review required: risk alert exceeds threshold for this region.</div>
        </div>
      </article>
    </div>
  );
}

export function WorkspaceModule({ module, user, searchQuery = '' }: WorkspaceModuleProps) {
  const location = useLocation();
  const navigate = useNavigate();
  const controlSection = location.pathname.startsWith('/control/')
    ? location.pathname.slice('/control/'.length)
    : 'overview';
  const [data, setData] = useState<DataMap>({});
  const [loading, setLoading] = useState(true);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');
  const [report, setReport] = useState<FinancialReport | null>(null);
  const [reportFrom, setReportFrom] = useState(dateInput(29));
  const [reportTo, setReportTo] = useState(dateInput(0));
  const [selectedScenario, setSelectedScenario] = useState<DataMap | null>(null);
  const [importPreview, setImportPreview] = useState<DataMap | null>(null);
  const [syncStatus, setSyncStatus] = useState<'syncing' | 'current' | 'interrupted' | 'paused'>('syncing');
  const [lastUpdatedAt, setLastUpdatedAt] = useState<number | null>(null);
  const [commandPeriod, setCommandPeriod] = useState('This month');
  const refreshInProgress = useRef(false);

  const canWriteFinance = user.permissions.includes('finance.write');
  const canWriteOperations = user.permissions.includes('operations.write');
  const permissions = new Set(user.permissions);
  const canReadDocuments = permissions.has('documents.read') || permissions.has('documents.upload');
  const permitted = permissionForModule[module].some((code) => permissions.has(code));

  const load = useCallback(async (silent = false) => {
    if (refreshInProgress.current) return;
    refreshInProgress.current = true;
    if (!silent) {
      setLoading(true);
      setError('');
    } else {
      setSyncStatus('syncing');
    }

    try {
      let nextData: DataMap;
      if (module === 'command') {
        const range = reportingPeriodRange(commandPeriod);
        const [finance, operations, risk, trend, previousTrend, decisions, forecast, sales] = await Promise.all([
          permissions.has('finance.read') ? moduleApi.financeSummary() : Promise.resolve(null),
          permissions.has('operations.read') ? moduleApi.operationsSummary() : Promise.resolve(null),
          permissions.has('risks.read') ? moduleApi.riskAlerts() : Promise.resolve(null),
          permissions.has('reports.read')
            ? moduleApi.financialReport(range.from, range.to)
            : Promise.resolve(null),
          permissions.has('reports.read')
            ? moduleApi.financialReport(range.previousFrom, range.previousTo)
            : Promise.resolve(null),
          permissions.has('decisions.manage') ? moduleApi.decisionScenarios() : Promise.resolve(null),
          permissions.has('operations.read') ? moduleApi.forecastSummary() : Promise.resolve(null),
          permissions.has('finance.read') ? moduleApi.sales(100, 0) : Promise.resolve(null),
        ]);
        nextData = { finance, operations, risk, trend, previousTrend, sales, decisions, forecast };
      } else if (module === 'finance') {
        const [finance, sales, expenses, products, invoices, customers, trend] = await Promise.all([
          moduleApi.financeSummary(),
          moduleApi.sales(),
          moduleApi.expenses(),
          moduleApi.products(),
          moduleApi.invoices(),
          moduleApi.customers(),
          permissions.has('reports.read')
            ? moduleApi.financialReport(dateInput(29), dateInput(0))
            : Promise.resolve(null),
        ]);
        nextData = { finance, sales, expenses, products, invoices, customers, trend };
      } else if (module === 'operations') {
        const [operations, products, suppliers, purchaseOrders] = await Promise.all([
          moduleApi.operationsSummary(),
          moduleApi.products(),
          moduleApi.suppliers(),
          moduleApi.purchaseOrders(),
        ]);
        nextData = { operations, products, suppliers, purchaseOrders };
      } else if (module === 'forecast') {
        nextData = { forecast: await moduleApi.forecastSummary() };
      } else if (module === 'risk') {
        nextData = { risk: await moduleApi.riskAlerts() };
      } else if (module === 'decisions') {
        nextData = { decisions: await moduleApi.decisionScenarios() };
      } else if (module === 'reports') {
        const [summary, financial] = await Promise.all([
          moduleApi.reportsSummary(),
          moduleApi.financialReport(reportFrom, reportTo),
        ]);
        nextData = { reports: summary };
        setReport(financial);
      } else if (module === 'connect') {
        nextData = { imports: await moduleApi.importsHistory() };
      } else if (module === 'design-system') {
        nextData = {};
      } else if (controlSection === 'overview') {
        const [overview, auditLogs, finance, operations, products, risk, trend, documents] = await Promise.all([
          moduleApi.adminOverview(),
          moduleApi.adminAuditLogs(),
          permissions.has('finance.read') ? moduleApi.financeSummary() : Promise.resolve(null),
          permissions.has('operations.read') ? moduleApi.operationsSummary() : Promise.resolve(null),
          permissions.has('operations.read') ? moduleApi.products() : Promise.resolve(null),
          permissions.has('risks.read') ? moduleApi.riskAlerts() : Promise.resolve(null),
          permissions.has('reports.read')
            ? moduleApi.financialReport(dateInput(59), dateInput(0))
            : Promise.resolve(null),
          canReadDocuments ? moduleApi.documents() : Promise.resolve(null),
        ]);
        nextData = {
          overview,
          auditLogs,
          controlFinance: finance,
          controlOperations: operations,
          controlProducts: products,
          controlRisk: risk,
          controlTrend: trend,
          controlDocuments: documents,
        };
      } else {
        const [overview, usersList, branchesList, departmentsList, rolesList, auditLogs] = await Promise.all([
          moduleApi.adminOverview(),
          moduleApi.adminUsers(),
          moduleApi.adminBranches(),
          moduleApi.adminDepartments(),
          moduleApi.adminRoles(),
          moduleApi.adminAuditLogs(),
        ]);
        nextData = {
          overview,
          users: usersList,
          branches: branchesList,
          departments: departmentsList,
          roles: rolesList,
          auditLogs,
        };
      }

      setData(nextData);
      setLastUpdatedAt(Date.now());
      setSyncStatus('current');
      if (silent) setError('');
    } catch (requestError) {
      setSyncStatus('interrupted');
      if (!silent) {
        setError(getErrorMessage(requestError));
        setData({});
      }
    } finally {
      if (!silent) setLoading(false);
      refreshInProgress.current = false;
    }
  }, [canReadDocuments, commandPeriod, controlSection, module, reportFrom, reportTo, user.id, user.permissions]);

  useEffect(() => {
    setNotice('');
    setError('');
    setSelectedScenario(null);
    setImportPreview(null);
    setLastUpdatedAt(null);
    setSyncStatus('syncing');
  }, [module]);

  useEffect(() => {
    if (permitted) void load();
    else {
      setLoading(false);
      setData({});
    }
  }, [load, permitted]);

  useEffect(() => {
    if (!permitted || module === 'design-system') return undefined;
    const refresh = () => {
      if (document.visibilityState === 'visible') {
        void load(true);
      } else {
        setSyncStatus('paused');
      }
    };
    const interval = window.setInterval(refresh, 30_000);
    document.addEventListener('visibilitychange', refresh);
    return () => {
      window.clearInterval(interval);
      document.removeEventListener('visibilitychange', refresh);
    };
  }, [load, module, permitted]);

  useEffect(() => {
    if (!permitted || module === 'design-system') return undefined;
    const onBusinessChange = (event: Event) => {
      const detail = (event as CustomEvent<{ modules?: unknown }>).detail;
      if (!Array.isArray(detail?.modules)) return;
      const affected = detail.modules.includes(module)
        || (detail.modules.includes('overview')
          && (module === 'command' || (module === 'control' && controlSection === 'overview')));
      if (affected && document.visibilityState === 'visible') void load(true);
    };
    window.addEventListener('vantiq:business-change', onBusinessChange);
    return () => window.removeEventListener('vantiq:business-change', onBusinessChange);
  }, [controlSection, load, module, permitted]);

  async function submit(
    event: FormEvent<HTMLFormElement>,
    action: (values: FormData) => Promise<void>,
  ) {
    event.preventDefault();
    setBusy(true);
    setError('');
    setNotice('');
    try {
      await action(new FormData(event.currentTarget));
      await load();
    } catch (requestError) {
      setError(getErrorMessage(requestError));
    } finally {
      setBusy(false);
    }
  }

  async function transitionPurchaseOrder(orderId: number, transition: 'submit' | 'receive') {
    setBusy(true);
    setError('');
    setNotice('');
    try {
      if (transition === 'submit') {
        await moduleApi.submitPurchaseOrder(orderId);
        setNotice(`Purchase order ${orderId} submitted.`);
      } else {
        await moduleApi.receivePurchaseOrder(orderId);
        setNotice(`Purchase order ${orderId} received and inventory updated.`);
      }
      await load();
    } catch (requestError) {
      setError(getErrorMessage(requestError));
    } finally {
      setBusy(false);
    }
  }

  if (module === 'design-system') {
    return <DesignSystemPreview />;
  }
  if (!permitted) {
    return <article className="panel-surface empty-state">This module is not available for your assigned permissions.</article>;
  }
  if (loading && module !== 'command') return <article className="panel-surface loading-state" role="status">Loading organisational data…</article>;

  const finance = objectValue(data.finance);
  const financeSummary = objectValue(finance.summary);
  const operations = objectValue(data.operations);
  const risk = objectValue(data.risk);
  const search = searchQuery.trim().toLocaleLowerCase();
  const matches = (values: Array<string | number | null | undefined>) =>
    search === '' || values.some((value) => String(value ?? '').toLocaleLowerCase().includes(search));
  const products = arrayValue<ProductRecord>(objectValue(data.products).products)
    .filter((product) => matches([product.name, product.sku, product.category_name]));
  const sales = arrayValue<SaleRecord>(objectValue(data.sales).sales)
    .filter((sale) => matches([sale.id, sale.customer_name, sale.invoice_number, sale.status]));
  const expenses = arrayValue<ExpenseRecord>(objectValue(data.expenses).expenses)
    .filter((expense) => matches([expense.description, expense.category_name, expense.branch_name]));
  const invoices = arrayValue<InvoiceRecord>(objectValue(data.invoices).invoices)
    .filter((invoice) => matches([invoice.invoice_number, invoice.customer_name, invoice.status]));
  const customers = arrayValue<CustomerRecord>(objectValue(data.customers).customers)
    .filter((customer) => matches([customer.name, customer.email, customer.status]));
  const suppliers = arrayValue<SupplierRecord>(objectValue(data.suppliers).suppliers)
    .filter((supplier) => matches([supplier.name, supplier.contact_email, supplier.status]));
  const purchaseOrders = arrayValue<PurchaseOrderRecord>(objectValue(data.purchaseOrders).purchase_orders)
    .filter((order) => matches([order.id, order.supplier_name, order.status]));
  const riskAlerts = arrayValue<DataMap>(risk.alerts)
    .filter((alert) => matches([alert.category as string, alert.explanation as string, alert.severity as string]));
  return (
    <div key={module} className={`module-content module-content--${module}`}>
      {notice && <p className="notice" role="status">{notice}</p>}
      {error && <p className="error" role="alert">{error}</p>}
      {syncStatus === 'interrupted' && lastUpdatedAt !== null && <p className="auto-refresh-warning" role="status">
        Automatic refresh was interrupted. Showing the last successful update from {new Date(lastUpdatedAt).toLocaleTimeString()}.
      </p>}
      {module === 'command' && (
        <Suspense fallback={<div className="statistics-empty" role="status">Loading Command Overview…</div>}>
        <LazyCinematicDashboard
          data={loading && Object.keys(data).length === 0
            ? null
            : adaptCinematicCommandOverview(adaptCommandOverview({
              finance,
              operations,
              risk,
              trend: data.trend as FinancialReport | null,
              previousTrend: data.previousTrend as FinancialReport | null,
              sales: data.sales as { sales: Array<{ sale_date: string; total: number; status: string }> } | null,
              period: commandPeriod,
              lastUpdatedAt,
            }))}
          loading={loading}
          error={error || null}
          selectedPeriod={commandPeriod}
          onPeriodChange={(period) => setCommandPeriod(period)}
          onRefresh={() => load(true)}
          onOpenOperations={() => navigate('/operations')}
          onOpenInsights={() => navigate('/risk')}
          onOpenReports={() => navigate('/reports')}
          onOpenAlert={() => navigate('/risk')}
          organisationName={user.organisation?.name ?? 'Organisation'}
        />
        </Suspense>
      )}

      {module === 'finance' && (
        <>
          <DashboardBentoHighlights
            module="finance"
            finance={financeSummary}
            report={data.trend as FinancialReport | null}
          />
          <Suspense fallback={<div className="statistics-empty" role="status">Loading financial analysis…</div>}>
            <LazyStatisticalInsights
              revenueByDay={objectValue(data.trend).revenue_by_day}
              expensesByDay={objectValue(data.trend).expenses_by_day}
              allowed={permissions.has('reports.read')}
            />
          </Suspense>
          {canWriteFinance && (
            <div className="content-grid two-col">
              <article className="panel-surface">
                <div className="panel-header"><h3>Post sale</h3><span>Stock checked and deducted atomically</span></div>
                <form id="invoice-form" onSubmit={(event) => submit(event, async (values) => {
                  const productId = Number(values.get('product_id'));
                  const quantity = Number(values.get('quantity'));
                  const result = await moduleApi.createSale({ items: [{ product_id: productId, quantity }] });
                  setNotice(`Sale ${result.id} posted. Total: ${money(result.total)}.`);
                })}>
                  <label>Product<select name="product_id" required defaultValue="">
                    <option value="" disabled>Select a product</option>
                    {products.map((product) => <option value={product.id} key={product.id}>
                      {product.name} · {product.sku} · stock {product.stock_level}
                    </option>)}
                  </select></label>
                  <label>Quantity<input name="quantity" type="number" min="1" step="1" required /></label>
                  <button className="primary-button" disabled={busy || products.length === 0}>
                    {busy ? 'Posting…' : 'Post sale'}
                  </button>
                </form>
              </article>
              <article className="panel-surface">
                <div className="panel-header"><h3>Record expense</h3><span>Actual expense entry</span></div>
                <form onSubmit={(event) => submit(event, async (values) => {
                  const result = await moduleApi.createExpense({
                    amount: String(values.get('amount')),
                    description: String(values.get('description')),
                  });
                  setNotice(`Expense ${result.id} recorded.`);
                })}>
                  <label>Amount<input name="amount" type="number" min="0.01" step="0.01" required /></label>
                  <label>Description<input name="description" maxLength={255} required /></label>
                  <button className="primary-button" disabled={busy}>{busy ? 'Saving…' : 'Record expense'}</button>
                </form>
              </article>
            </div>
          )}
          {canWriteFinance && <div className="content-grid two-col">
            <article className="panel-surface">
              <div className="panel-header"><h3>Add customer</h3><span>Organisation-scoped customer record</span></div>
              <form onSubmit={(event) => submit(event, async (values) => {
                const customer = await moduleApi.createCustomer({
                  name: String(values.get('name')),
                  email: String(values.get('email') || '') || null,
                });
                setNotice(`Customer ${customer.name} created.`);
              })}>
                <label>Name<input name="name" maxLength={180} required /></label>
                <label>Email<input name="email" type="email" maxLength={254} /></label>
                <button className="primary-button" disabled={busy}>{busy ? 'Saving…' : 'Add customer'}</button>
              </form>
            </article>
            <article className="panel-surface">
              <div className="panel-header"><h3>Issue invoice</h3><span>Product prices are resolved by the server</span></div>
              <form onSubmit={(event) => submit(event, async (values) => {
                const result = await moduleApi.createInvoice({
                  customer_id: Number(values.get('customer_id')),
                  due_date: String(values.get('due_date')),
                  items: [{
                    product_id: Number(values.get('product_id')),
                    quantity: Number(values.get('quantity')),
                  }],
                });
                setNotice(`Invoice ${result.invoice_number} issued for ${money(result.total)}.`);
              })}>
                <label>Customer<select name="customer_id" required defaultValue="">
                  <option value="" disabled>Select a customer</option>
                  {customers.map((customer) => <option value={customer.id} key={customer.id}>{customer.name}</option>)}
                </select></label>
                <label>Product<select name="product_id" required defaultValue="">
                  <option value="" disabled>Select a product</option>
                  {products.map((product) => <option value={product.id} key={product.id}>{product.name} · {product.sku}</option>)}
                </select></label>
                <label>Quantity<input name="quantity" type="number" min="1" step="1" required /></label>
                <label>Due date<input name="due_date" type="date" min={dateInput(0)} required /></label>
                <button className="primary-button" disabled={busy || products.length === 0 || customers.length === 0}>
                  {busy ? 'Issuing…' : 'Issue invoice'}
                </button>
              </form>
            </article>
          </div>}
          {canWriteFinance && <article className="panel-surface">
            <div className="panel-header"><h3>Record invoice payment</h3><span>Receivable balance is checked transactionally</span></div>
            <form className="inline-form" onSubmit={(event) => submit(event, async (values) => {
              const invoiceId = Number(values.get('invoice_id'));
              const result = await moduleApi.recordInvoicePayment(invoiceId, {
                amount: String(values.get('amount')),
                method: String(values.get('method')) as 'cash' | 'card' | 'bank_transfer' | 'cheque' | 'other',
              });
              setNotice(`Payment recorded. Remaining balance: ${money(result.balance_due)}.`);
            })}>
              <label>Invoice<select name="invoice_id" required defaultValue="">
                <option value="" disabled>Select an open invoice</option>
                {invoices.filter((invoice) => Number(invoice.balance_due) > 0).map((invoice) => (
                  <option value={invoice.id} key={invoice.id}>{invoice.invoice_number} · {invoice.customer_name} · due {money(invoice.balance_due)}</option>
                ))}
              </select></label>
              <label>Amount<input name="amount" type="number" min="0.01" step="0.01" required /></label>
              <label>Method<select name="method"><option value="bank_transfer">Bank transfer</option><option value="cash">Cash</option><option value="card">Card</option><option value="cheque">Cheque</option><option value="other">Other</option></select></label>
              <button className="primary-button" disabled={busy || invoices.every((invoice) => Number(invoice.balance_due) <= 0)}>
                {busy ? 'Saving…' : 'Record payment'}
              </button>
            </form>
          </article>}
          <div className="content-grid two-col">
            <article className="panel-surface">
              <div className="panel-header"><h3>Sales ledger</h3><span>Latest 50 records</span></div>
              <SalesLedger sales={sales} canRefund={canWriteFinance} busy={busy} onRefund={(event, saleId) => submit(event, async (values) => {
                await moduleApi.refundSale(saleId, String(values.get('reason')));
                setNotice(`Sale ${saleId} refunded and stock returned.`);
              })} />
            </article>
            <article className="panel-surface">
              <div className="panel-header"><h3>Expense ledger</h3><span>Latest 50 records</span></div>
              <DataTable headers={['Date', 'Description', 'Category', 'Amount']} searchQuery={searchQuery}
                rows={expenses.map((expense) => [expense.expense_date, expense.description, expense.category_name ?? 'Unclassified', money(expense.amount)])}
                empty="No expenses have been recorded." />
            </article>
          </div>
          <article className="panel-surface">
            <div className="panel-header"><h3>Customer invoices</h3><span>Issued and settled receivables</span></div>
            <DataTable headers={['Invoice', 'Customer', 'Issue date', 'Due date', 'Total', 'Paid', 'Balance', 'Status']} searchQuery={searchQuery}
              rows={invoices.map((invoice) => [
                invoice.invoice_number, invoice.customer_name, invoice.issue_date, invoice.due_date,
                money(invoice.total), money(invoice.paid_amount), money(invoice.balance_due), invoice.status,
              ])}
              empty="No invoices have been issued." />
          </article>
        </>
      )}

      {module === 'operations' && (
        <>
          <DashboardBentoHighlights
            module="operations"
            operations={operations}
            purchaseOrders={purchaseOrders}
          />
          {canWriteOperations && <div className="content-grid two-col">
            <article className="panel-surface">
              <div className="panel-header"><h3>Create product</h3><span>New product starts with zero stock</span></div>
              <form onSubmit={(event) => submit(event, async (values) => {
                const result = await moduleApi.createProduct({
                  name: String(values.get('name')),
                  sku: String(values.get('sku')),
                  unit_cost: String(values.get('unit_cost')),
                  selling_price: String(values.get('selling_price')),
                  reorder_level: Number(values.get('reorder_level')),
                });
                setNotice(`Product ${result.id} created. Record a receipt to add stock.`);
              })}>
                <label>Name<input name="name" maxLength={180} required /></label>
                <label>SKU<input name="sku" maxLength={80} required /></label>
                <label>Unit cost<input name="unit_cost" type="number" min="0" step="0.01" required /></label>
                <label>Selling price<input name="selling_price" type="number" min="0" step="0.01" required /></label>
                <label>Reorder point<input name="reorder_level" type="number" min="0" step="1" required /></label>
                <button className="primary-button" disabled={busy}>{busy ? 'Saving…' : 'Create product'}</button>
              </form>
            </article>
            <article className="panel-surface">
              <div className="panel-header"><h3>Record stock movement</h3><span>Every balance change is journalled</span></div>
              <form onSubmit={(event) => submit(event, async (values) => {
                const productId = Number(values.get('product_id'));
                const result = await moduleApi.recordStockMovement(productId, {
                  movement_type: String(values.get('movement_type')) as 'receipt' | 'adjustment' | 'return',
                  quantity: Number(values.get('quantity')),
                  notes: String(values.get('notes') ?? ''),
                });
                setNotice(`Movement recorded. New stock balance: ${result.stock_level}.`);
              })}>
                <label>Product<select name="product_id" required defaultValue="">
                  <option value="" disabled>Select a product</option>
                  {products.map((product) => <option key={product.id} value={product.id}>{product.name} · {product.sku}</option>)}
                </select></label>
                <label>Movement<select name="movement_type" required>
                  <option value="receipt">Receipt</option><option value="adjustment">Adjustment</option><option value="return">Return</option>
                </select></label>
                <label>Quantity<input name="quantity" type="number" step="1" required /></label>
                <label>Notes<input name="notes" maxLength={255} /></label>
                <button className="primary-button" disabled={busy || products.length === 0}>{busy ? 'Saving…' : 'Record movement'}</button>
              </form>
            </article>
          </div>}
          <article className="panel-surface">
            <div className="panel-header"><h3>Product inventory</h3><span>Database balances</span></div>
            <DataTable headers={['Product', 'SKU', 'Cost', 'Price', 'Stock', 'Reorder level']} searchQuery={searchQuery}
              rows={products.map((product) => [
                product.name, product.sku, money(product.unit_cost), money(product.selling_price),
                String(product.stock_level), String(product.reorder_level),
              ])}
              empty="No products exist yet. Create a product to begin." />
          </article>
          {canWriteOperations && <div className="content-grid two-col">
            <article className="panel-surface">
              <div className="panel-header"><h3>Register supplier</h3><span>Organisation-scoped supplier directory</span></div>
              <form onSubmit={(event) => submit(event, async (values) => {
                const supplier = await moduleApi.createSupplier({
                  name: String(values.get('name')),
                  contact_email: String(values.get('contact_email') || '') || null,
                });
                setNotice(`Supplier ${supplier.name} created.`);
              })}>
                <label>Name<input name="name" maxLength={180} required /></label>
                <label>Contact email<input name="contact_email" type="email" maxLength={254} /></label>
                <button className="primary-button" disabled={busy}>{busy ? 'Saving…' : 'Add supplier'}</button>
              </form>
              <DataTable headers={['Supplier', 'Email', 'Status']} searchQuery={searchQuery}
                rows={suppliers.map((supplier) => [supplier.name, supplier.contact_email ?? '—', supplier.status])}
                empty="No suppliers have been registered." />
            </article>
            <article className="panel-surface">
              <div className="panel-header"><h3>Create purchase order</h3><span>Draft orders do not change stock</span></div>
              <form onSubmit={(event) => submit(event, async (values) => {
                const result = await moduleApi.createPurchaseOrder({
                  supplier_id: Number(values.get('supplier_id')),
                  expected_date: String(values.get('expected_date') || '') || null,
                  items: [{
                    product_id: Number(values.get('product_id')),
                    quantity: Number(values.get('quantity')),
                    unit_cost: String(values.get('unit_cost')),
                  }],
                });
                setNotice(`Purchase order ${result.id} created as a draft.`);
              })}>
                <label>Supplier<select name="supplier_id" required defaultValue="">
                  <option value="" disabled>Select a supplier</option>
                  {suppliers.filter((supplier) => supplier.status === 'active').map((supplier) => (
                    <option value={supplier.id} key={supplier.id}>{supplier.name}</option>
                  ))}
                </select></label>
                <label>Product<select name="product_id" required defaultValue="">
                  <option value="" disabled>Select a product</option>
                  {products.map((product) => <option value={product.id} key={product.id}>{product.name} · {product.sku}</option>)}
                </select></label>
                <label>Quantity<input name="quantity" type="number" min="1" step="1" required /></label>
                <label>Quoted unit cost<input name="unit_cost" type="number" min="0" step="0.01" required /></label>
                <label>Expected date<input name="expected_date" type="date" min={dateInput(0)} /></label>
                <button className="primary-button" disabled={busy || suppliers.length === 0 || products.length === 0}>
                  {busy ? 'Saving…' : 'Create draft order'}
                </button>
              </form>
            </article>
          </div>}
          <article className="panel-surface">
            <div className="panel-header"><h3>Procurement</h3><span>Only received orders update inventory</span></div>
            {purchaseOrders.length === 0 ? <p className="empty-state">{searchQuery ? 'No purchase orders match this search.' : 'No purchase orders have been created.'}</p> : (
              <div className="table-scroll"><table className="data-table">
                <thead><tr><th>Order</th><th>Supplier</th><th>Order date</th><th>Expected</th><th>Lines</th><th>Total</th><th>Status</th>{canWriteOperations && <th>Action</th>}</tr></thead>
                <tbody>{purchaseOrders.map((order) => <tr key={order.id}>
                  <td>PO-{order.id}</td><td>{order.supplier_name}</td><td>{order.order_date}</td>
                  <td>{order.expected_date ?? '—'}</td><td>{order.item_count}</td><td>{money(order.total)}</td><td>{order.status}</td>
                  {canWriteOperations && <td>
                    {order.status === 'draft' && <button type="button" className="ghost-button" disabled={busy} onClick={() => void transitionPurchaseOrder(order.id, 'submit')}>Submit</button>}
                    {order.status === 'submitted' && <button type="button" className="primary-button" disabled={busy} onClick={() => void transitionPurchaseOrder(order.id, 'receive')}>Receive</button>}
                    {order.status !== 'draft' && order.status !== 'submitted' && '—'}
                  </td>}
                </tr>)}</tbody>
              </table></div>
            )}
          </article>
        </>
      )}

      {module === 'reports' && <DashboardBentoHighlights module="reports" report={report} />}
      {module === 'reports' && <article className="panel-surface">
        <div className="panel-header"><h3>Financial activity report</h3><span>Actual posted sales and recorded expenses</span></div>
        <form className="inline-form" onSubmit={(event) => {
          event.preventDefault();
          void load();
        }}>
          <label>From<input type="date" value={reportFrom} onChange={(event) => setReportFrom(event.target.value)} required /></label>
          <label>To<input type="date" value={reportTo} onChange={(event) => setReportTo(event.target.value)} required /></label>
          <button className="primary-button" disabled={loading}>Run report</button>
          <button type="button" className="ghost-button" disabled={!report} onClick={() => report && exportFinancialCsv(report)}>Export CSV</button>
        </form>
        {report && <div className="content-grid two-col">
          <div><h4>Sales by day</h4><DataTable headers={['Date', 'Sales', 'Revenue']} searchQuery={searchQuery}
            rows={report.revenue_by_day.map((row) => [row.period, String(row.sale_count), money(row.revenue)])}
            empty="No sales in the selected range." /></div>
          <div><h4>Expenses by day</h4><DataTable headers={['Date', 'Entries', 'Expenses']} searchQuery={searchQuery}
            rows={report.expenses_by_day.map((row) => [row.period, String(row.expense_count), money(row.expenses)])}
            empty="No expenses in the selected range." /></div>
          <div><h4>Refunds by day</h4><DataTable headers={['Date', 'Refunds', 'Amount']} searchQuery={searchQuery}
            rows={report.refunds_by_day.map((row) => [row.period, String(row.refund_count), money(row.refunds)])}
            empty="No refunds in the selected range." /></div>
        </div>}
      </article>}

      {module === 'forecast' && <>
        <DashboardBentoHighlights module="forecast" forecast={objectValue(data.forecast)} />
        <ForecastPanel forecast={objectValue(data.forecast)} searchQuery={searchQuery} />
      </>}

      {module === 'risk' && <DashboardBentoHighlights module="risk" riskAlerts={riskAlerts} />}
      {module === 'risk' && <RiskWorkflow
        alerts={riskAlerts}
        busy={busy}
        onUpdate={async (id, status, notes) => {
          setBusy(true);
          setError('');
          try {
            await moduleApi.updateRiskAlertStatus(id, status, null, notes);
            setNotice('Risk alert updated.');
            await load();
          } catch (requestError) {
            setError(getErrorMessage(requestError));
          } finally {
            setBusy(false);
          }
        }}
      />}

      {module === 'decisions' && <DashboardBentoHighlights
        module="decisions"
        scenarios={arrayValue<DataMap>(objectValue(data.decisions).scenarios)}
      />}
      {module === 'decisions' && <DecisionWorkflow
        data={objectValue(data.decisions)}
        searchQuery={searchQuery}
        busy={busy}
        selectedScenario={selectedScenario}
        onSelect={async (id) => {
          setError('');
          try {
            setSelectedScenario(await moduleApi.decisionScenarioDetail(id));
          } catch (requestError) {
            setError(getErrorMessage(requestError));
          }
        }}
        onCreate={(event) => submit(event, async (values) => {
          await moduleApi.createDecisionScenario({
            name: String(values.get('name')),
            description: String(values.get('description') ?? ''),
            assumptions: {
              revenue_growth: Number(values.get('revenue_growth')),
              price_increase: Number(values.get('price_increase')),
              cost_reduction: Number(values.get('cost_reduction')),
            },
          });
          setNotice('Scenario saved with calculated results.');
        })}
      />}

      {module === 'connect' && <DashboardBentoHighlights
        module="connect"
        imports={arrayValue<DataMap>(objectValue(data.imports).imports)}
      />}
      {module === 'connect' && <ConnectWorkflow
        data={objectValue(data.imports)}
        searchQuery={searchQuery}
        busy={busy}
        preview={importPreview}
        onPreview={(event) => submit(event, async (values) => {
          const file = values.get('file');
          if (!(file instanceof File) || file.size === 0 || file.size > 48 * 1024 || !file.name.toLowerCase().endsWith('.csv')) {
            throw new Error('Choose a non-empty CSV file no larger than 48 KB.');
          }
          const csv = await file.text();
          setImportPreview(await moduleApi.previewImport(file.name, csv));
          setNotice('CSV preview received. No business records have been imported.');
        })}
      />}

      {module === 'control' && <ControlPanel
        data={data}
        user={user}
        section={controlSection}
        busy={busy}
        lastUpdatedAt={lastUpdatedAt}
        syncStatus={syncStatus}
        onRefresh={() => { void load(true); }}
        onCreateBranch={(event) => submit(event, async (values) => {
          await moduleApi.createBranch(String(values.get('name')), String(values.get('code')));
          setNotice('Branch created.');
        })}
        onCreateDepartment={(event) => submit(event, async (values) => {
          const branch = String(values.get('branch_id') ?? '');
          await moduleApi.createDepartment(
            String(values.get('name')),
            String(values.get('code')),
            branch === '' ? null : Number(branch),
          );
          setNotice('Department created.');
        })}
        onUpdateRoles={(event, userId) => submit(event, async (values) => {
          if (!window.confirm('Replace this user’s organisation roles? This may change their access immediately.')) return;
          const roleIds = values.getAll('role_ids').map(Number).filter(Number.isInteger);
          await moduleApi.updateAccountRoles(userId, roleIds);
          setNotice('User roles updated.');
        })}
        onUpdateStatus={(event, userId) => submit(event, async (values) => {
          const status = String(values.get('status')) as 'active' | 'inactive' | 'suspended';
          if (!window.confirm(`Set this account to ${status}? Existing sessions may be revoked.`)) return;
          await moduleApi.updateAccountStatus(userId, status);
          setNotice('User status updated.');
        })}
      />}
    </div>
  );
}

export function CommandDashboard({
  finance,
  financeSummary,
  operations,
  risk,
  trend,
  decisions,
  forecast,
  permissions,
  searchQuery,
}: {
  finance: DataMap;
  financeSummary: DataMap;
  operations: DataMap;
  risk: DataMap;
  trend: DataMap;
  decisions: DataMap;
  forecast: DataMap;
  permissions: Set<string>;
  searchQuery: string;
}) {
  const [setupState, setSetupState] = useState({ imported: false, document: false, integration: false });
  const [setupError, setSetupError] = useState('');
  useEffect(() => {
    let active = true;
    Promise.all([
      permissions.has('data.import') || permissions.has('connect.manage') || permissions.has('finance.write')
        ? moduleApi.dataImportHistory().then(({ imports }) => imports.some((item) => item.status === 'committed'))
        : Promise.resolve(false),
      permissions.has('documents.read') || permissions.has('documents.upload')
        ? moduleApi.documents().then(({ documents }) => documents.length > 0)
        : Promise.resolve(false),
      permissions.has('integrations.manage')
        ? moduleApi.integrations().then(({ integrations }) => integrations.some((item) => item.status === 'active'))
        : Promise.resolve(false),
    ]).then(([imported, document, integration]) => {
      if (active) setSetupState({ imported, document, integration });
    }).catch((requestError: unknown) => {
      if (active) setSetupError(getErrorMessage(requestError));
    });
    return () => { active = false; };
  }, []);
  const alerts = arrayValue<DataMap>(risk.alerts);
  const activeAlerts = alerts.filter((alert) => !['resolved', 'dismissed'].includes(String(alert.status)));
  const lowStock = arrayValue<DataMap>(operations.lowStock);
  const stockHealth = objectValue(operations.stockHealth);
  const lowStockCount = Number(stockHealth.lowStockCount);
  const branches = arrayValue<{ label: string; value: number }>(finance.branchBreakdown);
  const revenueByDay = arrayValue<{ period: string; revenue: number }>(trend.revenue_by_day);
  const expensesByDay = arrayValue<{ period: string; expenses: number }>(trend.expenses_by_day);
  const grossMargin = finiteValue(financeSummary.grossMargin);
  const operatingMargin = finiteValue(financeSummary.operatingMargin);
  const hasFinance = permissions.has('finance.read');
  const hasOperations = permissions.has('operations.read');
  const hasRisks = permissions.has('risks.read');
  const hasReports = permissions.has('reports.read');
  const activity = arrayValue<DataMap>(finance.recentActivity);
  const decisionScenarios = arrayValue<DataMap>(decisions.scenarios);

  const metrics: Array<{
    label: string;
    value: number | null;
    context: string;
    icon: WorkspaceModuleKey;
    percentage?: number | null;
    sparkline?: Array<{ period: string; value: number }>;
  }> = [];
  if (hasFinance) {
    metrics.push(
      {
        label: 'Net revenue',
        value: finiteValue(financeSummary.totalRevenue),
        context: 'Posted sales, net of credits',
        icon: 'finance',
        sparkline: revenueByDay.map((item) => ({ period: item.period, value: item.revenue })),
      },
      { label: 'Gross profit', value: finiteValue(financeSummary.grossProfit), context: 'Gross margin', icon: 'reports', percentage: grossMargin },
      { label: 'Operating profit', value: finiteValue(financeSummary.operatingProfit), context: 'Operating margin', icon: 'forecast', percentage: operatingMargin },
    );
  }

  const periodRows = revenueByDay.map((row) => ({
    period: row.period,
    revenue: Number(row.revenue) || 0,
    expenses: Number(expensesByDay.find((item) => item.period === row.period)?.expenses ?? 0),
  }));
  const maxPeriodValue = Math.max(0, ...periodRows.flatMap((row) => [row.revenue, row.expenses]));
  const branchMaximum = Math.max(0, ...branches.map((branch) => Number(branch.value) || 0));
  const searchable = (values: Array<string | number | undefined>) => !searchQuery.trim()
    || values.some((value) => String(value ?? '').toLocaleLowerCase().includes(searchQuery.trim().toLocaleLowerCase()));
  const visibleAlerts = activeAlerts.filter((alert) => searchable([
    alert.category as string, alert.explanation as string, alert.severity as string,
  ]));
  const visibleActivities = activity.filter((item) => searchable([
    item.summary as string, item.type as string, item.entity as string, item.created_at as string,
  ]));

  return (
    <div className="command-dashboard">
      <section className="command-data-setup" aria-labelledby="data-setup-title">
        <div className="command-data-setup-heading"><div><span className="section-eyebrow">GET STARTED</span><h2 id="data-setup-title">Data setup checklist</h2></div>
          <span>Completion is based on your organisation’s saved records</span></div>
        {setupError && <p className="data-page-error" role="alert">{setupError}</p>}
        <div className="command-data-actions">
          {(permissions.has('data.import') || permissions.has('connect.manage') || permissions.has('finance.write')) && <Link to="/data/import" className={`command-data-action${setupState.imported ? ' complete' : ''}`}>
            <span className="command-data-action-state">{setupState.imported ? '✓' : '1'}</span><span><strong>Import financial data</strong><small>{setupState.imported ? 'A data batch has been committed' : 'Upload and validate a data file'}</small></span><span aria-hidden="true">→</span>
          </Link>}
          {(permissions.has('documents.read') || permissions.has('documents.upload')) && <Link to="/data/documents" className={`command-data-action${setupState.document ? ' complete' : ''}`}>
            <span className="command-data-action-state">{setupState.document ? '✓' : '2'}</span><span><strong>Upload documents</strong><small>{setupState.document ? 'A document is in your vault' : 'Add a statement or supporting file'}</small></span><span aria-hidden="true">→</span>
          </Link>}
          {(permissions.has('integrations.manage') || permissions.has('platform.users.manage')) && <Link to="/data/integrations" className={`command-data-action${setupState.integration ? ' complete' : ''}`}>
            <span className="command-data-action-state">{setupState.integration ? '✓' : '3'}</span><span><strong>Connect a system</strong><small>{setupState.integration ? 'An integration is active' : 'Configure an external data source'}</small></span><span aria-hidden="true">→</span>
          </Link>}
        </div>
      </section>
      {metrics.length > 0 && <section className="executive-metrics" aria-label="Financial snapshot">
        {metrics.map((metric) => <article className="executive-metric" key={metric.label}>
          <div className="executive-metric-top"><span>{metric.label}</span><DashboardIcon name={metric.icon} /></div>
          <strong><AnimatedMetric value={metric.value} formatter={money} /></strong>
          <small>{metric.percentage === undefined ? metric.context : metric.percentage === null
            ? `${metric.context} not available`
            : <>{metric.context} <AnimatedMetric value={metric.percentage} formatter={formatPercent} /></>}</small>
          <Sparkline observations={metric.sparkline ?? []} />
        </article>)}
      </section>}

      {metrics.length === 0 && <section className="command-onboarding">
        <span className="command-onboarding-icon"><DashboardIcon name="command" /></span>
        <div><h2>Your intelligence workspace is ready</h2>
          <p>Data and insights appear here when the relevant financial, operational, risk or reporting permissions are enabled.</p></div>
      </section>}

      <section className="command-main-grid">
        <article className="workspace-card command-trend-card">
          <div className="workspace-card-heading">
            <div><span className="section-eyebrow">PERFORMANCE</span><h2>Revenue &amp; expenditure</h2>
              <p>Daily totals · last 30 days · actual posted records</p></div>
            <span className="chart-period-label">30 DAYS</span>
          </div>
          {hasReports && maxPeriodValue > 0 && <div className="trend-chart-scroll"><TrendChart rows={periodRows} maxValue={maxPeriodValue} /></div>}
          {!hasReports && <p className="data-empty-inline">Report access is not available for your role.</p>}
          {hasReports && periodRows.length === 0 && <p className="data-empty-inline">No financial activity is recorded for this period.</p>}
          {hasReports && periodRows.length > 0 && maxPeriodValue === 0 && <p className="data-empty-inline">Financial records exist for this period, but there are no positive revenue or expenditure values to chart.</p>}
          {hasReports && periodRows.length > 0 && maxPeriodValue > 0 && <div className="chart-legend">
            <span><i className="legend-revenue" />Revenue</span><span><i className="legend-expenses" />Expenses</span>
          </div>}
        </article>

        <article className="workspace-card branch-performance-card">
          <div className="workspace-card-heading">
            <div><span className="section-eyebrow">ORGANISATION</span><h2>Branch performance</h2>
              <p>Net posted sales by branch</p></div>
            <DashboardIcon name="command" />
          </div>
          {hasFinance ? <BranchPerformance branches={branches} maximum={branchMaximum} searchQuery={searchQuery} />
            : <p className="data-empty-inline">Finance access is not available for your role.</p>}
        </article>
      </section>

      {hasReports && <Suspense fallback={<div className="statistics-empty" role="status">Loading statistical analysis…</div>}>
        <LazyStatisticalInsights
          revenueByDay={trend.revenue_by_day}
          expensesByDay={trend.expenses_by_day}
          allowed={hasReports}
        />
      </Suspense>}

      <section className="command-lower-grid">
        <article className="workspace-card">
          <div className="workspace-card-heading">
            <div><span className="section-eyebrow">RISK &amp; CONTROLS</span><h2>Management attention</h2>
              <p>Evidence-backed items requiring review</p></div>
            {hasRisks && <span className={`attention-count${activeAlerts.length ? ' has-items' : ''}`}>{activeAlerts.length}</span>}
          </div>
          {hasRisks ? <RiskList alerts={visibleAlerts.slice(0, 5)} />
            : <p className="data-empty-inline">Risk review is not available for your role.</p>}
        </article>
        <article className="workspace-card">
          <div className="workspace-card-heading">
            <div><span className="section-eyebrow">OPERATIONS</span><h2>Inventory watch</h2>
              <p>Inventory at cost: {money(operations.inventoryValue)} · products at or below reorder level</p></div>
            {hasOperations && <span className={`attention-count${lowStockCount > 0 ? ' has-items' : ''}`}>{Number.isFinite(lowStockCount) ? lowStockCount : '—'}</span>}
          </div>
          {hasOperations ? <div className="inventory-watch-list">
            {lowStock.filter((item) => searchable([item.name as string, item.sku as string])).slice(0, 5).map((item) => (
              <div className="inventory-watch-item" key={String(item.sku ?? item.name)}>
                <span className="inventory-watch-symbol"><DashboardIcon name="operations" /></span>
                <span><strong>{String(item.name ?? 'Product')}</strong><small>{String(item.sku ?? 'No SKU')} · reorder at {String(item.reorder_level ?? 0)}</small></span>
                <b>{String(item.stock_level ?? 0)}</b>
              </div>
            ))}
            {lowStock.length === 0 && <p className="data-empty-inline">No products currently meet the low-stock threshold.</p>}
            {lowStock.length > 0 && lowStockCount > lowStock.length && <p className="data-empty-inline">Showing the 5 most urgent of {lowStockCount} low-stock products.</p>}
          </div> : <p className="data-empty-inline">Operations access is not available for your role.</p>}
        </article>
        <article className="workspace-card">
          <div className="workspace-card-heading">
            <div><span className="section-eyebrow">ORGANISATIONAL ACTIVITY</span><h2>Recent activity</h2>
              <p>Latest audited business events</p></div>
            <DashboardIcon name="reports" />
          </div>
          {hasFinance ? <ActivityTimeline activities={visibleActivities.slice(0, 5)} />
            : <p className="data-empty-inline">Finance activity is not available for your role.</p>}
        </article>
        <article className="workspace-card decision-snapshot-card">
          <div className="workspace-card-heading">
            <div><span className="section-eyebrow">DECISION SUPPORT</span><h2>Scenario workspace</h2>
              <p>Saved management planning scenarios</p></div>
            {permissions.has('decisions.manage') && <span className="attention-count">{decisionScenarios.length}</span>}
          </div>
          {!permissions.has('decisions.manage') ? <p className="data-empty-inline">Decision scenarios are not available for your role.</p>
            : decisionScenarios.length === 0 ? <p className="data-empty-inline">No scenarios have been saved yet.</p>
              : <ul className="scenario-snapshot-list">{decisionScenarios
                .filter((scenario) => searchable([scenario.name as string, scenario.description as string, scenario.status as string]))
                .slice(0, 4).map((scenario) => (
                <li key={String(scenario.id)}><span><DashboardIcon name="decisions" /></span>
                  <div><strong>{String(scenario.name)}</strong><small>{String(scenario.status)} · {String(scenario.result_count ?? 0)} outcomes</small></div>
                </li>
              ))}</ul>}
        </article>
        <ForecastSnapshot forecast={forecast} permitted={hasOperations} />
      </section>
      <p className="command-data-note"><DashboardIcon name="shield" /> Metrics are calculated from the records available to your organisation. No comparative change is shown without a comparable prior period.</p>
    </div>
  );
}

function ForecastSnapshot({ forecast, permitted }: { forecast: DataMap; permitted: boolean }) {
  const results = arrayValue<DataMap>(forecast.results);
  const model = objectValue(forecast.forecast);
  const latest = results[0];
  return <article className="workspace-card forecast-snapshot-card">
    <div className="workspace-card-heading">
      <div><span className="section-eyebrow">PREDICTIVE INTELLIGENCE</span><h2>Forecast outlook</h2>
        <p>Model output, shown separately from actuals</p></div>
      <DashboardIcon name="forecast" />
    </div>
    {!permitted ? <p className="data-empty-inline">Forecast access is not available for your role.</p>
      : forecast.available !== true ? <p className="data-empty-inline">{String(forecast.message ?? 'Forecasting is unavailable with current data coverage.')}</p>
        : latest ? <div className="forecast-snapshot">
          <div><small>{String(latest.period_label ?? 'Forecast period')}</small><strong>{money(latest.projected_value)}</strong>
            <span>Projected value</span></div>
          {latest.actual_value !== null && latest.actual_value !== undefined && <div>
            <small>Same period</small><strong>{money(latest.actual_value)}</strong><span>Recorded actual</span>
          </div>}
          <p>{String(forecast.methodology ?? model.methodology ?? 'Forecast methodology not provided')}</p>
        </div> : <p className="data-empty-inline">A forecast model is available, but no forecast periods are recorded.</p>}
  </article>;
}

function TrendChart({
  rows,
  maxValue,
}: {
  rows: Array<{ period: string; revenue: number; expenses: number }>;
  maxValue: number;
}) {
  if (rows.length === 0 || maxValue <= 0) return null;
  const displayedRows = rows.slice(-30);
  return <div className="trend-chart" role="img" aria-label="Daily revenue and expenses for the last 30 days">
    {displayedRows.map((row, index) => {
      const revenueHeight = Math.max(3, (row.revenue / maxValue) * 100);
      const expenseHeight = Math.max(3, (row.expenses / maxValue) * 100);
      const motionStyle = {
        '--motion-delay': `${Math.min(index, 29) * MOTION_TIMING.trendPointStagger}ms`,
      } as CSSProperties & { '--motion-delay': string };
      return <div className="trend-day" key={row.period} style={motionStyle}
        title={`${row.period}: revenue ${money(row.revenue)}, expenses ${money(row.expenses)}`}>
        <div className="trend-bars">
          <span className="trend-bar-revenue" style={{ height: `${revenueHeight}%` }} />
          <span className="trend-bar-expenses" style={{ height: `${expenseHeight}%` }} />
        </div>
        <small>{index % 5 === 0 || index === displayedRows.length - 1 ? row.period.slice(5) : ''}</small>
      </div>;
    })}
  </div>;
}

function BranchPerformance({
  branches,
  maximum,
  searchQuery,
}: {
  branches: Array<{ label: string; value: number }>;
  maximum: number;
  searchQuery: string;
}) {
  const query = searchQuery.trim().toLocaleLowerCase();
  const visible = branches.filter((branch) => branch.label.toLocaleLowerCase().includes(query)).slice(0, 5);
  if (visible.length === 0) return <p className="data-empty-inline">No branch sales are recorded in the available data.</p>;
  return <div className="branch-performance-list">{visible.map((branch) => {
    const value = Number(branch.value) || 0;
    return <div className="branch-performance-item" key={branch.label}>
      <div className="branch-performance-label"><strong>{branch.label}</strong><span>{money(value)}</span></div>
      <div className="branch-performance-track"><span style={{ width: `${maximum > 0 ? Math.max(0, value / maximum * 100) : 0}%` }} /></div>
    </div>;
  })}</div>;
}

function ActivityTimeline({ activities }: { activities: DataMap[] }) {
  if (activities.length === 0) return <p className="data-empty-inline">No matching audited business activity is available.</p>;
  return <ol className="activity-timeline">{activities.map((activity, index) => (
    <li key={`${String(activity.type)}-${String(activity.created_at)}-${index}`}>
      <span className="activity-timeline-dot" />
      <div><strong>{String(activity.summary || activity.type || 'Business activity')}</strong>
        <small>{String(activity.entity ?? 'Organisation record')} · {String(activity.created_at ?? '')}</small></div>
    </li>
  ))}</ol>;
}

function DashboardIcon({ name }: { name: WorkspaceModuleKey | 'shield' | 'search' }) {
  const shapes: Record<WorkspaceModuleKey | 'shield' | 'search', ReactNode> = {
    command: <><rect x="3" y="3" width="5.5" height="5.5" rx="1" /><rect x="11.5" y="3" width="5.5" height="5.5" rx="1" /><rect x="3" y="11.5" width="5.5" height="5.5" rx="1" /><rect x="11.5" y="11.5" width="5.5" height="5.5" rx="1" /></>,
    'design-system': <><rect x="3" y="3" width="5.5" height="5.5" rx="1" /><rect x="11.5" y="3" width="5.5" height="5.5" rx="1" /><rect x="3" y="11.5" width="5.5" height="5.5" rx="1" /><rect x="11.5" y="11.5" width="5.5" height="5.5" rx="1" /></>,
    finance: <><rect x="3" y="5" width="14" height="11" rx="2" /><path d="M3 9h14m-9 0v2h4V9" /></>,
    operations: <><path d="m10 2.8 7 3.8v6.8l-7 3.8-7-3.8V6.6l7-3.8Z" /><path d="m3.3 6.7 6.7 3.8 6.7-3.8M10 10.5v6.4" /></>,
    forecast: <><path d="M3 16.5h14M4.5 13l3.2-3.5 2.7 1.9 5.1-6" /><path d="M12.5 5.4h3v3" /></>,
    risk: <><path d="M10 2.6 16 5v4.3c0 3.8-2.5 6.5-6 8.1-3.5-1.6-6-4.3-6-8.1V5l6-2.4Z" /><path d="M10 6.4v4.1m0 2.5h.01" /></>,
    decisions: <><circle cx="10" cy="10" r="7" /><circle cx="10" cy="10" r="3" /></>,
    reports: <><path d="M5 2.8h6l4 4v10.4H5V2.8Z" /><path d="M11 3v4h4M7.5 10h5m-5 3h5" /></>,
    connect: <><path d="M6.5 6.5 4 4m9.5 9.5L16 16M5.5 12.5l-2 2a2.1 2.1 0 0 0 3 3l2-2m6-6 2-2a2.1 2.1 0 0 0-3-3l-2 2" /><path d="m7.5 12.5 5-5" /></>,
    control: <><circle cx="10" cy="10" r="2.5" /><path d="M10 2.5v2m0 11v2M2.5 10h2m11 0h2M4.7 4.7l1.4 1.4m7.8 7.8 1.4 1.4m0-10.6-1.4 1.4m-7.8 7.8-1.4 1.4" /></>,
    shield: <><path d="M10 2.6 16 5v4.3c0 3.8-2.5 6.5-6 8.1-3.5-1.6-6-4.3-6-8.1V5l6-2.4Z" /><path d="m7.5 10 1.7 1.7 3.5-3.5" /></>,
    search: <><circle cx="8.5" cy="8.5" r="5.3" /><path d="m12.5 12.5 4.2 4.2" /></>,
  };
  return <svg className="workspace-icon" viewBox="0 0 20 20" fill="none" stroke="currentColor"
    strokeWidth="1.55" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true" focusable="false">{shapes[name]}</svg>;
}

function DataTable({
  headers,
  rows,
  empty,
  searchQuery = '',
}: {
  headers: string[];
  rows: string[][];
  empty: string;
  searchQuery?: string;
}) {
  const numericColumns = headers.map((header) =>
    /\b(amount|total|balance|revenue|expense|profit|price|quantity|qty|count|items|rows|margin|percent|rate|stock|cost|value|score|variance|debit|credit)\b/i.test(header));
  const [query, setQuery] = useState(searchQuery);
  const [sortColumn, setSortColumn] = useState<number | null>(null);
  const [sortDirection, setSortDirection] = useState<'ascending' | 'descending'>('ascending');
  const [page, setPage] = useState(0);
  const pageSize = 8;

  useEffect(() => {
    setQuery(searchQuery);
    setPage(0);
  }, [searchQuery]);

  const filteredRows = useMemo(() => {
    const needle = query.trim().toLocaleLowerCase();
    const matched = rows
      .map((row, index) => ({ row, index }))
      .filter(({ row }) => !needle || row.some((cell) => cell.toLocaleLowerCase().includes(needle)));
    if (sortColumn === null) return matched;
    return matched.sort((left, right) => {
      const a = left.row[sortColumn] ?? '';
      const b = right.row[sortColumn] ?? '';
      const numericA = Number(a.replace(/[^\d.-]/g, ''));
      const numericB = Number(b.replace(/[^\d.-]/g, ''));
      const compared = Number.isFinite(numericA) && Number.isFinite(numericB)
        ? numericA - numericB
        : a.localeCompare(b, undefined, { numeric: true, sensitivity: 'base' });
      return sortDirection === 'ascending' ? compared : -compared;
    });
  }, [query, rows, sortColumn, sortDirection]);
  const pageCount = Math.ceil(filteredRows.length / pageSize);
  const visibleRows = filteredRows.slice(page * pageSize, (page + 1) * pageSize);

  useEffect(() => {
    if (pageCount > 0 && page >= pageCount) setPage(pageCount - 1);
  }, [page, pageCount]);

  if (rows.length === 0) return <p className="empty-state">{searchQuery.trim() ? 'No rows match this search.' : empty}</p>;
  return <div className="table-widget">
    {rows.length >= 5 && <div className="table-toolbar">
      <label className="table-search"><DashboardIcon name="search" />
        <input aria-label="Search table rows" placeholder="Filter rows" value={query} onChange={(event) => { setQuery(event.target.value); setPage(0); }} />
      </label>
      <span>{filteredRows.length} {filteredRows.length === 1 ? 'record' : 'records'}</span>
    </div>}
    {filteredRows.length === 0 ? <p className="data-empty-inline">No rows match this search.</p>
      : <><div className="table-scroll"><table className="data-table">
        <thead><tr>{headers.map((header, index) => <th key={header}
          className={numericColumns[index] ? 'table-numeric' : undefined}
          aria-sort={sortColumn === index ? sortDirection : 'none'}>
          <button type="button" className="table-sort-button" onClick={() => {
            if (sortColumn === index) setSortDirection((direction) => direction === 'ascending' ? 'descending' : 'ascending');
            else { setSortColumn(index); setSortDirection('ascending'); }
            setPage(0);
          }}>{header}<span aria-hidden="true">{sortColumn !== index ? '↕' : sortDirection === 'ascending' ? '↑' : '↓'}</span></button>
        </th>)}</tr></thead>
        <tbody>{visibleRows.map(({ row, index }) => <tr key={`${index}-${row[0]}`}>{row.map((cell, cellIndex) =>
          <td key={cellIndex} className={numericColumns[cellIndex] ? 'table-numeric' : undefined}>{cell}</td>)}</tr>)}</tbody>
      </table></div>
      {pageCount > 1 && <div className="table-pagination"><span>Page {page + 1} of {pageCount}</span>
        <div><button type="button" className="table-page-button" disabled={page === 0} onClick={() => setPage((value) => value - 1)}>Previous</button>
          <button type="button" className="table-page-button" disabled={page >= pageCount - 1} onClick={() => setPage((value) => value + 1)}>Next</button></div>
      </div>}</>}
  </div>;
}

function SalesLedger({
  sales,
  canRefund,
  busy,
  onRefund,
}: {
  sales: SaleRecord[];
  canRefund: boolean;
  busy: boolean;
  onRefund: (event: FormEvent<HTMLFormElement>, saleId: number) => void;
}) {
  const headers = ['Date', 'Customer', 'Invoice', 'Items', 'Status', 'Total', ...(canRefund ? ['Refund'] : [])];
  if (sales.length === 0) return <p className="empty-state">No sales have been recorded.</p>;
  return <div className="table-scroll"><table className="data-table">
    <thead><tr>{headers.map((header) => <th key={header}
      className={['Items', 'Total'].includes(header) ? 'table-numeric' : undefined}>{header}</th>)}</tr></thead>
    <tbody>{sales.map((sale) => <tr key={sale.id}>
      <td>{sale.sale_date}</td><td>{sale.customer_name ?? '—'}</td><td>{sale.invoice_number ?? '—'}</td>
      <td className="table-numeric">{sale.item_count}</td><td>{sale.status}</td><td className="table-numeric">{money(sale.total)}</td>
      {canRefund && <td>{sale.status === 'posted' && sale.invoice_number === null ? <form className="refund-form" onSubmit={(event) => onRefund(event, sale.id)}>
        <input name="reason" aria-label={`Refund reason for sale ${sale.id}`} placeholder="Reason" maxLength={500} required />
        <button type="submit" className="ghost-button" disabled={busy}>Refund</button>
      </form> : sale.invoice_number !== null ? 'Invoice credit workflow unavailable' : '—'}</td>}
    </tr>)}</tbody>
  </table></div>;
}

function RiskList({ alerts }: { alerts: DataMap[] }) {
  if (alerts.length === 0) return <p className="empty-state">No risk alerts have been recorded.</p>;
  return <ul className="risk-list">{alerts.map((alert) => (
    <li key={String(alert.id)}>
      <span className={`severity ${String(alert.severity ?? 'low')}`}>{String(alert.severity ?? 'low')}</span>
      <div><strong>{String(alert.category ?? 'Risk alert')}</strong><p>{String(alert.explanation ?? '')}</p></div>
    </li>
  ))}</ul>;
}

function ForecastPanel({ forecast, searchQuery }: { forecast: DataMap; searchQuery: string }) {
  const results = arrayValue<DataMap>(forecast.results);
  return <article className="panel-surface">
    <div className="panel-header"><h3>Forecast</h3><span>Evidence-based projections only</span></div>
    {forecast.available === true ? <>
      <p>{String(forecast.methodology ?? 'Methodology not provided')}</p>
      <p>Model version: {String(objectValue(forecast.forecast).model_version ?? '—')}</p>
      <DataTable headers={['Period', 'Actual', 'Projected']} searchQuery={searchQuery}
        rows={results.map((result) => [
          String(result.period_label ?? ''),
          result.actual_value === null ? '—' : money(result.actual_value),
          money(result.projected_value),
        ])}
        empty="No forecast periods have been generated." />
    </> : <p className="empty-state">{String(forecast.message ?? 'Forecasting is unavailable with current data coverage.')}</p>}
  </article>;
}

function RiskWorkflow({
  alerts,
  busy,
  onUpdate,
}: {
  alerts: DataMap[];
  busy: boolean;
  onUpdate: (id: number, status: string, notes: string) => Promise<void>;
}) {
  return <article className="panel-surface">
    <div className="panel-header"><h3>Risk and anomaly review</h3><span>Alerts indicate investigation candidates, not proven misconduct</span></div>
    {alerts.length === 0 ? <p className="empty-state">No risk alerts are currently available.</p> : (
      <div className="stack-list">{alerts.map((alert) => (
        <AlertEditor alert={alert} busy={busy} onUpdate={onUpdate} key={String(alert.id)} />
      ))}</div>
    )}
  </article>;
}

function AlertEditor({
  alert,
  busy,
  onUpdate,
}: {
  alert: DataMap;
  busy: boolean;
  onUpdate: (id: number, status: string, notes: string) => Promise<void>;
}) {
  const [status, setStatus] = useState(String(alert.status ?? 'open'));
  const [notes, setNotes] = useState(String(alert.resolution_notes ?? ''));
  return <section className="list-item">
    <span className={`severity ${String(alert.severity ?? 'low')}`}>{String(alert.severity ?? 'low')}</span>
    <div className="alert-content">
      <strong>{String(alert.category ?? 'Alert')} · {String(alert.business_area ?? '')}</strong>
      <p>{String(alert.explanation ?? '')}</p>
      <p><b>Evidence:</b> {String(alert.evidence ?? 'Review linked business records.')}</p>
      <p><b>Suggested review:</b> {String(alert.suggested_action ?? '')}</p>
      <div className="inline-form">
        <label>Status<select value={status} onChange={(event) => setStatus(event.target.value)}>
          {['open', 'assigned', 'reviewing', 'resolved', 'dismissed'].map((value) => <option key={value}>{value}</option>)}
        </select></label>
        <label>Resolution notes<input value={notes} onChange={(event) => setNotes(event.target.value)} maxLength={4000} /></label>
        <button className="primary-button" disabled={busy} onClick={() => void onUpdate(Number(alert.id), status, notes)}>Save review</button>
      </div>
    </div>
  </section>;
}

function DecisionWorkflow({
  data,
  searchQuery,
  busy,
  selectedScenario,
  onSelect,
  onCreate,
}: {
  data: DataMap;
  searchQuery: string;
  busy: boolean;
  selectedScenario: DataMap | null;
  onSelect: (id: number) => Promise<void>;
  onCreate: (event: FormEvent<HTMLFormElement>) => void;
}) {
  const query = searchQuery.trim().toLocaleLowerCase();
  const scenarios = arrayValue<DataMap>(data.scenarios).filter((scenario) =>
    [scenario.name, scenario.description, scenario.status].some((value) =>
      String(value ?? '').toLocaleLowerCase().includes(query)));
  const detail = objectValue(selectedScenario);
  return <div className="content-grid two-col">
    <article className="panel-surface">
      <div className="panel-header"><h3>Create scenario</h3><span>Assumptions are hypothetical, not actuals</span></div>
      <form onSubmit={onCreate}>
        <label>Name<input name="name" maxLength={180} required /></label>
        <label>Description<input name="description" maxLength={2000} /></label>
        <label>Revenue growth (%)<input name="revenue_growth" type="number" min="0" max="100" step="0.1" defaultValue="0" required /></label>
        <label>Price increase (%)<input name="price_increase" type="number" min="0" max="100" step="0.1" defaultValue="0" required /></label>
        <label>Cost reduction (%)<input name="cost_reduction" type="number" min="0" max="100" step="0.1" defaultValue="0" required /></label>
        <button className="primary-button" disabled={busy}>{busy ? 'Calculating…' : 'Save scenario'}</button>
      </form>
    </article>
    <article className="panel-surface">
      <div className="panel-header"><h3>Saved scenarios</h3><span>Organisation scenarios</span></div>
      {scenarios.length === 0 ? <p className="empty-state">No scenarios have been saved.</p> : <ul className="button-list">
        {scenarios.map((scenario) => <li key={String(scenario.id)}>
          <button type="button" className="text-button" onClick={() => void onSelect(Number(scenario.id))}>
            {String(scenario.name)} · {String(scenario.status)}
          </button>
        </li>)}
      </ul>}
      {selectedScenario && <>
        <h4>{String(objectValue(detail.scenario).name ?? 'Scenario details')}</h4>
        <pre className="data-preview">{JSON.stringify({ assumptions: detail.assumptions, results: detail.results }, null, 2)}</pre>
      </>}
    </article>
  </div>;
}

function ConnectWorkflow({
  data,
  searchQuery,
  busy,
  preview,
  onPreview,
}: {
  data: DataMap;
  searchQuery: string;
  busy: boolean;
  preview: DataMap | null;
  onPreview: (event: FormEvent<HTMLFormElement>) => void;
}) {
  const imports = arrayValue<DataMap>(data.imports);
  return <div className="content-grid two-col">
    <article className="panel-surface">
      <div className="panel-header"><h3>Inspect CSV</h3><span>Preview only—no data is imported</span></div>
      <form id="csv-preview-form" onSubmit={onPreview}>
        <label>CSV file<input name="file" type="file" accept=".csv,text/csv" required /></label>
        <button className="primary-button" disabled={busy}>{busy ? 'Checking…' : 'Preview file'}</button>
      </form>
      {preview && <pre className="data-preview">{JSON.stringify(preview, null, 2)}</pre>}
    </article>
    <article className="panel-surface">
      <div className="panel-header"><h3>Import history</h3><span>Organisation scoped</span></div>
      <DataTable headers={['File', 'Status', 'Size', 'Uploaded']}
        searchQuery={searchQuery}
        rows={imports.map((item) => [
          String(item.file_name ?? ''), String(item.status ?? ''),
          `${String(item.file_size ?? 0)} bytes`, String(item.uploaded_at ?? ''),
        ])}
        empty="No import history is available." />
    </article>
  </div>;
}

function ControlPanel({
  data,
  user,
  section,
  busy,
  lastUpdatedAt,
  syncStatus,
  onRefresh,
  onCreateBranch,
  onCreateDepartment,
  onUpdateRoles,
  onUpdateStatus,
}: {
  data: DataMap;
  user: AuthUser;
  section: string;
  busy: boolean;
  lastUpdatedAt: number | null;
  syncStatus: 'syncing' | 'current' | 'interrupted' | 'paused';
  onRefresh: () => void;
  onCreateBranch: (event: FormEvent<HTMLFormElement>) => void;
  onCreateDepartment: (event: FormEvent<HTMLFormElement>) => void;
  onUpdateRoles: (event: FormEvent<HTMLFormElement>, userId: number) => void;
  onUpdateStatus: (event: FormEvent<HTMLFormElement>, userId: number) => void;
}) {
  const overview = objectValue(data.overview);
  const stats = objectValue(overview.stats);
  const organisation = objectValue(overview.organisation);
  const users = arrayValue<DataMap>(objectValue(data.users).users);
  const branches = arrayValue<DataMap>(objectValue(data.branches).branches);
  const departments = arrayValue<DataMap>(objectValue(data.departments).departments);
  const roles = arrayValue<AdminRole>(objectValue(data.roles).roles);
  const auditLogs = arrayValue<AdminAuditLog>(objectValue(data.auditLogs).audit_logs);
  const [userSearch, setUserSearch] = useState('');
  const [userStatus, setUserStatus] = useState('all');
  const [userRole, setUserRole] = useState('all');
  const [auditSearch, setAuditSearch] = useState('');
  const [auditAction, setAuditAction] = useState('all');
  const availableRoles = Array.from(new Set(users.flatMap((person) =>
    String(person.roles ?? '').split(',').map((role) => role.trim()).filter(Boolean))));
  const filteredUsers = users.filter((person) => {
    const text = `${String(person.name ?? '')} ${String(person.email ?? '')} ${String(person.roles ?? '')}`.toLocaleLowerCase();
    return (userSearch.trim() === '' || text.includes(userSearch.trim().toLocaleLowerCase()))
      && (userStatus === 'all' || person.status === userStatus)
      && (userRole === 'all' || String(person.roles ?? '').split(',').map((role) => role.trim()).includes(userRole));
  });
  const auditActions = Array.from(new Set(auditLogs.map((log) => log.action_type))).sort();
  const filteredAuditLogs = auditLogs.filter((log) => {
    const text = `${log.actor ?? ''} ${log.action_type} ${log.entity_name} ${JSON.stringify(log.metadata)}`.toLocaleLowerCase();
    return (auditSearch.trim() === '' || text.includes(auditSearch.trim().toLocaleLowerCase()))
      && (auditAction === 'all' || log.action_type === auditAction);
  });

  const controlTabs = [
    { path: 'overview', label: 'Overview' },
    { path: 'users', label: 'Users' },
    { path: 'roles', label: 'Roles' },
    { path: 'structure', label: 'Organisation structure' },
    { path: 'audit-logs', label: 'Audit logs' },
  ];

  return <div className="control-workspace">
    <nav className="control-tabs" aria-label="Control sections">
      {controlTabs.map((tab) => <Link key={tab.path} to={`/control/${tab.path}`}
        className={section === tab.path ? 'active' : ''}
        aria-current={section === tab.path ? 'page' : undefined}>{tab.label}</Link>)}
    </nav>

    {section === 'users' && <section className="control-section" aria-labelledby="control-users-title">
      <div className="control-section-heading">
        <div><p className="section-eyebrow">ACCESS MANAGEMENT</p><h2 id="control-users-title">Users</h2>
          <p>Search and manage accounts within your organisation.</p></div>
        <span>{filteredUsers.length} of {users.length}</span>
      </div>
      <p className="control-capability-note">New accounts and invitations are not supported by the current API. No password data is exposed here.</p>
      <div className="control-filter-bar">
        <label>Search users<input type="search" value={userSearch} onChange={(event) => setUserSearch(event.target.value)} placeholder="Name, email, or role" /></label>
        <label>Status<select value={userStatus} onChange={(event) => setUserStatus(event.target.value)}>
          <option value="all">All statuses</option><option value="active">Active</option>
          <option value="inactive">Inactive</option><option value="suspended">Suspended</option>
        </select></label>
        <label>Role<select value={userRole} onChange={(event) => setUserRole(event.target.value)}>
          <option value="all">All roles</option>{availableRoles.map((role) => <option value={role} key={role}>{role}</option>)}
        </select></label>
      </div>
      <article className="panel-surface control-panel">
        {filteredUsers.length === 0 ? <div className="empty-state"><h3>No matching users</h3><p>Try changing the search or filters.</p></div>
          : <div className="control-user-list">{filteredUsers.map((person) => {
            const personId = Number(person.id);
            const assignedRoleIds = String(person.role_ids ?? '').split(',').filter(Boolean);
            const personName = String(person.name ?? 'User');
            const status = String(person.status ?? 'unknown');
            return <article className="control-user-row" key={personId}>
              <span className="user-avatar">{personName.trim().split(/\s+/).slice(0, 2).map((part) => part[0]).join('').toLocaleUpperCase()}</span>
              <div className="control-user-identity">
                <strong>{personName}</strong><span>{String(person.email ?? '')}</span>
                <small>Last sign-in: {person.last_login_at ? new Date(String(person.last_login_at)).toLocaleString() : 'Not recorded'}</small>
              </div>
              <span className={`control-status-badge ${status}`}>{status}</span>
              {personId !== user.id && <div className="control-user-actions">
                <form className="control-role-form" onSubmit={(event) => onUpdateRoles(event, personId)}>
                  <label>Roles<select name="role_ids" multiple defaultValue={assignedRoleIds}
                    aria-label={`Roles for ${personName}`}>
                    {roles.map((role) => <option value={String(role.id)} key={role.id}>{role.name}</option>)}
                  </select></label>
                  <button type="submit" className="ghost-button" disabled={busy}>Save roles</button>
                </form>
                <form className="control-status-form" onSubmit={(event) => onUpdateStatus(event, personId)}>
                  <label>Status<select name="status" defaultValue={status}>
                    {['active', 'inactive', 'suspended'].map((value) => <option key={value}>{value}</option>)}
                  </select></label>
                  <button type="submit" className="ghost-button" disabled={busy}>Update status</button>
                </form>
              </div>}
            </article>;
          })}</div>}
      </article>
    </section>}

    {section === 'roles' && <section className="control-section" aria-labelledby="control-roles-title">
      <div className="control-section-heading">
        <div><p className="section-eyebrow">ROLE-BASED ACCESS</p><h2 id="control-roles-title">Roles &amp; permissions</h2>
          <p>Review organisation roles and their current permission grants.</p></div>
        <span>{roles.length} roles</span>
      </div>
      <p className="control-capability-note">Role definitions and permission grants are read-only because the current API does not support editing them.</p>
      <div className="control-role-grid">
        {roles.map((role) => {
          const permissionCodes = String(role.permissions ?? '').split(',').filter(Boolean);
          return <article className="panel-surface control-role-card" key={role.id}>
            <div className="control-role-heading"><div><h3>{role.name}</h3><p>{role.description ?? 'No description provided.'}</p></div>
              <span>{permissionCodes.length} permissions</span></div>
            {permissionCodes.length === 0 ? <p className="data-empty-inline">No permissions assigned.</p>
              : <ul className="permission-chip-list">{permissionCodes.map((permission) => <li key={permission}>{permission}</li>)}</ul>}
          </article>;
        })}
        {roles.length === 0 && <div className="empty-state">No organisation roles are available.</div>}
      </div>
    </section>}

    {section === 'structure' && <section className="control-section" aria-labelledby="control-structure-title">
      <div className="control-section-heading">
        <div><p className="section-eyebrow">ORGANISATION</p><h2 id="control-structure-title">Branches &amp; departments</h2>
          <p>Review the saved organisation structure and add new units.</p></div>
      </div>
      <div className="content-grid two-col control-structure-grid">
        <article className="panel-surface control-panel">
          <div className="panel-header"><h3>Create branch</h3><span>Scoped to your organisation</span></div>
          <form onSubmit={onCreateBranch}>
            <label>Branch name<input name="name" maxLength={160} required /></label>
            <label>Branch code<input name="code" maxLength={30} pattern="[A-Za-z0-9._-]+" required /></label>
            <button className="primary-button" disabled={busy}>{busy ? 'Saving…' : 'Create branch'}</button>
          </form>
          <h3 className="control-list-heading">Branches</h3>
          <DataTable headers={['Name', 'Code']} rows={branches.map((branch) => [String(branch.name ?? ''), String(branch.code ?? '')])} empty="No branches have been created." />
        </article>
        <article className="panel-surface control-panel">
          <div className="panel-header"><h3>Create department</h3><span>Organisation-wide or branch-linked</span></div>
          <form onSubmit={onCreateDepartment}>
            <label>Department name<input name="name" maxLength={120} required /></label>
            <label>Department code<input name="code" maxLength={30} pattern="[A-Za-z0-9._-]+" required /></label>
            <label>Branch<select name="branch_id" defaultValue=""><option value="">Organisation-wide</option>
              {branches.map((branch) => <option key={String(branch.id)} value={String(branch.id)}>{String(branch.name)}</option>)}
            </select></label>
            <button className="primary-button" disabled={busy}>{busy ? 'Saving…' : 'Create department'}</button>
          </form>
          <h3 className="control-list-heading">Departments</h3>
          <DataTable headers={['Name', 'Code', 'Branch']} rows={departments.map((department) => [
            String(department.name ?? ''), String(department.code ?? ''),
            String(branches.find((branch) => Number(branch.id) === Number(department.branch_id))?.name ?? 'Organisation-wide'),
          ])} empty="No departments have been created." />
        </article>
      </div>
      <p className="control-capability-note">Branch and department assignments to individual users are not supported by the current data model.</p>
    </section>}

    {section === 'audit-logs' && <section className="control-section" aria-labelledby="control-audit-title">
      <div className="control-section-heading">
        <div><p className="section-eyebrow">APPEND-ONLY RECORDS</p><h2 id="control-audit-title">Audit logs</h2>
          <p>Recent administrative and business events recorded for this organisation.</p></div>
        <span>Latest {auditLogs.length}</span>
      </div>
      <div className="control-filter-bar">
        <label>Search audit records<input type="search" value={auditSearch} onChange={(event) => setAuditSearch(event.target.value)} placeholder="Actor, action, entity, metadata" /></label>
        <label>Action<select value={auditAction} onChange={(event) => setAuditAction(event.target.value)}>
          <option value="all">All actions</option>{auditActions.map((action) => <option key={action}>{action}</option>)}
        </select></label>
      </div>
      <article className="panel-surface control-panel">
        {filteredAuditLogs.length === 0 ? <div className="empty-state"><h3>No matching audit records</h3>
          <p>{auditLogs.length === 0 ? 'No audit events have been recorded for this organisation.' : 'Try adjusting the filters.'}</p></div>
          : <div className="table-scroll"><table className="data-table control-audit-table">
            <thead><tr><th>Actor</th><th>Action</th><th>Target</th><th>Recorded</th><th>Metadata</th></tr></thead>
            <tbody>{filteredAuditLogs.map((log) => <tr key={log.id}>
              <td>{log.actor ?? 'System'}</td><td>{log.action_type}</td><td>{log.entity_name}</td>
              <td><time className="type-data-mono" dateTime={log.created_at}>{new Date(log.created_at).toLocaleString()}</time></td>
              <td><code>{JSON.stringify(log.metadata)}</code></td>
            </tr>)}</tbody>
          </table></div>}
      </article>
    </section>}

    {section !== 'users' && section !== 'roles' && section !== 'structure' && section !== 'audit-logs'
      && <section className="control-section" aria-labelledby="control-overview-title">
        <div className="control-section-heading">
          <div><p className="section-eyebrow">ORGANISATION ADMINISTRATION</p><h2 id="control-overview-title">Control overview</h2>
            <p>Business condition, operational activity, and areas requiring attention.</p></div>
        </div>
        <Suspense fallback={<div className="panel-surface loading-state" role="status">Preparing the business overview…</div>}>
          <LazyControlOverview
            organisation={organisation}
            stats={stats}
            finance={objectValue(data.controlFinance)}
            operations={objectValue(data.controlOperations)}
            products={arrayValue<ProductRecord>(objectValue(data.controlProducts).products)}
            risk={objectValue(data.controlRisk)}
            trend={objectValue(data.controlTrend)}
            documents={arrayValue<DataMap>(objectValue(data.controlDocuments).documents)}
            activity={auditLogs}
            permissions={user.permissions}
            lastUpdatedAt={lastUpdatedAt}
            syncStatus={syncStatus}
            onRefresh={onRefresh}
          />
        </Suspense>
      </section>}
  </div>;
}

function exportFinancialCsv(report: FinancialReport) {
  const lines = [
    ['type', 'date', 'count', 'amount'],
    ...report.revenue_by_day.map((row) => ['revenue', row.period, String(row.sale_count), String(row.revenue)]),
    ...report.expenses_by_day.map((row) => ['expense', row.period, String(row.expense_count), String(row.expenses)]),
    ...report.refunds_by_day.map((row) => ['refund', row.period, String(row.refund_count), String(row.refunds)]),
  ];
  const csv = lines.map((row) => row.map(csvCell).join(',')).join('\r\n');
  const url = URL.createObjectURL(new Blob([csv], { type: 'text/csv;charset=utf-8' }));
  const link = document.createElement('a');
  link.href = url;
  link.download = `vantiq-financial-${report.from.slice(0, 10)}-${report.to.slice(0, 10)}.csv`;
  link.click();
  URL.revokeObjectURL(url);
}

function csvCell(value: string): string {
  return `"${value.replaceAll('"', '""')}"`;
}
