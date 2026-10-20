import { useState, type FormEvent } from 'react';
import { Navigate, useLocation } from 'react-router-dom';
import { useAuth } from '../../app/auth/AuthContext';
import { ar } from '../../i18n/ar';
import { fieldError, formError } from '../../shared/forms/errors';
import { Button } from '../../shared/ui/Button';
import { TextField } from '../../shared/ui/Fields';
import { Notice } from '../../shared/ui/Notice';

export function LoginPage() {
  const { status, login } = useAuth();
  const location = useLocation();
  const [username, setUsername] = useState('');
  const [password, setPassword] = useState('');
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<unknown>(null);

  if (status === 'authenticated') {
    const from = (location.state as { from?: string } | null)?.from;

    return <Navigate to={from && from !== '/login' ? from : '/'} replace />;
  }

  async function onSubmit(event: FormEvent) {
    event.preventDefault();
    setSubmitting(true);
    setError(null);
    try {
      await login(username.trim(), password);
    } catch (caught) {
      setError(caught);
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <div className="auth">
      <form className="auth__card" onSubmit={onSubmit} noValidate>
        <div className="auth__brand">{ar.appName}</div>
        <h1 className="auth__title">{ar.auth.loginTitle}</h1>

        {formError(error, ['username']) && <Notice tone="error">{formError(error, ['username'])}</Notice>}

        <TextField
          label={ar.auth.username}
          value={username}
          onChange={(event) => setUsername(event.target.value)}
          error={fieldError(error, 'username')}
          autoComplete="username"
          autoFocus
          ltr
          required
        />
        <TextField
          label={ar.auth.password}
          type="password"
          value={password}
          onChange={(event) => setPassword(event.target.value)}
          error={fieldError(error, 'password')}
          autoComplete="current-password"
          ltr
          required
        />

        <Button type="submit" className="auth__submit" loading={submitting}>
          {submitting ? ar.auth.signingIn : ar.auth.signIn}
        </Button>
      </form>
    </div>
  );
}
