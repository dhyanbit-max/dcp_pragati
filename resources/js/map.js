// Works map (docs/10). OpenLayers, bundled locally. Config: #works-map[data-config].
import 'ol/ol.css';
import '../css/map.css';
import Map from 'ol/Map';
import View from 'ol/View';
import Feature from 'ol/Feature';
import TileLayer from 'ol/layer/Tile';
import VectorLayer from 'ol/layer/Vector';
import XYZ from 'ol/source/XYZ';
import TileWMS from 'ol/source/TileWMS';
import VectorSource from 'ol/source/Vector';
import Cluster from 'ol/source/Cluster';
import GeoJSON from 'ol/format/GeoJSON';
import Point from 'ol/geom/Point';
import Polygon, { circular, fromExtent } from 'ol/geom/Polygon';
import LinearRing from 'ol/geom/LinearRing';
import { fromLonLat } from 'ol/proj';
import { Style, Fill, Stroke, Text, Circle as CircleStyle, RegularShape, Icon } from 'ol/style';
import { defaults as defaultControls, ScaleLine } from 'ol/control';
import { boundingExtent, createEmpty, extend as extendExtent, isEmpty } from 'ol/extent';
import { getVectorContext } from 'ol/render';
import { easeOut } from 'ol/easing';

// ---------------------------------------------------------------------------
// Visual encoding. Status = colour AND shape (never colour alone). Colours are the
// reserved status palette plus series-1 blue for "in progress".
const ORDER = ['stalled', 'delayed', 'in_progress', 'not_started', 'completed', 'cancelled'];
const COLOR = {
    stalled: '#d03b3b',
    delayed: '#eda100',
    in_progress: '#2a78d6',
    not_started: '#75746f',
    completed: '#0ca30c',
    cancelled: '#a8a79f',
};
// Area shading: one blue ramp, 5 bins of "share delayed or stalled".
const BINS = [
    { label: '0%', max: 0, color: 'rgba(205,226,251,0.30)' },
    { label: '≤10%', max: 10, color: 'rgba(158,197,244,0.45)' },
    { label: '≤25%', max: 25, color: 'rgba(109,167,236,0.50)' },
    { label: '≤50%', max: 50, color: 'rgba(57,135,229,0.55)' },
    { label: '>50%', max: Infinity, color: 'rgba(28,92,171,0.62)' },
];
const binIndex = (pct) => (pct === 0 ? 0 : BINS.findIndex((b, i) => i > 0 && pct <= b.max));
const RATIO = Math.min(window.devicePixelRatio || 1, 2);

const isDark = () => {
    const t = document.documentElement.dataset.theme;
    return t ? t === 'dark' : window.matchMedia('(prefers-color-scheme: dark)').matches;
};
const surface = () => (isDark() ? '#1a1a19' : '#ffffff');
const ink = () => (isDark() ? '#f0efec' : '#1a1a19');
const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);
const store = {
    get(k) { try { return localStorage.getItem(k); } catch { return null; } },
    set(k, v) { try { localStorage.setItem(k, v); } catch { /* storage blocked */ } },
};

function marker(display, scale = 1) {
    const fill = new Fill({ color: COLOR[display] ?? COLOR.not_started });
    const stroke = new Stroke({ color: surface(), width: 2 });
    const r = 7 * scale;
    switch (display) {
        case 'completed': return new RegularShape({ points: 4, radius: r * 1.15, angle: Math.PI / 4, fill, stroke });
        case 'delayed': return new RegularShape({ points: 3, radius: r * 1.35, fill, stroke });
        case 'stalled': return new RegularShape({ points: 4, radius: r * 1.3, fill, stroke });
        case 'not_started':
        case 'cancelled': return new CircleStyle({ radius: r, fill: new Fill({ color: surface() }), stroke: new Stroke({ color: COLOR[display], width: 2.5 }) });
        default: return new CircleStyle({ radius: r, fill, stroke });
    }
}

/** Small canvas of a marker, for legend chips. */
function markerCanvas(display, stale = false) {
    const size = 22;
    const c = document.createElement('canvas');
    c.width = size * RATIO; c.height = size * RATIO;
    c.style.width = `${size}px`; c.style.height = `${size}px`;
    const ctx = c.getContext('2d');
    ctx.scale(RATIO, RATIO);
    if (stale) {
        ctx.setLineDash([3, 2]); ctx.strokeStyle = ink(); ctx.lineWidth = 1.5;
        ctx.beginPath(); ctx.arc(size / 2, size / 2, 10, 0, Math.PI * 2); ctx.stroke();
    }
    const img = marker(display).getImage(RATIO);
    ctx.setTransform(1, 0, 0, 1, 0, 0);
    ctx.drawImage(img, (c.width - img.width) / 2, (c.height - img.height) / 2);
    c.setAttribute('aria-hidden', 'true');
    return c;
}

