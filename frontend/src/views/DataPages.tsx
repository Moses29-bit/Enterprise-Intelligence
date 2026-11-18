import { useEffect, useMemo, useState, type FormEvent, type ReactNode } from 'react';
import {
  getErrorMessage,
  moduleApi,
  type AuthUser,
  type DocumentRecord,
  type ImportBatch,
  type IntegrationRecord,
} from '../services/api';

const categories = [
  ['financial_statement', 'Financial statement'],
  ['contract', 'Contract'],
  ['audit_report', 'Audit report'],
  ['policy', 'Policy'],
  ['invoice_receipt', 'Invoice or receipt'],
  ['other', 'Other'],
];

const connectorCatalog = [
  { group: 'ERP', entries: ['Odoo', 'SAP Business One', 'Sage', 'QuickBooks', 'Zoho Books'] },
  { group: 'CRM', entries: ['HubSpot', 'Salesforce', 'Zoho CRM'] },
  { group: 'Operations', entries: ['HCM', 'SCM', 'POS'] },
  { group: 'Other', entries: ['Generic REST API', 'Database connection', 'File drop (CSV/XLSX)', 'Webhook'] },
];

function PageMessage({ children, error = false }: { children: string; error?: boolean }) {
  return <p className={error ? 'data-page-error' : 'data-page-notice'} role={error ? 'alert' : 'status'}>{children}</p>;
}

function PageButton({ children, disabled, onClick, type = 'button' }: {
  children: ReactNode; disabled?: boolean; onClick?: () => void; type?: 'button' | 'submit';
}) {
  return <button className="primary-button data-page-button" type={type} disabled={disabled} onClick={onClick}>{children}</button>;
}

