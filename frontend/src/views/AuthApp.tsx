import { lazy, Suspense, useEffect, useMemo, useRef, useState, type FormEvent, type ReactNode } from 'react';
import { Command } from 'cmdk';
import * as Dialog from '@radix-ui/react-dialog';
import { Route, Routes, useLocation, useNavigate } from 'react-router-dom';
import {
  ApiError,
  authApi,
  configureSessionExpiryHandler,
  getErrorMessage,
  healthApi,
  moduleApi,
  type HealthStatus,
  type AuthUser,
} from '../services/api';
import { Icon, type IconName } from '../components/Icon';
import { WorkspaceModule, type WorkspaceModuleKey } from './WorkspaceModule';

const LazyDocumentVaultPage = lazy(async () => ({
  default: (await import('./DataPages')).DocumentVaultPage,
}));
const LazyImportCenterPage = lazy(async () => ({
  default: (await import('./DataPages')).ImportCenterPage,
}));
const LazyIntegrationHubPage = lazy(async () => ({
  default: (await import('./DataPages')).IntegrationHubPage,
}));
type TypographyShowcaseModule = typeof import('./TypographyShowcase');
const LazyTypographyShowcase = lazy(async () => {
  const moduleUrl = '/src/views/TypographyShowcase.tsx';
  const showcaseModule = await import(/* @vite-ignore */ moduleUrl) as TypographyShowcaseModule;
  return { default: showcaseModule.TypographyShowcase };
});

type View = 'login' | 'register' | 'forgot' | 'reset' | 'password' | 'workspace';
type DataDestinationKey = 'import' | 'documents' | 'integrations';
type LiveConnectionStatus = 'connected' | 'reconnecting' | 'disconnected';
type NavigationKey = WorkspaceModuleKey | DataDestinationKey;

interface ModuleDefinition {
  key: NavigationKey;
  label: string;
  description: string;
  permissions: string[];
  icon: WorkspaceModuleKey;
}

interface VisibleNavigationGroup {
  label: string;
  modules: ModuleDefinition[];
}

const modules: ModuleDefinition[] = [
  { key: 'command', label: 'Command', description: 'Executive intelligence centre', permissions: ['finance.read', 'operations.read', 'risks.read', 'reports.read'], icon: 'command' },
  { key: 'finance', label: 'Finance', description: 'Revenue, spend and performance', permissions: ['finance.read'], icon: 'finance' },
  { key: 'operations', label: 'Operations', description: 'Inventory and procurement', permissions: ['operations.read'], icon: 'operations' },
  { key: 'forecast', label: 'Forecast', description: 'Evidence-based projections', permissions: ['operations.read'], icon: 'forecast' },
  { key: 'risk', label: 'Risk', description: 'Anomaly detection and review', permissions: ['risks.read'], icon: 'risk' },
  { key: 'decisions', label: 'Decisions', description: 'Scenario planning studio', permissions: ['decisions.manage'], icon: 'decisions' },
  { key: 'reports', label: 'Reports', description: 'Operational reporting', permissions: ['reports.read'], icon: 'reports' },
  { key: 'connect', label: 'Connect', description: 'Data import centre', permissions: ['connect.manage'], icon: 'connect' },
  { key: 'control', label: 'Control', description: 'Administration and governance', permissions: ['users.manage'], icon: 'control' },
];

const dataDestinations: ModuleDefinition[] = [
  { key: 'import', label: 'Import Center', description: 'Map, validate and import business data', permissions: ['data.import', 'connect.manage', 'finance.write'], icon: 'connect' },
  { key: 'documents', label: 'Document Vault', description: 'Upload and review organisation documents', permissions: ['documents.read', 'documents.upload'], icon: 'reports' },
  { key: 'integrations', label: 'Integration Hub', description: 'Configure and monitor system connections', permissions: ['integrations.manage', 'platform.users.manage'], icon: 'control' },
];

const navigationGroups: Array<{ label: string; keys: NavigationKey[] }> = [
  { label: 'Intelligence', keys: ['command', 'forecast', 'risk', 'decisions'] },
  { label: 'Business Operations', keys: ['finance', 'operations'] },
  { label: 'Reporting & Integration', keys: ['reports', 'connect', 'import', 'documents', 'integrations'] },
  { label: 'Administration', keys: ['control'] },
];

interface GlobalSearchResult {
  id: string;
  title: string;
  detail: string;
  module: WorkspaceModuleKey;
}

interface NotificationAlert {
  id: number;
  category: string;
  severity: string;
  explanation: string;
  status: string;
}

type ThemePreference = 'system' | 'light' | 'dark';
type DensityPreference = 'comfortable' | 'compact';

interface AppearancePreference {
  theme: ThemePreference;
  density: DensityPreference;
}

interface BootstrapFailure {
  sessionDetails: string;
  healthDetails: string;
  backendReachable: boolean;
  databaseReachable: boolean | null;
  javaVersion: number | null;
}

type SearchRecord = Record<string, unknown>;

const defaultAppearance: AppearancePreference = { theme: 'system', density: 'comfortable' };

function describeBootstrapError(error: unknown): string {
  if (error instanceof ApiError) {
    const status = error.status > 0 ? `HTTP ${error.status}` : 'No HTTP response';
    return `${status}; ${error.code}: ${error.message}`;
  }
  if (error instanceof Error) return `${error.name}: ${error.message}`;
  return String(error);
}

function readAppearancePreference(userId: number): AppearancePreference {
  let stored: string | null;
  try {
    stored = localStorage.getItem(`vantiq:appearance:${userId}`);
  } catch (storageError) {
    console.error('Unable to read saved appearance preferences.', storageError);
    return defaultAppearance;
  }
  if (!stored) return defaultAppearance;

  let parsed: unknown;
  try {
    parsed = JSON.parse(stored);
  } catch (parseError) {
    console.error('Saved appearance preferences could not be parsed.', parseError);
    return defaultAppearance;
  }
  if (typeof parsed !== 'object' || parsed === null) return defaultAppearance;

  const preference = parsed as Partial<AppearancePreference>;
  return {
    theme: preference.theme === 'light' || preference.theme === 'dark' ? preference.theme : 'system',
    density: preference.density === 'compact' ? 'compact' : 'comfortable',
  };
}

