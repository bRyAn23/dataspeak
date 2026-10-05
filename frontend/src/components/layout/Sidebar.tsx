// FE-CMP-002 Sidebar: primary application navigation and the user menu.
import { useState } from 'react';
import { NavLink, useNavigate } from 'react-router-dom';
import { ChevronDown, LayoutDashboard, LogOut, Plus } from 'lucide-react';
import logo from '@/assets/logo.png';
import { useAuth } from '@/hooks/useAuth';
import { cn } from '@/lib/cn';
import { LanguageSwitcher } from './LanguageSwitcher';
import { t } from '@/i18n';

interface Props {
  open: boolean;
  onClose: () => void;
}

const linkClass = ({ isActive }: { isActive: boolean }) =>
  cn(
    'w-full flex items-center gap-2.5 px-3 py-2.5 text-sm rounded-lg transition-colors focus:outline-none focus:ring-2 focus:ring-brand-700',
    isActive
      ? 'bg-brand-50 text-brand-700 font-medium'
      : 'text-slate-700 hover:bg-slate-100 hover:text-slate-900',
  );

export function Sidebar({ open, onClose }: Props) {
  const { user, logout } = useAuth();
  const navigate = useNavigate();
  const [menuOpen, setMenuOpen] = useState(false);

  const handleLogout = async () => {
    await logout();
    navigate('/login', { replace: true });
  };

  return (
    <>
      {open && (
        <div
          className="fixed inset-0 z-20 bg-black/30 lg:hidden"
          onClick={onClose}
          aria-hidden="true"
        />
      )}
      <aside
        className={cn(
          'fixed lg:static inset-y-0 left-0 z-30 w-56 flex flex-col bg-white border-r border-slate-200 transition-transform duration-200',
          open ? 'translate-x-0' : '-translate-x-full lg:translate-x-0',
        )}
        aria-label={t('Sidebar')}
      >
        <div className="flex items-center justify-center px-4 h-16 border-b border-slate-100">
          <img src={logo} alt="Data Speak" className="h-9 w-auto object-contain" />
        </div>
        <nav className="flex-1 py-4 px-3 space-y-0.5 overflow-y-auto" aria-label={t('Primary')}>
          <NavLink to="/analyses" end className={linkClass} onClick={onClose}>
            <LayoutDashboard size={16} aria-hidden="true" />
            {t('Analyses')}
          </NavLink>
          <NavLink to="/analyses/new" className={linkClass} onClick={onClose}>
            <Plus size={16} aria-hidden="true" />
            {t('New analysis')}
          </NavLink>
        </nav>
        <div className="border-t border-slate-100 p-3 space-y-2">
          <div className="px-1">
            <LanguageSwitcher />
          </div>
          <div className="relative">
            <button
              type="button"
              onClick={() => setMenuOpen((v) => !v)}
              aria-expanded={menuOpen}
              aria-haspopup="menu"
              className="w-full flex items-center gap-2.5 px-3 py-2.5 text-sm rounded-lg hover:bg-slate-100 transition-colors focus:outline-none focus:ring-2 focus:ring-brand-700"
            >
              <div
                className="w-7 h-7 bg-brand-700 rounded-full flex items-center justify-center text-white text-xs font-semibold shrink-0"
                aria-hidden="true"
              >
                {user?.name.charAt(0) ?? '?'}
              </div>
              <span className="flex-1 text-left text-slate-700 font-medium truncate">
                {user?.name}
              </span>
              <ChevronDown size={14} className="text-slate-500 shrink-0" aria-hidden="true" />
            </button>
            {menuOpen && (
              <div
                role="menu"
                className="absolute bottom-full left-0 right-0 mb-1 bg-white border border-slate-200 rounded-xl shadow-lg py-1.5 z-10"
              >
                <p className="px-4 py-1.5 text-xs text-slate-500 truncate">{user?.email}</p>
                <div className="my-1 border-t border-slate-100" />
                <button
                  type="button"
                  role="menuitem"
                  onClick={handleLogout}
                  className="w-full flex items-center gap-2 px-4 py-2 text-sm text-slate-700 hover:bg-slate-50 transition-colors"
                >
                  <LogOut size={14} aria-hidden="true" />
                  {t('Log out')}
                </button>
              </div>
            )}
          </div>
        </div>
      </aside>
    </>
  );
}
