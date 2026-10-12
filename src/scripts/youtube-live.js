/**
 * Rafraîchit la section « Derniers enseignements » côté navigateur, à chaque
 * chargement de la page d'accueil.
 *
 * Les vidéos viennent de /api/youtube/latest.php : ce petit script PHP lit le
 * flux RSS public de la chaîne (aucune clé API) et le garde en cache 10 minutes.
 *
 * Le HTML statique (baké au build par fetchYoutube.mjs) reste en place comme
 * contenu de repli : si l'appel échoue (en local avec `astro dev`, par exemple),
 * rien ne change.
 */

const PLAY_SVG = '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M8 5v14l11-7z"/></svg>';

const esc = (s) => String(s == null ? '' : s).replace(/[&<>"']/g, (c) => (
  { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]
));

/** "130000" → "130 k vues" ; "1300000" → "1,3 M vues". */
function frViews(raw) {
  const n = Number(raw);
  if (!isFinite(n) || n <= 0) return '';
  let s;
  if (n >= 1e6) s = (n / 1e6).toFixed(1).replace(/\.0$/, '').replace('.', ',') + ' M';
  else if (n >= 1e3) s = Math.round(n / 1e3) + ' k';
  else s = String(n);
  return s + (n > 1 ? ' vues' : ' vue');
}

/** Date ISO → « Il y a 3 jours » en français. */
function frRelative(iso) {
  const then = new Date(iso).getTime();
  if (!isFinite(then)) return '';
  const mins = Math.max(0, Math.floor((Date.now() - then) / 60000));
  if (mins < 1) return "À l'instant";
  if (mins < 60) return `Il y a ${mins} min`;
  const hours = Math.floor(mins / 60);
  if (hours < 24) return `Il y a ${hours} h`;
  const days = Math.floor(hours / 24);
  if (days < 7) return `Il y a ${days} jour${days > 1 ? 's' : ''}`;
  if (days < 30) { const w = Math.floor(days / 7); return `Il y a ${w} semaine${w > 1 ? 's' : ''}`; }
  if (days < 365) { const m = Math.floor(days / 30); return `Il y a ${m} mois`; }
  const y = Math.floor(days / 365);
  return `Il y a ${y} an${y > 1 ? 's' : ''}`;
}

function metaLine(v) {
  const parts = [];
  if (v.views) parts.push(`<span>${esc(v.views)}</span>`);
  if (v.views && v.published) parts.push('<span class="dot">·</span>');
  if (v.published) parts.push(`<span>${esc(v.published)}</span>`);
  return parts.join('');
}

function buildInner(videos) {
  const [featured, ...rest] = videos;
  const featuredHtml = `
    <div class="watch-featured">
      <div class="lite-youtube featured" data-yt="${esc(featured.id)}" tabindex="0" role="button" aria-label="Lire la vidéo : ${esc(featured.title)}">
        <img src="${esc(featured.thumbnail)}" alt="" loading="lazy" />
        <span class="play-btn" aria-hidden="true">${PLAY_SVG}</span>
        <span class="watch-badge"><span class="badge-dot" aria-hidden="true"></span>Dernier direct</span>
        <div class="watch-overlay">
          <h3 class="watch-overlay-title">${esc(featured.title)}</h3>
          <p class="watch-overlay-meta">${metaLine(featured)}</p>
        </div>
      </div>
    </div>`;

  const listHtml = rest.length ? `
    <ul class="watch-list">
      ${rest.map((v) => `
        <li>
          <div class="lite-youtube small" data-yt="${esc(v.id)}" tabindex="0" role="button" aria-label="Lire la vidéo : ${esc(v.title)}">
            <img src="${esc(v.thumbnail)}" alt="" loading="lazy" />
            <span class="play-btn" aria-hidden="true">${PLAY_SVG}</span>
          </div>
          <div class="watch-list-info">
            <h4>${esc(v.title)}</h4>
            <p>${metaLine(v)}</p>
          </div>
        </li>`).join('')}
    </ul>` : '';

  return featuredHtml + listHtml;
}

async function fetchVideos(endpoint, limit) {
  const res = await fetch(endpoint, { headers: { Accept: 'application/json' } });
  if (!res.ok) throw new Error(`HTTP ${res.status}`);
  const data = await res.json();

  return (Array.isArray(data.videos) ? data.videos : []).slice(0, limit).map((v) => ({
    id: v.id,
    title: v.title,
    thumbnail: `https://i.ytimg.com/vi/${encodeURIComponent(v.id)}/hqdefault.jpg`,
    views: frViews(v.views),
    published: frRelative(v.published),
  }));
}

export async function mountYoutubeLive({ endpoint = '/api/youtube/latest.php', limit = 5 } = {}) {
  const wall = document.querySelector('.watch-wall');
  if (!wall) return;
  try {
    const videos = await fetchVideos(endpoint, limit);
    if (videos.length) wall.innerHTML = buildInner(videos);
  } catch (err) {
    console.warn('[youtube-live] rafraîchissement ignoré :', err.message);
  }
}
