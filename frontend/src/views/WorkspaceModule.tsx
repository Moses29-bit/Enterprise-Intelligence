import { FormEvent, useCallback, useEffect, useState } from 'react';
import {
  getErrorMessage,
  moduleApi,
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

export type WorkspaceModuleKey =
  | 'command' | 'finance' | 'operations' | 'forecast' | 'risk'
  | 'decisions' | 'reports' | 'connect' | 'control';

interface WorkspaceModuleProps {
  module: WorkspaceModuleKey;
  user: AuthUser;
}

type DataMap = Record<string, unknown>;

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
};

function arrayValue<T>(value: unknown): T[] {
  return Array.isArray(value) ? value as T[] : [];
}

function objectValue(value: unknown): DataMap {
  return typeof value === 'object' && value !== null ? value as DataMap : {};
}

function money(value: unknown): string {
  const amount = Number(value);
  return Number.isFinite(amount)
    ? new Intl.NumberFormat(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(amount)
    : '—';
}

function dateInput(daysAgo: number): string {
  const value = new Date();
  value.setDate(value.getDate() - daysAgo);
  return value.toISOString().slice(0, 10);
}

export function WorkspaceModule({ module, user }: WorkspaceModuleProps) {
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

  const canWriteFinance = user.permissions.includes('finance.write');
  const canWriteOperations = user.permissions.includes('operations.write');
  const permissions = new Set(user.permissions);
  const permitted = permissionForModule[module].some((code) => permissions.has(code));

  const load = useCallback(async () => {
    setLoading(true);
    setError('');
    try {
      if (module === 'command') {
        const [finance, operations, risk] = await Promise.all([
          permissions.has('finance.read') ? moduleApi.financeSummary() : Promise.resolve(null),
          permissions.has('operations.read') ? moduleApi.operationsSummary() : Promise.resolve(null),
          permissions.has('risks.read') ? moduleApi.riskAlerts() : Promise.resolve(null),
        ]);
        setData({ finance, operations, risk });
      } else if (module === 'finance') {
        const [finance, sales, expenses, products, invoices, customers] = await Promise.all([
          moduleApi.financeSummary(),
          moduleApi.sales(),
          moduleApi.expenses(),
          moduleApi.products(),
          moduleApi.invoices(),
          moduleApi.customers(),
        ]);
        setData({ finance, sales, expenses, products, invoices, customers });
      } else if (module === 'operations') {
        const [operations, products, suppliers, purchaseOrders] = await Promise.all([
          moduleApi.operationsSummary(),
          moduleApi.products(),
          moduleApi.suppliers(),
          moduleApi.purchaseOrders(),
        ]);
        setData({ operations, products, suppliers, purchaseOrders });
      } else if (module === 'forecast') {
        setData({ forecast: await moduleApi.forecastSummary() });
      } else if (module === 'risk') {
        setData({ risk: await moduleApi.riskAlerts() });
      } else if (module === 'decisions') {
        setData({ decisions: await moduleApi.decisionScenarios() });
      } else if (module === 'reports') {
        const [summary, financial] = await Promise.all([
          moduleApi.reportsSummary(),
          moduleApi.financialReport(reportFrom, reportTo),
        ]);
        setData({ reports: summary });
        setReport(financial);
      } else if (module === 'connect') {
        setData({ imports: await moduleApi.importsHistory() });
      } else {
        const [overview, usersList, branchesList, departmentsList, rolesList] = await Promise.all([
          moduleApi.adminOverview(),
          moduleApi.adminUsers(),
          moduleApi.adminBranches(),
          moduleApi.adminDepartments(),
          moduleApi.adminRoles(),
        ]);
        setData({ overview, users: usersList, branches: branchesList, departments: departmentsList, roles: rolesList });
      }
    } catch (requestError) {
      setError(getErrorMessage(requestError));
      setData({});
    } finally {
      setLoading(false);
    }
  }, [module, reportFrom, reportTo, user.id, user.permissions]);

  useEffect(() => {
    setNotice('');
    setError('');
    setSelectedScenario(null);
    setImportPreview(null);
  }, [module]);

  useEffect(() => {
    if (permitted) void load();
    else {
      setLoading(false);
      setData({});
    }
  }, [load, permitted]);

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

  if (!permitted) {
    return <article className="panel-surface empty-state">This module is not available for your assigned permissions.</article>;
  }
  if (loading) return <article className="panel-surface loading-state" role="status">Loading organisational data…</article>;

  const finance = objectValue(data.finance);
  const financeSummary = objectValue(finance.summary);
  const operations = objectValue(data.operations);
  const risk = objectValue(data.risk);
  const products = arrayValue<ProductRecord>(objectValue(data.products).products);
  const sales = arrayValue<SaleRecord>(objectValue(data.sales).sales);
  const expenses = arrayValue<ExpenseRecord>(objectValue(data.expenses).expenses);
  const invoices = arrayValue<InvoiceRecord>(objectValue(data.invoices).invoices);
  const customers = arrayValue<CustomerRecord>(objectValue(data.customers).customers);
  const suppliers = arrayValue<SupplierRecord>(objectValue(data.suppliers).suppliers);
  const purchaseOrders = arrayValue<PurchaseOrderRecord>(objectValue(data.purchaseOrders).purchase_orders);
  const kpis = arrayValue<{ label: string; value: number | string }>(finance.kpis);

  return (
    <div className="module-content">
      {notice && <p className="notice" role="status">{notice}</p>}
      {error && <p className="error" role="alert">{error}</p>}
      {module === 'command' && (
        <>
          {kpis.length > 0 && <div className="kpi-grid">
            {kpis.map((metric) => (
              <article key={metric.label} className="metric-card">
                <div className="metric-label">{metric.label}</div>
                <div className="metric-value">{money(metric.value)}</div>
              </article>
            ))}
          </div>}
          <div className="content-grid two-col">
            <article className="panel-surface">
              <div className="panel-header"><h3>Branch revenue</h3><span>Posted sales, net of refunds</span></div>
              <DataTable
                headers={['Branch', 'Net revenue']}
                rows={arrayValue<{ label: string; value: number }>(finance.branchBreakdown)
                  .map((row) => [row.label, money(row.value)])}
                empty="No branch sales have been recorded."
              />
            </article>
            <article className="panel-surface">
              <div className="panel-header"><h3>Risk review</h3><span>Organisation alerts</span></div>
              <RiskList alerts={arrayValue<DataMap>(risk.alerts).slice(0, 5)} />
            </article>
          </div>
          <article className="panel-surface">
            <div className="panel-header"><h3>Inventory health</h3><span>Current product balances</span></div>
            <p className="metric-label">Inventory valuation: {money(operations.inventoryValue)}</p>
            <DataTable
              headers={['Product', 'SKU', 'Stock', 'Reorder point', 'Branch']}
              rows={arrayValue<DataMap>(operations.lowStock).map((row) => [
                String(row.name ?? ''), String(row.sku ?? ''), String(row.stock_level ?? 0),
                String(row.reorder_level ?? 0), String(row.branch_name ?? ''),
              ])}
              empty="No low-stock products are currently reported."
            />
          </article>
        </>
      )}

      {module === 'finance' && (
        <>
          <div className="kpi-grid small-grid">
            {[
              ['Net revenue', financeSummary.totalRevenue],
              ['Cost of goods sold', financeSummary.costOfGoodsSold],
              ['Gross profit', financeSummary.grossProfit],
              ['Operating profit', financeSummary.operatingProfit],
              ['Receivables', financeSummary.outstandingReceivables],
            ].map(([label, amount]) => (
              <article className="metric-card" key={String(label)}>
                <div className="metric-label">{String(label)}</div>
                <div className="metric-value">{money(amount)}</div>
              </article>
            ))}
          </div>
          {canWriteFinance && (
            <div className="content-grid two-col">
              <article className="panel-surface">
                <div className="panel-header"><h3>Post sale</h3><span>Stock checked and deducted atomically</span></div>
                <form onSubmit={(event) => submit(event, async (values) => {
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
              <DataTable headers={['Date', 'Description', 'Category', 'Amount']}
                rows={expenses.map((expense) => [expense.expense_date, expense.description, expense.category_name ?? 'Unclassified', money(expense.amount)])}
                empty="No expenses have been recorded." />
            </article>
          </div>
          <article className="panel-surface">
            <div className="panel-header"><h3>Customer invoices</h3><span>Issued and settled receivables</span></div>
            <DataTable headers={['Invoice', 'Customer', 'Issue date', 'Due date', 'Total', 'Paid', 'Balance', 'Status']}
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
            <DataTable headers={['Product', 'SKU', 'Cost', 'Price', 'Stock', 'Reorder level']}
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
              <DataTable headers={['Supplier', 'Email', 'Status']}
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
            {purchaseOrders.length === 0 ? <p className="empty-state">No purchase orders have been created.</p> : (
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
          <div><h4>Sales by day</h4><DataTable headers={['Date', 'Sales', 'Revenue']}
            rows={report.revenue_by_day.map((row) => [row.period, String(row.sale_count), money(row.revenue)])}
            empty="No sales in the selected range." /></div>
          <div><h4>Expenses by day</h4><DataTable headers={['Date', 'Entries', 'Expenses']}
            rows={report.expenses_by_day.map((row) => [row.period, String(row.expense_count), money(row.expenses)])}
            empty="No expenses in the selected range." /></div>
          <div><h4>Refunds by day</h4><DataTable headers={['Date', 'Refunds', 'Amount']}
            rows={report.refunds_by_day.map((row) => [row.period, String(row.refund_count), money(row.refunds)])}
            empty="No refunds in the selected range." /></div>
        </div>}
      </article>}

      {module === 'forecast' && <ForecastPanel forecast={objectValue(data.forecast)} />}

      {module === 'risk' && <RiskWorkflow
        alerts={arrayValue<DataMap>(risk.alerts)}
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

      {module === 'decisions' && <DecisionWorkflow
        data={objectValue(data.decisions)}
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

      {module === 'connect' && <ConnectWorkflow
        data={objectValue(data.imports)}
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
        busy={busy}
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
          const roleIds = values.getAll('role_ids').map(Number).filter(Number.isInteger);
          await moduleApi.updateAccountRoles(userId, roleIds);
          setNotice('User roles updated.');
        })}
        onUpdateStatus={(event, userId) => submit(event, async (values) => {
          const status = String(values.get('status')) as 'active' | 'inactive' | 'suspended';
          await moduleApi.updateAccountStatus(userId, status);
          setNotice('User status updated.');
        })}
      />}
    </div>
  );
}

function DataTable({ headers, rows, empty }: { headers: string[]; rows: string[][]; empty: string }) {
  if (rows.length === 0) return <p className="empty-state">{empty}</p>;
  return <div className="table-scroll"><table className="data-table">
    <thead><tr>{headers.map((header) => <th key={header}>{header}</th>)}</tr></thead>
    <tbody>{rows.map((row, index) => <tr key={`${index}-${row[0]}`}>{row.map((cell, cellIndex) => <td key={cellIndex}>{cell}</td>)}</tr>)}</tbody>
  </table></div>;
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
  if (sales.length === 0) return <p className="empty-state">No sales have been recorded.</p>;
  return <div className="table-scroll"><table className="data-table">
    <thead><tr>{['Date', 'Customer', 'Invoice', 'Items', 'Status', 'Total', ...(canRefund ? ['Refund'] : [])].map((header) => <th key={header}>{header}</th>)}</tr></thead>
    <tbody>{sales.map((sale) => <tr key={sale.id}>
      <td>{sale.sale_date}</td><td>{sale.customer_name ?? '—'}</td><td>{sale.invoice_number ?? '—'}</td>
      <td>{sale.item_count}</td><td>{sale.status}</td><td>{money(sale.total)}</td>
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

function ForecastPanel({ forecast }: { forecast: DataMap }) {
  const results = arrayValue<DataMap>(forecast.results);
  return <article className="panel-surface">
    <div className="panel-header"><h3>Forecast</h3><span>Evidence-based projections only</span></div>
    {forecast.available === true ? <>
      <p>{String(forecast.methodology ?? 'Methodology not provided')}</p>
      <p>Model version: {String(objectValue(forecast.forecast).model_version ?? '—')}</p>
      <DataTable headers={['Period', 'Actual', 'Projected']}
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
  busy,
  selectedScenario,
  onSelect,
  onCreate,
}: {
  data: DataMap;
  busy: boolean;
  selectedScenario: DataMap | null;
  onSelect: (id: number) => Promise<void>;
  onCreate: (event: FormEvent<HTMLFormElement>) => void;
}) {
  const scenarios = arrayValue<DataMap>(data.scenarios);
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
  busy,
  preview,
  onPreview,
}: {
  data: DataMap;
  busy: boolean;
  preview: DataMap | null;
  onPreview: (event: FormEvent<HTMLFormElement>) => void;
}) {
  const imports = arrayValue<DataMap>(data.imports);
  return <div className="content-grid two-col">
    <article className="panel-surface">
      <div className="panel-header"><h3>Inspect CSV</h3><span>Preview only—no data is imported</span></div>
      <form onSubmit={onPreview}>
        <label>CSV file<input name="file" type="file" accept=".csv,text/csv" required /></label>
        <button className="primary-button" disabled={busy}>{busy ? 'Checking…' : 'Preview file'}</button>
      </form>
      {preview && <pre className="data-preview">{JSON.stringify(preview, null, 2)}</pre>}
    </article>
    <article className="panel-surface">
      <div className="panel-header"><h3>Import history</h3><span>Organisation scoped</span></div>
      <DataTable headers={['File', 'Status', 'Size', 'Uploaded']}
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
  busy,
  onCreateBranch,
  onCreateDepartment,
  onUpdateRoles,
  onUpdateStatus,
}: {
  data: DataMap;
  user: AuthUser;
  busy: boolean;
  onCreateBranch: (event: FormEvent<HTMLFormElement>) => void;
  onCreateDepartment: (event: FormEvent<HTMLFormElement>) => void;
  onUpdateRoles: (event: FormEvent<HTMLFormElement>, userId: number) => void;
  onUpdateStatus: (event: FormEvent<HTMLFormElement>, userId: number) => void;
}) {
  const overview = objectValue(data.overview);
  const stats = objectValue(overview.stats);
  const users = arrayValue<DataMap>(objectValue(data.users).users);
  const branches = arrayValue<DataMap>(objectValue(data.branches).branches);
  const departments = arrayValue<DataMap>(objectValue(data.departments).departments);
  const roles = arrayValue<DataMap>(objectValue(data.roles).roles);
  return <>
    <div className="kpi-grid small-grid">
      {Object.entries(stats).map(([label, value]) => <article key={label} className="metric-card">
        <div className="metric-label">{label}</div><div className="metric-value">{String(value)}</div>
      </article>)}
    </div>
    <div className="content-grid two-col">
      <article className="panel-surface"><div className="panel-header"><h3>People and access</h3><span>Organisation users</span></div>
        {users.length === 0 ? <p className="empty-state">No organisation users are available.</p> : <div className="stack-list">
          {users.map((person) => {
            const personId = Number(person.id);
            const assignedRoleIds = String(person.role_ids ?? '').split(',').filter(Boolean);
            return <div className="list-item" key={personId}>
              <span className="severity assigned">{String(person.status ?? '')}</span>
              <div className="alert-content"><strong>{String(person.name ?? '')} · {String(person.email ?? '')}</strong>
                <p>{String(person.roles ?? 'No roles assigned')}</p>
                {personId !== user.id && <>
                  <form className="inline-form" onSubmit={(event) => onUpdateRoles(event, personId)}>
                    <label>Roles<select name="role_ids" multiple defaultValue={assignedRoleIds} aria-label={`Roles for ${String(person.name ?? '')}`}>
                      {roles.map((role) => <option value={String(role.id)} key={String(role.id)}>{String(role.name)}</option>)}
                    </select></label>
                    <button className="ghost-button" disabled={busy}>Save roles</button>
                  </form>
                  <form className="inline-form" onSubmit={(event) => onUpdateStatus(event, personId)}>
                    <label>Account status<select name="status" defaultValue={String(person.status ?? 'active')}>
                      {['active', 'inactive', 'suspended'].map((status) => <option key={status}>{status}</option>)}
                    </select></label>
                    <button className="ghost-button" disabled={busy}>Save status</button>
                  </form>
                </>}
              </div>
            </div>;
          })}
        </div>}
      </article>
      <article className="panel-surface"><div className="panel-header"><h3>Branches and departments</h3><span>Organisation structure</span></div>
        <div className="content-grid two-col">
          <form onSubmit={onCreateBranch}>
            <label>Branch name<input name="name" maxLength={160} required /></label>
            <label>Branch code<input name="code" maxLength={30} pattern="[A-Za-z0-9._-]+" required /></label>
            <button className="primary-button" disabled={busy}>{busy ? 'Saving…' : 'Create branch'}</button>
          </form>
          <form onSubmit={onCreateDepartment}>
            <label>Department name<input name="name" maxLength={120} required /></label>
            <label>Department code<input name="code" maxLength={30} pattern="[A-Za-z0-9._-]+" required /></label>
            <label>Branch<select name="branch_id" defaultValue=""><option value="">Organisation-wide</option>
              {branches.map((branch) => <option key={String(branch.id)} value={String(branch.id)}>{String(branch.name)}</option>)}
            </select></label>
            <button className="primary-button" disabled={busy}>{busy ? 'Saving…' : 'Create department'}</button>
          </form>
        </div>
        <h4>Branches</h4>
        <DataTable headers={['Name', 'Code']} rows={branches.map((branch) => [String(branch.name ?? ''), String(branch.code ?? '')])} empty="No branches." />
        <h4>Departments</h4>
        <DataTable headers={['Name', 'Code']} rows={departments.map((department) => [String(department.name ?? ''), String(department.code ?? '')])} empty="No departments." />
      </article>
    </div>
  </>;
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
