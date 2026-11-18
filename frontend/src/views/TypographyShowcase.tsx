const typographyTokens = [
  { name: 'page-title', className: 'type-page-title', family: 'Plus Jakarta Sans', size: '30px', weight: '700', lineHeight: '1.2' },
  { name: 'kpi-hero', className: 'type-kpi-hero', family: 'Plus Jakarta Sans', size: '38px', weight: '700', lineHeight: '1.1' },
  { name: 'kpi-secondary', className: 'type-kpi-secondary', family: 'Plus Jakarta Sans', size: '27px', weight: '600', lineHeight: '1.2' },
  { name: 'card-header', className: 'type-card-header', family: 'Plus Jakarta Sans', size: '17px', weight: '600', lineHeight: '1.35' },
  { name: 'body-default', className: 'type-body-default', family: 'IBM Plex Sans', size: '16px', weight: '400', lineHeight: '1.55' },
  { name: 'table-cell', className: 'type-table-cell', family: 'IBM Plex Sans', size: '14px', weight: '400', lineHeight: '1.45' },
  { name: 'table-header / badge', className: 'type-table-header', family: 'IBM Plex Sans', size: '12px', weight: '600', lineHeight: '1.4' },
  { name: 'data-mono', className: 'type-data-mono', family: 'JetBrains Mono', size: '12px', weight: '400', lineHeight: '1.4' },
  { name: 'nav-item', className: 'type-nav-item', family: 'IBM Plex Sans', size: '15px', weight: '500 / 600 active', lineHeight: '1.4' },
  { name: 'section-heading', className: 'type-section-heading', family: 'Plus Jakarta Sans', size: '21px', weight: '600', lineHeight: '1.3' },
  { name: 'label / caption', className: 'type-label-caption', family: 'IBM Plex Sans', size: '13px', weight: '500', lineHeight: '1.45' },
  { name: 'kpi-delta', className: 'type-kpi-delta', family: 'IBM Plex Sans', size: '13px', weight: '600', lineHeight: '1.4' },
];

