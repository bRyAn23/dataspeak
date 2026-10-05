// FE-CMP-001 AppShell: global authenticated layout, navigation and content region.
import { useState } from 'react';
import { Outlet } from 'react-router-dom';
import { Menu } from 'lucide-react';
import logo from '@/assets/logo.png';
import { Sidebar } from './Sidebar';
import { t } from '@/i18n';

export function AppShell() {
  const [sidebarOpen, setSidebarOpen] = useState(false);
  return (
    <div className="flex h-screen bg-slate-50 overflow-hidden">
      <a
        href="#main"
        className="sr-only focus:not-sr-only focus:absolute focus:z-50 focus:m-2 focus:px-3 focus:py-2 focus:bg-white focus:rounded-lg"
      >
        {t('Skip to content')}
      </a>
      <Sidebar open={sidebarOpen} onClose={() => setSidebarOpen(false)} />
      <div className="flex-1 flex flex-col overflow-hidden">
        <div className="lg:hidden flex items-center gap-3 px-5 h-14 border-b border-slate-200 bg-white">
          <button
            type="button"
            onClick={() => setSidebarOpen(true)}
            className="p-1.5 rounded-lg hover:bg-slate-100"
            aria-label={t('Open menu')}
          >
            <Menu size={20} aria-hidden="true" />
          </button>
          <img src={logo} alt="Data Speak" className="h-7 w-auto object-contain" />
        </div>
        <main id="main" className="flex-1 overflow-y-auto">
          <div className="px-4 sm:px-8 py-8 max-w-6xl mx-auto w-full">
            <Outlet />
          </div>
        </main>
      </div>
    </div>
  );
}
