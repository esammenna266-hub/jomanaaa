import { useState, type FormEvent } from 'react';
import { Navigate } from 'react-router-dom';
import { useAuth } from '../../app/auth/AuthContext';
import { ar } from '../../i18n/ar';
import { api } from '../../shared/api/client';
import { fieldError, formError } from '../../shared/forms/errors';
import { Brand } from '../../shared/ui/Brand';
import { Button } from '../../shared/ui/Button';
import { TextField } from '../../shared/ui/Fields';
import { Notice } from '../../shared/ui/Notice';

const FIELDS = ['current_password', 'password'];

export function ChangePasswordPage() {
  const { user, reloadUser, logout } = useAuth();
  const [current, setCurrent] = useState('');
  const [next, setNext] = useState('');
  const [confirmation, setConfirmation] = useState('');
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<unknown>(null);

  if (user && !user.must_change_password && !submitting) {
    return <Navigate to="/" replace />;
  }

  async function onSubmit(event: FormEvent) {
    event.preventDefault();
    setSubmitting(true);
    setError(null);
    try {
      await api('/auth/change-password', {
        method: 'POST',
        body: { current_password: current, password: next, password_confirmation: confirmation },
      });
      await reloadUser();
    } catch (caught) {
      setError(caught);
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <div className="auth">
      <form className="auth__card" onSubmit={onSubmit} noValidate>
        <Brand size="large" className="auth__brand" />
        <h1 className="auth__title">{ar.auth.changePasswordTitle}</h1>
        <Notice>{ar.auth.changePasswordIntro}</Notice>

        {formError(error, FIELDS) && <Notice tone="error">{formError(error, FIELDS)}</Notice>}

        <TextField
          label={ar.auth.currentPassword}
          type="password"
          value={current}
          onChange={(event) => setCurrent(event.target.value)}
          error={fieldError(error, 'current_password')}
          autoComplete="current-password"
          ltr
          required
        />
        <TextField
          label={ar.auth.newPassword}
          type="password"
          value={next}
          onChange={(event) => setNext(event.target.value)}
          error={fieldError(error, 'password')}
          hint={ar.auth.passwordHint}
          autoComplete="new-password"
          ltr
          required
        />
        <TextField
          label={ar.auth.confirmPassword}
          type="password"
          value={confirmation}
          onChange={(event) => setConfirmation(event.target.value)}
          autoComplete="new-password"
          ltr
          required
        />

        <div className="form-actions">
          <Button type="submit" loading={submitting}>
            {ar.common.save}
          </Button>
          <Button variant="secondary" onClick={() => void logout()}>
            {ar.auth.signOut}
          </Button>
        </div>
      </form>
    </div>
  );
}
