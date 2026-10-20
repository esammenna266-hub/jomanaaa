import { useAuth } from '../../app/auth/AuthContext';
import { ar } from '../../i18n/ar';
import { PageHeader } from '../../shared/ui/PageHeader';
import { DashboardCards } from '../reports/DashboardCards';

export function HomePage() {
  const { user, scopeOf } = useAuth();

  if (!user) {
    return null;
  }

  // Company reports reach "all" or "branch"; an office's report scope is only its own account.
  const reportScope = scopeOf('report.view');
  const showDashboard = reportScope === 'all' || reportScope === 'branch';

  return (
    <>
      <PageHeader title={ar.home.welcome(user.name)} />
      <p className="muted">{ar.home.intro}</p>
      <div className="cards">
        <div className="card">
          <div className="card__label">{ar.home.role}</div>
          <div className="card__value">{user.role?.name_ar ?? '—'}</div>
        </div>
        {user.branch && (
          <div className="card">
            <div className="card__label">{ar.home.branch}</div>
            <div className="card__value">{user.branch.name}</div>
          </div>
        )}
        {user.agent && (
          <div className="card">
            <div className="card__label">{ar.home.agent}</div>
            <div className="card__value">{user.agent.name}</div>
          </div>
        )}
      </div>
      {showDashboard && <DashboardCards />}
    </>
  );
}
