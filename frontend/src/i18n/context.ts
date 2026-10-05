import { createContext, useContext } from 'react';
import type { Locale } from '@/lib/locale';
import type { t } from './core';

export interface I18nValue {
  locale: Locale;
  setLocale: (l: Locale) => void;
  t: typeof t;
}

export const I18nContext = createContext<I18nValue | null>(null);

export function useI18n(): I18nValue {
  const ctx = useContext(I18nContext);
  if (!ctx) throw new Error('useI18n must be used inside I18nProvider');
  return ctx;
}
