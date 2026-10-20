import { useState } from 'react';
import { ar } from '../../i18n/ar';

/**
 * The company logo (public/logo.png) with the company name. Until a logo file is dropped into public/, or if it
 * cannot be loaded, the name alone is shown.
 */
export function Brand({ className, size }: { className?: string; size: 'small' | 'large' }) {
  const [hasLogo, setHasLogo] = useState(true);

  return (
    <div className={`brand brand--${size}${className ? ` ${className}` : ''}`}>
      {hasLogo && <img className="brand__logo" src="/logo.png" alt="" onError={() => setHasLogo(false)} />}
      <span className="brand__name">{ar.appName}</span>
    </div>
  );
}
