import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';

// The dev proxy forwards /api/* to Laravel, so the browser thinks both
// live on the same origin. That means no CORS setup is needed while developing.
export default defineConfig({
  plugins: [react()],
  server: { port: 5173, proxy: { '/api': 'http://localhost:8000' } },
});
