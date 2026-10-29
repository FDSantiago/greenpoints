import { Navigate, Outlet, useLocation } from 'react-router-dom';
import { useAuth } from './AuthContext.jsx';
import { Spinner } from '../components/ui.jsx';

// Wrap routes with this. `roles` limits who may enter; the server enforces it too.
export default function RequireAuth({ roles }) {
  const { user, ready } = useAuth();
  const loc = useLocation();
  if (!ready) return <Spinner full />;
  if (!user) return <Navigate to="/login" state={{ from: loc.pathname }} replace />;
  // Unverified accounts are signed in but stay on the verification screen (the server blocks them too).
  if (!user.email_verified) return <Navigate to="/verify-email" replace />;
  if (roles && !roles.includes(user.role)) return <Navigate to="/" replace />;
  return <Outlet />;
}