// Cluster glyph: a ring split by status share (fixed order), surface gaps between segments,
// count in the middle. Cached by composition.
const glyphCache = new globalThis.Map();
function clusterGlyph(counts, total) {
    const key = `${ORDER.map((s) => counts[s] || 0).join('-')}|${isDark()}`;
    if (glyphCache.has(key)) return glyphCache.get(key);
    const r = Math.round(15 + Math.min(14, Math.log2(total) * 3.2));
    const size = (r + 4) * 2;
    const c = document.createElement('canvas');
    c.width = size * RATIO; c.height = size * RATIO;
    const ctx = c.getContext('2d');
    ctx.scale(RATIO, RATIO);
    const cx = size / 2, cy = size / 2;
    ctx.shadowColor = 'rgba(0,0,0,0.28)'; ctx.shadowBlur = 6; ctx.shadowOffsetY = 1.5;
    ctx.beginPath(); ctx.arc(cx, cy, r, 0, Math.PI * 2); ctx.fillStyle = surface(); ctx.fill();
    ctx.shadowColor = 'transparent';
    let a = -Math.PI / 2;
    const ring = Math.max(5, r * 0.32);
    ORDER.forEach((s) => {
        const n = counts[s] || 0;
        if (!n) return;
        const sweep = (n / total) * Math.PI * 2;
        ctx.beginPath();
        ctx.arc(cx, cy, r - ring / 2, a, a + sweep);
        ctx.strokeStyle = COLOR[s]; ctx.lineWidth = ring; ctx.stroke();
        if (sweep < Math.PI * 2) { // 2px surface gap between segments
            ctx.beginPath(); ctx.moveTo(cx, cy);
            ctx.lineTo(cx + Math.cos(a) * r, cy + Math.sin(a) * r);
            ctx.strokeStyle = surface(); ctx.lineWidth = 2; ctx.stroke();
        }
        a += sweep;
    });
    ctx.beginPath(); ctx.arc(cx, cy, r - ring - 0.5, 0, Math.PI * 2); ctx.fillStyle = surface(); ctx.fill();
    ctx.fillStyle = ink();
    ctx.font = `700 ${r > 22 ? 13 : 12}px 'Noto Sans', system-ui, sans-serif`;
    ctx.textAlign = 'center'; ctx.textBaseline = 'middle';
    ctx.fillText(String(total), cx, cy + 0.5);
    const style = new Style({ image: new Icon({ img: c, scale: 1 / RATIO }) });
    glyphCache.set(key, style);
    return style;
}

