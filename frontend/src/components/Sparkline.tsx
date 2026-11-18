interface SparklineObservation {
  period: string;
  value: number;
}

export function Sparkline({ observations }: { observations: SparklineObservation[] }) {
  const points = observations
    .filter((item) => /^\d{4}-\d{2}-\d{2}$/.test(item.period) && Number.isFinite(item.value))
    .sort((left, right) => left.period.localeCompare(right.period));
  if (points.length < 2) return null;

  const firstTime = Date.parse(`${points[0].period}T00:00:00Z`);
  const lastTime = Date.parse(`${points[points.length - 1].period}T00:00:00Z`);
  const span = Math.max(1, lastTime - firstTime);
  const minimum = Math.min(...points.map((item) => item.value));
  const maximum = Math.max(...points.map((item) => item.value));
  const range = maximum - minimum;
  let previousTime: number | null = null;
  const path = points.map((item) => {
    const time = Date.parse(`${item.period}T00:00:00Z`);
    const x = ((time - firstTime) / span) * 120;
    const y = 20 - (range === 0 ? 0 : (item.value - minimum) / range * 16);
    const command = previousTime === null || time - previousTime > 86_400_000 ? 'M' : 'L';
    previousTime = time;
    return `${command}${x.toFixed(2)},${y.toFixed(2)}`;
  }).join(' ');

  return <svg className="data-sparkline" viewBox="0 0 120 24" role="img"
    aria-label={`Daily recorded values from ${points[0].period} through ${points[points.length - 1].period}; gaps indicate dates not returned by the report.`}>
    <path key={path} d={path} pathLength="1000" />
  </svg>;
}