function applyThemePreference(theme: ThemePreference) {
  const root = document.documentElement;
  root.dataset.themeChoice = theme;
  root.dataset.theme = theme === 'system'
    ? (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light')
    : theme;
}

function searchRecords(value: unknown): SearchRecord[] {
  return Array.isArray(value) ? value.filter((item): item is SearchRecord =>
    typeof item === 'object' && item !== null && !Array.isArray(item)) : [];
}

function moduleFromPath(path: string): WorkspaceModuleKey {
  const firstSegment = path.split('/').filter(Boolean)[0];
  if (firstSegment === 'control') return 'control';
  return (modules.find((item) => item.key === firstSegment)?.key as WorkspaceModuleKey | undefined) ?? 'command';
}

export function AuthApp() {
  const routerNavigate = useNavigate();
  const location = useLocation();
  const [view, setView] = useState<View>(new URLSearchParams(location.search).has('token') ? 'reset' : 'login');
  const [user, setUser] = useState<AuthUser | null>(null);
  const [module, setModule] = useState<WorkspaceModuleKey>(() => moduleFromPath(location.pathname));
  const [busy, setBusy] = useState(false);
  const [checkingSession, setCheckingSession] = useState(true);
  const [bootstrapRetry, setBootstrapRetry] = useState(0);
  const [bootstrapFailure, setBootstrapFailure] = useState<BootstrapFailure | null>(null);
  const [showPassword, setShowPassword] = useState(false);
  const [showCurrentPassword, setShowCurrentPassword] = useState(false);
  const authenticatedSession = useRef(false);
  const [openNavigationGroup, setOpenNavigationGroup] = useState<string | null>(null);
  const navigationRef = useRef<HTMLElement | null>(null);
  const [commandOpen, setCommandOpen] = useState(false);
  const [commandQuery, setCommandQuery] = useState('');
  const [shortcutsOpen, setShortcutsOpen] = useState(false);
  const [pendingCommandTarget, setPendingCommandTarget] = useState<string | null>(null);
  const [theme, setTheme] = useState<ThemePreference>(() => {
    const choice = document.documentElement.dataset.themeChoice;
    return choice === 'light' || choice === 'dark' ? choice : 'system';
  });
  const [density, setDensity] = useState<DensityPreference>(() =>
    document.documentElement.dataset.density === 'compact' ? 'compact' : 'comfortable');
  const appearanceOwner = useRef<number | null>(null);
  const [mobileNavigationOpen, setMobileNavigationOpen] = useState(false);
  const [workspaceSearch, setWorkspaceSearch] = useState('');
  const [globalSearch, setGlobalSearch] = useState('');
  const [searchResults, setSearchResults] = useState<GlobalSearchResult[]>([]);
  const [searchOpen, setSearchOpen] = useState(false);
  const [searching, setSearching] = useState(false);
  const [notificationsOpen, setNotificationsOpen] = useState(false);
  const [notificationLoading, setNotificationLoading] = useState(false);
  const [notificationAlerts, setNotificationAlerts] = useState<NotificationAlert[] | null>(null);
  const [headerProfileOpen, setHeaderProfileOpen] = useState(false);
  const [notice, setNotice] = useState('');
  const [error, setError] = useState('');
  const [liveConnectionStatus, setLiveConnectionStatus] = useState<LiveConnectionStatus>('disconnected');
  const [lastBusinessEventAt, setLastBusinessEventAt] = useState<number | null>(null);

  const availableModules = useMemo(
    () => modules.filter((item) => item.permissions.some((permission) => user?.permissions.includes(permission))),
    [user],
  );
  const availableDataDestinations = useMemo(
    () => dataDestinations.filter((item) => item.permissions.some((permission) => user?.permissions.includes(permission))),
    [user],
  );
  const activeDataDestination = dataDestinations.find((item) => dataDestinationPaths[item.key as DataDestinationKey] === location.pathname);
  const activeModule = activeDataDestination ?? modules.find((item) => item.key === module) ?? modules[0];

  useEffect(() => {
    applyThemePreference(theme);
    document.documentElement.dataset.density = density;
  }, [theme, density]);

  useEffect(() => {
    if (!user || view !== 'workspace') {
      setLiveConnectionStatus('disconnected');
      setLastBusinessEventAt(null);
      return undefined;
    }

    const apiBase = import.meta.env.VITE_API_URL?.trim().replace(/\/+$/, '') || '/api';
    const streamUrl = `${apiBase.replace(/\/+$/, '')}/events/stream`;
    const source = new EventSource(streamUrl, { withCredentials: true });
    const seenEventIds = new Set<string>();
    setLiveConnectionStatus('reconnecting');
    source.onopen = () => setLiveConnectionStatus('connected');
    source.onerror = () => setLiveConnectionStatus(
      source.readyState === EventSource.CLOSED ? 'disconnected' : 'reconnecting',
    );
    const onBusinessChange = (message: MessageEvent<string>) => {
      try {
        const detail: unknown = JSON.parse(message.data);
        if (typeof detail !== 'object' || detail === null
          || !('event_id' in detail) || typeof detail.event_id !== 'string'
          || !('modules' in detail) || !Array.isArray(detail.modules)) {
          throw new Error('The event stream returned an invalid business update.');
        }
        if (seenEventIds.has(detail.event_id)) return;
        seenEventIds.add(detail.event_id);
        if (seenEventIds.size > 500) {
          const oldest = seenEventIds.values().next().value as string | undefined;
          if (oldest) seenEventIds.delete(oldest);
        }
        setLastBusinessEventAt(Date.now());
        window.dispatchEvent(new CustomEvent('vantiq:business-change', { detail }));
      } catch (eventError) {
        console.error('Unable to apply a business event notification.', eventError);
      }
    };
    source.addEventListener('business-change', onBusinessChange as EventListener);

    return () => {
      source.removeEventListener('business-change', onBusinessChange as EventListener);
      source.close();
      setLiveConnectionStatus('disconnected');
    };
  }, [user?.id, view]);

  useEffect(() => {
    if (!user) return;
    if (appearanceOwner.current !== user.id) {
      appearanceOwner.current = user.id;
      const preference = readAppearancePreference(user.id);
      setTheme(preference.theme);
      setDensity(preference.density);
      try {
        localStorage.setItem('vantiq:last-user', String(user.id));
      } catch (storageError) {
        console.error('Unable to remember the active appearance profile.', storageError);
        setError('Appearance preferences could not be saved in this browser.');
      }
      return;
    }

    try {
      localStorage.setItem(`vantiq:appearance:${user.id}`, JSON.stringify({ theme, density }));
      localStorage.setItem('vantiq:last-user', String(user.id));
    } catch (storageError) {
      console.error('Unable to save appearance preferences.', storageError);
      setError('Appearance preferences could not be saved in this browser.');
    }
  }, [density, theme, user?.id]);

  useEffect(() => {
    let active = true;
    const controller = new AbortController();
    setCheckingSession(true);
    setBootstrapFailure(null);

    async function bootstrapSession() {
      let sessionError: unknown;
      try {
        for (let attempt = 0; attempt < 2; attempt += 1) {
          try {
            const currentUser = await authApi.me(controller.signal);
            if (!active) return;
            authenticatedSession.current = true;
            setUser(currentUser);
            setView('workspace');
            return;
          } catch (requestError: unknown) {
            if (!active || controller.signal.aborted) return;
            if (requestError instanceof ApiError && requestError.status === 401) {
              authenticatedSession.current = false;
              setUser(null);
              setView('login');
              return;
            }
            sessionError = requestError;
          }
        }

        if (!active) return;
        let health: HealthStatus | null = null;
        let healthError: unknown;
        try {
          health = await healthApi.check(controller.signal);
        } catch (requestError: unknown) {
          healthError = requestError;
        }
        if (!active || controller.signal.aborted) return;

        setBootstrapFailure({
          sessionDetails: describeBootstrapError(sessionError),
          healthDetails: health
            ? `Health endpoint returned ${health.status}.`
            : describeBootstrapError(healthError),
          backendReachable: health !== null,
          databaseReachable: health?.database.connected ?? null,
          javaVersion: health?.java_version ?? null,
        });
      } finally {
        if (active) setCheckingSession(false);
      }
    }

    void bootstrapSession();

    const unsubscribe = configureSessionExpiryHandler(() => {
      if (!authenticatedSession.current) return;
      authenticatedSession.current = false;
      setUser(null);
      setView('login');
      setError('');
      setNotice('Your session expired. Please sign in again.');
    });
    return () => {
      active = false;
      controller.abort();
      unsubscribe();
    };
  }, [bootstrapRetry]);

  useEffect(() => {
    if (user && !availableModules.some((item) => item.key === module)) {
      setModule((availableModules[0]?.key as WorkspaceModuleKey | undefined) ?? 'command');
    }
  }, [availableModules, module, user]);

  useEffect(() => {
    if (!location.pathname.startsWith('/data/')) {
      setModule(moduleFromPath(location.pathname));
    }
    setOpenNavigationGroup(null);
    setMobileNavigationOpen(false);
    setHeaderProfileOpen(false);
  }, [location.pathname]);

  useEffect(() => {
    function closeNavigation(event: MouseEvent) {
      if (event.target instanceof Node && !navigationRef.current?.contains(event.target)) {
        setOpenNavigationGroup(null);
      }
    }
    function closeOnEscape(event: KeyboardEvent) {
      if (event.key === 'Escape') {
        setOpenNavigationGroup(null);
        setMobileNavigationOpen(false);
      }
    }
    document.addEventListener('pointerdown', closeNavigation);
    window.addEventListener('keydown', closeOnEscape);
    return () => {
      document.removeEventListener('pointerdown', closeNavigation);
      window.removeEventListener('keydown', closeOnEscape);
    };
  }, []);

  useEffect(() => {
    function handleSearchShortcut(event: KeyboardEvent) {
      if (user && view === 'workspace' && (event.ctrlKey || event.metaKey) && event.key.toLocaleLowerCase() === 'k') {
        event.preventDefault();
        setCommandQuery('');
        setCommandOpen(true);
      } else if (user && view === 'workspace' && !commandOpen && !searchOpen && event.key === '?'
        && !(event.target instanceof HTMLElement && event.target.matches('input, textarea, select, [contenteditable="true"]'))) {
        event.preventDefault();
        setShortcutsOpen(true);
      }
    }
    window.addEventListener('keydown', handleSearchShortcut);
    return () => window.removeEventListener('keydown', handleSearchShortcut);
  }, [commandOpen, searchOpen, user, view]);

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const formElement = event.currentTarget;
    const form = new FormData(formElement);
    setError('');
    setNotice('');
    setBusy(true);
    try {
      if (view === 'login') {
        const signedIn = await authApi.login(String(form.get('email')), String(form.get('password')));
        authenticatedSession.current = true;
        setUser(signedIn);
        setView('workspace');
      } else if (view === 'register') {
        const registered = await authApi.register(
          String(form.get('organisation_name')),
          String(form.get('name')),
          String(form.get('email')),
          String(form.get('password')),
        );
        authenticatedSession.current = true;
        setUser(registered);
        setView('workspace');
      } else if (view === 'forgot') {
        const result = await authApi.forgotPassword(String(form.get('email')));
        setNotice(result.message);
      } else if (view === 'reset') {
        const result = await authApi.resetPassword(
          new URLSearchParams(location.search).get('token') ?? '',
          String(form.get('password')),
        );
        if (result.reset) {
          history.replaceState(null, '', location.pathname);
          setNotice('Password reset completed. Sign in with your new password.');
          setView('login');
        }
      } else if (view === 'password') {
        await authApi.changePassword(String(form.get('current_password')), String(form.get('new_password')));
        setNotice('Password changed. Other active sessions were invalidated.');
        setView('workspace');
      }
      formElement.reset();
    } catch (requestError) {
      setError(getErrorMessage(requestError));
    } finally {
      setBusy(false);
    }
  }

  async function logout() {
    setError('');
    try {
      await authApi.logout();
      authenticatedSession.current = false;
      setUser(null);
      setView('login');
      setNotice('You have been signed out.');
    } catch (requestError) {
      setError(getErrorMessage(requestError));
    }
  }

  function navigate(nextView: View) {
    setView(nextView);
    setError('');
    setNotice('');
    setShowPassword(false);
    setShowCurrentPassword(false);
  }

  function navigateToModule(nextModule: WorkspaceModuleKey, focusTarget?: string) {
    setModule(nextModule);
    routerNavigate(nextModule === 'command' ? '/' : `/${nextModule}`);
    setWorkspaceSearch('');
    setMobileNavigationOpen(false);
    setOpenNavigationGroup(null);
    setSearchOpen(false);
    setCommandOpen(false);
    setPendingCommandTarget(focusTarget ?? null);
  }

  function navigateToControlSection(section: 'overview' | 'users' | 'roles' | 'structure' | 'audit-logs') {
    setModule('control');
    routerNavigate(`/control/${section}`);
    setWorkspaceSearch('');
    setMobileNavigationOpen(false);
    setOpenNavigationGroup(null);
  }

  function navigateToDataDestination(destination: DataDestinationKey) {
    routerNavigate(dataDestinationPaths[destination]);
    setWorkspaceSearch('');
    setMobileNavigationOpen(false);
    setSearchOpen(false);
    setCommandOpen(false);
  }

  function activateNavigationItem(item: ModuleDefinition) {
    if (item.key === 'import' || item.key === 'documents' || item.key === 'integrations') {
      navigateToDataDestination(item.key);
    } else {
      navigateToModule(item.key);
    }
    setWorkspaceSearch('');
    setMobileNavigationOpen(false);
    setOpenNavigationGroup(null);
  }

  function renderNavigationGroup(group: VisibleNavigationGroup, index: number, isMore = false) {
    const expanded = openNavigationGroup === group.label;
    const groupIcon: IconName = group.label === 'Intelligence' ? 'command'
      : group.label === 'Business Operations' ? 'operations'
        : group.label === 'Reporting & Integration' ? 'reports'
          : group.label === 'Administration' ? 'control'
            : 'more';
    const controlSections: Array<{ key: 'overview' | 'users' | 'roles' | 'structure' | 'audit-logs'; label: string }> = [
      { key: 'overview', label: 'Overview' },
      { key: 'users', label: 'Users' },
      { key: 'roles', label: 'Roles' },
      { key: 'structure', label: 'Organisation structure' },
      { key: 'audit-logs', label: 'Audit logs' },
    ];

    return <div className={`navigation-group${index > 1 && !isMore ? ' navigation-group-overflow' : ''}${isMore ? ' navigation-group-more' : ''}`}
      key={`${group.label}-${isMore ? 'more' : 'primary'}`}>
      <button type="button" className={`navigation-group-trigger${expanded ? ' expanded' : ''}`}
        aria-label={`Open ${group.label} menu`}
        aria-haspopup="menu" aria-expanded={expanded}
        onClick={() => setOpenNavigationGroup((current) => current === group.label ? null : group.label)}
        onKeyDown={(event) => {
          if (event.key === 'ArrowDown' || event.key === 'Enter' || event.key === ' ') {
            event.preventDefault();
            setOpenNavigationGroup(group.label);
            window.requestAnimationFrame(() => navigationRef.current
              ?.querySelector<HTMLElement>(`[data-navigation-menu="${group.label}"] button`)?.focus());
          }
        }}>
        <Icon name={groupIcon} />
        <span>{group.label}</span>
        <Icon name="chevron" />
      </button>
      {expanded && <div className="navigation-dropdown" role="menu" aria-label={`${group.label} navigation`}
        data-navigation-menu={group.label}
        onKeyDown={(event) => {
          const items = Array.from(event.currentTarget.querySelectorAll<HTMLButtonElement>('[role="menuitem"]'));
          const activeIndex = items.indexOf(document.activeElement as HTMLButtonElement);
          if (event.key === 'Escape') {
            event.preventDefault();
            setOpenNavigationGroup(null);
            navigationRef.current?.querySelector<HTMLButtonElement>(`[aria-label="Open ${group.label} menu"]`)?.focus();
          } else if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
            event.preventDefault();
            const direction = event.key === 'ArrowDown' ? 1 : -1;
            items[(activeIndex + direction + items.length) % items.length]?.focus();
          } else if (event.key === 'Home' || event.key === 'End') {
            event.preventDefault();
            items[event.key === 'Home' ? 0 : items.length - 1]?.focus();
          }
        }}>
        {group.modules.map((item) => item.key === 'control'
          ? controlSections.map((section) => <button key={section.key} type="button" role="menuitem"
            className={`navigation-menu-item${location.pathname === `/control/${section.key}` ? ' active' : ''}`}
            onClick={() => navigateToControlSection(section.key)}>
            <Icon name="control" /><span>{section.label}</span>
          </button>)
          : <button key={item.key} type="button" role="menuitem"
            className={`navigation-menu-item${(item.key === 'import' || item.key === 'documents' || item.key === 'integrations')
              ? activeDataDestination?.key === item.key ? ' active' : ''
              : module === item.key && !activeDataDestination ? ' active' : ''}`}
            onClick={() => activateNavigationItem(item)}>
            <Icon name={item.icon} />
            <span>{item.label}</span>
          </button>)}
      </div>}
    </div>;
  }

  async function searchOrganisation(term: string) {
    const query = term.trim().toLocaleLowerCase();
    if (query.length < 2) {
      setSearchResults([]);
      setError('Enter at least two characters to search your organisation.');
      return;
    }

    setSearching(true);
    setError('');
    const results: GlobalSearchResult[] = [];
    const includesQuery = (...values: unknown[]) => values
      .filter((value): value is string | number => typeof value === 'string' || typeof value === 'number')
      .some((value) => String(value).toLocaleLowerCase().includes(query));
    const addMatches = <T,>(
      records: T[],
      matches: (record: T) => boolean,
      toResult: (record: T) => GlobalSearchResult,
    ) => records.filter(matches).forEach((record) => results.push(toResult(record)));
    const tasks: Promise<void>[] = [];
    const permissionsForSearch = new Set(user?.permissions ?? []);

    if (permissionsForSearch.has('finance.read')) {
      tasks.push(moduleApi.customers().then(({ customers }) => addMatches(
        customers,
        (item) => includesQuery(item.name, item.email),
        (item) => ({ id: `customer-${item.id}`, title: item.name, detail: `Customer · ${item.email ?? item.status}`, module: 'finance' }),
      )));
      tasks.push(moduleApi.invoices().then(({ invoices }) => addMatches(
        invoices,
        (item) => includesQuery(item.invoice_number, item.customer_name, item.status),
        (item) => ({ id: `invoice-${item.id}`, title: item.invoice_number, detail: `Invoice · ${item.customer_name} · ${item.status}`, module: 'finance' }),
      )));
      tasks.push(moduleApi.sales().then(({ sales }) => addMatches(
        sales,
        (item) => includesQuery(item.id, item.customer_name, item.invoice_number, item.status),
        (item) => ({ id: `sale-${item.id}`, title: `Sale ${item.id}`, detail: `Transaction · ${item.customer_name ?? item.status}`, module: 'finance' }),
      )));
      tasks.push(moduleApi.expenses().then(({ expenses }) => addMatches(
        expenses,
        (item) => includesQuery(item.description, item.category_name, item.branch_name),
        (item) => ({ id: `expense-${item.id}`, title: item.description, detail: `Expense · ${item.expense_date}`, module: 'finance' }),
      )));
    }

    if (permissionsForSearch.has('operations.read')) {
      tasks.push(moduleApi.products().then(({ products }) => addMatches(
        products,
        (item) => includesQuery(item.name, item.sku, item.category_name),
        (item) => ({ id: `product-${item.id}`, title: item.name, detail: `Product · ${item.sku}`, module: 'operations' }),
      )));
    }
    if (permissionsForSearch.has('operations.read')) {
      tasks.push(moduleApi.suppliers().then(({ suppliers }) => addMatches(
        suppliers,
        (item) => includesQuery(item.name, item.contact_email),
        (item) => ({ id: `supplier-${item.id}`, title: item.name, detail: `Supplier · ${item.contact_email ?? item.status}`, module: 'operations' }),
      )));
    }
    if (permissionsForSearch.has('risks.read')) {
      tasks.push(moduleApi.riskAlerts().then((data) => {
        const alerts = Array.isArray(data.alerts) ? data.alerts as NotificationAlert[] : [];
        addMatches(
          alerts,
          (item) => includesQuery(item.category, item.severity, item.explanation),
          (item) => ({ id: `risk-${item.id}`, title: item.category, detail: `Risk · ${item.severity} · ${item.status}`, module: 'risk' }),
        );
      }));
    }
    if (permissionsForSearch.has('decisions.manage')) {
      tasks.push(moduleApi.decisionScenarios().then(({ scenarios }) => addMatches(
        searchRecords(scenarios),
        (item) => includesQuery(item.name, item.description, item.status),
        (item) => ({ id: `scenario-${item.id}`, title: String(item.name ?? 'Scenario'), detail: `Decision scenario · ${String(item.status ?? '')}`, module: 'decisions' }),
      )));
    }
    if (permissionsForSearch.has('connect.manage')) {
      tasks.push(moduleApi.importsHistory().then(({ imports }) => addMatches(
        searchRecords(imports),
        (item) => includesQuery(item.file_name, item.status),
        (item) => ({ id: `import-${item.id}`, title: String(item.file_name ?? 'Import'), detail: `Import · ${String(item.status ?? '')}`, module: 'connect' }),
      )));
    }

    try {
      await Promise.all(tasks);
      setSearchResults(results.slice(0, 30));
      setSearchOpen(true);
      if (results.length === 0) setNotice(`No accessible records matched “${term.trim()}”.`);
    } catch (requestError) {
      setError(getErrorMessage(requestError));
      setSearchResults([]);
      setSearchOpen(true);
    } finally {
      setSearching(false);
    }
  }

  async function loadNotifications() {
    if (!user?.permissions.includes('risks.read')) {
      setNotificationAlerts([]);
      setNotificationsOpen(true);
      return;
    }
    setNotificationsOpen(true);
    setNotificationLoading(true);
    setError('');
    try {
      const data = await moduleApi.riskAlerts();
      setNotificationAlerts(Array.isArray(data.alerts) ? data.alerts as NotificationAlert[] : []);
    } catch (requestError) {
      setError(getErrorMessage(requestError));
      setNotificationsOpen(false);
    } finally {
      setNotificationLoading(false);
    }
  }

  if (checkingSession) {
    return (
      <main className="auth-loading" role="status" aria-live="polite">
        <VantiqMark />
        <span>Securing your workspace</span>
      </main>
    );
  }

  if (bootstrapFailure) {
    return (
      <main className="auth-bootstrap-error" role="alert">
        <div className="auth-bootstrap-card">
          <VantiqMark />
          <p className="auth-bootstrap-eyebrow">Connection issue</p>
          <h1>We can’t reach the server</h1>
          <p className="auth-bootstrap-message">We can't reach the server. Check that the backend is running.</p>
          <div className="auth-bootstrap-status" aria-live="polite">
            <p>Backend <strong>{bootstrapFailure.backendReachable ? 'Reachable' : 'Unreachable'}</strong></p>
            <p>Database <strong>{bootstrapFailure.databaseReachable === null
              ? 'Unknown'
              : bootstrapFailure.databaseReachable ? 'Reachable' : 'Unreachable'}</strong></p>
          </div>
          <button type="button" className="auth-bootstrap-retry"
            onClick={() => setBootstrapRetry((attempt) => attempt + 1)}>
            Retry
          </button>
          <details className="auth-bootstrap-details">
            <summary>Technical details</summary>
            <dl>
              <dt>Session check</dt>
              <dd>{bootstrapFailure.sessionDetails}</dd>
              <dt>Health check</dt>
              <dd>{bootstrapFailure.healthDetails}</dd>
              {bootstrapFailure.javaVersion !== null && <>
                <dt>Java version</dt>
                <dd>{bootstrapFailure.javaVersion}</dd>
              </>}
            </dl>
          </details>
        </div>
      </main>
    );
  }

  if (view === 'workspace' && user) {
    const navigationItems: ModuleDefinition[] = [...availableModules, ...availableDataDestinations];
    const visibleGroups: VisibleNavigationGroup[] = navigationGroups.map((group) => ({
      ...group,
      modules: group.keys.map((key) => navigationItems.find((item) => item.key === key))
        .filter((item): item is ModuleDefinition => item !== undefined),
    })).filter((group) => group.modules.length > 0);
    const tabletMoreGroup: VisibleNavigationGroup = {
      label: 'More',
      modules: visibleGroups.slice(2).flatMap((group) => group.modules),
    };
    const controlPage = location.pathname.startsWith('/control/')
      ? location.pathname.slice('/control/'.length)
      : 'overview';
    const controlPageLabel: Record<string, string> = {
      overview: 'Overview',
      users: 'Users',
      roles: 'Roles & permissions',
      structure: 'Organisation structure',
      'audit-logs': 'Audit logs',
    };
    const pageTitle = module === 'control'
      ? controlPageLabel[controlPage] ?? 'Overview'
      : activeModule.label === 'Command' ? 'Executive intelligence' : activeModule.label;
    return (
      <div className={`app-shell${mobileNavigationOpen ? ' mobile-navigation-open' : ''}`}>
        <main className="workspace">
          <header className="brand-header">
            <div className="brand-header-identity">
              <button type="button" className="mobile-menu-button" aria-label="Open navigation"
                aria-expanded={mobileNavigationOpen} aria-controls="primary-navigation"
                onClick={() => setMobileNavigationOpen((open) => !open)}>
                <Icon name={mobileNavigationOpen ? 'close' : 'menu'} />
              </button>
            <VantiqMark />
              <div className="brand-header-copy">
                <strong>VANTIQ</strong>
                <span>Enterprise Intelligence &amp; Decision Platform</span>
                <small>See beyond the numbers.</small>
              </div>
            </div>
            <div className="header-actions">
              <div className="organisation-context" title={user.organisation?.name ?? 'Platform workspace'}>
                <span className="organisation-context-mark">{(user.organisation?.name ?? 'V').slice(0, 1).toLocaleUpperCase()}</span>
                <span>{user.organisation?.name ?? 'Platform workspace'}</span>
              </div>
              <div className={`live-connection-indicator ${liveConnectionStatus}`} role="status" aria-live="polite"
                title={lastBusinessEventAt === null
                  ? 'Business event connection status'
                  : `Last business update received at ${new Date(lastBusinessEventAt).toLocaleTimeString()}`}>
                <span className="live-status-dot" aria-hidden="true" />
                <span>{liveConnectionStatus === 'connected' ? 'Live updates' : liveConnectionStatus === 'reconnecting' ? 'Reconnecting' : 'Offline'}</span>
                {lastBusinessEventAt !== null && <time dateTime={new Date(lastBusinessEventAt).toISOString()}>
                  {new Date(lastBusinessEventAt).toLocaleTimeString()}
                </time>}
              </div>
              <form className="global-search" role="search" onSubmit={(event) => { event.preventDefault(); void searchOrganisation(globalSearch); }}
                onClick={(event) => { if (window.matchMedia('(max-width: 760px)').matches) event.currentTarget.querySelector('input')?.focus(); }}>
                <Icon name="search" />
                <input aria-label="Search organisation records" placeholder="Search organisation records" value={globalSearch}
                  onFocus={() => setSearchOpen(true)} onChange={(event) => { setGlobalSearch(event.target.value); setSearchOpen(true); }}
                  onKeyDown={(event) => { if (event.key === 'Escape') setSearchOpen(false); }} />
                {searching ? <span className="search-progress" aria-label="Searching" /> : <kbd>{/Mac|iPhone|iPad/.test(navigator.platform) ? '⌘ K' : 'Ctrl K'}</kbd>}
              </form>
              <details className="appearance-menu">
                <summary aria-label="Appearance settings" title="Appearance settings">Aa</summary>
                <div className="appearance-popover">
                  <label>Theme
                    <select value={theme} onChange={(event) => {
                      const value = event.currentTarget.value;
                      if (value === 'system' || value === 'light' || value === 'dark') setTheme(value);
                    }}>
                      <option value="system">System</option>
                      <option value="light">Light</option>
                      <option value="dark">Dark</option>
                    </select>
                  </label>
                  <label>Density
                    <select value={density} onChange={(event) => {
                      const value = event.currentTarget.value;
                      if (value === 'comfortable' || value === 'compact') setDensity(value);
                    }}>
                      <option value="comfortable">Comfortable</option>
                      <option value="compact">Compact</option>
                    </select>
                  </label>
                </div>
              </details>
              <button type="button" className="header-icon-button shortcut-help-trigger" aria-label="Keyboard shortcuts"
                title="Keyboard shortcuts" onClick={() => setShortcutsOpen(true)}>?</button>
              <button type="button" className={`header-icon-button${notificationsOpen ? ' selected' : ''}`}
                aria-label="View business alerts" aria-expanded={notificationsOpen} onClick={() => {
                  if (notificationsOpen) setNotificationsOpen(false);
                  else void loadNotifications();
                }}>
                <Icon name="bell" />
                {notificationAlerts && notificationAlerts.filter((item) => !['resolved', 'dismissed'].includes(item.status)).length > 0
                  && <span className="notification-dot" aria-label="Active business alerts" />}
              </button>
              <button type="button" className="header-profile" onClick={() => setHeaderProfileOpen((open) => !open)}
                aria-haspopup="menu" aria-expanded={headerProfileOpen}>
                <span className="user-avatar">{user.name.trim().split(/\s+/).slice(0, 2).map((part) => part[0]).join('').toLocaleUpperCase()}</span>
                <span className="header-profile-name">{user.name.split(/\s+/)[0]}</span><Icon name="chevron" />
              </button>
              {headerProfileOpen && <div className="header-profile-menu" role="menu" aria-label="Account options">
                <div className="header-profile-identity"><strong>{user.name}</strong><span>{user.email}</span></div>
                <button type="button" role="menuitem" onClick={() => { navigate('password'); setHeaderProfileOpen(false); }}>Change password</button>
                <button type="button" role="menuitem" onClick={() => { setHeaderProfileOpen(false); void logout(); }}>Sign out</button>
              </div>}
            </div>
            {searchOpen && <div className="global-search-popover">
              <div className="popover-heading"><div><strong>Organisation search</strong><span>Only records available to your role are searched.</span></div>
                <button type="button" aria-label="Close search" onClick={() => setSearchOpen(false)}><Icon name="close" /></button></div>
              <div className="search-submit-row"><span>Search customers, invoices, products, transactions, risks and scenarios</span>
                <button type="button" className="search-submit-button" disabled={searching || globalSearch.trim().length < 2}
                  onClick={() => void searchOrganisation(globalSearch)}>{searching ? 'Searching…' : 'Search'}</button></div>
              {searchResults.length > 0 && <div className="search-result-list" role="list">
                {searchResults.map((result) => <button key={result.id} type="button" role="listitem" className="search-result"
                  onClick={() => { setModule(result.module); setWorkspaceSearch(globalSearch.trim()); setSearchOpen(false); setMobileNavigationOpen(false); }}>
                  <span className="search-result-icon"><Icon name={result.module} /></span>
                  <span><strong>{result.title}</strong><small>{result.detail}</small></span>
                  <Icon name="arrow" />
                </button>)}
              </div>}
              {searchResults.length === 0 && !searching && globalSearch.trim().length >= 2 && <p className="search-empty">No matching records found in the accessible workspace data.</p>}
            </div>}
            <Dialog.Root open={commandOpen} onOpenChange={setCommandOpen}>
              <Dialog.Portal>
                <Dialog.Overlay className="command-overlay" />
                <Dialog.Content
                  aria-label="VANTIQ command palette"
                  className="command-dialog"
                  onCloseAutoFocus={(event) => {
                    if (!pendingCommandTarget) return;
                    event.preventDefault();
                    let attempts = 0;
                    const focusTarget = () => {
                      const target = document.getElementById(pendingCommandTarget);
                      if (target) {
                        target.scrollIntoView({
                          behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth',
                          block: 'center',
                        });
                        target.querySelector<HTMLElement>(
                          'input:not(:disabled), select:not(:disabled), textarea:not(:disabled), button:not(:disabled)',
                        )?.focus({ preventScroll: true });
                        setPendingCommandTarget(null);
                        return;
                      }
                      attempts += 1;
                      if (attempts < 40) {
                        window.setTimeout(focusTarget, 50);
                      } else {
                        setNotice('The requested form is not available in this workspace.');
                        setPendingCommandTarget(null);
                      }
                    };
                    window.requestAnimationFrame(focusTarget);
                  }}
                >
                  <Command loop>
                    <div className="command-input-row">
                      <Icon name="search" />
                      <Command.Input
                        aria-label="Search commands and workspaces"
                        placeholder="Search workspaces, records, and actions…"
                        value={commandQuery}
                        onValueChange={setCommandQuery}
                      />
                      <kbd>ESC</kbd>
                    </div>
                    <Command.List label="Available commands">
                      <Command.Empty className="command-empty">No matching commands.</Command.Empty>
                      <Command.Group heading="Go to workspace">
                        {availableModules.map((item) => (
                          <Command.Item
                            key={item.key}
                            value={`Go to ${item.label} ${item.description}`}
                            onSelect={() => navigateToModule(item.key as WorkspaceModuleKey)}
                          >
                            <Icon name={item.icon} />
                            <span>{item.label}</span>
                            <small>{item.description}</small>
                          </Command.Item>
                        ))}
                        {availableDataDestinations.map((item) => (
                          <Command.Item
                            key={item.key}
                            value={`Go to ${item.label} ${item.description}`}
                            onSelect={() => navigateToDataDestination(item.key as DataDestinationKey)}
                          >
                            <Icon name={item.icon} />
                            <span>{item.label}</span>
                            <small>{item.description}</small>
                          </Command.Item>
                        ))}
                      </Command.Group>
                      <Command.Group heading="Quick actions">
                        {user.permissions.includes('finance.write') && availableModules.some((item) => item.key === 'finance')
                          && <Command.Item value="Record a sale" onSelect={() => navigateToModule('finance', 'invoice-form')}>
                            <Icon name="finance" /><span>Record a sale</span><small>Finance</small>
                          </Command.Item>}
                        {user.permissions.includes('connect.manage')
                          && <Command.Item value="Inspect a CSV file" onSelect={() => navigateToModule('connect', 'csv-preview-form')}>
                            <Icon name="connect" /><span>Inspect a CSV file</span><small>Connect</small>
                          </Command.Item>}
                      </Command.Group>
                      {commandQuery.trim().length >= 2 && <Command.Group heading="Organisation search">
                        <Command.Item value={`Search records for ${commandQuery}`} onSelect={() => {
                          setGlobalSearch(commandQuery);
                          setCommandOpen(false);
                          void searchOrganisation(commandQuery);
                        }}>
                          <Icon name="search" />
                          <span>Search records for “{commandQuery.trim()}”</span>
                          <small>Only records available to your role</small>
                        </Command.Item>
                      </Command.Group>}
                    </Command.List>
                    <div className="command-footer"><span><kbd>↑</kbd><kbd>↓</kbd> to navigate</span><span><kbd>↵</kbd> to select</span></div>
                  </Command>
                </Dialog.Content>
              </Dialog.Portal>
            </Dialog.Root>
            <Dialog.Root open={shortcutsOpen} onOpenChange={setShortcutsOpen}>
              <Dialog.Portal>
                <Dialog.Overlay className="command-overlay" />
                <Dialog.Content className="command-dialog shortcut-dialog">
                  <div className="shortcut-dialog-heading">
                    <Dialog.Title>Keyboard shortcuts</Dialog.Title>
                    <Dialog.Close asChild>
                      <button type="button" className="shortcut-dialog-close" aria-label="Close keyboard shortcuts">
                        <Icon name="close" />
                      </button>
                    </Dialog.Close>
                  </div>
                  <p>Move through VANTIQ without leaving the keyboard.</p>
                  <dl>
                    <div><dt>Open command palette</dt><dd><kbd>Ctrl</kbd> or <kbd>⌘</kbd> + <kbd>K</kbd></dd></div>
                    <div><dt>Move through commands</dt><dd><kbd>↑</kbd> <kbd>↓</kbd></dd></div>
                    <div><dt>Select a command</dt><dd><kbd>Enter</kbd></dd></div>
                    <div><dt>Close a dialog or palette</dt><dd><kbd>Esc</kbd></dd></div>
                  </dl>
                </Dialog.Content>
              </Dialog.Portal>
            </Dialog.Root>
            {notificationsOpen && <div className="notifications-popover" role="region" aria-label="Business alerts">
              <div className="popover-heading"><div><strong>Business alerts</strong><span>Live organisation risk review</span></div>
                <button type="button" aria-label="Close alerts" onClick={() => setNotificationsOpen(false)}><Icon name="close" /></button></div>
              {notificationLoading ? <p className="popover-empty" role="status">Loading current alerts…</p>
                : !user.permissions.includes('risks.read') ? <p className="popover-empty">Business alerts are not available for your role.</p>
                  : notificationAlerts?.filter((item) => !['resolved', 'dismissed'].includes(item.status)).length
                    ? <div className="notification-list">{notificationAlerts.filter((item) => !['resolved', 'dismissed'].includes(item.status)).slice(0, 5).map((item) => (
                      <div className="notification-item" key={item.id}><span className={`notification-severity ${item.severity.toLowerCase()}`} />
                        <div><strong>{item.category}</strong><p>{item.explanation}</p><small>{item.severity} · {item.status}</small></div></div>
                    ))}</div>
                    : <p className="popover-empty">No active business alerts are recorded.</p>}
              {user.permissions.includes('risks.read') && <button type="button" className="popover-action"
                  onClick={() => { navigateToModule('risk'); setNotificationsOpen(false); }}>Open risk workspace <Icon name="arrow" /></button>}
            </div>}
          </header>
            {mobileNavigationOpen && <button className="mobile-navigation-backdrop" type="button"
              aria-label="Close navigation" onClick={() => setMobileNavigationOpen(false)} />}
            <nav id="primary-navigation" ref={navigationRef}
              className={`horizontal-navigation${mobileNavigationOpen ? ' mobile-navigation-open' : ''}`}
              aria-label="Primary navigation">
              {visibleGroups.map((group, index) => renderNavigationGroup(group, index))}
              {tabletMoreGroup.modules.length > 0 && renderNavigationGroup(tabletMoreGroup, 4, true)}
            </nav>
          <section className="workspace-page-heading">
              <div><p className="page-eyebrow">VANTIQ / {activeModule.label.toLocaleUpperCase()} / {pageTitle.toLocaleUpperCase()}</p>
                <h1>{pageTitle}</h1>
                <p>{module === 'control'
                  ? controlPage === 'overview'
                    ? 'Visual business health, operational activity, and management attention.'
                    : 'Manage access and organisation structure.'
                  : activeModule.description}</p></div>
            <div className="page-heading-context">
              {!(module === 'control' && controlPage === 'overview') && <span className="live-status-dot" />}
              {module === 'control' && controlPage === 'overview' ? 'Refreshes automatically every 30 seconds' : 'Authorised organisational data'}
            </div>
          </section>
          {(notice || error) && <div className="workspace-feedback" aria-live="polite">
            {notice && <p className="notice" role="status">{notice}</p>}
            {error && <p className="error" role="alert">{error}</p>}
          </div>}
          <Suspense fallback={<div className="data-route-loading" role="status">Loading workspace…</div>}>
            <Routes>
              {import.meta.env.DEV && <Route path="/dev/typography" element={<LazyTypographyShowcase />} />}
              <Route path="/data/import" element={<DataPermissionRoute user={user} permissions={['data.import', 'connect.manage', 'finance.write']}>
                <LazyImportCenterPage user={user} />
              </DataPermissionRoute>} />
              <Route path="/data/documents" element={<DataPermissionRoute user={user} permissions={['documents.read', 'documents.upload']}>
                <LazyDocumentVaultPage user={user} />
              </DataPermissionRoute>} />
              <Route path="/data/integrations" element={<DataPermissionRoute user={user} permissions={['integrations.manage', 'platform.users.manage']}>
                <LazyIntegrationHubPage user={user} />
              </DataPermissionRoute>} />
              <Route path="*" element={<WorkspaceModule module={module} user={user} searchQuery={workspaceSearch} />} />
            </Routes>
          </Suspense>
        </main>
      </div>
    );
  }

  const title = view === 'register' ? 'Register your organisation'
    : view === 'forgot' ? 'Reset your password'
      : view === 'reset' ? 'Choose a new password'
        : view === 'password' ? 'Change your password'
          : 'Welcome back.';
  const description = view === 'register' ? 'Create your organisation’s secure intelligence workspace.'
    : view === 'forgot' ? 'Enter your work email and we’ll send password reset instructions.'
      : view === 'reset' ? 'Choose a new password to secure your account.'
        : view === 'password' ? 'Update your password to keep your account secure.'
          : 'Sign in to access your organisation’s intelligence workspace.';
  const submitLabel = view === 'forgot' ? 'Send reset instructions'
    : view === 'reset' ? 'Reset password'
      : view === 'password' ? 'Update password'
        : view === 'register' ? 'Create organisation'
          : 'Sign in to VANTIQ';

  return (
    <main className="auth-shell">
      <AuthBrandPanel />
      <section className="auth-form-panel" aria-label="Account access">
        <div className="auth-card">
          <div className="auth-card-heading">
            <p className="auth-eyebrow">{view === 'login' ? 'Secure account access' : 'VANTIQ account'}</p>
            <h1>{title}</h1>
            <p className="auth-description">{description}</p>
          </div>
          {notice && <p className="auth-feedback auth-feedback-success" role="status">{notice}</p>}
          {error && <p className="auth-feedback auth-feedback-error" role="alert">{error}</p>}
          <form className="auth-form" onSubmit={submit} aria-busy={busy}>
            {view === 'register' && <>
              <label className="auth-field" htmlFor="organisation-name">Organisation name
                <input id="organisation-name" name="organisation_name" maxLength={180} autoComplete="organization"
                  placeholder="Your organisation" required disabled={busy} />
              </label>
              <label className="auth-field" htmlFor="full-name">Your name
                <input id="full-name" name="name" maxLength={180} autoComplete="name"
                  placeholder="Full name" required disabled={busy} />
              </label>
            </>}
            {(view === 'login' || view === 'register' || view === 'forgot') && (
              <label className="auth-field" htmlFor="work-email">Work email
                <input id="work-email" name="email" type="email" maxLength={254} autoComplete="email"
                  placeholder="name@company.com" required disabled={busy} />
              </label>
            )}
            {view === 'password' && (
              <PasswordField id="current-password" name="current_password" label="Current password"
                autoComplete="current-password" visible={showCurrentPassword} disabled={busy}
                onToggle={() => setShowCurrentPassword((visible) => !visible)} />
            )}
            {(view === 'login' || view === 'register' || view === 'reset' || view === 'password') && (
              <PasswordField
                id="account-password"
                name={view === 'password' ? 'new_password' : 'password'}
                label={view === 'login' ? 'Password' : 'New password'}
                autoComplete={view === 'login' ? 'current-password' : 'new-password'}
                minLength={view === 'login' ? 1 : 12}
                visible={showPassword}
                disabled={busy}
                onToggle={() => setShowPassword((visible) => !visible)}
              />
            )}
            {view === 'login' && (
              <div className="auth-form-options">
                <span aria-hidden="true">Secure session</span>
                <button type="button" className="auth-inline-link" onClick={() => navigate('forgot')} disabled={busy}>
                  Forgot password?
                </button>
              </div>
            )}
            <button className="auth-submit" type="submit" disabled={busy}>
              {busy && <span className="auth-spinner" aria-hidden="true" />}
              <span>{busy ? 'Please wait…' : submitLabel}</span>
            </button>
          </form>
          <nav className="auth-links" aria-label="Account options">
            {view === 'password' && (
              <button type="button" className="auth-inline-link" onClick={() => navigate('workspace')}>Return to VANTIQ</button>
            )}
            {view !== 'login' && view !== 'password' && (
              <button type="button" className="auth-inline-link" onClick={() => navigate('login')}>Back to sign in</button>
            )}
            {view === 'login' && (
              <p>New to VANTIQ? <button type="button" className="auth-inline-link" onClick={() => navigate('register')}>Register your organisation</button></p>
            )}
          </nav>
          <div className="auth-security-note">
            <span className="auth-security-icon" aria-hidden="true"><SecurityIcon /></span>
            <span>Protected by secure sessions and request verification.</span>
          </div>
          <footer className="auth-footer">© {new Date().getFullYear()} VANTIQ <span>·</span> See beyond the numbers.</footer>
        </div>
      </section>
    </main>
  );
}

