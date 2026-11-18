import {
  Bar,
  BarChart,
  CartesianGrid,
  Label,
  ResponsiveContainer,
  Scatter,
  ScatterChart,
  Tooltip,
  XAxis,
  YAxis,
} from 'recharts';
import {
  AnimatedMetric,
  CHART_EASING,
  MOTION_TIMING,
  usePrefersReducedMotion,
} from './AnimatedMetric';

type ReportRow = Record<string, unknown>;

interface DailyObservation {
  period: string;
  revenue?: number;
  expenses?: number;
  sales?: number;
}

interface HistogramBin {
  label: string;
  from: number;
  to: number;
  count: number;
}

interface Distribution {
  count: number;
  mean: number;
  median: number;
  standardDeviation: number | null;
  minimum: number;
  maximum: number;
  firstQuartile: number;
  thirdQuartile: number;
  lowerWhisker: number;
  upperWhisker: number;
  outliers: number[];
}

interface StatisticalInsightsProps {
  revenueByDay: unknown;
  expensesByDay: unknown;
  allowed: boolean;
}

function records(value: unknown): ReportRow[] {
  return Array.isArray(value)
    ? value.filter((item): item is ReportRow => typeof item === 'object' && item !== null)
    : [];
}

function finiteNumber(value: unknown): number | undefined {
  if (value === null || value === undefined || value === '') return undefined;
  const parsed = Number(value);
  return Number.isFinite(parsed) ? parsed : undefined;
}

function currency(value: unknown): string {
  const amount = finiteNumber(value);
  return amount === undefined ? 'Unavailable' : new Intl.NumberFormat('en-GH', {
    style: 'currency',
    currency: 'GHS',
    maximumFractionDigits: 2,
  }).format(amount);
}

function compactCurrency(value: unknown): string {
  const amount = finiteNumber(value);
  return amount === undefined ? '' : new Intl.NumberFormat('en-GH', {
    notation: 'compact',
    maximumFractionDigits: 1,
  }).format(amount);
}

function periodObservations(revenueByDay: unknown, expensesByDay: unknown): DailyObservation[] {
  const byPeriod = new Map<string, DailyObservation>();
  const earliestDate = new Date();
  earliestDate.setDate(earliestDate.getDate() - 29);
  const earliestPeriod = earliestDate.toISOString().slice(0, 10);
  const latestPeriod = new Date().toISOString().slice(0, 10);
  for (const item of records(revenueByDay)) {
    const period = String(item.period ?? '');
    const revenue = finiteNumber(item.revenue);
    if (!period || period < earliestPeriod || period > latestPeriod || revenue === undefined) continue;
    const observation = byPeriod.get(period) ?? { period };
    observation.revenue = (observation.revenue ?? 0) + revenue;
    const sales = finiteNumber(item.sale_count);
    if (sales !== undefined) observation.sales = (observation.sales ?? 0) + sales;
    byPeriod.set(period, observation);
  }
  for (const item of records(expensesByDay)) {
    const period = String(item.period ?? '');
    const expenses = finiteNumber(item.expenses);
    if (!period || period < earliestPeriod || period > latestPeriod || expenses === undefined) continue;
    const observation = byPeriod.get(period) ?? { period };
    observation.expenses = (observation.expenses ?? 0) + expenses;
    byPeriod.set(period, observation);
  }
  return [...byPeriod.values()].sort((left, right) => left.period.localeCompare(right.period));
}

function percentile(sortedValues: number[], fraction: number): number {
  const position = (sortedValues.length - 1) * fraction;
  const lowerIndex = Math.floor(position);
  const upperIndex = Math.ceil(position);
  if (lowerIndex === upperIndex) return sortedValues[lowerIndex];
  const weight = position - lowerIndex;
  return sortedValues[lowerIndex] * (1 - weight) + sortedValues[upperIndex] * weight;
}

