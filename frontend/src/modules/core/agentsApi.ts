import { keepPreviousData, useQuery } from '@tanstack/react-query';
import { api } from '../../shared/api/client';
import type { Agent, AgentAccount, AgentPrice, AgentService, Paginated } from '../../shared/api/types';

export interface AgentPayload {
  name: string;
  country: 'EG' | 'SD' | null;
  phone: string | null;
  active?: boolean;
}

export function useAgents(page: number, q: string) {
  return useQuery({
    queryKey: ['agents', page, q],
    queryFn: () => api<Paginated<Agent>>('/agents', { query: { page, q, per_page: 15 } }),
    placeholderData: keepPreviousData,
  });
}

export function useAgent(id: number) {
  return useQuery({
    queryKey: ['agent', id],
    queryFn: async () => (await api<{ data: Agent }>(`/agents/${id}`)).data,
  });
}

export const saveAgent = (id: number | null, payload: AgentPayload) =>
  id === null
    ? api<{ data: Agent }>('/agents', { method: 'POST', body: payload })
    : api<{ data: Agent }>(`/agents/${id}`, { method: 'PUT', body: payload });

export const setAgentActive = (id: number, active: boolean) =>
  active ? api(`/agents/${id}`, { method: 'PUT', body: { active: true } }) : api(`/agents/${id}`, { method: 'DELETE' });

export function useAgentPrices(agentId: number) {
  return useQuery({
    queryKey: ['agent-prices', agentId],
    queryFn: async () => (await api<{ data: AgentPrice[] }>(`/agents/${agentId}/prices`)).data,
  });
}

export interface AgentPricePayload {
  service: AgentService;
  route: string;
  price: string;
  currency_id: number;
}

export const saveAgentPrice = (agentId: number, id: number | null, payload: AgentPricePayload) =>
  id === null
    ? api<{ data: AgentPrice }>(`/agents/${agentId}/prices`, { method: 'POST', body: payload })
    : api<{ data: AgentPrice }>(`/agent-prices/${id}`, { method: 'PUT', body: payload });

export const setAgentPriceActive = (id: number, active: boolean) =>
  active ? api(`/agent-prices/${id}`, { method: 'PUT', body: { active: true } }) : api(`/agent-prices/${id}`, { method: 'DELETE' });

export function useAgentAccount(agentId: number) {
  return useQuery({
    queryKey: ['agent-account', agentId],
    queryFn: async () => (await api<{ data: AgentAccount }>(`/agents/${agentId}/account`)).data,
  });
}

export const settleAgent = (agentId: number, payload: { treasury_id: number; amount: string; notes?: string }) =>
  api(`/agents/${agentId}/settlements`, { method: 'POST', body: payload });
