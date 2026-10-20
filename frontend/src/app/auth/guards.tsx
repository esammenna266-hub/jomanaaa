import { Navigate, Outlet, useLocation } from 'react-router-dom';
import { Spinner } from '../../shared/ui/Spinner';
import { useAuth } from './AuthContext';

/** Everything behind login. A temporary password must be replaced before anything else is reachable. */
export function RequireAuth() {
  const { status, user } = useAuth();
  const location = useLocation();

  if (status === 'loading') {
    return <Spinner fullPage />;
  }

  if (status === 'anonymous' || user === null) {
    return <Navigate to="/login" replace state={{ from: location.pathname }} />;
  }

  if (user.must_change_password && location.pathname !== '/change-password') {
    return <Navigate to="/change-password" replace />;
  }

  return <Outlet />;
}

/** Hides a screen the signed-in user has no permission for (any one of several is enough when a list is given). */
export function RequirePermission({ permission }: { permission: string | string[] }) {
  const { can } = useAuth();

  return [permission].flat().some((code) => can(code)) ? <Outlet /> : <Navigate to="/" replace />;
}