function distribution(values: number[]): Distribution | null {
  if (values.length < 5) return null;
  const sorted = [...values].sort((left, right) => left - right);
  const firstQuartile = percentile(sorted, 0.25);
  const median = percentile(sorted, 0.5);
  const thirdQuartile = percentile(sorted, 0.75);
  const interquartileRange = thirdQuartile - firstQuartile;
  const lowerFence = firstQuartile - 1.5 * interquartileRange;
  const upperFence = thirdQuartile + 1.5 * interquartileRange;
  const whiskerValues = sorted.filter((value) => value >= lowerFence && value <= upperFence);
  const mean = values.reduce((total, value) => total + value, 0) / values.length;
  const standardDeviation = values.length < 2
    ? null
    : Math.sqrt(values.reduce((total, value) => total + (value - mean) ** 2, 0) / (values.length - 1));

  return {
    count: values.length,
    mean,
    median,
    standardDeviation,
    minimum: sorted[0],
    maximum: sorted[sorted.length - 1],
    firstQuartile,
    thirdQuartile,
    lowerWhisker: whiskerValues[0],
    upperWhisker: whiskerValues[whiskerValues.length - 1],
    outliers: sorted.filter((value) => value < lowerFence || value > upperFence),
  };
}

function histogram(values: number[]): HistogramBin[] {
  if (values.length < 5) return [];
  const minimum = Math.min(...values);
  const maximum = Math.max(...values);
  const binCount = Math.max(3, Math.min(8, Math.ceil(Math.log2(values.length) + 1)));
  const range = maximum - minimum;
  const width = range === 0 ? Math.max(Math.abs(minimum) * 0.1, 1) : range / binCount;
  const start = range === 0 ? minimum - (binCount / 2) * width : minimum;
  const bins = Array.from({ length: binCount }, (_, index) => {
    const from = start + index * width;
    const to = from + width;
    return { label: compactCurrency(from), from, to, count: 0 };
  });

  for (const value of values) {
    const index = Math.min(binCount - 1, Math.max(0, Math.floor((value - start) / width)));
    bins[index].count += 1;
  }
  return bins;
}

function correlation(pairs: Array<{ x: number; y: number }>): number | null {
  if (pairs.length < 5) return null;
  const meanX = pairs.reduce((sum, pair) => sum + pair.x, 0) / pairs.length;
  const meanY = pairs.reduce((sum, pair) => sum + pair.y, 0) / pairs.length;
  const covariance = pairs.reduce((sum, pair) => sum + (pair.x - meanX) * (pair.y - meanY), 0);
  const varianceX = pairs.reduce((sum, pair) => sum + (pair.x - meanX) ** 2, 0);
  const varianceY = pairs.reduce((sum, pair) => sum + (pair.y - meanY) ** 2, 0);
  if (varianceX === 0 || varianceY === 0) return null;
  return Math.max(-1, Math.min(1, covariance / Math.sqrt(varianceX * varianceY)));
}

function boxPosition(value: number, minimum: number, maximum: number): number {
  if (minimum === maximum) return 50;
  return 32 + ((value - minimum) / (maximum - minimum)) * 576;
}

function BoxPlot({ summary }: { summary: Distribution }) {
  const { minimum, maximum, firstQuartile, median, thirdQuartile, lowerWhisker, upperWhisker, outliers, mean } = summary;
  return (
    <figure className="statistics-figure">
      <svg className="statistics-boxplot"
        key={`${minimum}-${firstQuartile}-${median}-${thirdQuartile}-${maximum}`}
        viewBox="0 0 640 112" role="img"
        aria-label={`Box plot for ${summary.count} returned daily revenue values. Minimum ${currency(minimum)}, first quartile ${currency(firstQuartile)}, median ${currency(median)}, third quartile ${currency(thirdQuartile)}, maximum ${currency(maximum)}, with ${outliers.length} potential outliers.`}>
        <title>Daily revenue box-and-whisker plot</title>
        <line x1={boxPosition(lowerWhisker, minimum, maximum)} x2={boxPosition(upperWhisker, minimum, maximum)}
          y1="48" y2="48" className="statistics-whisker" />
        <line x1={boxPosition(lowerWhisker, minimum, maximum)} x2={boxPosition(lowerWhisker, minimum, maximum)}
          y1="37" y2="59" className="statistics-whisker" />
        <line x1={boxPosition(upperWhisker, minimum, maximum)} x2={boxPosition(upperWhisker, minimum, maximum)}
          y1="37" y2="59" className="statistics-whisker" />
        <rect x={boxPosition(firstQuartile, minimum, maximum)} y="30"
          width={Math.max(1, boxPosition(thirdQuartile, minimum, maximum) - boxPosition(firstQuartile, minimum, maximum))}
          height="36" rx="3" className="statistics-box" />
        <line x1={boxPosition(median, minimum, maximum)} x2={boxPosition(median, minimum, maximum)}
          y1="30" y2="66" className="statistics-median" />
        <path d={`M ${boxPosition(mean, minimum, maximum)} 25 l 5 5 -5 5 -5 -5 z`} className="statistics-mean" />
        {outliers.map((value, index) => <circle key={`${value}-${index}`} cx={boxPosition(value, minimum, maximum)}
          cy={77 + (index % 2) * 10} r="3.5" className="statistics-outlier">
          <title>{`Potential outlier: ${currency(value)}`}</title>
        </circle>)}
        <text x="32" y="106" textAnchor="start">{compactCurrency(minimum)}</text>
        <text x="608" y="106" textAnchor="end">{compactCurrency(maximum)}</text>
      </svg>
      <div className="statistics-box-legend">
        <span><i className="mean-marker" /> Mean</span>
        <span><i className="median-marker" /> Median</span>
        <span>Box: middle 50% · whiskers: 1.5×IQR fences</span>
      </div>
    </figure>
  );
}

