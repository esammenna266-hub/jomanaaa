import react from '@vitejs/plugin-react';
import { loadEnv } from 'vite';
import { defineConfig } from 'vitest/config';

export default defineConfig(({ mode }) => {
  // In development the API is proxied, so the browser talks to one origin and CORS is not needed.
  const env = loadEnv(mode, '.', '');
  const apiTarget = env.VITE_PROXY_TARGET || 'http://127.0.0.1:8000';

  return {
    plugins: [react()],
    server: {
      port: 5173,
      proxy: { '/api': { target: apiTarget, changeOrigin: true } },
    },
    test: {
      environment: 'jsdom',
      setupFiles: ['./src/test/setup.ts'],
      css: false,
      // Each jsdom worker is heavy; a small pool stays reliable on laptops with little free memory.
      maxWorkers: 2,
    },
  };
});
