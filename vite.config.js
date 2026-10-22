import { defineConfig } from 'vite';
import tailwindcss from '@tailwindcss/vite';

// Output is committed to client/dist and exposed via composer "extra.expose".
// PHP reads client/dist/.vite/manifest.json (see YouWillLikeIT\CareHome\View\ViteAssets).
export default defineConfig({
  plugins: [tailwindcss()],
  base: './',
  build: {
    outDir: 'client/dist',
    emptyOutDir: true,
    manifest: true,
    sourcemap: false,
    target: 'es2020',
    rollupOptions: {
      input: 'client/src/portal.js',
    },
  },
  server: {
    // used with ViteAssets.dev_server_url for HMR during development
    cors: true,
    strictPort: true,
    port: 5173,
  },
});
