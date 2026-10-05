import { useCallback, useEffect, useMemo, useState, type ReactNode } from 'react';
import { writeLocale, type Locale } from '@/lib/locale';
import { activateLocale, currentLocale, t } from './core';
import { I18nContext, type I18nValue } from './context';

export function I18nProvider({ children }: { children: ReactNode }) {
  const [locale, setLocaleState] = useState<Locale>(currentLocale());
  const setLocale = useCallback((l: Locale) => {
    activateLocale(l);
    writeLocale(l);
    setLocaleState(l);
  }, []);
  useEffect(() => {
    document.documentElement.lang = locale;
  }, [locale]);
  const value = useMemo<I18nValue>(() => ({ locale, setLocale, t }), [locale, setLocale]);
  // Remount the tree on language change so every label table re-reads the active dictionary.
  return (
    <I18nContext.Provider value={value}>
      <div key={locale} className="contents">
        {children}
      </div>
    </I18nContext.Provider>
  );
}
