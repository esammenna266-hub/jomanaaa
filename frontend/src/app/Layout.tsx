import { useState } from 'react';
import { NavLink, Outlet } from 'react-router-dom';
import { ar } from '../i18n/ar';
import { Button } from '../shared/ui/Button';
import { useAuth } from './auth/AuthContext';
import { navItems } from './nav';

export function Layout() {
  const { user, can, logout } = useAuth();
  const [menuOpen, setMenuOpen] = useState(false);

  const isOffice = user?.agent != null;
  const items = navItems.filter(
    (item) =>
      (item.permission === undefined || [item.permission].flat().some((code) => can(code))) &&
      (!item.officeOnly || isOffice) &&
      (!item.hideForOffice || !isOffice),
  );

  return (
    <div className="shell">
      <aside className={`sidebar${menuOpen ? ' sidebar--open' : ''}`}>
        <div className="sidebar__brand">{ar.appName}</div>
        <nav aria-label={ar.nav.menu}>
          {items.map((item) => (
            <NavLink
              key={item.to}
              to={item.to}
              end={item.end}
              className={({ isActive }) => `sidebar__link${isActive ? ' sidebar__link--active' : ''}`}
              onClick={() => setMenuOpen(false)}
            >
              {item.label}
            </NavLink>
          ))}
        </nav>
      </aside>

      <div className="shell__main">
        <header className="topbar">
          <button
            type="button"
            className="topbar__menu"
            aria-label={ar.nav.menu}
            aria-expanded={menuOpen}
            onClick={() => setMenuOpen((open) => !open)}
          >
            ☰
          </button>
          <div className="topbar__user">
            <strong>{user?.name}</strong>
            {user?.role && <span className="muted"> — {user.role.name_ar}</span>}
          </div>
          <Button variant="secondary" onClick={() => void logout()}>
            {ar.auth.signOut}
          </Button>
        </header>

        <main className="content">
          <Outlet />
        </main>
      </div>
    </div>
  );
}
