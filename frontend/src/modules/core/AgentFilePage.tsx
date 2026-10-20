import { useMutation, useQueryClient } from '@tanstack/react-query';
import { useState, type FormEvent } from 'react';
import { Link, useParams } from 'react-router-dom';
import { useAuth } from '../../app/auth/AuthContext';
import { ar } from '../../i18n/ar';
import type { Agent, AgentAccount, AgentPrice, AgentService } from '../../shared/api/types';
import { formatMoney } from '../../shared/format';
import { fieldError, formError } from '../../shared/forms/errors';
import { Button } from '../../shared/ui/Button';
import { SelectField, TextField } from '../../shared/ui/Fields';
import { Modal } from '../../shared/ui/Modal';
import { Notice } from '../../shared/ui/Notice';
import { PageHeader } from '../../shared/ui/PageHeader';
import { Spinner } from '../../shared/ui/Spinner';
import { StatusBadge } from '../../shared/ui/StatusBadge';
import { currencyName, useCurrencies } from '../master-data/api';
import { useTreasuries } from '../shipping/api';
import { saveAgentPrice, setAgentPriceActive, settleAgent, useAgent, useAgentAccount, useAgentPrices } from './agentsApi';
import { AgentAccountView } from './AgentAccountView';
import { AgentForm } from './AgentForm';

const SERVICES = Object.keys(ar.agents.service) as AgentService[];

export function AgentFilePage() {
  const id = Number(useParams().id);
  const agent = useAgent(id);

  if (agent.isPending) {
    return <Spinner />;
  }

  if (agent.isError) {
    return <Notice tone="error">{agent.error.message}</Notice>;
  }

  return <AgentFile agent={agent.data} />;
}

