// FEAT-VIS-004 Chart colour themes: chosen at chart review, stored on the analysis by the API and applied to every
// chart in the report and the PDF. Mirrors backend/app/Support/ChartThemes.php exactly (colours only, never data).
export type ChartThemeKey = 'emerald' | 'ocean' | 'sunset' | 'plum' | 'slate' | 'vivid';

export interface ChartTheme {
  key: ChartThemeKey;
  /** English label; translated through t() where shown. */
  label: string;
  colors: readonly string[];
}

export const DEFAULT_CHART_THEME: ChartThemeKey = 'emerald';

export const CHART_THEMES: readonly ChartTheme[] = [
  {
    key: 'emerald',
    label: 'Data Speak green',
    colors: ['#087A43', '#15965A', '#2DB37A', '#5CCB9A', '#8FE0BD', '#065F46'],
  },
  {
    key: 'ocean',
    label: 'Ocean blue',
    colors: ['#1D4ED8', '#2563EB', '#3B82F6', '#60A5FA', '#93C5FD', '#1E3A8A'],
  },
  {
    key: 'sunset',
    label: 'Sunset orange',
    colors: ['#C2410C', '#EA580C', '#F97316', '#FB923C', '#FDBA74', '#9A3412'],
  },
  {
    key: 'plum',
    label: 'Plum violet',
    colors: ['#6D28D9', '#7C3AED', '#8B5CF6', '#A78BFA', '#C4B5FD', '#4C1D95'],
  },
  {
    key: 'slate',
    label: 'Monochrome',
    colors: ['#0F172A', '#334155', '#475569', '#64748B', '#94A3B8', '#1E293B'],
  },
  {
    key: 'vivid',
    label: 'Vivid mix',
    colors: ['#087A43', '#1D4ED8', '#EA580C', '#7C3AED', '#DB2777', '#0891B2'],
  },
];

export function isChartTheme(value: unknown): value is ChartThemeKey {
  return typeof value === 'string' && CHART_THEMES.some((th) => th.key === value);
}

/** Palette for a stored theme key; unknown or missing keys fall back to the default. */
export function themeColors(key: string | null | undefined): readonly string[] {
  return (CHART_THEMES.find((th) => th.key === key) ?? CHART_THEMES[0]).colors;
}
