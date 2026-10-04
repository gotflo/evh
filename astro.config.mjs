import { defineConfig } from 'astro/config';

// https://astro.build/config
export default defineConfig({
  site: 'https://vasesdhonneurchicoutimi.org',
  output: 'static',
  build: {
    format: 'file', // génère /nos-eglises.html (compatible Hostinger sans .htaccess)
    assets: '_assets'
  },
  compressHTML: true
});
