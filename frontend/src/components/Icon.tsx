import type { ReactNode } from 'react';

export type IconName =
  | 'command' | 'design-system' | 'finance' | 'operations' | 'forecast' | 'risk'
  | 'decisions' | 'reports' | 'connect' | 'control' | 'search' | 'collapse'
  | 'shield' | 'more' | 'chevron' | 'menu' | 'bell' | 'close' | 'arrow';

const materialSymbolNames: Record<IconName, string> = {
  command: 'dashboard',
  'design-system': 'dashboard_customize',
  finance: 'account_balance_wallet',
  operations: 'inventory_2',
  forecast: 'query_stats',
  risk: 'warning',
  decisions: 'track_changes',
  reports: 'description',
  connect: 'hub',
  control: 'settings',
  search: 'search',
  collapse: 'left_panel_close',
  shield: 'shield',
  more: 'more_horiz',
  chevron: 'expand_more',
  menu: 'menu',
  bell: 'notifications',
  close: 'close',
  arrow: 'arrow_forward',
};

export function Icon({ name, label }: { name: IconName; label?: string }) {
  let shape: ReactNode;
  switch (name) {
    case 'command':
    case 'design-system':
      shape = <><rect x="3.5" y="3.5" width="5.5" height="5.5" rx="1" /><rect x="11" y="3.5" width="5.5" height="5.5" rx="1" /><rect x="3.5" y="11" width="5.5" height="5.5" rx="1" /><rect x="11" y="11" width="5.5" height="5.5" rx="1" /></>;
      break;
    case 'finance':
      shape = <><rect x="3" y="6" width="14" height="10" rx="2" /><path d="M7 6V4.8A1.8 1.8 0 0 1 8.8 3h2.4A1.8 1.8 0 0 1 13 4.8V6M3 9.5h14m-9 0v1.7h4V9.5" /></>;
      break;
    case 'operations':
      shape = <><path d="m10 2.8 7 3.8v6.8l-7 3.8-7-3.8V6.6l7-3.8Z" /><path d="m3.3 6.7 6.7 3.8 6.7-3.8M10 10.5v6.4M6.6 4.7l6.9 3.9" /></>;
      break;
    case 'forecast':
      shape = <><path d="M3 16.5h14M4.5 13l3.2-3.5 2.7 1.9 5.1-6" /><path d="M12.5 5.4h3v3" /><circle cx="4.5" cy="13" r=".6" /><circle cx="7.7" cy="9.5" r=".6" /><circle cx="10.4" cy="11.4" r=".6" /></>;
      break;
    case 'risk':
    case 'shield':
      shape = <><path d="M10 2.6 16 5v4.3c0 3.8-2.5 6.5-6 8.1-3.5-1.6-6-4.3-6-8.1V5l6-2.4Z" /><path d="M10 6.4v4.1m0 2.5h.01" /></>;
      break;
    case 'decisions':
      shape = <><circle cx="10" cy="10" r="7" /><circle cx="10" cy="10" r="3" /><path d="m11.8 8.2 4.7-4.7" /><path d="M13.5 3.5h3v3" /></>;
      break;
    case 'reports':
      shape = <><path d="M5 2.8h6l4 4v10.4H5V2.8Z" /><path d="M11 3v4h4M7.5 10h5m-5 3h5" /></>;
      break;
    case 'connect':
      shape = <><path d="M6.5 6.5 4 4m9.5 9.5L16 16M5.5 12.5l-2 2a2.1 2.1 0 0 0 3 3l2-2m6-6 2-2a2.1 2.1 0 0 0-3-3l-2 2" /><path d="m7.5 12.5 5-5" /></>;
      break;
    case 'control':
      shape = <><circle cx="10" cy="10" r="2.5" /><path d="m16.5 11.6 1.1.9-1.4 2.4-1.4-.5a6.8 6.8 0 0 1-1.5.9l-.2 1.5h-2.8l-.2-1.5a6.8 6.8 0 0 1-1.5-.9l-1.4.5-1.4-2.4 1.1-.9a6 6 0 0 1 0-1.8l-1.1-.9 1.4-2.4 1.4.5a6.8 6.8 0 0 1 1.5-.9l.2-1.5h2.8l.2 1.5a6.8 6.8 0 0 1 1.5.9l1.4-.5 1.4 2.4-1.1.9a6 6 0 0 1 0 1.8Z" transform="translate(-1.5 -1.5) scale(.92)" /></>;
      break;
    case 'search':
      shape = <><circle cx="8.5" cy="8.5" r="5.3" /><path d="m12.5 12.5 4.2 4.2" /></>;
      break;
    case 'collapse':
      shape = <><rect x="3" y="3.5" width="14" height="13" rx="2" /><path d="M8 3.5v13m3.5-9 2.5 2.5-2.5 2.5" /></>;
      break;
    case 'more':
      shape = <><circle cx="4" cy="10" r=".8" /><circle cx="10" cy="10" r=".8" /><circle cx="16" cy="10" r=".8" /></>;
      break;
    case 'chevron':
      shape = <path d="m7 8.5 3 3 3-3" />;
      break;
    case 'menu':
      shape = <><path d="M3 5.5h14M3 10h14M3 14.5h14" /></>;
      break;
    case 'bell':
      shape = <><path d="M15.5 8a5.5 5.5 0 0 0-11 0c0 6-2 6-2 7.5h15C17.5 14 15.5 14 15.5 8ZM8 18h4" /></>;
      break;
    case 'close':
      shape = <><path d="m5 5 10 10M15 5 5 15" /></>;
      break;
    case 'arrow':
      shape = <><path d="M3.5 10h12m-5-5 5 5-5 5" /></>;
      break;
  }

  return <span className="workspace-icon-shell" role={label ? 'img' : undefined}
    aria-label={label} aria-hidden={label ? undefined : true}>
    <span className="material-symbols-rounded workspace-icon-font" aria-hidden="true">{materialSymbolNames[name]}</span>
    <svg className="workspace-icon workspace-icon-fallback" viewBox="0 0 20 20" fill="none" stroke="currentColor"
      strokeWidth="1.55" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true" focusable="false">{shape}</svg>
  </span>;
}
