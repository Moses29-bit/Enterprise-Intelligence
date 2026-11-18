import axios, { AxiosError, type AxiosRequestConfig } from 'axios';

export interface AuthUser {
  id: number;
  email: string;
  name: string;
  status: string;
  organisation: { id: number; name: string; slug: string; status: string } | null;
  roles: string[];
  permissions: string[];
}

export interface HealthStatus {
  status: 'ok' | 'degraded';
  php_version: string;
  database: { connected: boolean };
}

export interface DocumentRecord {
  id: number;
  version_group: string;
  version_number: number;
  original_name: string;
  mime_type: string;
  file_size: number;
  category: string;
  extraction_status: 'available' | 'unsupported' | 'failed';
  reviewed_at: string | null;
  created_at: string;
}

export interface IntegrationOrganisation {
  id: number;
  name: string;
}

export interface ImportBatch {
  id: number;
  file_name: string;
  data_type: string;
  status: string;
  row_count: number;
  error_count: number;
  created_at: string;
  committed_at: string | null;
}

export interface IntegrationRecord {
  id: number;
  name: string;
  category: string;
  connector_type: string;
  endpoint_url: string;
  field_mappings: Record<string, string>;
  sync_schedule: string;
  status: string;
  last_synced_at: string | null;
  records_synced: number;
  last_error: string | null;
}

interface ApiEnvelope<T> {
  data?: T;
  error?: {
    code: string;
    message: string;
    details?: Record<string, string> | null;
    reference_id?: string;
  };
}

export class ApiError extends Error {
  constructor(
    message: string,
    public readonly status: number,
    public readonly code: string,
    public readonly details?: Record<string, string> | null,
    public readonly referenceId?: string,
  ) {
    super(message);
    this.name = 'ApiError';
  }
}

declare module 'axios' {
  export interface AxiosRequestConfig {
    skipSessionExpiryEvent?: boolean;
  }
}

const client = axios.create({
  baseURL: import.meta.env.VITE_API_URL?.trim() || '/api',
  timeout: 8000,
  withCredentials: true,
  headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
});

let csrfToken: string | null = null;
let csrfRequest: Promise<string> | null = null;

async function getCsrfToken(): Promise<string> {
  if (csrfToken !== null) return csrfToken;
  if (csrfRequest !== null) return csrfRequest;

  csrfRequest = client.get<ApiEnvelope<{ csrfToken: string }>>('/auth/csrf', {
    skipSessionExpiryEvent: true,
  }).then(({ data }) => {
    const token = data.data?.csrfToken;
    if (!token) throw new ApiError('Unable to initialize the security token.', 500, 'csrf_unavailable');
    csrfToken = token;
    return token;
  }).finally(() => {
    csrfRequest = null;
  });

  return csrfRequest;
}

client.interceptors.request.use(async (config) => {
  const method = config.method?.toUpperCase();
  if (method && ['POST', 'PUT', 'PATCH', 'DELETE'].includes(method)) {
    const token = await getCsrfToken();
    config.headers.set('X-CSRF-Token', token);
  }
  return config;
});

client.interceptors.response.use((response) => {
  const envelope = response.data as ApiEnvelope<{ csrfToken?: string }> | undefined;
  if (typeof envelope?.data?.csrfToken === 'string') {
    csrfToken = envelope.data.csrfToken;
  }
  if (response.config.url === '/auth/logout') {
    csrfToken = null;
  }
  return response;
}, (error: AxiosError<ApiEnvelope<never>>) => {
  const payload = error.response?.data?.error;
  const status = error.response?.status ?? 0;
  const url = error.config?.url ?? '';
  if (status === 401 && !url.endsWith('/auth/login') && !error.config?.skipSessionExpiryEvent) {
    csrfToken = null;
    window.dispatchEvent(new CustomEvent('vantiq:session-expired'));
  }
  return Promise.reject(new ApiError(
    payload?.message ?? 'The request could not be completed.',
    status,
    payload?.code ?? error.code ?? 'network_error',
    payload?.details,
    payload?.reference_id,
  ));
});

async function data<T>(request: Promise<{ data: ApiEnvelope<T> }>): Promise<T> {
  const response = await request;
  if (!response.data.data) {
    throw new ApiError('The server returned an invalid response.', 502, 'invalid_response');
  }
  return response.data.data;
}

