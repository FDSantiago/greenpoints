import { Navigate, Outlet, useLocation } from 'react-router-dom';
import { useAuth } from './AuthContext.jsx';
import { Spinner } from '../components/ui.jsx';

// Wrap routes with this. `roles` limits who may enter; the server enforces it too.
export default function RequireAuth({ roles }) {
  const { user, ready } = useAuth();
  const loc = useLocation();
  if (!ready) return <Spinner full />;
  if (!user) return <Navigate to="/login" state={{ from: loc.pathname }} replace />;
  if (roles && !roles.includes(user.role)) return <Navigate to="/" replace />;
  return <Outlet />;
}