export function DocumentVaultPage({ user }: { user: AuthUser }) {
  const canUpload = user.permissions.includes('documents.upload');
  const [documents, setDocuments] = useState<DocumentRecord[]>([]);
  const [search, setSearch] = useState('');
  const [category, setCategory] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');
  const [versions, setVersions] = useState<Array<Record<string, unknown>>>([]);
  const [extraction, setExtraction] = useState<{ id: number; original_name: string; extracted_text: string | null; extraction_status: string; reviewed_at: string | null } | null>(null);

  async function loadDocuments() {
    try {
      const result = await moduleApi.documents(search, category);
      setDocuments(result.documents);
      setError('');
    } catch (requestError) {
      setError(getErrorMessage(requestError));
    }
  }
  useEffect(() => { void loadDocuments(); }, [search, category]);

  async function upload(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const formElement = event.currentTarget;
    const form = new FormData(formElement);
    setBusy(true);
    setError('');
    setNotice('');
    try {
      const result = await moduleApi.uploadDocument(form);
      setNotice(result.duplicate
        ? `This file matches document ${result.document.id}, version ${result.document.version}; the existing document was opened instead of reprocessing the upload.`
        : result.extraction_status === 'available'
        ? `Document uploaded as version ${result.document.version}; extracted text is ready to review.`
        : `Document uploaded as version ${result.document.version}; text extraction is ${result.extraction_status}.`);
      formElement.reset();
      await loadDocuments();
      const detail = await moduleApi.documentExtraction(result.document.id);
      setExtraction(detail.extraction);
    } catch (requestError) {
      setError(getErrorMessage(requestError));
    } finally {
      setBusy(false);
    }
  }

  async function openVersions(group: string) {
    try {
      const result = await moduleApi.documentVersions(group);
      setVersions(result.versions);
      setError('');
    } catch (requestError) {
      setError(getErrorMessage(requestError));
    }
  }

  async function openExtraction(id: number) {
    try {
      const result = await moduleApi.documentExtraction(id);
      setExtraction(result.extraction);
      setError('');
    } catch (requestError) {
      setError(getErrorMessage(requestError));
    }
  }

  async function markReviewed() {
    if (!extraction) return;
    try {
      await moduleApi.reviewDocument(extraction.id);
      setNotice('Extraction review saved.');
      await openExtraction(extraction.id);
      await loadDocuments();
    } catch (requestError) {
      setError(getErrorMessage(requestError));
    }
  }

  return <div className="data-workspace-page">
    {error && <PageMessage error>{error}</PageMessage>}
    {notice && <PageMessage>{notice}</PageMessage>}
    <div className="data-page-toolbar">
      <label>Search documents<input value={search} onChange={(event) => setSearch(event.target.value)} placeholder="Search by file name" /></label>
      <label>Category<select value={category} onChange={(event) => setCategory(event.target.value)}>
        <option value="">All categories</option>{categories.map(([value, label]) => <option value={value} key={value}>{label}</option>)}
      </select></label>
    </div>
    {canUpload && <section className="panel-surface data-page-panel">
      <div className="panel-header"><div><h2>Upload a document</h2><p>PDF, Word, Excel, CSV, or text files up to 15 MB.</p></div></div>
      <form className="data-upload-form" onSubmit={upload}>
        <label>File<input name="file" type="file" accept=".docx,.pdf,.xlsx,.csv,.txt" required /></label>
        <label>Category<select name="category" defaultValue="other">{categories.map(([value, label]) => <option value={value} key={value}>{label}</option>)}</select></label>
        <PageButton type="submit" disabled={busy}>{busy ? 'Uploading…' : 'Upload document'}</PageButton>
      </form>
    </section>}
    <section className="panel-surface data-page-panel">
      <div className="panel-header"><div><h2>Documents</h2><p>Organisation files, extracted text, and version history.</p></div><span>{documents.length} shown</span></div>
      {documents.length === 0 ? <div className="data-empty-state">
        <h3>{canUpload ? 'Your document vault is ready' : 'No documents are available'}</h3>
        <p>{canUpload ? 'Upload a statement, contract, audit report, or other file to start a review.' : 'Documents shared with your organisation will appear here.'}</p>
        {canUpload && <a className="primary-button data-page-button" href="#document-upload" onClick={(event) => {
          event.preventDefault();
          document.querySelector<HTMLInputElement>('.data-upload-form input[type=file]')?.focus();
        }}>Upload your first document</a>}
      </div> : <div className="table-scroll"><table className="data-table">
        <thead><tr><th>Document</th><th>Category</th><th>Version</th><th>Extraction</th><th>Uploaded</th><th>Review</th></tr></thead>
        <tbody>{documents.map((document) => <tr key={document.id}>
          <td>{document.original_name}<small className="data-subline">{Math.ceil(document.file_size / 1024)} KB</small></td>
          <td>{categories.find(([key]) => key === document.category)?.[1] ?? document.category}</td>
          <td><button className="text-button" type="button" onClick={() => void openVersions(document.version_group)}>v{document.version_number} · history</button></td>
          <td><span className={`data-status ${document.extraction_status}`}>{document.extraction_status}</span></td>
          <td>{new Date(document.created_at).toLocaleDateString()}</td>
          <td><button className="text-button" type="button" onClick={() => void openExtraction(document.id)}>Review text</button></td>
        </tr>)}</tbody>
      </table></div>}
    </section>
    {versions.length > 0 && <section className="panel-surface data-page-panel">
      <div className="panel-header"><h2>Version history</h2><button className="text-button" type="button" onClick={() => setVersions([])}>Close</button></div>
      <ol className="data-version-list">{versions.map((version) => <li key={String(version.id)}>
        <strong>Version {String(version.version_number)}</strong><span>{String(version.original_name)}</span><time>{new Date(String(version.created_at)).toLocaleString()}</time>
      </li>)}</ol>
    </section>}
    {extraction && <section className="panel-surface data-page-panel" aria-live="polite">
      <div className="panel-header"><div><h2>Extraction review</h2><p>{extraction.original_name}</p></div><button className="text-button" type="button" onClick={() => setExtraction(null)}>Close</button></div>
      {extraction.extraction_status === 'available'
        ? <pre className="data-extraction-text">{extraction.extracted_text || 'No readable text was found.'}</pre>
        : <p className="data-empty-copy">Text extraction is {extraction.extraction_status}. The original file is retained securely for review.</p>}
      {canUpload && extraction.extraction_status === 'available' && (extraction.reviewed_at
        ? <p className="data-reviewed-label">Reviewed {new Date(extraction.reviewed_at).toLocaleString()}</p>
        : <PageButton onClick={() => void markReviewed()}>Mark review complete</PageButton>)}
    </section>}
  </div>;
}