export const authApi = {
  me: (signal?: AbortSignal) =>
    data(client.get<ApiEnvelope<{ user: AuthUser }>>('/auth/me', { signal })).then((result) => result.user),
  login: (email: string, password: string) =>
    data(client.post<ApiEnvelope<{ user: AuthUser; csrfToken: string }>>('/auth/login', { email, password }))
      .then((result) => result.user),
  register: (organisationName: string, name: string, email: string, password: string) =>
    data(client.post<ApiEnvelope<{ user: AuthUser; csrfToken: string }>>('/auth/register', {
      organisation_name: organisationName, name, email, password,
    })).then((result) => result.user),
  logout: () => data(client.post<ApiEnvelope<{ loggedOut: boolean }>>('/auth/logout')),
  changePassword: (currentPassword: string, newPassword: string) =>
    data(client.post<ApiEnvelope<{ changed: boolean; csrfToken: string }>>('/auth/change-password', {
      current_password: currentPassword, new_password: newPassword,
    })),
  forgotPassword: (email: string) =>
    data(client.post<ApiEnvelope<{ message: string }>>('/auth/forgot-password', { email })),
  resetPassword: (token: string, password: string) =>
    data(client.post<ApiEnvelope<{ reset: boolean }>>('/auth/reset-password', { token, password })),
};

export const healthApi = {
  check: (signal?: AbortSignal) =>
    data(client.get<ApiEnvelope<HealthStatus>>('/health', { signal })),
};

