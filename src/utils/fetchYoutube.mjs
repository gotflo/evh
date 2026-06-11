/**
 * Fetcher YouTube au build, scrape la chaîne et extrait les dernières vidéos
 * (toutes catégories : uploads, lives archivés, shorts), triées par date.
 *
 * Appelé depuis le frontmatter d'index.astro lors de `astro build`.
 * Zero JS runtime côté client.
 *
 * Si le fetch échoue (offline, YouTube down), un fallback minimal est retourné
 * pour ne pas casser le build.
 */

const CHANNEL_ID = 'UCOBrKVhgjiUcSoGyqeo29WA';
const UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';

const FALLBACK = [
  { id: 'YMqQed3cAtk', title: 'De la poussière à la gloire', views: '130k vues', published: 'Récemment', thumbnail: 'https://i.ytimg.com/vi/YMqQed3cAtk/hqdefault.jpg', url: 'https://www.youtube.com/watch?v=YMqQed3cAtk', kind: 'video', daysAgo: 999 },
];

/** Découpe le JSON `ytInitialData` du HTML en respectant strings et échappements. */
function extractYtInitialData(html) {
  const marker = 'ytInitialData = {';
  const idx = html.indexOf(marker);
  if (idx < 0) return null;
  const start = idx + marker.length - 1;
  let depth = 0, inStr = false, esc = false;
  for (let j = start; j < html.length; j++) {
    const c = html[j];
    if (esc) { esc = false; continue; }
    if (c === '\\') { esc = true; continue; }
    if (c === '"') { inStr = !inStr; continue; }
    if (inStr) continue;
    if (c === '{') depth++;
    else if (c === '}') {
      depth--;
      if (depth === 0) {
        try { return JSON.parse(html.slice(start, j + 1)); }
        catch { return null; }
      }
    }
  }
  return null;
}

function findFirstText(o) {
  if (!o || typeof o !== 'object') return '';
  if (o.content && typeof o.content === 'string') return o.content;
  if (o.simpleText) return o.simpleText;
  if (o.runs && o.runs[0]?.text) return o.runs[0].text;
  for (const k in o) {
    const r = findFirstText(o[k]);
    if (r) return r;
  }
  return '';
}

function findTextContents(o, acc = []) {
  if (!o || typeof o !== 'object') return acc;
  if (o.text && o.text.content) acc.push(o.text.content);
  for (const k in o) findTextContents(o[k], acc);
  return acc;
}

/**
 * Convertit "3 weeks ago" → 21 (jours). Retourne Infinity si non parsable.
 * Gère aussi "Streamed X ago" pour les lives archivés.
 */
function parseRelativeDays(text) {
  if (!text) return Infinity;
  const t = text.toLowerCase()
    .replace(/^(streamed|live streamed|premiered|diffus[ée] en direct|premi[èe]re)\s+(il y a\s+)?/i, '')
    .replace(/^il y a\s+/i, '');

  // EN: "3 weeks ago" / FR: "3 semaines" (le "il y a" est déjà retiré)
  const m = t.match(/(\d+)\s*(seconde|minute|heure|jour|semaine|mois|an|second|minute|hour|day|week|month|year)s?\b/);
  if (!m) return Infinity;
  const n = parseInt(m[1], 10);
  const unit = m[2];
  const conv = {
    // EN
    second: 1/86400, minute: 1/1440, hour: 1/24, day: 1, week: 7, month: 30, year: 365,
    // FR
    seconde: 1/86400, heure: 1/24, jour: 1, semaine: 7, mois: 30, an: 365,
  };
  return n * (conv[unit] || Infinity);
}

/** Localise EN → FR pour l'affichage. */
function localize(v) {
  let views = (v.views || '').replace(/\bviews?\b/gi, 'vues');
  let published = (v.published || '').trim();
  // EN → FR
  const map = [
    [/\b(\d+)\s*years?\s*ago\b/i,    (_, n) => `Il y a ${n} an${n>1?'s':''}`],
    [/\b(\d+)\s*months?\s*ago\b/i,   (_, n) => `Il y a ${n} mois`],
    [/\b(\d+)\s*weeks?\s*ago\b/i,    (_, n) => `Il y a ${n} semaine${n>1?'s':''}`],
    [/\b(\d+)\s*days?\s*ago\b/i,     (_, n) => `Il y a ${n} jour${n>1?'s':''}`],
    [/\b(\d+)\s*hours?\s*ago\b/i,    (_, n) => `Il y a ${n} h`],
    [/\b(\d+)\s*minutes?\s*ago\b/i,  (_, n) => `Il y a ${n} min`],
    [/\bstreamed\b/i,                () => 'Diffusé en direct'],
    [/\bpremiered\b/i,               () => 'Première'],
  ];
  for (const [re, fn] of map) {
    if (re.test(published)) published = published.replace(re, fn);
  }
  // Capitaliser la première lettre (FR YouTube renvoie "il y a 1 mois" minuscule)
  if (published) published = published.charAt(0).toUpperCase() + published.slice(1);
  return { ...v, views, published };
}

