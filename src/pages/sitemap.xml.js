// Plan du site pour les moteurs de recherche, généré au build (dist/sitemap.xml).
// Toutes les pages de src/pages y figurent, sauf celles listées dans EXCLUS.

const EXCLUS = new Set(['404']);

// Pages les plus importantes en premier, avec leur priorité relative.
const PRIORITES = { index: '1.0', 'nos-eglises': '0.8', histoire: '0.8', ministeres: '0.8', eden: '0.7' };

export function GET({ site }) {
  const pages = Object.keys(import.meta.glob('./*.astro'))
    .map((file) => file.replace(/^\.\/(.+)\.astro$/, '$1'))
    .filter((name) => !EXCLUS.has(name))
    .sort((a, b) => (PRIORITES[b] ?? '0.5').localeCompare(PRIORITES[a] ?? '0.5') || a.localeCompare(b));

  const lastmod = new Date().toISOString().slice(0, 10);
  const urls = pages.map((name) => {
    const loc = new URL(name === 'index' ? '/' : `/${name}.html`, site).href;
    return `  <url>\n    <loc>${loc}</loc>\n    <lastmod>${lastmod}</lastmod>\n    <priority>${PRIORITES[name] ?? '0.5'}</priority>\n  </url>`;
  });

  const xml = `<?xml version="1.0" encoding="UTF-8"?>\n<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">\n${urls.join('\n')}\n</urlset>\n`;
  return new Response(xml, { headers: { 'Content-Type': 'application/xml; charset=utf-8' } });
}