export const moduleApi = {
  financeSummary: () => data(client.get<ApiEnvelope<Record<string, unknown>>>('/finance/summary')),
  operationsSummary: () => data(client.get<ApiEnvelope<Record<string, unknown>>>('/operations/summary')),
  reportsSummary: () => data(client.get<ApiEnvelope<Record<string, unknown>>>('/reports/summary')),
  forecastSummary: () => data(client.get<ApiEnvelope<Record<string, unknown>>>('/forecast/summary')),
  riskAlerts: () => data(client.get<ApiEnvelope<Record<string, unknown>>>('/risk/alerts')),
  updateRiskAlertStatus: (alertId: number, status: string, reviewerUserId?: number | null, resolutionNotes?: string | null) =>
    data(client.patch<ApiEnvelope<Record<string, unknown>>>(`/risk/alerts/${alertId}/status`, {
      status,
      reviewer_user_id: reviewerUserId ?? null,
      resolution_notes: resolutionNotes ?? null,
    })),
  decisionScenarios: () => data(client.get<ApiEnvelope<Record<string, unknown>>>('/decisions/scenarios')),
  createDecisionScenario: (payload: Record<string, unknown>) =>
    data(client.post<ApiEnvelope<Record<string, unknown>>>('/decisions/scenarios', payload)),
  decisionScenarioDetail: (scenarioId: number) =>
    data(client.get<ApiEnvelope<Record<string, unknown>>>(`/decisions/scenarios/${scenarioId}`)),
  importsHistory: () => data(client.get<ApiEnvelope<Record<string, unknown>>>('/connect/imports')),
  documents: (search = '', category = '') =>
    data(client.get<ApiEnvelope<{ documents: DocumentRecord[]; categories: string[] }>>('/documents', {
      params: { search, category },
    })),
  uploadDocument: (form: FormData) =>
    data(client.post<ApiEnvelope<{ document: { id: number; version: number }; duplicate: boolean; extraction_status: string; text: string }>>(
      '/documents/upload', form, { headers: { 'Content-Type': 'multipart/form-data' } },
    )),
  documentVersions: (group: string) =>
    data(client.get<ApiEnvelope<{ versions: Array<Record<string, unknown>> }>>(`/documents/versions/${encodeURIComponent(group)}`)),
  documentExtraction: (id: number) =>
    data(client.get<ApiEnvelope<{ extraction: { id: number; original_name: string; extracted_text: string | null; extraction_status: string; reviewed_at: string | null } }>>(`/documents/${id}/extraction`)),
  reviewDocument: (id: number) =>
    data(client.post<ApiEnvelope<{ reviewed: boolean }>>(`/documents/${id}/review`)),
  uploadImport: (form: FormData) =>
    data(client.post<ApiEnvelope<{
      batch_id: number; file_name: string; data_type: string; headers: string[];
      fields: string[]; rows: Array<Record<string, string>>; row_count: number;
    }>>('/imports/upload', form, { headers: { 'Content-Type': 'multipart/form-data' } })),
  validateImport: (id: number, mapping: Record<string, string>) =>
    data(client.post<ApiEnvelope<{
      valid: boolean; row_count: number; error_count: number;
      preview: Array<{ row: number; values: Record<string, string>; errors: string[] }>;
    }>>(`/imports/${id}/validate`, { mapping })),
  dataImportHistory: () =>
    data(client.get<ApiEnvelope<{ imports: ImportBatch[] }>>('/imports')),
  commitImport: (id: number) =>
    data(client.post<ApiEnvelope<{ status: string; records_imported: number }>>(`/imports/${id}/commit`)),
  rollbackImport: (id: number) =>
    data(client.post<ApiEnvelope<{ status: string }>>(`/imports/${id}/rollback`)),
  importTemplate: async (type: string) => {
    const response = await client.get<Blob>(`/imports/templates/${encodeURIComponent(type)}`, { responseType: 'blob' });
    return response.data;
  },
  integrationOrganisations: () =>
    data(client.get<ApiEnvelope<{ organisations: IntegrationOrganisation[] }>>('/integrations/organisations')),
  integrations: (organisationId?: number) =>
    data(client.get<ApiEnvelope<{ integrations: IntegrationRecord[] }>>('/integrations', {
      params: organisationId ? { organisation_id: organisationId } : undefined,
    })),
  createIntegration: (payload: Record<string, unknown>, organisationId?: number) =>
    data(client.post<ApiEnvelope<{ integration_id: number; status: string }>>('/integrations', {
      ...payload, ...(organisationId ? { organisation_id: organisationId } : {}),
    })),
  testIntegration: (payload: Record<string, unknown>, organisationId?: number) =>
    data(client.post<ApiEnvelope<{ connected: boolean; http_status: number; records_detected: number }>>('/integrations/test', {
      ...payload, ...(organisationId ? { organisation_id: organisationId } : {}),
    })),
  activateIntegration: (id: number, organisationId?: number) =>
    data(client.post<ApiEnvelope<{ status: string; records_synced: number }>>(`/integrations/${id}/activate`, null, {
      params: organisationId ? { organisation_id: organisationId } : undefined,
    })),
  syncIntegration: (id: number, organisationId?: number) =>
    data(client.post<ApiEnvelope<{ status: string; records_synced: number; message: string }>>(`/integrations/${id}/sync`, null, {
      params: organisationId ? { organisation_id: organisationId } : undefined,
    })),
  previewImport: (fileName: string, csv: string) =>
    data(client.post<ApiEnvelope<Record<string, unknown>>>('/connect/imports/preview', { file_name: fileName, csv })),
  adminOverview: () => data(client.get<ApiEnvelope<Record<string, unknown>>>('/admin/overview')),
  adminUsers: () => data(client.get<ApiEnvelope<Record<string, unknown>>>('/admin/users')),
  adminBranches: () => data(client.get<ApiEnvelope<Record<string, unknown>>>('/admin/branches')),
  adminDepartments: () => data(client.get<ApiEnvelope<Record<string, unknown>>>('/admin/departments')),
  adminRoles: () => data(client.get<ApiEnvelope<{ roles: AdminRole[] }>>('/admin/roles')),
  adminAuditLogs: () => data(client.get<ApiEnvelope<{ audit_logs: AdminAuditLog[] }>>('/admin/audit-logs')),
  createBranch: (name: string, code: string) =>
    data(client.post<ApiEnvelope<{ id: number; name: string; code: string }>>('/admin/branches', { name, code })),
  createDepartment: (name: string, code: string, branchId?: number | null) =>
    data(client.post<ApiEnvelope<{ id: number; name: string; code: string }>>('/admin/departments', {
      name, code, branch_id: branchId ?? null,
    })),
  updateAccountStatus: (userId: number, status: 'active' | 'inactive' | 'suspended') =>
    data(client.patch<ApiEnvelope<{ account: { id: number; status: string } }>>(`/auth/accounts/${userId}/status`, { status })),
  updateAccountRoles: (userId: number, roleIds: number[]) =>
    data(client.put<ApiEnvelope<{ assignment: { user_id: number; roles: string[] } }>>(`/auth/accounts/${userId}/roles`, { role_ids: roleIds })),
  products: () => data(client.get<ApiEnvelope<{ products: ProductRecord[] }>>('/operations/products')),
  createProduct: (payload: NewProduct) =>
    data(client.post<ApiEnvelope<{ id: number; stock_level: number }>>('/operations/products', payload)),
  recordStockMovement: (productId: number, payload: StockMovementInput) =>
    data(client.post<ApiEnvelope<{ id: number; product_id: number; stock_level: number }>>(
      `/operations/products/${productId}/movements`, payload,
    )),
  sales: (limit = 50, offset = 0) =>
    data(client.get<ApiEnvelope<{ sales: SaleRecord[]; limit: number; offset: number }>>('/finance/sales', { params: { limit, offset } })),
  createSale: (payload: NewSale) =>
    data(client.post<ApiEnvelope<NewSaleResult>>('/finance/sales', payload)),
  refundSale: (saleId: number, reason: string) =>
    data(client.post<ApiEnvelope<{ id: number; status: string; amount: number }>>(`/finance/sales/${saleId}/refund`, { reason })),
  expenses: (limit = 50, offset = 0) =>
    data(client.get<ApiEnvelope<{ expenses: ExpenseRecord[]; limit: number; offset: number }>>('/finance/expenses', { params: { limit, offset } })),
  createExpense: (payload: NewExpense) =>
    data(client.post<ApiEnvelope<{ id: number; amount: string }>>('/finance/expenses', payload)),
  invoices: () => data(client.get<ApiEnvelope<{ invoices: InvoiceRecord[] }>>('/finance/invoices')),
  customers: () => data(client.get<ApiEnvelope<{ customers: CustomerRecord[] }>>('/finance/customers')),
  createCustomer: (payload: NewCustomer) =>
    data(client.post<ApiEnvelope<{ id: number; name: string; email: string | null; status: string }>>('/finance/customers', payload)),
  createInvoice: (payload: NewInvoice) =>
    data(client.post<ApiEnvelope<{ id: number; invoice_number: string; total: number; status: string }>>('/finance/invoices', payload)),
  invoiceDetail: (invoiceId: number) =>
    data(client.get<ApiEnvelope<InvoiceDetail>>(`/finance/invoices/${invoiceId}`)),
  recordInvoicePayment: (invoiceId: number, payload: InvoicePaymentInput) =>
    data(client.post<ApiEnvelope<InvoicePaymentResult>>(`/finance/invoices/${invoiceId}/payments`, payload)),
  createInvoiceCredit: (invoiceId: number, payload: NewCreditNote) =>
    data(client.post<ApiEnvelope<CreditNoteResult>>(`/finance/invoices/${invoiceId}/credit-notes`, payload)),
  suppliers: () => data(client.get<ApiEnvelope<{ suppliers: SupplierRecord[] }>>('/operations/suppliers')),
  createSupplier: (payload: NewSupplier) =>
    data(client.post<ApiEnvelope<{ id: number; name: string; contact_email: string | null; status: string }>>('/operations/suppliers', payload)),
  purchaseOrders: () => data(client.get<ApiEnvelope<{ purchase_orders: PurchaseOrderRecord[] }>>('/operations/purchase-orders')),
  createPurchaseOrder: (payload: NewPurchaseOrder) =>
    data(client.post<ApiEnvelope<{ id: number; total: number; status: string }>>('/operations/purchase-orders', payload)),
  submitPurchaseOrder: (orderId: number) =>
    data(client.post<ApiEnvelope<{ id: number; status: string }>>(`/operations/purchase-orders/${orderId}/submit`)),
  receivePurchaseOrder: (orderId: number) =>
    data(client.post<ApiEnvelope<{ id: number; status: string; item_count: number }>>(`/operations/purchase-orders/${orderId}/receive`)),
  financialReport: (from: string, to: string) =>
    data(client.get<ApiEnvelope<FinancialReport>>('/reports/financial', { params: { from, to } })),
};