export function ImportCenterPage({ user }: { user: AuthUser }) {
  const canImport = ['data.import', 'connect.manage', 'finance.write'].some((permission) => user.permissions.includes(permission));
  const [history, setHistory] = useState<ImportBatch[]>([]);
  const [uploadResult, setUploadResult] = useState<{
    batch_id: number; file_name: string; data_type: string; headers: string[];
    fields: string[]; rows: Array<Record<string, string>>; row_count: number;
  } | null>(null);
  const [mapping, setMapping] = useState<Record<string, string>>({});
  const [preview, setPreview] = useState<Array<{ row: number; values: Record<string, string>; errors: string[] }>>([]);
  const [validated, setValidated] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');

  async function loadHistory() {
    try {
      setHistory((await moduleApi.dataImportHistory()).imports);
      setError('');
    } catch (requestError) {
      setError(getErrorMessage(requestError));
    }
  }
  useEffect(() => { void loadHistory(); }, []);

  async function upload(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setBusy(true);
    setError('');
    setNotice('');
    try {
      const result = await moduleApi.uploadImport(new FormData(event.currentTarget));
      setUploadResult(result);
      const defaults = Object.fromEntries(result.fields
        .filter((field) => result.headers.includes(field))
        .map((field) => [field, field]));
      setMapping(defaults);
      setPreview([]);
      setValidated(false);
      setNotice(`${result.row_count} rows ready for column mapping.`);
      await loadHistory();
    } catch (requestError) {
      setError(getErrorMessage(requestError));
    } finally {
      setBusy(false);
    }
  }

  async function validate() {
    if (!uploadResult) return;
    setBusy(true);
    setError('');
    try {
      const result = await moduleApi.validateImport(uploadResult.batch_id, mapping);
      setPreview(result.preview);
      setValidated(result.valid);
      setNotice(result.valid ? 'All rows passed validation.' : `${result.error_count} row(s) need attention before import.`);
      await loadHistory();
    } catch (requestError) {
      setError(getErrorMessage(requestError));
    } finally {
      setBusy(false);
    }
  }

  async function commit() {
    if (!uploadResult) return;
    setBusy(true);
    setError('');
    try {
      const result = await moduleApi.commitImport(uploadResult.batch_id);
      setNotice(`${result.records_imported} records imported successfully.`);
      setUploadResult(null);
      setPreview([]);
      setValidated(false);
      await loadHistory();
    } catch (requestError) {
      setError(getErrorMessage(requestError));
    } finally {
      setBusy(false);
    }
  }

  async function rollback(batch: ImportBatch) {
    if (!window.confirm(`Remove records created by “${batch.file_name}”? This cannot be undone.`)) return;
    setBusy(true);
    try {
      await moduleApi.rollbackImport(batch.id);
      setNotice('Imported records were rolled back.');
      await loadHistory();
    } catch (requestError) {
      setError(getErrorMessage(requestError));
    } finally {
      setBusy(false);
    }
  }

  async function downloadTemplate(type: string) {
    try {
      const blob = await moduleApi.importTemplate(type);
      const url = URL.createObjectURL(blob);
      const link = document.createElement('a');
      link.href = url;
      link.download = `${type}-template.csv`;
      link.click();
      URL.revokeObjectURL(url);
    } catch (requestError) {
      setError(getErrorMessage(requestError));
    }
  }

  return <div className="data-workspace-page">
    {error && <PageMessage error>{error}</PageMessage>}
    {notice && <PageMessage>{notice}</PageMessage>}
    {canImport && <section className="panel-surface data-page-panel">
      <div className="panel-header"><div><h2>Start an import</h2><p>Upload CSV or XLSX, map fields, preview, and commit validated rows.</p></div></div>
      <form className="data-upload-form" onSubmit={upload}>
        <label>Data type<select name="data_type" defaultValue="expenses">
          <option value="sales">Sales</option><option value="expenses">Expenses</option>
          <option value="customers">Customers</option><option value="suppliers">Suppliers</option><option value="products">Products</option>
        </select></label>
        <label>CSV or XLSX file<input name="file" type="file" accept=".csv,.xlsx" required /></label>
        <PageButton type="submit" disabled={busy}>{busy ? 'Reading…' : 'Upload and map columns'}</PageButton>
      </form>
      <div className="data-template-row"><span>Download a template:</span>
        {['sales', 'expenses', 'customers', 'suppliers', 'products'].map((type) =>
          <button key={type} className="text-button" type="button" onClick={() => void downloadTemplate(type)}>{type}</button>)}
      </div>
    </section>}
    {uploadResult && canImport && <section className="panel-surface data-page-panel">
      <div className="panel-header"><div><h2>Map and validate</h2><p>{uploadResult.file_name} · {uploadResult.row_count} rows · {uploadResult.data_type}</p></div></div>
      <div className="data-mapping-grid">{uploadResult.fields.map((field) => <label key={field}>
        {field.replaceAll('_', ' ')}{['sale_date', 'expense_date', 'amount', 'description', 'name', 'sku'].includes(field) && <span> Required</span>}
        <select value={mapping[field] ?? ''} onChange={(event) => setMapping((current) => ({ ...current, [field]: event.target.value }))}>
          <option value="">Choose a source column</option>
          {uploadResult.headers.map((header) => <option value={header} key={header}>{header}</option>)}
        </select>
      </label>)}</div>
      <div className="data-page-actions"><PageButton onClick={() => void validate()} disabled={busy}>Validate preview</PageButton>
        {validated && <PageButton onClick={() => void commit()} disabled={busy}>Commit {String(uploadResult.row_count)} rows</PageButton>}
      </div>
      {preview.length > 0 && <div className="table-scroll"><table className="data-table"><thead><tr><th>Row</th>
        {uploadResult.fields.map((field) => <th key={field}>{field}</th>)}<th>Validation</th></tr></thead>
        <tbody>{preview.map((row) => <tr key={row.row}><td>{row.row}</td>
          {uploadResult.fields.map((field) => <td key={field}>{row.values[field] ?? '—'}</td>)}
          <td>{row.errors.length ? row.errors.join(' ') : 'Ready'}</td></tr>)}</tbody>
      </table></div>}
    </section>}
    <section className="panel-surface data-page-panel">
      <div className="panel-header"><div><h2>Import history</h2><p>Committed batches can be rolled back.</p></div></div>
      {history.length === 0 ? <div className="data-empty-state">
        <h3>{canImport ? 'No imports yet' : 'No import history is available'}</h3>
        <p>{canImport ? 'Upload your first financial data file to begin.' : 'A user with import access can start the first import.'}</p>
        {canImport && <a className="primary-button data-page-button" href="#import-upload" onClick={(event) => {
          event.preventDefault(); document.querySelector<HTMLInputElement>('.data-upload-form input[type=file]')?.focus();
        }}>Import financial data</a>}
      </div> : <div className="table-scroll"><table className="data-table"><thead><tr>
        <th>File</th><th>Type</th><th>Status</th><th>Rows</th><th>Created</th><th>Action</th></tr></thead><tbody>
        {history.map((batch) => <tr key={batch.id}><td>{batch.file_name}</td><td>{batch.data_type}</td>
          <td><span className={`data-status ${batch.status}`}>{batch.status}</span>{batch.error_count > 0 && ` · ${batch.error_count} errors`}</td>
          <td>{batch.row_count}</td><td>{new Date(batch.created_at).toLocaleString()}</td>
          <td>{batch.status === 'committed' && canImport
            ? <button className="text-button data-danger-action" type="button" disabled={busy} onClick={() => void rollback(batch)}>Rollback</button>
            : '—'}</td></tr>)}
      </tbody></table></div>}
    </section>
  </div>;
}

