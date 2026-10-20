import { useMutation, useQueryClient } from '@tanstack/react-query';
import { useState, type FormEvent } from 'react';
import { ar } from '../../i18n/ar';
import type { UserRecord } from '../../shared/api/types';
import { fieldError, formError } from '../../shared/forms/errors';
import { Button } from '../../shared/ui/Button';
import { SelectField, TextField } from '../../shared/ui/Fields';
import { Modal } from '../../shared/ui/Modal';
import { Notice } from '../../shared/ui/Notice';
import { useAllAgents, useAllBranches } from '../core/api';
import { saveUser, useRoles } from './api';

const FIELDS = ['name', 'username', 'password', 'role_id', 'branch_id', 'agent_id'];

export function UserForm({ user, canListAgents, onClose }: { user: UserRecord | null; canListAgents: boolean; onClose: () => void }) {
  const queryClient = useQueryClient();
  const roles = useRoles();
  const branches = useAllBranches();

  const [name, setName] = useState(user?.name ?? '');
  const [username, setUsername] = useState(user?.username ?? '');
  const [password, setPassword] = useState('');
  const [roleId, setRoleId] = useState(user?.role_id ? String(user.role_id) : '');
  const [branchId, setBranchId] = useState(user?.branch_id ? String(user.branch_id) : '');
  const [agentId, setAgentId] = useState(user?.agent_id ? String(user.agent_id) : '');

  const roleCode = roles.data?.find((role) => String(role.id) === roleId)?.code;
  const isAgentRole = roleCode === 'agent';
  const agents = useAllAgents(canListAgents && isAgentRole);

  const mutation = useMutation({
    mutationFn: () =>
      saveUser(user?.id ?? null, {
        name: name.trim(),
        username: username.trim(),
        ...(user ? {} : { password }),
        role_id: Number(roleId),
        branch_id: isAgentRole || !branchId ? null : Number(branchId),
        agent_id: isAgentRole && agentId ? Number(agentId) : null,
      }),
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: ['users'] });
      onClose();
    },
  });

  function onSubmit(event: FormEvent) {
    event.preventDefault();
    mutation.mutate();
  }

  return (
    <Modal title={user ? ar.users.editTitle : ar.users.add} onClose={onClose}>
      <form onSubmit={onSubmit} noValidate>
        {formError(mutation.error, FIELDS) && <Notice tone="error">{formError(mutation.error, FIELDS)}</Notice>}

        <TextField
          label={ar.users.name}
          value={name}
          onChange={(event) => setName(event.target.value)}
          error={fieldError(mutation.error, 'name')}
          required
        />
        <TextField
          label={ar.users.username}
          value={username}
          onChange={(event) => setUsername(event.target.value)}
          error={fieldError(mutation.error, 'username')}
          autoComplete="off"
          ltr
          required
        />
        {!user && (
          <TextField
            label={ar.users.password}
            type="password"
            value={password}
            onChange={(event) => setPassword(event.target.value)}
            error={fieldError(mutation.error, 'password')}
            hint={ar.auth.passwordHint}
            autoComplete="new-password"
            ltr
            required
          />
        )}
        <SelectField
          label={ar.users.role}
          value={roleId}
          onChange={(event) => setRoleId(event.target.value)}
          error={fieldError(mutation.error, 'role_id')}
          required
        >
          <option value="">{ar.common.choose}</option>
          {roles.data?.map((role) => (
            <option key={role.id} value={role.id}>
              {role.name_ar}
            </option>
          ))}
        </SelectField>

        {isAgentRole ? (
          <SelectField
            label={ar.users.agent}
            value={agentId}
            onChange={(event) => setAgentId(event.target.value)}
            error={fieldError(mutation.error, 'agent_id')}
            hint={ar.users.agentRequired}
            required
          >
            <option value="">{ar.common.choose}</option>
            {agents.data?.map((agent) => (
              <option key={agent.id} value={agent.id}>
                {agent.name}
              </option>
            ))}
          </SelectField>
        ) : (
          <SelectField
            label={ar.users.branch}
            value={branchId}
            onChange={(event) => setBranchId(event.target.value)}
            error={fieldError(mutation.error, 'branch_id')}
            hint={roleCode === 'branch_staff' ? ar.users.branchRequired : undefined}
            optional={roleCode !== 'branch_staff'}
          >
            <option value="">{ar.common.choose}</option>
            {branches.data?.map((branch) => (
              <option key={branch.id} value={branch.id}>
                {branch.name}
              </option>
            ))}
          </SelectField>
        )}

        <div className="form-actions">
          <Button type="submit" loading={mutation.isPending}>
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