// ---------------------------------------------------------------------------
function init() {
    const el = document.getElementById('works-map');
    if (!el || el.dataset.ready) return;
    el.dataset.ready = '1';

    const cfg = JSON.parse(el.dataset.config);
    const L = cfg.labels;
    const shell = el.closest('.map-shell');
    const ui = {
        controls: document.getElementById('map-controls'),
        legend: document.getElementById('map-legend'),
        drawer: document.getElementById('map-drawer'),
        tooltip: document.getElementById('map-tooltip'),
        status: document.getElementById('map-status'),
        search: document.getElementById('map-search'),
        results: document.getElementById('map-search-results'),
    };

    let query = cfg.query;
    let level = cfg.level;
    let areasOn = level !== 'none';
    let selected = null; // { id, coord, startedAt }
    let hoveredArea = null;
    const hidden = new Set(); // statuses switched off in the legend
    let allFeatures = [];

    // ---- Base layers -------------------------------------------------------
    const baseById = Object.fromEntries(cfg.baseLayers.map((b) => [b.id, b]));
    const baseLayers = {};
    const labelLayers = {};
    cfg.baseLayers.forEach((b) => {
        baseLayers[b.id] = b.type === 'wms'
            ? new TileLayer({ visible: false, preload: 1, source: new TileWMS({ url: b.url, params: { LAYERS: b.layer, TILED: true }, attributions: b.attribution }) })
            : new TileLayer({ visible: false, preload: 1, source: new XYZ({ url: b.url, attributions: b.attribution, maxZoom: b.max_zoom, crossOrigin: 'anonymous' }) });
        if (b.labels_url) {
            labelLayers[b.id] = new TileLayer({ visible: false, zIndex: 6, source: new XYZ({ url: b.labels_url, maxZoom: b.max_zoom, crossOrigin: 'anonymous' }) });
        }
    });
    const params = new URLSearchParams(location.search);
    let userChoseBase = !!(params.get('base') || store.get('dcp-map-base'));
    let currentBase = [params.get('base'), store.get('dcp-map-base'), isDark() ? cfg.defaultBaseDark : cfg.defaultBase, cfg.baseLayers[0]?.id]
        .find((id) => id && baseById[id]);

    // ---- Data layers ---------------------------------------------------------
    const areaSource = new VectorSource();
    const areaLayer = new VectorLayer({
        source: areaSource,
        zIndex: 4,
        style: (f, resolution) => {
            const hot = f === hoveredArea;
            // Shading helps at state/district scale but hides the base map up close: fade it out
            // between ~zoom 9 (300 m/px) and ~zoom 12 (38 m/px), keeping the outline.
            const fade = Math.min(1, Math.max(0, (resolution - 30) / 270));
            const base = BINS[binIndex(f.get('flagged_pct'))].color;
            const fill = base.replace(/[\d.]+\)$/, (a) => `${(parseFloat(a) * (hot ? Math.max(fade, 0.5) : fade)).toFixed(3)})`);
            return new Style({
                fill: new Fill({ color: fill }),
                stroke: new Stroke({ color: hot ? '#1c5cab' : 'rgba(28,92,171,0.85)', width: hot ? 2.75 : 1.25, lineDash: hot ? undefined : [6, 3] }),
            });
        },
    });

    // ---- State highlight: outline with a halo, and a mask dimming everything outside ----
    let highlightOn = store.get('dcp-map-highlight') !== '0';
    let stateFeature = null;
    const stateSource = new VectorSource();
    const maskSource = new VectorSource();
    const IMAGERY = ['satellite', 'hybrid', 'terrain', 'bhuvan'];
    const maskColor = () => (IMAGERY.includes(currentBase)
        ? 'rgba(10,14,20,0.55)'
        : isDark() || currentBase === 'dark' ? 'rgba(12,12,11,0.62)' : 'rgba(246,246,244,0.72)');
    const maskLayer = new VectorLayer({ source: maskSource, zIndex: 3, style: () => new Style({ fill: new Fill({ color: maskColor() }) }) });
    const stateLayer = new VectorLayer({
        source: stateSource,
        zIndex: 7,
        style: () => {
            const onImagery = IMAGERY.includes(currentBase);
            return [
                new Style({ stroke: new Stroke({ color: onImagery ? 'rgba(255,214,102,0.35)' : 'rgba(28,92,171,0.18)', width: 14 }) }), // glow
                new Style({ stroke: new Stroke({ color: onImagery ? 'rgba(0,0,0,0.55)' : surface(), width: 5.5 }) }), // halo
                new Style({ stroke: new Stroke({ color: onImagery ? '#ffd666' : '#1c5cab', width: 2.5 }) }), // line
            ];
        },
    });
    function buildMask(feature) {
        const world = fromExtent([-20037508.34, -20037508.34, 20037508.34, 20037508.34]);
        const geom = feature.getGeometry();
        const polys = geom.getType() === 'MultiPolygon' ? geom.getPolygons() : [geom];
        polys.forEach((p) => world.appendLinearRing(new LinearRing(p.getLinearRing(0).getCoordinates())));
        return new Feature(world);
    }
    function applyHighlight() {
        stateLayer.setVisible(!!stateFeature && highlightOn);
        maskLayer.setVisible(!!stateFeature && highlightOn);
    }
    async function loadState() {
        try {
            const res = await fetch(cfg.stateUrl, { headers: { Accept: 'application/geo+json' } });
            if (!res.ok) return;
            const features = new GeoJSON().readFeatures(await res.json(), { featureProjection: 'EPSG:3857' });
            if (!features.length) return;
            stateFeature = features[0];
            if (stateFeature.get('source')) stateSource.setAttributions(`${esc(L.stateBorder)}: ${esc(stateFeature.get('source'))}`);
            stateSource.addFeature(stateFeature);
            maskSource.addFeature(buildMask(stateFeature));
            applyHighlight();
            renderControls();
        } catch { /* no state outline: map works without it */ }
    }
    function fitState(duration = 700) {
        if (!stateFeature) return false;
        const pad = window.innerWidth < 640 ? [70, 20, 130, 20] : [80, 100, 140, 100];
        map.getView().fit(stateFeature.getGeometry().getExtent(), { padding: pad, duration });
        return true;
    }

    // ---- District and co-district borders: plain lines with names, all loaded outlines ----
    // District: solid purple line with a halo; co-district: thinner dashed line, from ~zoom 8.
    // Purple keeps them apart from the blue state outline and area shading.
    const BORDER_LEVELS = ['district', 'co_district'];
    const borderOn = Object.fromEntries(BORDER_LEVELS.map((lv) => [lv, store.get(`dcp-map-border-${lv}`) !== '0']));
    const borderColor = (lv) => {
        if (IMAGERY.includes(currentBase)) return lv === 'district' ? '#ffffff' : 'rgba(255,255,255,0.8)';
        if (isDark() || currentBase === 'dark') return lv === 'district' ? '#c3a6ee' : 'rgba(195,166,238,0.8)';
        return lv === 'district' ? '#6b3fa0' : 'rgba(107,63,160,0.75)';
    };
    const borderHalo = () => (IMAGERY.includes(currentBase) ? 'rgba(0,0,0,0.5)' : surface());
    const borderStyleCache = {};
    const borderStyle = (lv) => (f, resolution) => {
        // Names: districts from ~zoom 7.5 to 12, co-districts from ~zoom 9.5.
        const labelled = lv === 'district' ? resolution < 900 && resolution > 30 : resolution < 230;
        const key = `${lv}|${currentBase}|${isDark()}`;
        const [halo, line] = borderStyleCache[key] ??= [
            new Style({ stroke: new Stroke({ color: borderHalo(), width: lv === 'district' ? 4 : 2.5 }) }),
            new Style({ stroke: new Stroke({ color: borderColor(lv), width: lv === 'district' ? 1.75 : 1, lineDash: lv === 'district' ? undefined : [5, 4] }) }),
        ];
        if (!labelled) return [halo, line];
        return [halo, line, new Style({
            text: new Text({
                text: f.get('name'),
                font: `${lv === 'district' ? 600 : 500} ${lv === 'district' ? 13 : 11}px 'Noto Sans', 'Noto Sans Bengali', system-ui, sans-serif`,
                fill: new Fill({ color: borderColor(lv) }),
                stroke: new Stroke({ color: borderHalo(), width: 3 }),
                overflow: false,
            }),
        })];
    };
    const borderSources = {};
    const borderLayers = {};
    BORDER_LEVELS.forEach((lv) => {
        borderSources[lv] = new VectorSource();
        borderLayers[lv] = new VectorLayer({
            source: borderSources[lv],
            zIndex: lv === 'district' ? 5.5 : 5,
            minZoom: lv === 'district' ? undefined : 8,
            visible: borderOn[lv],
            declutter: lv, // labels of one level never overlap each other
            style: borderStyle(lv),
        });
    });
    async function loadBorders() {
        await Promise.all(BORDER_LEVELS.map(async (lv) => {
            try {
                const res = await fetch(cfg.bordersUrl.replace('__LEVEL__', lv), { headers: { Accept: 'application/geo+json' } });
                if (res.ok) borderSources[lv].addFeatures(new GeoJSON().readFeatures(await res.json(), { featureProjection: 'EPSG:3857' }));
            } catch { /* map works without borders */ }
        }));
        renderControls();
        renderLegend();
    }
    const hasBorders = (lv) => borderSources[lv].getFeatures().length > 0;
    const restyleBorders = () => { Object.keys(borderStyleCache).forEach((k) => delete borderStyleCache[k]); BORDER_LEVELS.forEach((lv) => borderLayers[lv].changed()); };

    const visibleSource = new VectorSource();
    const clusterSource = new Cluster({ source: visibleSource, distance: 42, minDistance: 14 });
    const workStyle = (f) => {
        const p = f.getProperties();
        const sel = selected && p.id === selected.id;
        const styles = [];
        if (p.stale) {
            styles.push(new Style({ image: new CircleStyle({ radius: sel ? 16 : 12.5, stroke: new Stroke({ color: ink(), width: 1.5, lineDash: [3, 2] }) }) }));
        }
        styles.push(new Style({ image: marker(p.display, sel ? 1.4 : 1), zIndex: sel ? 10 : ORDER.length - ORDER.indexOf(p.display) }));
        return styles;
    };
    const workLayer = new VectorLayer({
        source: clusterSource,
        zIndex: 10,
        style: (f) => {
            const members = f.get('features');
            if (members.length === 1) return workStyle(members[0]);
            const counts = {};
            members.forEach((m) => { counts[m.get('display')] = (counts[m.get('display')] || 0) + 1; });
            return clusterGlyph(counts, members.length);
        },
    });

    const detailSource = new VectorSource();
    const detailLayer = new VectorLayer({ source: detailSource, zIndex: 8 });

    const map = new Map({
        target: el,
        layers: [...Object.values(baseLayers), maskLayer, areaLayer, borderLayers.co_district, borderLayers.district, ...Object.values(labelLayers), stateLayer, detailLayer, workLayer],
        controls: defaultControls({ zoom: false, rotate: false, attributionOptions: { collapsible: true } }).extend([new ScaleLine()]),
        view: new View({ center: fromLonLat(cfg.center), zoom: cfg.zoom, maxZoom: 19, minZoom: 5 }),
    });

    function setBase(id, remember) {
        if (!baseById[id]) return;
        currentBase = id;
        Object.entries(baseLayers).forEach(([k, layer]) => layer.setVisible(k === id));
        Object.entries(labelLayers).forEach(([k, layer]) => layer.setVisible(k === id));
        map.getView().setMaxZoom(baseById[id].max_zoom || 19);
        if (remember) { userChoseBase = true; store.set('dcp-map-base', id); }
        maskLayer.changed();
        stateLayer.changed();
        restyleBorders();
        renderControls();
        renderLegend(); // border swatches follow the base map colours
    }

    // ---- Pulse on the selected work -------------------------------------------
    workLayer.on('postrender', (event) => {
        if (!selected?.coord) return;
        const ctx = getVectorContext(event);
        const t = ((Date.now() - selected.startedAt) % 1800) / 1800;
        const e = easeOut(t);
        ctx.setStyle(new Style({ image: new CircleStyle({ radius: 12 + e * 26, stroke: new Stroke({ color: `rgba(42,120,214,${(1 - e) * 0.9})`, width: 2.5 }) }) }));
        ctx.drawGeometry(new Point(selected.coord));
        map.render();
    });

    // ---- Loading ---------------------------------------------------------------
    const readFeatures = (data) => new GeoJSON().readFeatures(data, { featureProjection: 'EPSG:3857' });
    const showStatus = (text) => { ui.status.textContent = text; ui.status.classList.toggle('hidden', !text); };

    function applyVisibility() {
        visibleSource.clear(true);
        visibleSource.addFeatures(allFeatures.filter((f) => !hidden.has(f.get('display'))));
        renderLegend();
    }

    function fitTo(features, duration = 600) {
        const extent = createEmpty();
        features.forEach((f) => extendExtent(extent, f.getGeometry().getExtent()));
        if (isEmpty(extent)) return;
        const pad = window.innerWidth < 640 ? [70, 30, 140, 30] : [90, 110, 150, 380];
        map.getView().fit(extent, { padding: pad, maxZoom: 13, duration });
    }

    async function loadAll(fit) {
        showStatus(L.loading);
        try {
            const res = await fetch(`${cfg.worksUrl}?${query}`, { headers: { Accept: 'application/geo+json' } });
            if (!res.ok) throw new Error(String(res.status));
            allFeatures = readFeatures(await res.json());
            applyVisibility();

            areaSource.clear(true);
            if (level !== 'none') {
                const ares = await fetch(`${cfg.boundariesUrl.replace('__LEVEL__', level)}?${query}`);
                if (ares.ok) areaSource.addFeatures(readFeatures(await ares.json()));
            }
            areaLayer.setVisible(areasOn && level !== 'none');
            showStatus('');
            const deepLink = Number(params.get('work'));
            if (fit && deepLink && allFeatures.some((f) => f.get('id') === deepLink)) {
                params.delete('work'); // only on first load
                select(deepLink);
            } else if (fit === 'state') {
                if (!fitState(0)) fitTo(allFeatures.length ? allFeatures : areaSource.getFeatures());
            } else if (fit) {
                fitTo(allFeatures.length ? allFeatures : areaSource.getFeatures());
            }
        } catch {
            showStatus(L.failed);
        }
    }

    // ---- Controls (top right): base map switcher + zoom / fit / fullscreen ----------
    const icon = (d) => `<svg aria-hidden="true" viewBox="0 0 24 24" class="size-[18px]" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">${d}</svg>`;
    let switcherOpen = false;

    function tileHtml(x) {
        if (!x) return '';
        return x.thumb
            ? `<img src="${esc(x.thumb)}" alt="" loading="lazy" crossorigin="anonymous">${x.labels_thumb ? `<img src="${esc(x.labels_thumb)}" alt="" class="absolute inset-0" loading="lazy" crossorigin="anonymous">` : ''}`
            : '<div class="h-full w-full bg-linear-to-br from-emerald-700 to-sky-800"></div>';
    }

    // A short sample of the border line, for the switcher and the legend.
    const borderSwatch = (lv) => `<svg aria-hidden="true" width="22" height="8" viewBox="0 0 22 8"><line x1="1" y1="4" x2="21" y2="4"
        stroke="${borderColor(lv)}" stroke-width="${lv === 'district' ? 2 : 1.5}" ${lv === 'district' ? '' : 'stroke-dasharray="4 3"'}/></svg>`;

    function renderControls() {
        const b = baseById[currentBase];
        ui.controls.innerHTML = `
            <div class="relative">
                <button type="button" data-act="switcher" aria-expanded="${switcherOpen}" aria-label="${esc(L.baseMap)}: ${esc(b?.name)}" title="${esc(L.baseMap)}"
                    class="map-glass map-base-tile block h-[4.25rem] w-[4.25rem] p-0" aria-pressed="false">
                    ${tileHtml(b)}<span>${esc(b?.name)}</span>
                </button>
                <div class="map-glass absolute right-[4.75rem] top-0 w-[17.5rem] p-3 ${switcherOpen ? '' : 'hidden'}" role="dialog" aria-label="${esc(L.baseMap)}">
                    <div class="mb-2 text-xs font-semibold uppercase tracking-wide text-ink-3">${esc(L.baseMap)}</div>
                    <div class="grid grid-cols-3 gap-2">
                        ${cfg.baseLayers.map((x) => `
                            <button type="button" data-base="${esc(x.id)}" aria-pressed="${x.id === currentBase}" class="map-base-tile aspect-square">
                                ${tileHtml(x)}<span>${esc(x.name)}</span>
                            </button>`).join('')}
                    </div>
                    ${stateFeature ? `
                    <label class="mt-3 flex cursor-pointer items-center justify-between gap-2 border-t border-line pt-2.5 text-sm text-ink">
                        ${esc(L.highlight)}
                        <input type="checkbox" data-act="highlight" ${highlightOn ? 'checked' : ''} class="size-4 accent-[var(--accent)]">
                    </label>` : ''}
                    ${BORDER_LEVELS.filter(hasBorders).map((lv, i) => `
                    <label class="${i === 0 ? 'mt-3 border-t border-line pt-2.5' : 'mt-2'} flex cursor-pointer items-center justify-between gap-2 text-sm text-ink">
                        <span class="inline-flex items-center gap-2">${borderSwatch(lv)}${esc(L.borders[lv])}</span>
                        <input type="checkbox" data-act="border" data-level="${lv}" ${borderOn[lv] ? 'checked' : ''} class="size-4 accent-[var(--accent)]">
                    </label>`).join('')}
                    ${level !== 'none' ? `
                    <label class="mt-3 flex cursor-pointer items-center justify-between gap-2 border-t border-line pt-2.5 text-sm text-ink">
                        ${esc(L.areas)}
                        <input type="checkbox" data-act="areas" ${areasOn ? 'checked' : ''} class="size-4 accent-[var(--accent)]">
                    </label>` : ''}
                </div>
            </div>
            <div class="map-glass flex flex-col overflow-hidden p-0">
                <button type="button" data-act="in" class="map-icon-btn" aria-label="${esc(L.zoomIn)}" title="${esc(L.zoomIn)}">${icon('<path d="M12 5v14M5 12h14"/>')}</button>
                <button type="button" data-act="out" class="map-icon-btn" aria-label="${esc(L.zoomOut)}" title="${esc(L.zoomOut)}">${icon('<path d="M5 12h14"/>')}</button>
                ${stateFeature ? `<button type="button" data-act="home" class="map-icon-btn" aria-label="${esc(L.home)}" title="${esc(L.home)}">${icon('<path d="M3 11l9-7 9 7"/><path d="M5 10v10h14V10"/>')}</button>` : ''}
                <button type="button" data-act="fit" class="map-icon-btn" aria-label="${esc(L.fit)}" title="${esc(L.fit)}">${icon('<path d="M4 9V4h5M20 9V4h-5M4 15v5h5M20 15v5h-5"/>')}</button>
                <button type="button" data-act="full" class="map-icon-btn" aria-label="${esc(L.fullscreen)}" title="${esc(L.fullscreen)}">${icon('<path d="M8 3H3v5M21 8V3h-5M3 16v5h5M16 21h5v-5"/>')}</button>
            </div>`;
    }

    ui.controls.addEventListener('click', (e) => {
        const btn = e.target.closest('button');
        if (!btn) return;
        const view = map.getView();
        if (btn.dataset.base) { setBase(btn.dataset.base, true); return; }
        switch (btn.dataset.act) {
            case 'switcher': switcherOpen = !switcherOpen; renderControls(); break;
            case 'in': view.animate({ zoom: view.getZoom() + 1, duration: 220 }); break;
            case 'out': view.animate({ zoom: view.getZoom() - 1, duration: 220 }); break;
            case 'home': fitState(); break;
            case 'fit': fitTo(visibleSource.getFeatures().length ? visibleSource.getFeatures() : allFeatures); break;
            case 'full':
                if (document.fullscreenElement) document.exitFullscreen();
                else shell.requestFullscreen?.();
                break;
        }
    });
    ui.controls.addEventListener('change', (e) => {
        if (e.target.dataset.act === 'highlight') { highlightOn = e.target.checked; store.set('dcp-map-highlight', highlightOn ? '1' : '0'); applyHighlight(); }
        if (e.target.dataset.act === 'border') {
            const lv = e.target.dataset.level;
            borderOn[lv] = e.target.checked;
            store.set(`dcp-map-border-${lv}`, borderOn[lv] ? '1' : '0');
            borderLayers[lv].setVisible(borderOn[lv]);
            renderLegend();
        }
        if (e.target.dataset.act === 'areas') { areasOn = e.target.checked; areaLayer.setVisible(areasOn); renderLegend(); }
    });
    document.addEventListener('fullscreenchange', () => setTimeout(() => map.updateSize(), 50));
    document.addEventListener('keydown', (e) => {
        if (e.key !== 'Escape') return;
        if (switcherOpen) { switcherOpen = false; renderControls(); } else if (selected) select(null);
    });

    // ---- Legend (bottom left): status chips double as filters ------------------------
    function renderLegend() {
        const counts = {};
        let stale = 0;
        allFeatures.forEach((f) => { counts[f.get('display')] = (counts[f.get('display')] || 0) + 1; if (f.get('stale')) stale++; });
        const shown = visibleSource.getFeatures().length;
        const statuses = ORDER.filter((s) => counts[s] || s !== 'cancelled');

        ui.legend.innerHTML = `
            <div class="mb-1.5 flex items-baseline justify-between gap-4 px-0.5">
                <span class="text-xs text-ink-2">${esc(L.showing)} <strong class="tabular-nums text-ink">${shown}</strong> ${esc(L.of)} <span class="tabular-nums">${allFeatures.length}</span> ${esc(L.works)}</span>
                <span class="hidden text-[11px] text-ink-3 sm:inline">${esc(L.toggleHint)}</span>
            </div>
            <div class="flex flex-wrap gap-1.5" role="group">
                ${statuses.map((s) => `
                    <button type="button" data-status="${s}" aria-pressed="${!hidden.has(s)}"
                        class="map-chip inline-flex items-center gap-1.5 rounded-lg border border-line bg-surface px-2 py-1 text-xs text-ink-2 hover:border-line-strong">
                        <span data-icon="${s}"></span>
                        <span class="map-chip-label">${esc(L.status[s])}</span>
                        <span class="rounded-md bg-surface-2 px-1.5 font-semibold tabular-nums text-ink">${counts[s] || 0}</span>
                    </button>`).join('')}
            </div>
            <div class="mt-2 flex flex-wrap items-center gap-x-4 gap-y-1.5 px-0.5 text-[11px] text-ink-2">
                <span class="inline-flex items-center gap-1.5"><span data-icon="stale"></span>${esc(L.staleRing)} · <strong class="tabular-nums text-ink">${stale}</strong></span>
                ${BORDER_LEVELS.filter((lv) => borderOn[lv] && hasBorders(lv)).map((lv) => `
                <span class="inline-flex items-center gap-1.5">${borderSwatch(lv)}${esc(L.borders[lv])}</span>`).join('')}
                ${areasOn && level !== 'none' ? `
                <span class="inline-flex items-center gap-1.5">${esc(L.areaLegend)}
                    <span class="inline-flex overflow-hidden rounded border border-line">
                        ${BINS.map((b) => `<span class="block h-3 w-6" style="background:${b.color}" title="${esc(b.label)}"></span>`).join('')}
                    </span>
                    <span class="tabular-nums text-ink-3">0 → 50%+</span>
                </span>` : ''}
            </div>`;
        ui.legend.querySelectorAll('[data-icon]').forEach((slot) => {
            const s = slot.dataset.icon;
            slot.replaceWith(s === 'stale' ? markerCanvas('in_progress', true) : markerCanvas(s));
        });
    }
    ui.legend.addEventListener('click', (e) => {
        const chip = e.target.closest('[data-status]');
        if (!chip) return;
        const s = chip.dataset.status;
        if (hidden.has(s)) hidden.delete(s); else hidden.add(s);
        applyVisibility();
    });

    // ---- Tooltip ----------------------------------------------------------------
    function tip(px, html) {
        ui.tooltip.innerHTML = html;
        ui.tooltip.style.left = `${Math.min(px[0] + 14, el.clientWidth - 270)}px`;
        ui.tooltip.style.top = `${px[1] + 16}px`;
        ui.tooltip.classList.remove('hidden');
    }
    map.on('pointermove', (evt) => {
        if (evt.dragging) return;
        let hit = null;
        map.forEachFeatureAtPixel(evt.pixel, (f, layer) => { hit = { f, layer }; return true; }, { hitTolerance: 5, layerFilter: (l) => l === workLayer || l === areaLayer });
        const prevArea = hoveredArea;
        hoveredArea = hit?.layer === areaLayer ? hit.f : null;
        if (prevArea !== hoveredArea) areaLayer.changed();
        el.style.cursor = hit?.layer === workLayer ? 'pointer' : '';
        if (!hit) { ui.tooltip.classList.add('hidden'); return; }

        if (hit.layer === workLayer) {
            const members = hit.f.get('features');
            if (members.length > 1) {
                const c = {};
                members.forEach((m) => { c[m.get('display')] = (c[m.get('display')] || 0) + 1; });
                tip(evt.pixel, `<div class="font-semibold text-ink">${members.length} ${esc(L.works)}</div>
                    ${ORDER.filter((s) => c[s]).map((s) => `<div class="flex items-center gap-1.5 text-ink-2"><span class="inline-block size-2 rounded-full" style="background:${COLOR[s]}"></span>${esc(L.status[s])}<span class="ml-auto pl-3 tabular-nums text-ink">${c[s]}</span></div>`).join('')}
                    <div class="mt-1 text-ink-3">${esc(L.clusterHint)}</div>`);
            } else {
                const p = members[0].getProperties();
                tip(evt.pixel, `<div class="font-semibold text-ink">${esc(p.code)}</div><div class="text-ink-2">${esc(p.name)}</div>
                    <div class="mt-1 flex items-center gap-1.5 text-ink-2"><span class="inline-block size-2 rounded-full" style="background:${COLOR[p.display]}"></span>${esc(L.status[p.display])}${p.stale ? ` · ${esc(L.stale)}` : ''}</div>
                    <div class="tabular-nums text-ink-2">${esc(L.physical)} ${p.physical_pct}% · ${esc(L.financial)} ${p.financial_pct}%</div>`);
            }
        } else {
            const p = hit.f.getProperties();
            tip(evt.pixel, `<div class="font-semibold text-ink">${esc(p.name)}</div>
                <div class="tabular-nums text-ink-2">${p.works} ${esc(L.works)} · ${p.flagged} ${esc(L.flagged)} (${p.flagged_pct}%)</div>`);
        }
    });
    map.getViewport().addEventListener('mouseleave', () => {
        ui.tooltip.classList.add('hidden');
        if (hoveredArea) { hoveredArea = null; areaLayer.changed(); }
    });

    // ---- Selection + drawer ---------------------------------------------------------
    const bar = (label, value, color) => `
        <div>
            <div class="mb-1 flex justify-between text-xs"><span class="text-ink-2">${esc(label)}</span><span class="font-semibold tabular-nums text-ink">${value}%</span></div>
            <div class="h-2 overflow-hidden rounded-full bg-surface-2"><div class="h-full rounded-full" style="width:${Math.min(100, Math.max(0, value))}%;background:${color}"></div></div>
        </div>`;

    function openDrawer(html) {
        ui.drawer.innerHTML = html;
        ui.drawer.classList.remove('translate-x-[110%]');
        ui.drawer.setAttribute('aria-hidden', 'false');
    }
    function closeDrawer() {
        ui.drawer.classList.add('translate-x-[110%]');
        ui.drawer.setAttribute('aria-hidden', 'true');
    }

    async function select(id, fly = true) {
        detailSource.clear();
        if (!id) { selected = null; workLayer.changed(); closeDrawer(); return; }

        const f = allFeatures.find((x) => x.get('id') === id);
        const p = f?.getProperties() ?? {};
        selected = { id, coord: f?.getGeometry().getCoordinates(), startedAt: Date.now() };
        workLayer.changed();
        map.render();
        if (fly && selected.coord) map.getView().animate({ center: selected.coord, zoom: Math.max(map.getView().getZoom(), 15), duration: 700 });

        openDrawer('<div class="space-y-3 p-4"><div class="h-4 w-24 animate-pulse rounded bg-surface-2"></div><div class="h-6 w-3/4 animate-pulse rounded bg-surface-2"></div><div class="h-24 animate-pulse rounded bg-surface-2"></div></div>');

        const res = await fetch(cfg.workUrl.replace('__ID__', id), { headers: { Accept: 'application/json' } });
        if (!res.ok || selected?.id !== id) return;
        const w = await res.json();

        if (w.site) {
            const ring = new Feature(circular([w.site.lng, w.site.lat], w.radius_m, 72).transform('EPSG:4326', 'EPSG:3857'));
            ring.setStyle(new Style({ fill: new Fill({ color: 'rgba(42,120,214,0.12)' }), stroke: new Stroke({ color: '#2a78d6', width: 2, lineDash: [7, 5] }) }));
            detailSource.addFeature(ring);
        }
        const pts = [];
        w.photos.filter((ph) => ph.lat !== null && ph.lng !== null).forEach((ph) => {
            const coord = fromLonLat([ph.lng, ph.lat]);
            pts.push(coord);
            const pf = new Feature(new Point(coord));
            pf.setStyle(new Style({ image: new RegularShape({ points: 4, radius: 6, angle: Math.PI / 4, fill: new Fill({ color: ph.outside ? '#d03b3b' : ink() }), stroke: new Stroke({ color: surface(), width: 2 }) }) }));
            detailSource.addFeature(pf);
        });
        if (fly && pts.length && w.site) {
            pts.push(fromLonLat([w.site.lng, w.site.lat]));
            const pad = window.innerWidth < 640 ? [80, 40, 180, 40] : [110, 430, 160, 110];
            map.getView().fit(boundingExtent(pts), { padding: pad, maxZoom: 17, duration: 800 });
        }

        const display = p.display ?? 'not_started';
        const photos = w.photos.length
            ? `<div class="grid grid-cols-2 gap-2">${w.photos.slice(0, 6).map((ph) => `
                <figure class="group">
                    <a href="${esc(ph.url)}" target="_blank" rel="noopener" class="block overflow-hidden rounded-lg border border-line">
                        <img src="${esc(ph.url)}" alt="" loading="lazy" class="aspect-[4/3] w-full object-cover transition-transform duration-300 group-hover:scale-105">
                    </a>
                    <figcaption class="mt-1 text-[11px] leading-tight ${ph.outside ? 'font-semibold text-critical' : 'text-ink-3'}">
                        ${esc(ph.taken ?? '')}${ph.distance_m !== null ? ` · ${Math.round(ph.distance_m)} ${esc(L.mFromSite)}` : ''}${ph.outside ? ` · ${esc(L.outside)}` : ''}
                    </figcaption>
                </figure>`).join('')}</div>`
            : `<p class="rounded-lg bg-surface-2 px-3 py-4 text-center text-xs text-ink-3">${esc(L.noPhotos)}</p>`;

        openDrawer(`
            <div class="flex items-start justify-between gap-3 border-b border-line p-4">
                <div class="min-w-0">
                    <div class="mb-1.5 inline-flex items-center gap-1.5 rounded-full border border-line bg-surface px-2 py-0.5 text-xs font-semibold text-ink">
                        <span class="inline-block size-2 rounded-full" style="background:${COLOR[display]}"></span>${esc(L.status[display])}${p.stale ? ` · ${esc(L.stale)}` : ''}
                    </div>
                    <h2 class="text-base font-semibold leading-snug text-ink">${esc(w.name)}</h2>
                    <div class="text-xs text-ink-3">${esc(w.code)} · ${esc(w.district ?? '')}</div>
                </div>
                <button type="button" data-act="close" class="map-icon-btn -mr-1 -mt-1 shrink-0 rounded-lg" aria-label="${esc(L.close)}">${icon('<path d="M6 6l12 12M18 6L6 18"/>')}</button>
            </div>
            <div class="flex-1 space-y-4 overflow-y-auto p-4">
                <div class="space-y-3">
                    ${bar(L.physical, w.physical_pct, '#2a78d6')}
                    ${bar(L.financial, w.financial_pct, '#eb6834')}
                </div>
                <dl class="grid grid-cols-2 gap-x-3 gap-y-2 text-xs">
                    <div><dt class="text-ink-3">${esc(L.stage)}</dt><dd class="font-medium text-ink">${esc(w.stage ?? '—')}</dd></div>
                    <div><dt class="text-ink-3">${esc(L.lastUpdate)}</dt><dd class="font-medium text-ink">${esc(w.last_update ?? L.never)}</dd></div>
                </dl>
                <div>
                    <h3 class="mb-2 text-xs font-semibold uppercase tracking-wide text-ink-3">${esc(L.photos)}</h3>
                    ${photos}
                </div>
            </div>
            <div class="border-t border-line p-3">
                <a href="${esc(w.url)}" class="btn-primary w-full">${esc(L.open)} →</a>
            </div>`);
    }
    ui.drawer.addEventListener('click', (e) => { if (e.target.closest('[data-act="close"]')) select(null); });

    map.on('singleclick', (evt) => {
        let hit = null;
        map.forEachFeatureAtPixel(evt.pixel, (f) => { hit = f; return true; }, { hitTolerance: 5, layerFilter: (l) => l === workLayer });
        if (!hit) { if (selected) select(null); return; }
        const members = hit.get('features');
        if (members.length > 1) {
            const ext = boundingExtent(members.map((m) => m.getGeometry().getCoordinates()));
            map.getView().fit(ext, { padding: [140, 140, 180, 140], maxZoom: 16, duration: 600 });
            return;
        }
        select(members[0].get('id'));
    });

    // ---- Search -----------------------------------------------------------------
    let active = -1;
    let matches = [];
    function renderResults() {
        if (!ui.search.value.trim()) { ui.results.classList.add('hidden'); return; }
        ui.results.innerHTML = matches.length
            ? matches.map((f, i) => `
                <li role="option" aria-selected="${i === active}" data-id="${f.get('id')}"
                    class="flex cursor-pointer items-center gap-2 px-3 py-1.5 ${i === active ? 'bg-accent-soft' : 'hover:bg-surface-2'}">
                    <span class="inline-block size-2.5 shrink-0 rounded-full" style="background:${COLOR[f.get('display')]}"></span>
                    <span class="min-w-0"><span class="block truncate font-medium text-ink">${esc(f.get('code'))}</span><span class="block truncate text-xs text-ink-3">${esc(f.get('name'))}</span></span>
                </li>`).join('')
            : `<li class="px-3 py-2 text-ink-3">${esc(L.noMatch)}</li>`;
        ui.results.classList.remove('hidden');
    }
    function choose(id) {
        const f = allFeatures.find((x) => x.get('id') === id);
        if (f && hidden.has(f.get('display'))) { hidden.delete(f.get('display')); applyVisibility(); }
        ui.results.classList.add('hidden');
        ui.search.value = f ? f.get('code') : '';
        select(id);
    }
    ui.search.addEventListener('input', () => {
        const q = ui.search.value.trim().toLowerCase();
        matches = q ? allFeatures.filter((f) => `${f.get('code')} ${f.get('name')}`.toLowerCase().includes(q)).slice(0, 8) : [];
        active = matches.length ? 0 : -1;
        renderResults();
    });
    ui.search.addEventListener('keydown', (e) => {
        if (e.key === 'ArrowDown') { active = Math.min(matches.length - 1, active + 1); renderResults(); e.preventDefault(); }
        else if (e.key === 'ArrowUp') { active = Math.max(0, active - 1); renderResults(); e.preventDefault(); }
        else if (e.key === 'Enter' && matches[active]) { choose(matches[active].get('id')); e.preventDefault(); }
        else if (e.key === 'Escape') { ui.search.value = ''; renderResults(); }
    });
    ui.results.addEventListener('mousedown', (e) => {
        const li = e.target.closest('[data-id]');
        if (li) { e.preventDefault(); choose(Number(li.dataset.id)); }
    });
    ui.search.addEventListener('blur', () => setTimeout(() => ui.results.classList.add('hidden'), 120));

    // ---- Livewire filters ---------------------------------------------------------
    const onFilters = ({ query: q, level: lv }) => {
        query = q;
        if (lv !== level) { level = lv; areasOn = lv !== 'none'; renderControls(); }
        select(null, false);
        loadAll(true);
    };
    const hookLivewire = () => window.Livewire.on('map-filters', onFilters);
    if (window.Livewire) hookLivewire();
    else document.addEventListener('livewire:init', hookLivewire);

    // ---- Theme follow ----------------------------------------------------------------
    new MutationObserver(() => {
        glyphCache.clear();
        if (!userChoseBase) setBase(isDark() ? cfg.defaultBaseDark : cfg.defaultBase, false);
        maskLayer.changed();
        stateLayer.changed();
        restyleBorders();
        workLayer.changed();
        renderLegend();
        renderControls();
    }).observe(document.documentElement, { attributes: true, attributeFilter: ['data-theme'] });

    map.on('pointerdown', () => { if (switcherOpen) { switcherOpen = false; renderControls(); } });

    setBase(currentBase, false);
    renderLegend();
    loadState().finally(() => loadAll('state'));
    loadBorders();
    // TEMP-DEBUG
    if (params.get('debug')) setInterval(() => {
        const c = el.querySelector('canvas');
        ui.status.classList.remove('hidden');
        ui.status.textContent = `target ${el.clientWidth}x${el.clientHeight} | map ${map.getSize()} | canvas ${c ? c.style.width + 'x' + c.style.height + ' / ' + c.width + 'x' + c.height : 'none'} | vp ${map.getViewport().clientHeight}`;
    }, 500);
}

if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
else init();
document.addEventListener('livewire:navigated', init);