function DataPermissionRoute({
  user,
  permissions,
  children,
}: {
  user: AuthUser;
  permissions: string[];
  children: ReactNode;
}) {
  if (!permissions.some((permission) => user.permissions.includes(permission))) {
    return <div className="data-access-denied" role="alert">
      <h2>This workspace is restricted</h2>
      <p>Your role does not have permission to access this destination.</p>
    </div>;
  }
  return <>{children}</>;
}

const dataDestinationPaths: Record<DataDestinationKey, string> = {
  import: '/data/import',
  documents: '/data/documents',
  integrations: '/data/integrations',
};

function VantiqMark() {
  return (
    <span className="vantiq-mark" aria-hidden="true">
      <svg viewBox="0 0 40 40" focusable="false">
        <path d="M7 9 17.5 31 22 22.5 26.5 31 37 9h-8.2l-6.8 14-6.8-14H7Z" />
        <path d="M17.5 31h9" />
      </svg>
    </span>
  );
}

function AuthBrandPanel() {
  return (
    <section className="auth-brand-panel" aria-labelledby="brand-headline">
      <div className="auth-brand-top">
        <div className="auth-brand-lockup">
          <VantiqMark />
          <div><strong>VANTIQ</strong><span>ENTERPRISE INTELLIGENCE</span></div>
        </div>
        <span className="auth-brand-edition">DECISION PLATFORM</span>
      </div>
      <div className="auth-brand-main">
        <div className="auth-brand-copy">
          <p className="auth-brand-kicker"><span /> CLARITY FOR WHAT COMES NEXT</p>
          <h2 id="brand-headline">See your business <em className="accent">differently.</em></h2>
          <p>Connect your enterprise. Uncover meaningful insight. Make decisions with confidence.</p>
        </div>
        <div className="auth-intelligence-visual" aria-hidden="true">
          <div className="auth-visual-topline"><span>THE ENTERPRISE, IN CONTEXT</span><span className="auth-live-indicator"><i /> ONE CLEARER VIEW</span></div>
          <svg className="auth-network" viewBox="0 0 620 310" focusable="false">
            <defs>
              <pattern id="auth-grid" width="32" height="32" patternUnits="userSpaceOnUse">
                <path d="M32 0H0V32" fill="none" stroke="var(--color-auth-grid-line)" strokeWidth="1" />
              </pattern>
              <linearGradient id="auth-path" x1="0" x2="1" y1="0" y2="1">
                <stop offset="0" stopColor="var(--color-gold-accent)" stopOpacity=".78" />
                <stop offset="1" stopColor="var(--color-brand-300)" stopOpacity=".36" />
              </linearGradient>
              <radialGradient id="auth-core">
                <stop offset="0" stopColor="var(--color-gold-accent)" stopOpacity=".16" />
                <stop offset="1" stopColor="var(--color-gold-accent)" stopOpacity="0" />
              </radialGradient>
            </defs>
            <rect width="620" height="310" fill="url(#auth-grid)" />
            <circle cx="310" cy="158" r="118" fill="url(#auth-core)" />
            <g fill="none" stroke="url(#auth-path)" strokeWidth="1.25">
              <path d="M102 91 217 121 310 158 410 108 520 76" />
              <path d="M102 229 214 197 310 158 422 203 520 236" />
              <path d="M217 121 214 197M410 108 422 203M102 91 102 229M520 76 520 236" opacity=".62" />
              <path d="M217 121 310 74 410 108M214 197 310 244 422 203" opacity=".42" />
            </g>
            <g fill="var(--color-auth-deep)" stroke="var(--color-gold-accent)" strokeWidth="1.5">
              <circle cx="102" cy="91" r="5" /><circle cx="102" cy="229" r="5" />
              <circle cx="217" cy="121" r="5" /><circle cx="214" cy="197" r="5" />
              <circle cx="310" cy="74" r="4" /><circle cx="310" cy="244" r="4" />
              <circle cx="410" cy="108" r="5" /><circle cx="422" cy="203" r="5" />
              <circle cx="520" cy="76" r="5" /><circle cx="520" cy="236" r="5" />
            </g>
            <circle cx="310" cy="158" r="39" fill="var(--color-auth-core)" stroke="var(--color-gold-accent)" strokeOpacity=".8" />
            <path d="m292 143 11 25 5-10 5 10 11-25h-8l-8 17-8-17h-8Z" fill="var(--color-gold-accent)" />
            <path d="M115 89h47M115 227h47M465 74h42M465 234h42" stroke="var(--color-auth-line)" strokeOpacity=".64" strokeWidth="1" />
          </svg>
          <div className="auth-source-label auth-source-finance"><span>01</span> FINANCE</div>
          <div className="auth-source-label auth-source-operations"><span>02</span> OPERATIONS</div>
          <div className="auth-source-label auth-source-customers"><span>03</span> CUSTOMERS</div>
          <div className="auth-source-label auth-source-supply"><span>04</span> SUPPLY</div>
          <div className="auth-visual-caption"><span>CONNECTED INFORMATION</span><b>→</b><span>DECISIONS WITH CONTEXT</span></div>
        </div>
      </div>
      <div className="auth-brand-bottom">
        <span>See Beyond the <em className="accent">Numbers.</em></span>
        <span className="auth-brand-trust"><SecurityIcon /> BUILT FOR CONFIDENT DECISIONS</span>
      </div>
    </section>
  );
}