export function IntegrationHubPage({ user }: { user: AuthUser }) {
  const isPlatformAdmin = user.organisation === null && user.permissions.includes('platform.users.manage');
  const canManage = user.permissions.includes('integrations.manage') || isPlatformAdmin;
  const [organisations, setOrganisations] = useState<Array<{ id: number; name: string }>>([]);
  const [organisationId, setOrganisationId] = useState<number | null>(user.organisation?.id ?? null);
  const [organisationsLoading, setOrganisationsLoading] = useState(isPlatformAdmin);
  const [integrations, setIntegrations] = useState<IntegrationRecord[]>([]);
  const [selected, setSelected] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');
  const [tested, setTested] = useState(false);
  const [draftId, setDraftId] = useState<number | null>(null);

  async function loadIntegrations() {
    try {
      setIntegrations((await moduleApi.integrations(organisationId ?? undefined)).integrations);
      setError('');
    } catch (requestError) {
      setError(getErrorMessage(requestError));
    }
  }
  useEffect(() => {
    if (!isPlatformAdmin) return;
    void moduleApi.integrationOrganisations().then((result) => {
      setOrganisations(result.organisations);
      setOrganisationId((current) => current ?? result.organisations[0]?.id ?? null);
      if (result.organisations.length === 0) setError('There are no active organisations to configure.');
    }).catch((requestError) => {
      setError(getErrorMessage(requestError));
    }).finally(() => setOrganisationsLoading(false));
  }, [isPlatformAdmin]);
  useEffect(() => {
    if (canManage && organisationId !== null) void loadIntegrations();
  }, [canManage, organisationId]);

  const selectedGroup = useMemo(() => connectorCatalog.find((group) => group.entries.includes(selected))?.group ?? 'Other', [selected]);

  async function create(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setBusy(true);
    setError('');
    setNotice('');
    try {
      const form = new FormData(event.currentTarget);
      const mappingsText = String(form.get('field_mappings') ?? '{}');
      let mappings: Record<string, string>;
      try {
        mappings = JSON.parse(mappingsText) as Record<string, string>;
      } catch {
        throw new Error('Field mappings must be valid JSON, such as {"source":"target"}.');
      }
      const result = await moduleApi.createIntegration({
        name: form.get('name'),
        category: selectedGroup,
        connector_type: selected,
        endpoint_url: form.get('endpoint_url'),
        sync_schedule: form.get('sync_schedule'),
        field_mappings: mappings,
        credentials: { api_token: form.get('api_token'), username: form.get('username'), password: form.get('password') },
      }, organisationId ?? undefined);
      setDraftId(result.integration_id);
      setNotice('Connection saved securely as a draft. Activate it to verify the endpoint.');
      await loadIntegrations();
    } catch (requestError) {
      setError(getErrorMessage(requestError));
    } finally {
      setBusy(false);
    }
  }

  async function test(formElement: HTMLFormElement) {
    const form = new FormData(formElement);
    setBusy(true);
    setError('');
    setNotice('');
    try {
      const result = await moduleApi.testIntegration({
        endpoint_url: form.get('endpoint_url'),
        credentials: { api_token: form.get('api_token'), username: form.get('username'), password: form.get('password') },
      }, organisationId ?? undefined);
      setTested(true);
      setNotice(`Endpoint responded with HTTP ${result.http_status}; ${result.records_detected} record(s) detected.`);
    } catch (requestError) {
      setTested(false);
      setError(getErrorMessage(requestError));
    } finally {
      setBusy(false);
    }
  }

  async function activate(id: number) {
    setBusy(true);
    setError('');
    try {
      const result = await moduleApi.activateIntegration(id, organisationId ?? undefined);
      setNotice(`Connection verified and activated; ${result.records_synced} record(s) detected.`);
      setDraftId(null);
      await loadIntegrations();
    } catch (requestError) {
      setError(getErrorMessage(requestError));
    } finally {
      setBusy(false);
    }
  }

  async function sync(id: number) {
    setBusy(true);
    setError('');
    try {
      const result = await moduleApi.syncIntegration(id, organisationId ?? undefined);
      setNotice(result.message);
      await loadIntegrations();
    } catch (requestError) {
      setError(getErrorMessage(requestError));
    } finally {
      setBusy(false);
    }
  }

  if (!canManage) {
    return <div className="data-workspace-page"><PageMessage error>You need the Organisation Admin or System Admin role to manage system integrations.</PageMessage>
      <div className="data-empty-state"><h2>Integration access is restricted</h2><p>Ask an organisation administrator to configure data connections.</p></div></div>;
  }

  return <div className="data-workspace-page">
    {error && <PageMessage error>{error}</PageMessage>}
    {notice && <PageMessage>{notice}</PageMessage>}
    {isPlatformAdmin && <section className="panel-surface data-page-panel">
      <label>Organisation
        <select value={organisationId ?? ''} disabled={organisationsLoading || organisations.length === 0}
          onChange={(event) => {
            setOrganisationId(event.target.value ? Number(event.target.value) : null);
            setIntegrations([]);
            setSelected('');
            setDraftId(null);
            setTested(false);
          }}>
          <option value="">{organisationsLoading ? 'Loading organisations…' : 'Select an organisation'}</option>
          {organisations.map((organisation) => <option value={organisation.id} key={organisation.id}>{organisation.name}</option>)}
        </select>
      </label>
    </section>}
    <section className="panel-surface data-page-panel">
      <div className="panel-header"><div><h2>Connector gallery</h2><p>Configure HTTPS connection checks. Vendor-specific sync and database, file-drop, and webhook transports are not implemented yet.</p></div></div>
      <div className="connector-groups">{connectorCatalog.map((group) => <div key={group.group}>
        <h3>{group.group}</h3><div className="connector-gallery">{group.entries.map((entry) =>
          <button type="button" className={`connector-card${selected === entry ? ' selected' : ''}`} key={entry}
            disabled={organisationId === null || organisationsLoading}
            onClick={() => { setSelected(entry); setTested(false); setDraftId(null); }}>
            <span className="connector-card-mark">{entry.slice(0, 1)}</span><strong>{entry}</strong><small>Connection check</small>
          </button>)}</div>
      </div>)}</div>
    </section>
    {selected && <section className="panel-surface data-page-panel">
      <div className="panel-header"><div><h2>Connect {selected}</h2><p>Credentials are encrypted before storage and are never returned by the API.</p></div>
        <button className="text-button" type="button" onClick={() => { setSelected(''); setTested(false); setDraftId(null); }}>Cancel</button></div>
      <form className="data-connector-form" onSubmit={create}>
        <label>Connection name<input name="name" required maxLength={120} placeholder={`${selected} finance`} /></label>
        <label>HTTPS endpoint<input name="endpoint_url" type="url" required
          defaultValue={selected === 'Generic REST API' ? 'https://jsonplaceholder.typicode.com/posts/1' : ''}
          placeholder="https://api.example.com/v1/records" /></label>
        <div className="data-credential-grid"><label>API token<input type="password" name="api_token" autoComplete="new-password" /></label>
          <label>Username<input name="username" autoComplete="off" /></label>
          <label>Password<input type="password" name="password" autoComplete="new-password" /></label></div>
        <label>Field mappings (JSON)<textarea name="field_mappings" defaultValue="{}" rows={3} /></label>
        <label>Sync schedule<select name="sync_schedule"><option value="manual">Manual</option><option value="hourly">Hourly</option>
          <option value="daily">Daily</option><option value="weekly">Weekly</option></select></label>
        <div className="data-page-actions">
          <PageButton type="button" disabled={busy || organisationId === null} onClick={() => {
            const form = document.querySelector<HTMLFormElement>('.data-connector-form');
            if (form) void test(form);
          }}>{busy ? 'Testing…' : 'Test connection'}</PageButton>
          <PageButton type="submit" disabled={busy || organisationId === null}>{busy ? 'Saving…' : 'Save as draft'}</PageButton>
        </div>
      </form>
      {draftId && <div className="data-page-actions"><PageButton disabled={busy || organisationId === null} onClick={() => void activate(draftId)}>Activate connection</PageButton></div>}
      {tested && <p className="data-reviewed-label">Connection test passed. Save the settings to continue setup.</p>}
    </section>}
    <section className="panel-surface data-page-panel">
      <div className="panel-header"><div><h2>Connection health</h2><p>Checks verify endpoint availability; they do not write remote business data.</p></div><span>{integrations.length} systems</span></div>
      {integrations.length === 0 ? <div className="data-empty-state">
        <h3>Connect your first system</h3><p>Choose an ERP, CRM, or REST connector to start a connection setup.</p>
        <PageButton onClick={() => document.querySelector('.connector-gallery')?.scrollIntoView({ behavior: 'smooth', block: 'center' })}>Choose a system</PageButton>
      </div> : <div className="table-scroll"><table className="data-table"><thead><tr>
        <th>System</th><th>Status</th><th>Last checked</th><th>Records detected</th><th>Errors</th><th>Action</th></tr></thead>
        <tbody>{integrations.map((integration) => <tr key={integration.id}><td><strong>{integration.name}</strong><small className="data-subline">{integration.connector_type} · {integration.sync_schedule}</small></td>
          <td><span className={`data-status ${integration.status}`}>{integration.status}</span></td>
          <td>{integration.last_synced_at ? new Date(integration.last_synced_at).toLocaleString() : 'Not synced'}</td>
          <td>{integration.records_synced}</td><td>{integration.last_error ?? '—'}</td>
          <td>{integration.status === 'draft' || integration.status === 'error'
            ? <button className="text-button" type="button" disabled={busy} onClick={() => void activate(integration.id)}>Activate</button>
            : <button className="text-button" type="button" disabled={busy || organisationId === null} onClick={() => void sync(integration.id)}>Sync now</button>}</td>
        </tr>)}</tbody></table></div>}
    </section>
  </div>;
}