function AgentFile({ agent }: { agent: Agent }) {
  const { can } = useAuth();
  const queryClient = useQueryClient();
  const currencies = useCurrencies();
  const account = useAgentAccount(agent.id);
  const prices = useAgentPrices(agent.id);
  const [dialog, setDialog] = useState<'edit' | 'settle' | null>(null);
  const [pricing, setPricing] = useState<AgentPrice | 'new' | null>(null);

  const togglePrice = useMutation({
    mutationFn: ({ id, active }: { id: number; active: boolean }) => setAgentPriceActive(id, active),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['agent-prices', agent.id] }),
  });

  const money = (value: string, currencyId: number) => (
    <>
      <span className="ltr">{formatMoney(value)}</span> {currencyName(currencies.data, currencyId)}
    </>
  );

  return (
    <>
      <PageHeader title={agent.name}>
        <div className="actions-bar">
          {can('masterdata.manage') && (
            <Button variant="secondary" onClick={() => setDialog('edit')}>
              {ar.common.edit}
            </Button>
          )}
          {can('payment.collect') && <Button onClick={() => setDialog('settle')}>{ar.agents.recordPayment}</Button>}
        </div>
      </PageHeader>

      <div className="file-head">
        <StatusBadge active={agent.active} />
        <span className="muted">{agent.country ? ar.countries[agent.country] : ''}</span>
        <span className="ltr muted">{agent.phone}</span>
      </div>

      <AgentAccountView account={account.data} failed={account.isError} />

      <h2 className="section-title">{ar.agents.pricesTitle}</h2>
      <p className="muted">{ar.agents.pricesHint}</p>
      {can('agent_price.manage') && (
        <p>
          <Button onClick={() => setPricing('new')}>{ar.agents.addPrice}</Button>
        </p>
      )}
      {togglePrice.isError && <Notice tone="error">{togglePrice.error.message}</Notice>}
      <div className="table-wrap">
        <table className="table">
          <thead>
            <tr>
              <th scope="col">{ar.agents.serviceLabel}</th>
              <th scope="col">{ar.agents.route}</th>
              <th scope="col">{ar.agents.price}</th>
              <th scope="col">{ar.common.status}</th>
              <th scope="col" />
            </tr>
          </thead>
          <tbody>
            {prices.data?.length === 0 && (
              <tr>
                <td colSpan={5} className="table__empty">
                  {ar.common.empty}
                </td>
              </tr>
            )}
            {prices.data?.map((price) => (
              <tr key={price.id}>
                <td>{ar.agents.service[price.service]}</td>
                <td>{price.route}</td>
                <td>{money(price.price, price.currency_id)}</td>
                <td>
                  <StatusBadge active={price.active} />
                </td>
                <td>
                  {can('agent_price.manage') && (
                    <div className="row-actions">
                      <Button variant="ghost" aria-label={`${ar.common.edit} ${price.route}`} onClick={() => setPricing(price)}>
                        {ar.common.edit}
                      </Button>
                      <Button
                        variant="ghost"
                        aria-label={`${price.active ? ar.common.deactivate : ar.common.activate} ${price.route}`}
                        disabled={togglePrice.isPending}
                        onClick={() => togglePrice.mutate({ id: price.id, active: !price.active })}
                      >
                        {price.active ? ar.common.deactivate : ar.common.activate}
                      </Button>
                    </div>
                  )}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      <p>
        <Link to="/agents">← {ar.agents.title}</Link>
      </p>

      {dialog === 'edit' && <AgentForm agent={agent} onClose={() => setDialog(null)} />}
      {dialog === 'settle' && <SettleDialog agent={agent} account={account.data} onClose={() => setDialog(null)} />}
      {pricing !== null && <PriceDialog agentId={agent.id} price={pricing === 'new' ? null : pricing} onClose={() => setPricing(null)} />}
    </>
  );
}

function SettleDialog({ agent, account, onClose }: { agent: Agent; account: AgentAccount | undefined; onClose: () => void }) {
  const queryClient = useQueryClient();
  const currencies = useCurrencies();
  const treasuries = useTreasuries(true);
  const [treasuryId, setTreasuryId] = useState('');
  const [amount, setAmount] = useState('');
  const [notes, setNotes] = useState('');

  const chosen = treasuries.data?.find((treasury) => String(treasury.id) === treasuryId);
  const remaining = chosen ? account?.balances.find((balance) => balance.currency_id === chosen.currency_id)?.remaining : undefined;

  const mutation = useMutation({
    mutationFn: () => settleAgent(agent.id, { treasury_id: Number(treasuryId), amount: amount.trim(), ...(notes.trim() ? { notes: notes.trim() } : {}) }),
    onSuccess: async () => {
      await Promise.all([queryClient.invalidateQueries({ queryKey: ['agent-account', agent.id] }), queryClient.invalidateQueries({ queryKey: ['treasuries'] })]);
      onClose();
    },
  });

  function onSubmit(event: FormEvent) {
    event.preventDefault();
    mutation.mutate();
  }

  const fields = ['treasury_id', 'amount', 'notes'];

  return (
    <Modal title={ar.agents.recordPayment} onClose={onClose}>
      <form onSubmit={onSubmit} noValidate>
        {formError(mutation.error, fields) && <Notice tone="error">{formError(mutation.error, fields)}</Notice>}

        <SelectField label={ar.agents.treasury} value={treasuryId} onChange={(event) => setTreasuryId(event.target.value)} error={fieldError(mutation.error, 'treasury_id')} required>
          <option value="">{ar.common.choose}</option>
          {treasuries.data?.map((treasury) => (
            <option key={treasury.id} value={treasury.id}>
              {ar.shipments.treasuryMethod[treasury.method]} — {treasury.currency?.name_ar} ({treasury.branch?.name})
            </option>
          ))}
        </SelectField>
        {chosen && <Notice>{ar.agents.remainingIn(formatMoney(remaining ?? '0'), currencyName(currencies.data, chosen.currency_id))}</Notice>}
        <TextField label={ar.agents.amount} value={amount} onChange={(event) => setAmount(event.target.value)} error={fieldError(mutation.error, 'amount')} inputMode="decimal" ltr required />
        <TextField label={ar.agents.notes} value={notes} onChange={(event) => setNotes(event.target.value)} error={fieldError(mutation.error, 'notes')} optional />

        <div className="form-actions">
          <Button type="submit" loading={mutation.isPending} disabled={treasuryId === '' || amount.trim() === ''}>
            {ar.agents.recordPayment}
          </Button>
          <Button variant="secondary" onClick={onClose}>
            {ar.common.cancel}
          </Button>
        </div>
      </form>
    </Modal>
  );
}

function PriceDialog({ agentId, price, onClose }: { agentId: number; price: AgentPrice | null; onClose: () => void }) {
  const queryClient = useQueryClient();
  const currencies = useCurrencies();
  const [service, setService] = useState<AgentService>(price?.service ?? 'bus');
  const [route, setRoute] = useState(price?.route ?? '');
  const [amount, setAmount] = useState(price?.price ?? '');
  const [currencyId, setCurrencyId] = useState(price ? String(price.currency_id) : '');

  const mutation = useMutation({
    mutationFn: () =>
      saveAgentPrice(agentId, price?.id ?? null, {
        service,
        route: route.trim(),
        price: amount.trim(),
        currency_id: Number(currencyId || currencies.data?.[0]?.id),
      }),
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: ['agent-prices', agentId] });
      onClose();
    },
  });

  function onSubmit(event: FormEvent) {
    event.preventDefault();
    mutation.mutate();
  }

  const fields = ['service', 'route', 'price', 'currency_id'];

  return (
    <Modal title={price ? ar.agents.editPrice : ar.agents.addPrice} onClose={onClose}>
      <form onSubmit={onSubmit} noValidate>
        {formError(mutation.error, fields) && <Notice tone="error">{formError(mutation.error, fields)}</Notice>}

        <SelectField label={ar.agents.serviceLabel} value={service} onChange={(event) => setService(event.target.value as AgentService)} error={fieldError(mutation.error, 'service')}>
          {SERVICES.map((value) => (
            <option key={value} value={value}>
              {ar.agents.service[value]}
            </option>
          ))}
        </SelectField>
        <TextField label={ar.agents.route} value={route} onChange={(event) => setRoute(event.target.value)} hint={ar.agents.routeHint} error={fieldError(mutation.error, 'route')} required />
        <TextField label={ar.agents.price} value={amount} onChange={(event) => setAmount(event.target.value)} error={fieldError(mutation.error, 'price')} inputMode="decimal" ltr required />
        <SelectField label={ar.agents.currency} value={currencyId || String(currencies.data?.[0]?.id ?? '')} onChange={(event) => setCurrencyId(event.target.value)} error={fieldError(mutation.error, 'currency_id')}>
          {currencies.data?.map((currency) => (
            <option key={currency.id} value={currency.id}>
              {currency.name_ar}
            </option>
          ))}
        </SelectField>

        <div className="form-actions">
          <Button type="submit" loading={mutation.isPending} disabled={route.trim() === '' || amount.trim() === ''}>
            {ar.common.save}
          </Button>
          <Button variant="secondary" onClick={onClose}>
            {ar.common.cancel}
          </Button>
        </div>
      </form>
    </Modal>
  );
}