function salesHeatmap(observations: DailyObservation[]) {
  const rows = observations.filter((item) => item.sales !== undefined);
  if (rows.length === 0) return null;
  const dayLabels = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
  const earliest = new Date(`${rows[0].period}T00:00:00Z`);
  const earliestWeekday = (earliest.getUTCDay() + 6) % 7;
  const firstMonday = new Date(earliest);
  firstMonday.setUTCDate(earliest.getUTCDate() - earliestWeekday);
  const columns = Math.max(1, ...rows.map((item) =>
    Math.floor((new Date(`${item.period}T00:00:00Z`).getTime() - firstMonday.getTime()) / 604800000) + 1));
  const counts = new Map<string, number>();
  for (const item of rows) {
    const date = new Date(`${item.period}T00:00:00Z`);
    const day = (date.getUTCDay() + 6) % 7;
    const week = Math.floor((date.getTime() - firstMonday.getTime()) / 604800000);
    counts.set(`${day}:${week}`, item.sales ?? 0);
  }
  const maximum = Math.max(0, ...counts.values());
  return { dayLabels, columns, counts, maximum };
}

function activityLevel(value: number, maximum: number): string {
  if (value <= 0 || maximum <= 0) return 'level-0';
  return `level-${Math.max(1, Math.ceil((value / maximum) * 4))}`;
}

