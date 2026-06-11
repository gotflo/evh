import { defineConfig } from 'astro/config';

// https://astro.build/config
export default defineConfig({
  site: 'https://evhca.com',
  output: 'static',
  build: {
    format: 'file', // génère /nos-eglises.html (compatible Hostinger sans .htaccess)
    assets: '_assets'
  },
  compressHTML: true
});
