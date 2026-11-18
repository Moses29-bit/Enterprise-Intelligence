import { defineConfig, loadEnv } from 'vite';
import react from '@vitejs/plugin-react';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const frontendRoot = path.dirname(fileURLToPath(import.meta.url));

export default defineConfig(({ mode }) => {
  const env = loadEnv(mode, process.cwd(), 'VITE_');
  return {
    base: './',
    plugins: [react()],
    server: {
      fs: {
        allow: [frontendRoot, path.resolve(frontendRoot, '..', 'node_modules')],
      },
      proxy: {
        '/api': {
          target: env.VITE_API_PROXY_TARGET || 'http://localhost/Enterprise%20Intelligence',
          changeOrigin: true,
        },
      },
    },
    build: {
      outDir: 'dist',
      emptyOutDir: true,
    },
  };
});