export function StatisticalInsights({ revenueByDay, expensesByDay, allowed }: StatisticalInsightsProps) {
  const animateCharts = !usePrefersReducedMotion();
  const observations = allowed ? periodObservations(revenueByDay, expensesByDay) : [];
  const revenueValues = observations.flatMap((item) => item.revenue === undefined ? [] : [item.revenue]);
  const revenueSummary = distribution(revenueValues);
  const bins = histogram(revenueValues);
  const paired = observations.flatMap((item) => item.revenue === undefined || item.expenses === undefined
    ? [] : [{ period: item.period, x: item.revenue, y: item.expenses }]);
  const correlationValue = correlation(paired);
  const heatmap = salesHeatmap(observations);

  return (
    <section className="statistical-insights" aria-labelledby="statistical-insights-title">
      <header className="statistics-heading">
        <div>
          <span className="section-eyebrow">STATISTICAL INTELLIGENCE</span>
          <h2 id="statistical-insights-title">Patterns in recorded activity</h2>
          <p>Distribution and co-movement across dates returned by the financial report.</p>
        </div>
        <span className="statistics-period">REPORT DATA</span>
      </header>
      {!allowed ? <p className="statistics-empty" role="status">Report access is not available for your role.</p> : (
        <div className="statistics-grid">
          <article className="statistics-panel">
            <div className="statistics-panel-heading">
              <h3>Daily revenue distribution</h3>
              <span>{revenueValues.length} reported dates</span>
            </div>
            {bins.length < 3 ? <p className="statistics-empty" role="status">
              At least five daily revenue observations are needed to show a distribution.
            </p> : <>
              <figure className="statistics-figure" role="img"
                aria-label={`Histogram of ${revenueValues.length} daily revenue values divided into ${bins.length} equal-width numerical intervals.`}>
                <figcaption className="visually-hidden">Frequency of daily revenue values by GHS interval.</figcaption>
                <ResponsiveContainer width="100%" height={198}>
                  <BarChart data={bins} margin={{ top: 8, right: 8, bottom: 20, left: 0 }}>
                    <CartesianGrid stroke="var(--color-border)" strokeDasharray="3 5" vertical={false} />
                    <XAxis dataKey="label" tick={{ fill: 'var(--color-text-muted)', fontSize: 'var(--type-chart-label-size)' }}
                      tickLine={false} axisLine={false} interval="preserveStartEnd">
                      <Label value="Daily revenue interval (GHS)" position="insideBottom" offset={-14}
                        fill="var(--color-text-muted)" fontSize="var(--type-chart-label-size)" />
                    </XAxis>
                    <YAxis allowDecimals={false} tick={{ fill: 'var(--color-text-muted)', fontSize: 'var(--type-chart-label-size)' }}
                      tickLine={false} axisLine={false} width={30}>
                      <Label value="Dates" angle={-90} position="insideLeft" fill="var(--color-text-muted)" fontSize="var(--type-chart-label-size)" />
                    </YAxis>
                    <Tooltip
                      labelFormatter={(_, payload) => {
                        const bin = payload?.[0]?.payload as HistogramBin | undefined;
                        return bin ? `${currency(bin.from)} to ${currency(bin.to)}` : '';
                      }}
                      formatter={(value) => [value, 'Reported dates']}
                      contentStyle={{ borderColor: 'var(--color-border)', background: 'var(--color-surface)', fontFamily: 'var(--font-body)', fontSize: 'var(--type-chart-label-size)' }}
                    />
                    <Bar dataKey="count" name="Reported dates" fill="var(--chart-category-1)" radius={[3, 3, 0, 0]}
                      isAnimationActive={animateCharts} animationBegin={MOTION_TIMING.chartDelay}
                      animationDuration={MOTION_TIMING.chart} animationEasing={CHART_EASING} />
                  </BarChart>
                </ResponsiveContainer>
              </figure>
              {revenueSummary && <div className="statistics-summary">
                <span>Mean <strong><AnimatedMetric value={revenueSummary.mean} formatter={currency} /></strong></span>
                <span>Median <strong><AnimatedMetric value={revenueSummary.median} formatter={currency} /></strong></span>
                <span>Range <strong><AnimatedMetric value={revenueSummary.minimum} formatter={currency} />–<AnimatedMetric value={revenueSummary.maximum} formatter={currency} /></strong></span>
              </div>}
              {revenueSummary && <details className="statistics-extra">
                <summary>Quartiles and spread</summary>
                <dl>
                  <div><dt>First quartile</dt><dd><AnimatedMetric value={revenueSummary.firstQuartile} formatter={currency} /></dd></div>
                  <div><dt>Third quartile</dt><dd><AnimatedMetric value={revenueSummary.thirdQuartile} formatter={currency} /></dd></div>
                  <div><dt>Sample standard deviation</dt><dd>{revenueSummary.standardDeviation === null
                    ? 'Insufficient observations' : currency(revenueSummary.standardDeviation)}</dd></div>
                </dl>
              </details>}
            </>}
          </article>

          <article className="statistics-panel">
            <div className="statistics-panel-heading">
              <h3>Revenue variation</h3>
              <span>{revenueSummary ? `${revenueSummary.count} observations` : 'Insufficient history'}</span>
            </div>
            {revenueSummary ? <>
              <BoxPlot summary={revenueSummary} />
              <p className="statistics-interpretation">
                Median {currency(revenueSummary.median)}; {revenueSummary.outliers.length} potential outlier{revenueSummary.outliers.length === 1 ? '' : 's'} by Tukey fences.
              </p>
            </> : <p className="statistics-empty" role="status">
              At least five daily revenue observations are needed to summarize quartiles and spread.
            </p>}
          </article>

          <article className="statistics-panel">
            <div className="statistics-panel-heading">
              <h3>Revenue and expense relationship</h3>
              <span>{paired.length} paired dates</span>
            </div>
            {correlationValue === null ? <p className="statistics-empty" role="status">
              Correlation needs at least five paired dates with variation in both measures.
            </p> : <>
              <figure className="statistics-figure" role="img"
                aria-label={`Scatter plot of revenue and expenses for ${paired.length} paired dates. Pearson correlation is ${correlationValue.toFixed(2)}. Correlation does not establish causation.`}>
                <figcaption className="visually-hidden">Each point represents one date returned by both report series.</figcaption>
                <ResponsiveContainer width="100%" height={198}>
                  <ScatterChart margin={{ top: 8, right: 14, bottom: 23, left: 6 }}>
                    <CartesianGrid stroke="var(--color-border)" strokeDasharray="3 5" />
                    <XAxis type="number" dataKey="x" name="Revenue" tickFormatter={compactCurrency}
                      tick={{ fill: 'var(--color-text-muted)', fontSize: 'var(--type-chart-label-size)' }} tickLine={false} axisLine={false}>
                      <Label value="Revenue (GHS)" position="insideBottom" offset={-16}
                        fill="var(--color-text-muted)" fontSize="var(--type-chart-label-size)" />
                    </XAxis>
                    <YAxis type="number" dataKey="y" name="Expenses" tickFormatter={compactCurrency}
                      tick={{ fill: 'var(--color-text-muted)', fontSize: 'var(--type-chart-label-size)' }} tickLine={false} axisLine={false} width={44}>
                      <Label value="Expenses (GHS)" angle={-90} position="insideLeft"
                        fill="var(--color-text-muted)" fontSize="var(--type-chart-label-size)" />
                    </YAxis>
                    <Tooltip
                      cursor={{ strokeDasharray: '3 3' }}
                      labelFormatter={(_, payload) => String(payload?.[0]?.payload?.period ?? '')}
                      formatter={(value, name) => [currency(value), String(name)]}
                      contentStyle={{ borderColor: 'var(--color-border)', background: 'var(--color-surface)', fontFamily: 'var(--font-body)', fontSize: 'var(--type-chart-label-size)' }}
                    />
                    <Scatter data={paired} fill="var(--chart-category-1)" isAnimationActive={animateCharts}
                      animationBegin={MOTION_TIMING.chartDelay} animationDuration={MOTION_TIMING.chart}
                      animationEasing={CHART_EASING} />
                  </ScatterChart>
                </ResponsiveContainer>
              </figure>
              <p className="statistics-interpretation">
                Pearson r = <AnimatedMetric value={correlationValue} formatter={(value) => value.toFixed(2)} />. This describes co-movement in paired dates; correlation does not establish causation.
              </p>
            </>}
          </article>

          <article className="statistics-panel">
            <div className="statistics-panel-heading">
              <h3>Sales activity by weekday</h3>
              <span>{heatmap ? `${heatmap.columns} reported weeks` : 'No daily counts returned'}</span>
            </div>
            {heatmap ? <>
              <div className="statistics-heatmap-wrap">
                <table className="statistics-heatmap">
                  <thead><tr><th scope="col">Day</th>{Array.from({ length: heatmap.columns }, (_, week) =>
                    <th scope="col" key={week}>W{week + 1}</th>)}</tr></thead>
                  <tbody>{heatmap.dayLabels.map((day, dayIndex) => <tr key={day}>
                    <th scope="row">{day}</th>
                    {Array.from({ length: heatmap.columns }, (_, week) => {
                      const count = heatmap.counts.get(`${dayIndex}:${week}`);
                      return <td key={week} className={count === undefined ? 'unreported' : activityLevel(count, heatmap.maximum)}
                        aria-label={count === undefined ? `${day}, week ${week + 1}: no daily count returned`
                          : `${day}, week ${week + 1}: ${count} sales`}>
                        {count === undefined ? '·' : count}
                      </td>;
                    })}
                  </tr>)}</tbody>
                </table>
              </div>
              <div className="statistics-heatmap-legend" aria-label="Sales count intensity">
                <span>Fewer</span>{[1, 2, 3, 4].map((level) => <i className={`level-${level}`} key={level} />)}<span>More sales</span>
              </div>
              <p className="statistics-interpretation">Blank cells mean no daily count was returned, not zero sales.</p>
            </> : <p className="statistics-empty" role="status">
              Daily sales counts were not included in the available report data.
            </p>}
          </article>
        </div>
      )}
      <details className="statistics-methodology">
        <summary>Statistical methodology</summary>
        <p>Quartiles use linear interpolation (Type 7); standard deviation uses the sample denominator (n−1); potential outliers use 1.5×IQR Tukey fences; correlation is Pearson’s r over dates present in both series. Missing dates are not imputed.</p>
      </details>
    </section>
  );
}
