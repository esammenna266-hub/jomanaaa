import { Link } from 'react-router-dom';
import { useAuth } from '../../app/auth/AuthContext';
import { ar } from '../../i18n/ar';
import { Notice } from '../../shared/ui/Notice';
import { PageHeader } from '../../shared/ui/PageHeader';
import { AgentAccountView } from './AgentAccountView';
import { useAgentAccount } from './agentsApi';

/** The external office's own account with the company. Reached only by office accounts. */
export function MyAccountPage() {
  const { user } = useAuth();

  if (!user?.agent) {
    return <Notice>{ar.agents.officesOnly}</Notice>;
  }

  return <MyAccount agentId={user.agent.id} name={user.agent.name} />;
}

function MyAccount({ agentId, name }: { agentId: number; name: string }) {
  const account = useAgentAccount(agentId);

  return (
    <>
      <PageHeader title={ar.agents.myAccount} />
      <p className="muted">{ar.agents.myAccountIntro(name)}</p>
      <AgentAccountView account={account.data} failed={account.isError} />
      <p>
        <Link to="/bookings">{ar.nav.bookings}</Link> · <Link to="/shipments">{ar.nav.shipments}</Link>
      </p>
    </>
  );
}
