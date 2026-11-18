import { useEffect, useRef, useState } from 'react';

export const MOTION_TIMING = {
  countUp: 650,
  chart: 720,
  chartDelay: 100,
  chartSecondaryDelay: 160,
  trendPointStagger: 12,
  valueHighlight: 900,
} as const;

export const CHART_EASING = 'ease-out' as const;

function prefersReducedMotion(): boolean {
  return typeof window === 'undefined'
    || typeof window.matchMedia !== 'function'
    || window.matchMedia('(prefers-reduced-motion: reduce)').matches;
}

export function usePrefersReducedMotion(): boolean {
  const [reducedMotion, setReducedMotion] = useState(prefersReducedMotion);

  useEffect(() => {
    if (typeof window.matchMedia !== 'function') return undefined;
    const query = window.matchMedia('(prefers-reduced-motion: reduce)');
    const onChange = (event: MediaQueryListEvent) => setReducedMotion(event.matches);
    setReducedMotion(query.matches);
    query.addEventListener('change', onChange);
    return () => query.removeEventListener('change', onChange);
  }, []);

  return reducedMotion;
}

export function AnimatedMetric({
  value,
  formatter,
  className,
  duration = MOTION_TIMING.countUp,
}: {
  value: number | null;
  formatter: (value: number) => string;
  className?: string;
  duration?: number;
}) {
  const reducedMotion = usePrefersReducedMotion();
  const initialValue = value !== null && reducedMotion ? value : value === null ? null : 0;
  const [displayValue, setDisplayValue] = useState<number | null>(initialValue);
  const [updated, setUpdated] = useState(false);
  const previousTarget = useRef<number | null>(null);
  const currentValue = useRef<number | null>(initialValue);

  useEffect(() => {
    let frame: number | undefined;
    let updateTimer: number | undefined;

    if (value === null || !Number.isFinite(value)) {
      previousTarget.current = null;
      currentValue.current = null;
      setDisplayValue(null);
      setUpdated(false);
      return undefined;
    }

    const previous = previousTarget.current;
    previousTarget.current = value;

    if (reducedMotion) {
      currentValue.current = value;
      setDisplayValue(value);
      setUpdated(false);
      return undefined;
    }

    const start = currentValue.current ?? (previous ?? 0);
    if (previous === value && start === value) return undefined;

    if (previous !== null && previous !== value) {
      setUpdated(true);
      updateTimer = window.setTimeout(() => setUpdated(false), MOTION_TIMING.valueHighlight);
    } else {
      setUpdated(false);
    }

    const startedAt = performance.now();
    const safeDuration = Math.max(1, duration);
    const tick = (now: number) => {
      const progress = Math.min(1, (now - startedAt) / safeDuration);
      const eased = 1 - (1 - progress) ** 3;
      const nextValue = start + (value - start) * eased;
      currentValue.current = progress === 1 ? value : nextValue;
      setDisplayValue(currentValue.current);
      if (progress < 1) frame = window.requestAnimationFrame(tick);
    };
    frame = window.requestAnimationFrame(tick);

    return () => {
      if (frame !== undefined) window.cancelAnimationFrame(frame);
      if (updateTimer !== undefined) window.clearTimeout(updateTimer);
    };
  }, [duration, reducedMotion, value]);

  const classes = ['animated-metric', className, updated ? 'is-updating' : '']
    .filter(Boolean)
    .join(' ');
  const label = value === null || !Number.isFinite(value) ? 'Unavailable' : formatter(value);

  return <span className={classes} aria-label={label}>
    {displayValue === null ? '—' : formatter(displayValue)}
  </span>;
}
