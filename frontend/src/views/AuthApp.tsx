import { useEffect, useMemo, useState, type FormEvent } from 'react';
import {
  ApiError,
  authApi,
  configureSessionExpiryHandler,
  getErrorMessage,
  type AuthUser,
} from '../services/api';
import { WorkspaceModule, type WorkspaceModuleKey } from './WorkspaceModule';

type View = 'login' | 'register' | 'forgot' | 'reset' | 'password' | 'workspace';

interface ModuleDefinition {
  key: WorkspaceModuleKey;
  label: string;
  description: string;
  permissions: string[];
}

const modules: ModuleDefinition[] = [
  { key: 'command', label: 'VANTIQ Command', description: 'Executive intelligence centre', permissions: ['finance.read', 'operations.read', 'risks.read', 'reports.read'] },
  { key: 'finance', label: 'VANTIQ Finance', description: 'Revenue, spend and performance', permissions: ['finance.read'] },
  { key: 'operations', label: 'VANTIQ Operations', description: 'Inventory and procurement', permissions: ['operations.read'] },
  { key: 'forecast', label: 'VANTIQ Forecast', description: 'Evidence-based projections', permissions: ['operations.read'] },
  { key: 'risk', label: 'VANTIQ Risk', description: 'Anomaly detection and review', permissions: ['risks.read'] },
  { key: 'decisions', label: 'VANTIQ Decisions', description: 'Scenario planning studio', permissions: ['decisions.manage'] },
  { key: 'reports', label: 'VANTIQ Reports', description: 'Operational reporting', permissions: ['reports.read'] },
  { key: 'connect', label: 'VANTIQ Connect', description: 'Data import centre', permissions: ['connect.manage'] },
  { key: 'control', label: 'VANTIQ Control', description: 'Administration and governance', permissions: ['users.manage'] },
];