export function TypographyShowcase() {
  return (
    <section className="typography-showcase" aria-labelledby="typography-showcase-title">
      <header className="typography-showcase-heading">
        <p className="type-table-header">VANTIQ / DEVELOPMENT / DESIGN SYSTEM</p>
        <h1 id="typography-showcase-title" className="type-page-title">Typography system</h1>
        <p className="type-body-default">Financial authority with modern AI-SaaS polish. See beyond the numbers.</p>
      </header>

      <section className="typography-showcase-section" aria-labelledby="typography-tokens-title">
        <h2 id="typography-tokens-title" className="type-section-heading">Type tokens</h2>
        <div className="typography-token-grid">
          {typographyTokens.map((token) => (
            <article className="typography-token-card" key={token.name}>
              <span className="type-table-header">{token.name}</span>
              <p className={token.className}>Aa Bb 0123456789</p>
              <small className="type-label-caption">
                {token.family} · {token.size} · {token.weight} · line {token.lineHeight}
              </small>
            </article>
          ))}
        </div>
      </section>

      <section className="typography-showcase-section" aria-labelledby="typography-header-title">
        <p className="type-table-header">VANTIQ / FINANCE / OVERVIEW</p>
        <h2 id="typography-header-title" className="type-page-title">Financial overview</h2>
        <p className="type-body-default">A clear view of business performance for the selected reporting period.</p>
      </section>

      <section className="typography-showcase-section" aria-labelledby="typography-kpi-title">
        <div className="typography-section-heading">
          <h2 id="typography-kpi-title" className="type-section-heading">KPI cards</h2>
          <span className="type-table-header">SAMPLE - DEMONSTRATION DATA</span>
        </div>
        <div className="typography-kpi-grid">
          {[
            { label: 'Total Revenue', value: 'GHS 1,284,500.00', delta: '+8.4% vs prior period' },
            { label: 'Net Profit', value: 'GHS 326,420.00', delta: '+3.1% vs prior period' },
            { label: 'Cash Position', value: 'GHS 842,750.00', delta: '-1.2% vs prior period' },
          ].map((metric) => (
            <article className="typography-kpi-card" key={metric.label}>
              <span className="type-label-caption">{metric.label}</span>
              <strong className="type-kpi-hero">{metric.value}</strong>
              <span className="type-kpi-delta">{metric.delta}</span>
              <span className="type-table-header">SAMPLE</span>
            </article>
          ))}
        </div>
      </section>

      <section className="typography-showcase-section" aria-labelledby="typography-table-title">
        <div className="typography-section-heading">
          <h2 id="typography-table-title" className="type-section-heading">Financial table</h2>
          <span className="type-table-header">SAMPLE - DEMONSTRATION DATA</span>
        </div>
        <div className="typography-table-scroll">
          <table className="typography-sample-table">
            <thead>
              <tr>
                <th scope="col">Reference</th>
                <th scope="col">Customer</th>
                <th scope="col">Status</th>
                <th scope="col" className="table-numeric">Revenue</th>
                <th scope="col" className="table-numeric">Margin</th>
              </tr>
            </thead>
            <tbody>
              <tr>
                <td className="type-data-mono">INV-2026-00128</td>
                <td className="type-table-cell">Aster Holdings</td>
                <td><span className="typography-status success">Paid</span></td>
                <td className="type-table-cell table-numeric">GHS 184,500.00</td>
                <td className="type-table-cell table-numeric">24.8%</td>
              </tr>
              <tr>
                <td className="type-data-mono">INV-2026-00129</td>
                <td className="type-table-cell">Meridian Group</td>
                <td><span className="typography-status warning">Pending</span></td>
                <td className="type-table-cell table-numeric">GHS 92,750.00</td>
                <td className="type-table-cell table-numeric">18.2%</td>
              </tr>
              <tr>
                <td className="type-data-mono">INV-2026-00130</td>
                <td className="type-table-cell">Northstar Partners</td>
                <td><span className="typography-status neutral">Review</span></td>
                <td className="type-table-cell table-numeric">GHS 61,900.00</td>
                <td className="type-table-cell table-numeric">12.6%</td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>

      <section className="typography-showcase-section" aria-labelledby="typography-audit-title">
        <h2 id="typography-audit-title" className="type-section-heading">Audit log sample</h2>
        <div className="typography-audit-row">
          <time className="type-data-mono" dateTime="2026-11-17T14:36:49Z">2026-11-17 14:36:49Z</time>
          <span className="type-body-default">Invoice payment recorded by Finance Manager</span>
          <code className="type-data-mono">ACT-7F3A92</code>
        </div>
      </section>

      <section className="typography-showcase-section" aria-labelledby="typography-surfaces-title">
        <h2 id="typography-surfaces-title" className="type-section-heading">Surface and numeral checks</h2>
        <div className="typography-surface-grid">
          <article className="typography-surface light">
            <span className="type-table-header">WARM IVORY / WHITE</span>
            <h3 className="type-card-header">Light surface</h3>
            <p className="type-body-default">Secondary labels use contrast-safe Slate text against Warm Ivory.</p>
          </article>
          <article className="typography-surface dark">
            <span className="type-table-header">GRAPHITE / DEEP EMERALD</span>
            <h3 className="type-card-header">Dark surface</h3>
            <p className="type-body-default">Body copy remains legible in White on dark surfaces.</p>
          </article>
        </div>
        <div className="typography-numeral-comparison">
          <div>
            <span className="type-table-header">TABULAR NUMERALS ON</span>
            <p className="type-body-default typography-numeral-on">1111.11 · 8888.88</p>
          </div>
          <div>
            <span className="type-table-header">TABULAR NUMERALS OFF</span>
            <p className="type-body-default typography-numeral-off">1111.11 · 8888.88</p>
          </div>
          <p className="type-label-caption">SAMPLE - font-feature support should be confirmed in a browser with Plus Jakarta Sans loaded.</p>
        </div>
      </section>
    </section>
  );
}