function PasswordField({
  id,
  name,
  label,
  autoComplete,
  minLength,
  visible,
  disabled,
  onToggle,
}: {
  id: string;
  name: string;
  label: string;
  autoComplete: string;
  minLength?: number;
  visible: boolean;
  disabled: boolean;
  onToggle: () => void;
}) {
  return (
    <label className="auth-field" htmlFor={id}>{label}
      <span className="auth-password-wrap">
        <input id={id} name={name} type={visible ? 'text' : 'password'} minLength={minLength} maxLength={72}
          autoComplete={autoComplete} placeholder={label === 'Password' ? 'Enter your password' : 'At least 12 characters'}
          required disabled={disabled} />
        <button className="auth-password-toggle" type="button" aria-label={visible ? 'Hide password' : 'Show password'}
          aria-pressed={visible} onClick={onToggle} disabled={disabled}>
          <EyeIcon visible={visible} />
        </button>
      </span>
    </label>
  );
}

function EyeIcon({ visible }: { visible: boolean }) {
  return (
    <svg viewBox="0 0 20 20" fill="none" aria-hidden="true" focusable="false">
      <path d="M2.2 10s2.7-5 7.8-5 7.8 5 7.8 5-2.7 5-7.8 5-7.8-5-7.8-5Z" />
      <circle cx="10" cy="10" r="2.2" />
      {visible && <path d="m3.3 3.3 13.4 13.4" />}
    </svg>
  );
}

function SecurityIcon() {
  return (
    <svg viewBox="0 0 20 20" fill="none" aria-hidden="true" focusable="false">
      <path d="M10 2.5 16 5v4.4c0 3.8-2.5 6.5-6 8.1-3.5-1.6-6-4.3-6-8.1V5l6-2.5Z" />
      <path d="m7.3 9.9 1.8 1.8 3.8-4" />
    </svg>
  );
}
