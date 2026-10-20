import { ar } from '../../i18n/ar';
import type { AgentAccount } from '../../shared/api/types';
import { formatDateTime, formatMoney } from '../../shared/format';
import { Notice } from '../../shared/ui/Notice';
import { currencyName, useCurrencies } from '../master-data/api';

/** What an external office owes, what it paid and the payments behind it: shown to the company and to the office itself. */
export function AgentAccountView({ account, failed }: { account: AgentAccount | undefined; failed: boolean }) {
  const currencies = useCurrencies();

  const money = (value: string, currencyId: number) => (
    <>
      <span className="ltr">{formatMoney(value)}</span> {currencyName(currencies.data, currencyId)}
    </>
  );

  return (
    <>
      <h2 className="section-title">{ar.agents.accountTitle}</h2>
      {failed && <Notice tone="error">{ar.common.loadFailed}</Notice>}
      <div className="table-wrap">
        <table className="table">
          <thead>
            <tr>
              <th scope="col">{ar.agents.currency}</th>
              <th scope="col">{ar.agents.due}</th>
              <th scope="col">{ar.agents.paid}</th>
              <th scope="col">{ar.agents.remaining}</th>
            </tr>
          </thead>
          <tbody>
            {account?.balances.length === 0 && (
              <tr>
                <td colSpan={4} className="table__empty">
                  {ar.agents.noBalance}
                </td>
              </tr>
            )}
            {account?.balances.map((balance) => (
              <tr key={balance.currency_id}>
                <td>{currencyName(currencies.data, balance.currency_id)}</td>
                <td className="ltr">{formatMoney(balance.due)}</td>
                <td className="ltr">{formatMoney(balance.paid)}</td>
                <td className="ltr">
                  <strong>{formatMoney(balance.remaining)}</strong>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      <h2 className="section-title">{ar.agents.settlementsTitle}</h2>
      <div className="table-wrap">
        <table className="table">
          <thead>
            <tr>
              <th scope="col">{ar.agents.paymentNo}</th>
              <th scope="col">{ar.agents.amount}</th>
              <th scope="col">{ar.agents.when}</th>
              <th scope="col">{ar.agents.notes}</th>
            </tr>
          </thead>
          <tbody>
            {account?.settlements.length === 0 && (
              <tr>
                <td colSpan={4} className="table__empty">
                  {ar.common.empty}
                </td>
              </tr>
            )}
            {account?.settlements.map((payment) => (
              <tr key={payment.id} className={payment.direction === 'out' ? 'row--alert' : undefined}>
                <td className="ltr">{payment.serial_no}</td>
                <td>
                  {payment.direction === 'out' ? '− ' : ''}
                  {money(payment.amount, payment.currency_id)}
                </td>
                <td>{formatDateTime(payment.created_at)}</td>
                <td>{payment.notes ?? '—'}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </>
  );
}