/** Renderer "lockupViewModel", utilisé pour /videos et /streams. */
function extractFromLockups(data, kind) {
  const seen = new Set();
  const out = [];
  function walk(o) {
    if (!o || typeof o !== 'object') return;
    if (o.lockupViewModel) {
      const lv = o.lockupViewModel;
      if (lv.contentType === 'LOCKUP_CONTENT_TYPE_VIDEO' && lv.contentId) {
        const id = lv.contentId;
        if (seen.has(id)) return;
        const title = findFirstText(lv.metadata?.lockupMetadataViewModel?.title) || findFirstText(lv.metadata);
        const rows = findTextContents(lv.metadata);
        if (id && title) {
          seen.add(id);
          out.push({
            id,
            title: title.replace(/\s+/g, ' ').trim(),
            views: rows[0] || '',
            published: rows[1] || '',
            thumbnail: `https://i.ytimg.com/vi/${id}/hqdefault.jpg`,
            url: `https://www.youtube.com/watch?v=${id}`,
            kind,
            daysAgo: parseRelativeDays(rows[1] || ''),
          });
        }
      }
    }
    for (const k in o) walk(o[k]);
  }
  walk(data);
  return out;
}

/** Renderer "shortsLockupViewModel", pour /shorts. */
function extractShorts(data) {
  const seen = new Set();
  const out = [];
  function walk(o) {
    if (!o || typeof o !== 'object') return;
    if (o.shortsLockupViewModel) {
      const sv = o.shortsLockupViewModel;
      const id = sv.onTap?.innertubeCommand?.reelWatchEndpoint?.videoId
              || sv.entityId?.replace(/^shorts-shelf-item-/, '');
      const title = findFirstText(sv.overlayMetadata?.primaryText);
      const views = findFirstText(sv.overlayMetadata?.secondaryText);
      if (id && title && !seen.has(id)) {
        seen.add(id);
        out.push({
          id,
          title: title.replace(/\s+/g, ' ').trim(),
          views,
          published: '', // shorts n'exposent pas la date dans cette vue
          thumbnail: `https://i.ytimg.com/vi/${id}/hqdefault.jpg`,
          url: `https://www.youtube.com/shorts/${id}`,
          kind: 'short',
          daysAgo: Infinity, // pas de date → fin de tri
        });
      }
    }
    for (const k in o) walk(o[k]);
  }
  walk(data);
  return out;
}

async function fetchTab(tab) {
  const res = await fetch(
    `https://www.youtube.com/channel/${CHANNEL_ID}/${tab}`,
    { headers: { 'User-Agent': UA, 'Accept-Language': 'fr-CA,fr;q=0.9,en;q=0.5' } }
  );
  if (!res.ok) throw new Error(`${tab}: HTTP ${res.status}`);
  return res.text();
}

/**
 * @param {number} limit nombre max de vidéos à retourner
 * @param {object} opts
 * @param {boolean} opts.includeVideos inclure aussi les uploads /videos (par défaut false, streams seuls)
 * @param {boolean} opts.includeShorts inclure les Shorts (par défaut false)
 *
 * Priorité par défaut : /streams (lives & replays). Les onglets supplémentaires
 * ne sont utilisés que si demandés explicitement, ou en fallback si /streams est vide.
 */
export async function fetchLatestVideos(limit = 5, opts = {}) {
  const { includeVideos = false, includeShorts = false } = opts;
  try {
    const tabs = ['streams'];
    if (includeVideos) tabs.push('videos');
    if (includeShorts) tabs.push('shorts');

    let htmls = await Promise.all(tabs.map(t => fetchTab(t).catch(() => null)));
    let all = [];
    const collect = (html, tab) => {
      if (!html) return [];
      const data = extractYtInitialData(html);
      if (!data) return [];
      return tab === 'shorts'
        ? extractShorts(data)
        : extractFromLockups(data, tab === 'streams' ? 'stream' : 'video');
    };
    htmls.forEach((html, i) => { all.push(...collect(html, tabs[i])); });

    // Fallback : si /streams ne ramène rien et qu'on ne demandait pas déjà /videos, on le tente.
    if (!all.length && !includeVideos) {
      const html = await fetchTab('videos').catch(() => null);
      all.push(...collect(html, 'videos'));
    }

    // Dédupliquer par id (un live peut apparaître à la fois dans /videos et /streams)
    const byId = new Map();
    for (const v of all) {
      const existing = byId.get(v.id);
      if (!existing || v.daysAgo < existing.daysAgo) byId.set(v.id, v);
    }

    // Dédupliquer aussi par titre normalisé (doublons FR/EN du même contenu live)
    const norm = (s) => s.toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/[^a-z0-9]+/g, ' ').trim();
    const byTitle = new Map();
    for (const v of byId.values()) {
      const k = norm(v.title);
      const existing = byTitle.get(k);
      if (!existing || v.daysAgo < existing.daysAgo) byTitle.set(k, v);
    }

    // Trier par récence réelle
    const sorted = [...byTitle.values()].sort((a, b) => a.daysAgo - b.daysAgo);

    if (!sorted.length) throw new Error('aucune vidéo extraite');
    return sorted.slice(0, limit).map(localize);
  } catch (err) {
    console.warn(`[fetchYoutube] échec: ${err.message}, fallback utilisé`);
    return FALLBACK;
  }
}
