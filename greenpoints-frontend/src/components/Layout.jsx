import { useState } from 'react';
import { Link, NavLink, Outlet, useLocation } from 'react-router-dom';
import { useAuth } from '../auth/AuthContext.jsx';
import { useNotifications } from '../auth/NotificationsContext.jsx';

// [path, label, emoji for the phone tab bar]
const NAV = {
  student: [['/', 'Home', '🏠'], ['/drop-off', 'Drop off', '♻️'], ['/rewards', 'Rewards', '🎁'], ['/leaderboard', 'Ranks', '🏆'], ['/history', 'History', '🧾'], ['/wallet', 'Wallet', '👛']],
  staff: [['/staff/review', 'Review', '✅'], ['/staff/pickups', 'Pickups', '📦']],
  admin: [['/admin', 'Overview', '📊'], ['/staff/review', 'Review', '✅'], ['/staff/pickups', 'Pickups', '📦'], ['/admin/users', 'Users', '👥'], ['/admin/groups', 'Groups', '🏫'], ['/admin/categories', 'Waste types', '♻️'], ['/admin/rewards', 'Rewards', '🎁']],
};
const MENU_EXTRA = [['/impact', 'My impact'], ['/pickups', 'My reward pickups']];

export default function Layout() {
  const { user, logout } = useAuth();
  const [open, setOpen] = useState(false);
  // Bell badge and pop-up come live from the notifications stream.
  const { unread, toast, dismissToast } = useNotifications();
  const { pathname } = useLocation();
  const items = NAV[user.role] || NAV.student;

  const link = ([to, label]) => (
    <NavLink key={to} to={to} end={to === '/' || to === '/admin'} className={({ isActive }) => (isActive ? 'active' : '')}>{label}</NavLink>
  );

  return (
    <>
      <header className="topbar">
        <Link to="/" className="brand"><img src="/logo.png" alt="" />GreenPoints</Link>
        <nav className="nav" aria-label="Main">{items.map(link)}</nav>
        <div className="tools">
          <Link to="/alerts" className="iconbtn" aria-label={`Alerts, ${unread} unread`}>
            🔔{unread > 0 && <span className="dot">{unread}</span>}
          </Link>
          <button className="avatar" onClick={() => setOpen(!open)} aria-label="Account menu" aria-expanded={open}>
            {user.name.charAt(0)}
          </button>
          {open && (
            <>
              <div className="scrim" onClick={() => setOpen(false)} />
              <div className="menu" onClick={() => setOpen(false)}>
                <div className="who"><b>{user.name}</b><span className="muted small">{user.email}<br />{user.group?.name || 'No group yet'} · {user.role}</span></div>
                {user.role === 'student' && MENU_EXTRA.map(([to, label]) => <Link key={to} to={to}>{label}</Link>)}
                <button onClick={logout}>Log out</button>
              </div>
            </>
          )}
        </div>
      </header>
      <main className="page"><Outlet /></main>
      {toast && pathname !== '/alerts' && (
        <Link to="/alerts" className={`note ${toast.style} toast`} role="status" onClick={dismissToast}>
          <div style={{ flex: 1 }}><b>{toast.title}</b><span className="small">{toast.body}</span></div>
        </Link>
      )}
      <nav className="tabbar" aria-label="Main">
        {items.map(([to, label, emoji]) => (
          <NavLink key={to} to={to} end={to === '/' || to === '/admin'} className={({ isActive }) => (isActive ? 'active' : '')}><span>{emoji}</span>{label}</NavLink>
        ))}
      </nav>
    </>
  );
}
