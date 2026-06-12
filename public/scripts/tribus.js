/**
 * Carte interactive des 12 tribus, Chicoutimi
 * Mode consultation : 9 tribus fixes, fond OSM, deux couches activables :
 *   - Rues nommées (points colorés selon la tribu)
 *   - Noms de rues (étiquettes texte, visibles à zoom élevé)
 *
 * Tout le reste (palette, exports, sliders, recherche) du projet d'origine
 * a été retiré : la carte est ici en lecture seule, intégrée au site EVH.
 */

(function () {
  'use strict';

  const PALETTE = [
    '#e63946', '#f4a261', '#e9c46a', '#2a9d8f', '#264653',
    '#8338ec', '#3a86ff', '#ff006e', '#06a77d'
  ];

  const TRIBES_9 = [
    'ZABULON', 'DAN', 'JUDA ET JOSEPH', 'RUBEN', 'GAD',
    'NEPHTALIE', 'LEVI', 'SIMEON', 'ASER'
  ];

  const BASEMAPS = {
    osm: {
      version: 8,
      sources: {
        osm: {
          type: 'raster',
          tiles: ['https://tile.openstreetmap.org/{z}/{x}/{y}.png'],
          tileSize: 256,
          attribution: '© OpenStreetMap'
        }
      },
      layers: [{ id: 'osm', type: 'raster', source: 'osm' }]
    },
    sat: {
      version: 8,
      sources: {
        esri: {
          type: 'raster',
          tiles: ['https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}'],
          tileSize: 256,
          attribution: '© Esri'
        }
      },
      layers: [{ id: 'esri', type: 'raster', source: 'esri' }]
    }
  };

  const FILL_OPACITY = 0.30;
  const LINE_OPACITY = 1.0;
  const LINE_WIDTH = 2.5;

  const state = {
    data: {},
    layers: { rues: false, rueLabels: false },
    basemap: 'osm',
  };

  const map = new maplibregl.Map({
    container: 'tribus-map',
    style: BASEMAPS.osm,
    center: [-71.07, 48.42],
    zoom: 11,
  });
  map.addControl(new maplibregl.NavigationControl({ visualizePitch: false }), 'top-right');
  map.addControl(new maplibregl.ScaleControl({ unit: 'metric' }), 'bottom-right');

  const popup = new maplibregl.Popup({ closeButton: true, closeOnClick: false, maxWidth: '280px' });

  /* ============================ géométrie ============================ */

  function pointInPoly(pt, geom) {
    if (geom.type === 'Polygon') return pointInRings(pt, geom.coordinates);
    if (geom.type === 'MultiPolygon') return geom.coordinates.some(rings => pointInRings(pt, rings));
    return false;
  }
  function pointInRings(pt, rings) {
    if (!pointInRing(pt, rings[0])) return false;
    for (let i = 1; i < rings.length; i++) if (pointInRing(pt, rings[i])) return false;
    return true;
  }
  function pointInRing(pt, ring) {
    let inside = false;
    const [x, y] = pt;
    for (let i = 0, j = ring.length - 1; i < ring.length; j = i++) {
      const xi = ring[i][0], yi = ring[i][1], xj = ring[j][0], yj = ring[j][1];
      if (((yi > y) !== (yj > y)) && (x < (xj - xi) * (y - yi) / (yj - yi) + xi)) inside = !inside;
    }
    return inside;
  }
  function polygonCenter(geom) {
    const rings = geom.type === 'MultiPolygon' ? geom.coordinates.flat() : geom.coordinates;
    let best = null, bestArea = -1;
    for (const ring of rings) {
      let minx = Infinity, miny = Infinity, maxx = -Infinity, maxy = -Infinity;
      for (const [x, y] of ring) {
        if (x < minx) minx = x; if (x > maxx) maxx = x;
        if (y < miny) miny = y; if (y > maxy) maxy = y;
      }
      const area = (maxx - minx) * (maxy - miny);
      if (area > bestArea) { bestArea = area; best = [minx, miny, maxx, maxy]; }
    }
    return [(best[0] + best[2]) / 2, (best[1] + best[3]) / 2];
  }

  function escapeHtml(s) {
    return String(s).replace(/[&<>"']/g, c =>
      ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c])
    );
  }

  /* ============================ chargement ============================ */

  async function loadData() {
    const base = '/data/tribus/';
    const files = {
      zones:     base + 'zones_9.geojson',
      landmarks: base + 'landmarks_9.geojson',
      rues:      base + 'rues.geojson',
    };
    const entries = await Promise.all(Object.entries(files).map(
      async ([k, p]) => [k, await (await fetch(p)).json()]
    ));
    state.data = Object.fromEntries(entries);

    // index stable
    ['zones', 'landmarks'].forEach((k) => {
      state.data[k].features.forEach((f, i) => {
        f.id = i;
        if (!f.properties) f.properties = {};
        if (f.properties._idx === undefined) f.properties._idx = i;
        if (!f.properties._num) f.properties._num = i + 1;
        if (!f.properties.color) f.properties.color = PALETTE[i] || '#888';
      });
    });

    // enrichir les rues avec la tribu d'appartenance
    const zones = state.data.zones.features;
    for (const r of state.data.rues.features) {
      const c = r.geometry.coordinates;
      const z = whichZone(c, zones);
      r.properties.sz9 = z;
      if (z != null) r.properties.color9 = zones[z - 1].properties.color;
    }
  }

  function whichZone(coord, features) {
    for (const f of features) {
      if (pointInPoly(coord, f.geometry)) return f.properties._num;
    }
    return null;
  }

  /* ============================ rendu carte ============================ */

  function colorExpression() {
    const expr = ['match', ['get', '_idx']];
    PALETTE.forEach((c, i) => expr.push(i, c));
    expr.push('#888');
    return expr;
  }
  function ruesColorExpression() {
    const expr = ['match', ['get', 'sz9']];
    PALETTE.forEach((c, i) => expr.push(i + 1, c));
    expr.push('#888');
    return expr;
  }

  function buildLayers() {
    rebuildVectorLayers();
    buildLandmarkMarkers();
    buildRueLabels();
  }

  /* ============================ marqueurs HTML ============================ */

  // étoile + nom de tribu + nom du quartier (toujours visibles, ce sont les
  // repères principaux des 9 tribus)
  function buildLandmarkMarkers() {
    state.data.landmarks.features.forEach((f) => {
      const idx = f.properties._idx;
      const color = PALETTE[idx] || '#888';
      const wrap = document.createElement('div');
      wrap.className = 'lm-marker';
      wrap.style.setProperty('--tribe-color', color);
      wrap.innerHTML = `
        <div class="lm-tribe-star">★</div>
        <div class="lm-tribe-body">
          <div class="lm-tribe-name">${escapeHtml(TRIBES_9[idx] || ('TRIBU ' + (idx + 1)))}</div>
          <div class="lm-tribe-quartier">${escapeHtml(f.properties.name || '')}</div>
        </div>
      `;
      new maplibregl.Marker({ element: wrap, anchor: 'center' })
        .setLngLat(f.geometry.coordinates)
        .addTo(map);
    });
  }

  // labels de rue (toggleable + visibles à zoom >= 13)
  const rueLabelMarkers = [];
  function buildRueLabels() {
    rueLabelMarkers.forEach(m => m.remove());
    rueLabelMarkers.length = 0;
    for (const r of state.data.rues.features) {
      const el = document.createElement('div');
      el.className = 'rue-label';
      el.textContent = r.properties.name || '';
      rueLabelMarkers.push(
        new maplibregl.Marker({ element: el, anchor: 'top' })
          .setLngLat(r.geometry.coordinates)
          .addTo(map)
      );
    }
    refreshRueLabelVisibility();
  }
  function refreshRueLabelVisibility() {
    const zoom = map.getZoom();
    const wanted = state.layers.rueLabels && state.layers.rues && zoom >= 13;
    for (const m of rueLabelMarkers) {
      m.getElement().style.display = wanted ? '' : 'none';
    }
  }
  map.on('zoom', refreshRueLabelVisibility);

  /* ============================ interactions ============================ */

  let hoveredZone = null;
  function attachInteractions() {
    map.on('mousemove', 'zones-fill', (e) => {
      if (!e.features.length) return;
      map.getCanvas().style.cursor = 'pointer';
      const fid = e.features[0].id;
      if (fid === undefined || fid === null) return;
      if (hoveredZone !== null && hoveredZone !== fid)
        map.setFeatureState({ source: 'zones', id: hoveredZone }, { hover: false });
      hoveredZone = fid;
      map.setFeatureState({ source: 'zones', id: hoveredZone }, { hover: true });
    });
    map.on('mouseleave', 'zones-fill', () => {
      map.getCanvas().style.cursor = '';
      if (hoveredZone !== null)
        map.setFeatureState({ source: 'zones', id: hoveredZone }, { hover: false });
      hoveredZone = null;
    });

    map.on('click', 'zones-fill', (e) => {
      const f = e.features[0];
      const idx = f.properties._idx;
      const color = PALETTE[idx] || '#888';
      const lm = state.data.landmarks.features[idx];
      const ruesCount = state.data.rues.features.filter(r => r.properties.sz9 === idx + 1).length;
      popup.setLngLat(e.lngLat).setHTML(`
        <div class="popup-title">
          <span class="popup-swatch" style="background:${color}"></span>
          ${escapeHtml(TRIBES_9[idx] || ('TRIBU ' + (idx + 1)))}
        </div>
        <div class="popup-meta">${ruesCount} rue(s) nommée(s)</div>
        ${lm ? `<div class="popup-meta"><strong>${escapeHtml(lm.properties.kind || '')} :</strong> ${escapeHtml(lm.properties.name || '')}</div>` : ''}
      `).addTo(map);
    });

    map.on('mouseenter', 'rues-pt', () => map.getCanvas().style.cursor = 'pointer');
    map.on('mouseleave', 'rues-pt', () => map.getCanvas().style.cursor = '');
    map.on('click', 'rues-pt', (e) => {
      const f = e.features[0];
      const tribeIdx = (f.properties.sz9 || 1) - 1;
      popup.setLngLat(e.lngLat).setHTML(`
        <div class="popup-title">${escapeHtml(f.properties.name || '')}</div>
        <div class="popup-meta">${escapeHtml(f.properties.kind || '')} · ${escapeHtml(TRIBES_9[tribeIdx] || '')}</div>
      `).addTo(map);
    });
  }

  /* ============================ toggles UI ============================ */

  function applyLayerVisibility() {
    if (map.getLayer('rues-pt')) {
      map.setLayoutProperty('rues-pt', 'visibility', state.layers.rues ? 'visible' : 'none');
    }
    refreshRueLabelVisibility();
  }

  function bindToggles() {
    document.querySelectorAll('[data-tribu-layer]').forEach((el) => {
      const key = el.getAttribute('data-tribu-layer');
      el.checked = !!state.layers[key];
      el.addEventListener('change', () => {
        state.layers[key] = el.checked;
        applyLayerVisibility();
      });
    });
  }

  /* ============================ basemap switch ============================ */

  // Recrée uniquement les sources + couches MapLibre.
  // Les markers HTML (landmarks, rue labels) survivent au changement de style.
  function rebuildVectorLayers() {
    map.addSource('zones', { type: 'geojson', data: state.data.zones, promoteId: 'id' });
    map.addSource('rues',  { type: 'geojson', data: state.data.rues });

    map.addLayer({
      id: 'zones-fill',
      type: 'fill',
      source: 'zones',
      paint: {
        'fill-color': colorExpression(),
        'fill-opacity': ['case',
          ['boolean', ['feature-state', 'hover'], false],
          Math.min(1, FILL_OPACITY + 0.18),
          FILL_OPACITY
        ]
      }
    });
    map.addLayer({
      id: 'zones-line',
      type: 'line',
      source: 'zones',
      paint: {
        'line-color': colorExpression(),
        'line-width': LINE_WIDTH,
        'line-opacity': LINE_OPACITY
      }
    });
    map.addLayer({
      id: 'rues-pt',
      type: 'circle',
      source: 'rues',
      layout: { visibility: state.layers.rues ? 'visible' : 'none' },
      paint: {
        'circle-radius': ['interpolate', ['linear'], ['zoom'], 11, 1.6, 15, 4],
        'circle-color': ruesColorExpression(),
        'circle-stroke-color': '#fff',
        'circle-stroke-width': 0.6,
        'circle-opacity': 0.9
      }
    });
    attachInteractions();
  }

  function switchBasemap(key) {
    if (!BASEMAPS[key] || key === state.basemap) return;
    state.basemap = key;
    map.setStyle(BASEMAPS[key]);
    map.once('style.load', () => {
      rebuildVectorLayers();
      applyLayerVisibility();
    });
  }

  function bindBasemapSeg() {
    const seg = document.querySelector('[data-tribu-basemap-seg]');
    if (!seg) return;
    seg.querySelectorAll('button[data-basemap]').forEach((btn) => {
      btn.addEventListener('click', () => {
        const key = btn.getAttribute('data-basemap');
        seg.querySelectorAll('button').forEach(b => b.classList.toggle('is-active', b === btn));
        switchBasemap(key);
      });
    });
  }

  /* ============================ boot ============================ */

  map.on('load', async () => {
    try {
      await loadData();
      buildLayers();
      bindToggles();
      bindBasemapSeg();
      applyLayerVisibility();
    } catch (err) {
      console.error('[tribus] chargement échoué', err);
    }
  });
})();