export interface ProductRecord {
  id: number;
  name: string;
  sku: string;
  unit_cost: number;
  selling_price: number;
  stock_level: number;
  reorder_level: number;
  branch_id: number | null;
  branch_name: string | null;
  category_id: number | null;
  category_name: string | null;
}

export interface AdminRole {
  id: number;
  name: string;
  description: string | null;
  permissions: string | null;
}

export interface AdminAuditLog {
  id: number;
  action_type: string;
  entity_name: string;
  actor: string | null;
  created_at: string;
  metadata: Record<string, unknown>;
}

export interface NewProduct {
  name: string;
  sku: string;
  unit_cost: string;
  selling_price: string;
  reorder_level: number;
}

export interface StockMovementInput {
  movement_type: 'receipt' | 'adjustment' | 'return';
  quantity: number;
  notes?: string;
}

export interface SaleRecord {
  id: number;
  sale_date: string;
  subtotal: number;
  discount: number;
  tax: number;
  total: number;
  status: string;
  invoice_number: string | null;
  branch_name: string | null;
  customer_name: string | null;
  item_count: number;
}

export interface NewSale {
  items: Array<{ product_id: number; quantity: number }>;
  discount?: string;
  tax?: string;
}

export interface NewSaleResult {
  id: number;
  subtotal: number;
  discount: number;
  tax: number;
  total: number;
  status: string;
}