export function AuthApp() {
  const [view, setView] = useState<View>(new URLSearchParams(location.search).has('token') ? 'reset' : 'login');
  const [user, setUser] = useState<AuthUser | null>(null);
  const [module, setModule] = useState<WorkspaceModuleKey>('command');
  const [busy, setBusy] = useState(false);
  const [notice, setNotice] = useState('');
  const [error, setError] = useState('');

  const availableModules = useMemo(
    () => modules.filter((item) => item.permissions.some((permission) => user?.permissions.includes(permission))),
    [user],
  );
  const activeModule = modules.find((item) => item.key === module) ?? modules[0];

  useEffect(() => {
    let active = true;
    authApi.me().then((currentUser) => {
      if (active) {
        setUser(currentUser);
        setView('workspace');
      }
    }).catch((requestError: unknown) => {
      if (active && (!(requestError instanceof ApiError) || requestError.status !== 401)) {
        setError(getErrorMessage(requestError));
      }
    });

    const unsubscribe = configureSessionExpiryHandler(() => {
      setUser(null);
      setView('login');
      setError('');
      setNotice('Your session expired. Please sign in again.');
    });
    return () => {
      active = false;
      unsubscribe();
    };
  }, []);

  useEffect(() => {
    if (user && !availableModules.some((item) => item.key === module)) {
      setModule(availableModules[0]?.key ?? 'command');
    }
  }, [availableModules, module, user]);

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
        setUser(signedIn);
        setView('workspace');
      } else if (view === 'register') {
        const registered = await authApi.register(
          String(form.get('organisation_name')),
          String(form.get('name')),
          String(form.get('email')),
          String(form.get('password')),
        );
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
      setUser(null);
      setView('login');
      setNotice('You have been signed out.');
    } catch (requestError) {
      setError(getErrorMessage(requestError));
    }
  }

  if (view === 'workspace' && user) {
    return (
      <main className="app-shell">
        <aside className="sidebar">
          <div className="brand-block">
            <div className="brand-mark" aria-hidden="true">V</div>
            <div><div className="brand-title">VANTIQ</div><div className="brand-subtitle">See beyond the numbers</div></div>
          </div>
          <nav className="nav-group" aria-label="Main navigation">
            {availableModules.map((item) => (
              <button
                key={item.key}
                className={module === item.key ? 'nav-button active' : 'nav-button'}
                onClick={() => setModule(item.key)}
                type="button"
              >
                <span>{item.label}</span><small>{item.description}</small>
              </button>
            ))}
          </nav>
          <div className="sidebar-card">
            <div className="eyebrow">Signed in</div>
            <div className="user-name">{user.name}</div>
            <div className="user-email">{user.organisation?.name ?? user.email}</div>
            <div className="chip-row">{user.roles.slice(0, 3).map((role) => <span key={role} className="chip">{role}</span>)}</div>
          </div>
        </aside>
        <section className="workspace">
          <header className="topbar">
            <div><div className="eyebrow">{user.organisation?.name ?? 'Platform workspace'}</div><h1>{activeModule.label}</h1></div>
            <div className="topbar-actions">
              <button type="button" className="ghost-button" onClick={() => { setView('password'); setError(''); setNotice(''); }}>Change password</button>
              <button type="button" className="primary-button" onClick={logout}>Sign out</button>
            </div>
          </header>
          {(notice || error) && <div className="workspace-feedback">
            {notice && <p className="notice" role="status">{notice}</p>}
            {error && <p className="error" role="alert">{error}</p>}
          </div>}
          <WorkspaceModule module={module} user={user} />
        </section>
      </main>
    );
  }

  const title = view === 'register' ? 'Register your organisation'
    : view === 'forgot' ? 'Reset your password'
      : view === 'reset' ? 'Choose a new password'
        : view === 'password' ? 'Change your password'
          : 'Sign in to VANTIQ';

  return (
    <main className="shell">
      <section className="panel">
        <div className="brand"><span className="mark" aria-hidden="true">V</span><strong>VANTIQ</strong></div>
        <p className="eyebrow">Secure account access</p>
        <h1>{title}</h1>
        <p className="muted">See beyond the numbers.</p>
        {notice && <p className="notice" role="status">{notice}</p>}
        {error && <p className="error" role="alert">{error}</p>}
        <form onSubmit={submit}>
          {view === 'register' && <>
            <label>Organisation name<input name="organisation_name" maxLength={180} autoComplete="organization" required /></label>
            <label>Your name<input name="name" maxLength={180} autoComplete="name" required /></label>
          </>}
          {(view === 'login' || view === 'register' || view === 'forgot') && (
            <label>Work email<input name="email" type="email" maxLength={254} autoComplete="email" required /></label>
          )}
          {view === 'password' && <label>Current password<input name="current_password" type="password" maxLength={72} autoComplete="current-password" required /></label>}
          {(view === 'login' || view === 'register' || view === 'reset' || view === 'password') && (
            <label>{view === 'login' ? 'Password' : 'New password'}
              <input name={view === 'password' ? 'new_password' : 'password'} type="password"
                minLength={view === 'login' ? 1 : 12} maxLength={72}
                autoComplete={view === 'login' ? 'current-password' : 'new-password'} required />
            </label>
          )}
          <button className="primary-button" disabled={busy}>{busy ? 'Please wait…' :
            view === 'forgot' ? 'Request reset link' : view === 'reset' ? 'Reset password' :
              view === 'password' ? 'Change password' : view === 'register' ? 'Create organisation' : 'Sign in'}</button>
        </form>
        <nav className="links" aria-label="Account options">
          {view === 'password' && <button type="button" className="text-button" onClick={() => setView('workspace')}>Return to VANTIQ</button>}
          {view !== 'login' && view !== 'password' && <button type="button" className="text-button" onClick={() => { setView('login'); setError(''); }}>Sign in</button>}
          {view === 'login' && <>
            <button type="button" className="text-button" onClick={() => { setView('forgot'); setError(''); }}>Forgot password?</button>
            <button type="button" className="text-button" onClick={() => { setView('register'); setError(''); }}>Register organisation</button>
          </>}
        </nav>
      </section>
    </main>
  );
}
