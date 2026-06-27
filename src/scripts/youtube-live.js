/**
 * Rafraîchit la section « Derniers enseignements » côté navigateur, à chaque
 * chargement de la page, en interrogeant l'API YouTube Data v3.
 *
 * Le HTML statique (baké au build par fetchYoutube.mjs) reste en place comme
 * contenu de repli : si l'API échoue ou si la clé manque, rien ne change.
 *
 * Quota : 1 appel playlistItems (1 unité) + 1 appel videos (1 unité) par visite.
 * La clé doit être restreinte au domaine du site (referrer HTTP) dans Google Cloud.
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

async function fetchVideos(apiKey, playlistId, limit) {
  const plRes = await fetch(
    `https://www.googleapis.com/youtube/v3/playlistItems?part=contentDetails&maxResults=${limit}&playlistId=${playlistId}&key=${apiKey}`
  );
  if (!plRes.ok) throw new Error(`playlistItems HTTP ${plRes.status}`);
  const pl = await plRes.json();
  const ids = (pl.items || []).map((it) => it.contentDetails?.videoId).filter(Boolean);
  if (!ids.length) throw new Error('aucune vidéo');

  const vRes = await fetch(
    `https://www.googleapis.com/youtube/v3/videos?part=snippet,statistics&id=${ids.join(',')}&key=${apiKey}`
  );
  if (!vRes.ok) throw new Error(`videos HTTP ${vRes.status}`);
  const vd = await vRes.json();
  const byId = new Map((vd.items || []).map((it) => [it.id, it]));

  // Conserver l'ordre du playlist (le plus récent en premier)
  return ids.map((id) => byId.get(id)).filter(Boolean).map((it) => {
    const sn = it.snippet || {};
    const th = sn.thumbnails || {};
    const thumb = (th.high || th.medium || th.default || {}).url
      || `https://i.ytimg.com/vi/${it.id}/hqdefault.jpg`;
    return {
      id: it.id,
      title: (sn.title || '').replace(/\s+/g, ' ').trim(),
      thumbnail: thumb,
      views: frViews(it.statistics?.viewCount),
      published: frRelative(sn.publishedAt),
    };
  });
}

export async function mountYoutubeLive({ apiKey, uploadsPlaylistId, limit = 5 } = {}) {
  const wall = document.querySelector('.watch-wall');
  if (!wall || !apiKey || !uploadsPlaylistId) return; // pas de clé → on garde le repli baké
  try {
    const videos = await fetchVideos(apiKey, uploadsPlaylistId, limit);
    if (videos.length) wall.innerHTML = buildInner(videos);
  } catch (err) {
    console.warn('[youtube-live] rafraîchissement ignoré :', err.message);
  }
}