export interface ExpenseRecord {
  id: number;
  expense_date: string;
  amount: number;
  description: string;
  category_name: string | null;
  branch_name: string | null;
  department_name: string | null;
}

export interface NewExpense {
  amount: string;
  description: string;
}

export interface InvoiceRecord {
  id: number;
  invoice_number: string;
  issue_date: string;
  due_date: string;
  total: number | string;
  paid_amount: number | string;
  credited_amount: number | string;
  refunded_amount: number | string;
  balance_due: number | string;
  status: string;
  customer_name: string;
}

export interface CustomerRecord {
  id: number;
  name: string;
  email: string | null;
  status: string;
  created_at: string;
}

export interface NewCustomer {
  name: string;
  email?: string | null;
}

export interface NewInvoice {
  customer_id: number;
  branch_id?: number | null;
  issue_date?: string;
  due_date: string;
  tax_amount?: string;
  items: Array<{
    product_id: number | null;
    description?: string;
    quantity: number;
    unit_price?: string;
  }>;
}

export interface InvoiceItemRecord {
  id: number;
  product_id: number | null;
  description: string;
  quantity: number;
  credited_quantity: number;
  unit_price: number | string;
  unit_cost: number | string;
  total: number | string;
}

export interface InvoiceDetail {
  invoice: InvoiceRecord & { tax_amount: number | string; net_paid: number | string; net_total: number | string };
  items: InvoiceItemRecord[];
  credit_notes: CreditNoteRecord[];
}

export interface CreditNoteRecord {
  id: number;
  credit_number: string;
  reason: string;
  subtotal: number | string;
  tax_amount: number | string;
  total: number | string;
  stock_returned: number;
  created_at: string;
}

export interface NewCreditNote {
  reason: string;
  items: Array<{ invoice_item_id: number; quantity: number; return_to_stock: boolean }>;
  refund_method?: 'cash' | 'card' | 'bank_transfer' | 'cheque' | 'other';
  refund_reference?: string | null;
}

export interface CreditNoteResult {
  id: number;
  credit_number: string;
  invoice_id: number;
  subtotal: number;
  tax_amount: number;
  total: number;
  refunded: boolean;
  balance_due: number;
  invoice_status: string;
}

export interface InvoicePaymentInput {
  payment_date?: string;
  amount: string;
  method: 'cash' | 'card' | 'bank_transfer' | 'cheque' | 'other';
  reference?: string | null;
}

export interface InvoicePaymentResult {
  id: number;
  invoice_id: number;
  amount: number;
  paid_amount: number;
  balance_due: number;
  invoice_status: string;
}

export interface SupplierRecord {
  id: number;
  name: string;
  contact_email: string | null;
  status: string;
  created_at: string;
}

export interface NewSupplier {
  name: string;
  contact_email?: string | null;
}

export interface PurchaseOrderRecord {
  id: number;
  order_date: string;
  expected_date: string | null;
  status: 'draft' | 'submitted' | 'received' | 'closed';
  total: number | string;
  supplier_name: string;
  branch_name: string | null;
  item_count: number;
}

export interface NewPurchaseOrder {
  supplier_id: number;
  branch_id?: number | null;
  order_date?: string;
  expected_date?: string | null;
  items: Array<{ product_id: number; quantity: number; unit_cost: string }>;
}

export interface FinancialReport {
  from: string;
  to: string;
  revenue_by_day: Array<{ period: string; revenue: number; sale_count: number }>;
  expenses_by_day: Array<{ period: string; expenses: number; expense_count: number }>;
  refunds_by_day: Array<{ period: string; refunds: number; refund_count: number }>;
}

export function getErrorMessage(error: unknown): string {
  if (error instanceof ApiError) {
    return error.referenceId
      ? `${error.message} Reference ID: ${error.referenceId}`
      : error.message;
  }
  if (axios.isAxiosError(error)) return 'The VANTIQ service could not be reached.';
  return error instanceof Error ? error.message : 'An unexpected error occurred.';
}

export function configureSessionExpiryHandler(handler: () => void): () => void {
  const listener = () => handler();
  window.addEventListener('vantiq:session-expired', listener);
  return () => window.removeEventListener('vantiq:session-expired', listener);
}

export type RequestOptions = AxiosRequestConfig;
