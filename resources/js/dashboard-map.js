// Dashboard map (docs/07 §2): the drill-down state → district → co-district → works, as a map of Assam.
// Shapes come once from the cached border endpoints; figures arrive with every Livewire render
// (event "dashboard-map"), so filters and drill-down stay in one place: the Overview component.
import 'ol/ol.css';
import '../css/map.css';
import Map from 'ol/Map';
import View from 'ol/View';
import Feature from 'ol/Feature';
import TileLayer from 'ol/layer/Tile';
import VectorLayer from 'ol/layer/Vector';
import XYZ from 'ol/source/XYZ';
import VectorSource from 'ol/source/Vector';
import GeoJSON from 'ol/format/GeoJSON';
import Point from 'ol/geom/Point';
import { fromExtent } from 'ol/geom/Polygon';
import LinearRing from 'ol/geom/LinearRing';
import { fromLonLat } from 'ol/proj';
import { Style, Fill, Stroke, Text, Circle as CircleStyle } from 'ol/style';
import { defaults as defaultControls } from 'ol/control';
import { defaults as defaultInteractions, MouseWheelZoom } from 'ol/interaction';
import { platformModifierKeyOnly } from 'ol/events/condition';
import { createEmpty, extend as extendExtent, isEmpty } from 'ol/extent';

// ---- Encoding --------------------------------------------------------------------
// Two sequential ramps: blue for "more is more" (counts, progress), warm for problems (shares delayed, stale).
const RAMPS = {
    blue: ['#dbe9fb', '#a9cbf3', '#6fa6ea', '#3a82dc', '#1c5cab'],
    warm: ['#fdebc8', '#f9c97f', '#f19a45', '#d9622b', '#a8361a'],
};
const STATUS_COLOR = {
    stalled: '#d03b3b', delayed: '#eda100', in_progress: '#2a78d6',
    not_started: '#75746f', completed: '#0ca30c', cancelled: '#a8a79f',
};
const share = (n, a) => (a.works ? (n * 100) / a.works : null);
const nf0 = new Intl.NumberFormat('en-IN', { maximumFractionDigits: 0 });
const nf1 = new Intl.NumberFormat('en-IN', { maximumFractionDigits: 1 });
const pct = (v) => (v === null || v === undefined ? '—' : `${nf1.format(v)}%`);
const PCT_BOUNDS = [20, 40, 60, 80, Infinity];
const SHARE_BOUNDS = [0, 10, 25, 50, Infinity];
const METRICS = {
    flagged: { ramp: 'warm', value: (a) => share(a.flagged, a), fmt: pct, bounds: () => SHARE_BOUNDS },
    works: { ramp: 'blue', value: (a) => a.works, fmt: (v) => nf0.format(v), bounds: (areas) => countBounds(areas) },
    physical: { ramp: 'blue', value: (a) => a.physical_pct, fmt: pct, bounds: () => PCT_BOUNDS },
    financial: { ramp: 'blue', value: (a) => a.financial_pct, fmt: pct, bounds: () => PCT_BOUNDS },
    stale: { ramp: 'warm', value: (a) => share(a.stale, a), fmt: pct, bounds: () => SHARE_BOUNDS },
};
function countBounds(areas) {
    const max = Math.max(1, ...areas.map((a) => a.works));
    const raw = max / 5;
    const mag = 10 ** Math.floor(Math.log10(raw));
    const step = Math.max(1, [1, 2, 5, 10].map((m) => m * mag).find((s) => s >= raw));
    return [step, step * 2, step * 3, step * 4, Infinity];
}
const binOf = (v, bounds) => bounds.findIndex((b) => v <= b);
function boundLabel(bounds, i, fmt) {
    if (i === 0) return bounds[0] === 0 ? fmt(0) : `≤ ${fmt(bounds[0])}`;
    if (bounds[i] === Infinity) return `> ${fmt(bounds[i - 1])}`;
    return `≤ ${fmt(bounds[i])}`;
}
function money(paise) {
    const r = (paise || 0) / 100;
    if (r >= 1e7) return `₹${nf1.format(r / 1e7)} Cr`;
    if (r >= 1e5) return `₹${nf1.format(r / 1e5)} L`;
    return `₹${nf0.format(r)}`;
}

const isDark = () => {
    const t = document.documentElement.dataset.theme;
    return t ? t === 'dark' : window.matchMedia('(prefers-color-scheme: dark)').matches;
};
const css = (name) => getComputedStyle(document.documentElement).getPropertyValue(name).trim();
const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);
const store = {
    get(k) { try { return localStorage.getItem(k); } catch { return null; } },
    set(k, v) { try { localStorage.setItem(k, v); } catch { /* storage blocked */ } },
};
// Any CSS colour (#fff, #ffffff, rgb(), named) → rgba with the given alpha; the canvas normalises it to #rrggbb.
const colorCtx = document.createElement('canvas').getContext('2d');
const alpha = (color, a) => {
    colorCtx.fillStyle = '#000';
    colorCtx.fillStyle = color;
    const hex = colorCtx.fillStyle;
    if (!hex.startsWith('#')) return hex; // already rgba(...)
    const n = parseInt(hex.slice(1), 16);
    return `rgba(${(n >> 16) & 255},${(n >> 8) & 255},${n & 255},${a})`;
};
const reduceMotion = () => window.matchMedia('(prefers-reduced-motion: reduce)').matches;

// ---------------------------------------------------------------------------------
let current = null;

function init() {
    const el = document.getElementById('dash-map');
    if (!el || el.dataset.ready) return;
    el.dataset.ready = '1';

    const cfg = JSON.parse(el.dataset.config);
    const L = cfg.labels;
    const ui = {
        metrics: document.getElementById('dash-map-metrics'),
        crumbs: document.getElementById('dash-map-crumbs'),
        tools: document.getElementById('dash-map-tools'),
        legend: document.getElementById('dash-map-legend'),
        tip: document.getElementById('dash-map-tip'),
        note: document.getElementById('dash-map-note'),
        side: document.getElementById('dash-map-side'),
    };

    let stats = cfg.stats;
    let metric = METRICS[store.get('dcp-dash-metric')] ? store.get('dcp-dash-metric') : 'flagged';
    let hoverId = null;
    const shapes = { district: [], co_district: [] };
    let stateFeature = null;
    let byId = new globalThis.Map();

    // ---- Layers ------------------------------------------------------------------
    const baseFor = () => (isDark() ? cfg.base.dark : cfg.base.light) ?? cfg.base.light;
    const baseLayer = new TileLayer({ opacity: 0.6 });
    const setBase = () => {
        const b = baseFor();
        if (b) baseLayer.setSource(new XYZ({ url: b.url, attributions: b.attribution, maxZoom: b.max_zoom, crossOrigin: 'anonymous' }));
    };

    const maskSource = new VectorSource();
    const maskLayer = new VectorLayer({ source: maskSource, zIndex: 2, style: () => new Style({ fill: new Fill({ color: alpha(css('--surface') || '#ffffff', 0.72) }) }) });

    const areaSource = new VectorSource();
    const areaLayer = new VectorLayer({ source: areaSource, zIndex: 3, declutter: true, style: (f, res) => areaStyle(f, res) });

    const contextSource = new VectorSource(); // district outlines around the one being looked at
    const contextLayer = new VectorLayer({
        source: contextSource, zIndex: 4,
        style: () => new Style({ stroke: new Stroke({ color: alpha(css('--text-3') || '#75746f', 0.6), width: 1, lineDash: [4, 3] }) }),
    });
    const focusSource = new VectorSource(); // outline of the drilled area
    const focusLayer = new VectorLayer({
        source: focusSource, zIndex: 5,
        style: () => [
            new Style({ stroke: new Stroke({ color: css('--surface') || '#fff', width: 5 }) }),
            new Style({ stroke: new Stroke({ color: css('--accent') || '#1c5cab', width: 2.25 }) }),
        ],
    });
    const stateSource = new VectorSource();
    const stateLayer = new VectorLayer({
        source: stateSource, zIndex: 6,
        style: () => new Style({ stroke: new Stroke({ color: css('--accent') || '#1c5cab', width: 1.75 }) }),
    });
    const workSource = new VectorSource();
    const workLayer = new VectorLayer({ source: workSource, zIndex: 7, style: (f) => workStyle(f) });

    const map = new Map({
        target: el,
        layers: [baseLayer, maskLayer, areaLayer, contextLayer, focusLayer, stateLayer, workLayer],
        controls: defaultControls({ zoom: false, rotate: false, attributionOptions: { collapsible: true } }),
        interactions: defaultInteractions({ mouseWheelZoom: false }).extend([new MouseWheelZoom({ condition: platformModifierKeyOnly })]),
        view: new View({ center: fromLonLat(cfg.center), zoom: cfg.zoom, minZoom: 5, maxZoom: 16 }),
    });
    setBase();

    // ---- Styles -------------------------------------------------------------------
    const m = () => METRICS[metric];
    let bounds = PCT_BOUNDS;

    function areaStyle(f, resolution) {
        const a = byId.get(f.get('id'));
        const hot = f.get('id') === hoverId;
        const v = a ? m().value(a) : null;
        const fill = v === null || v === undefined
            ? alpha(css('--text-3') || '#75746f', 0.08)
            : alpha(RAMPS[m().ramp][binOf(v, bounds)], hot ? 0.95 : 0.8);
        const styles = [new Style({
            fill: new Fill({ color: fill }),
            stroke: new Stroke({ color: hot ? css('--text') || '#1a1a19' : css('--surface') || '#fff', width: hot ? 2.5 : 1 }),
            zIndex: hot ? 2 : 1,
        })];
        // Name and value inside the area once it is big enough on screen.
        const ext = f.getGeometry().getExtent();
        const px = Math.min(ext[2] - ext[0], ext[3] - ext[1]) / resolution;
        if (px > 46) {
            styles.push(new Style({
                text: new Text({
                    text: a ? `${f.get('name')}\n${m().fmt(v)}` : f.get('name'),
                    font: `600 ${px > 110 ? 12 : 11}px 'Noto Sans', 'Noto Sans Bengali', system-ui, sans-serif`,
                    fill: new Fill({ color: css('--text') || '#1a1a19' }),
                    stroke: new Stroke({ color: css('--surface') || '#fff', width: 3 }),
                    overflow: false,
                }),
                zIndex: 3,
            }));
        }
        return styles;
    }

    function workStyle(f) {
        const hot = f.get('id') === hoverId;
        return new Style({
            image: new CircleStyle({
                radius: hot ? 9 : 6.5,
                fill: new Fill({ color: STATUS_COLOR[f.get('display')] ?? STATUS_COLOR.not_started }),
                stroke: new Stroke({ color: css('--surface') || '#fff', width: 2 }),
            }),
            zIndex: hot ? 2 : 1,
        });
    }

    // ---- Data → map ------------------------------------------------------------------
    const levelShapes = () => {
        if (stats.level === 'district') return shapes.district;
        if (stats.level === 'co_district') return shapes.co_district.filter((f) => f.get('parent_id') === stats.district);
        return [];
    };
    const focusShape = () => {
        if (stats.level === 'co_district') return shapes.district.find((f) => f.get('id') === stats.district);
        if (stats.level === 'works') {
            return shapes.co_district.find((f) => f.get('id') === stats.coDistrict)
                ?? shapes.district.find((f) => f.get('id') === stats.district);
        }
        return null;
    };

    function apply(fit) {
        byId = new globalThis.Map(stats.areas.map((a) => [a.id, a]));
        bounds = m().bounds(stats.areas);
        hoverId = null;

        areaSource.clear(true);
        areaSource.addFeatures(levelShapes());
        contextSource.clear(true);
        if (stats.level !== 'district') contextSource.addFeatures(shapes.district.filter((f) => f.get('id') !== stats.district));
        focusSource.clear(true);
        const focus = focusShape();
        if (focus) focusSource.addFeature(focus);

        workSource.clear(true);
        workSource.addFeatures(stats.works.map((w) => {
            const f = new Feature(new Point(fromLonLat([w.lng, w.lat])));
            f.setProperties(w);
            return f;
        }));

        const missing = stats.level !== 'works' && stats.areas.length > 0 && levelShapes().length === 0;
        ui.note.textContent = missing ? L.noShapes : '';
        ui.note.classList.toggle('hidden', !missing);

        if (fit) fitView(fit === 'instant' ? 0 : 650);
        renderAll();
    }

    function fitView(duration = 650) {
        const extent = createEmpty();
        if (stats.level === 'district' && stateFeature) extendExtent(extent, stateFeature.getGeometry().getExtent());
        else if (stats.level === 'works' && workSource.getFeatures().length) workSource.getFeatures().forEach((f) => extendExtent(extent, f.getGeometry().getExtent()));
        else if (focusShape()) extendExtent(extent, focusShape().getGeometry().getExtent());
        else areaSource.getFeatures().forEach((f) => extendExtent(extent, f.getGeometry().getExtent()));
        if (isEmpty(extent)) return;
        const side = window.innerWidth < 640 ? 24 : 48;
        map.getView().fit(extent, { padding: [56, side + 24, 72, side], maxZoom: 14, duration: reduceMotion() ? 0 : duration });
    }

    // ---- Drill (calls the Livewire component; the table and KPIs update with it) -------
    const wire = () => {
        const root = el.closest('[wire\\:id]');
        return root && window.Livewire ? window.Livewire.find(root.getAttribute('wire:id')) : null;
    };
    function open(id) {
        if (stats.level === 'district') wire()?.drill(id);
        else if (stats.level === 'co_district') wire()?.drill(stats.district, String(id));
        else {
            const url = cfg.workUrl.replace('__ID__', id);
            if (window.Livewire?.navigate) window.Livewire.navigate(url); else window.location.href = url;
        }
    }
    function up() {
        if (stats.level === 'works') wire()?.drill(stats.district);
        else if (stats.level === 'co_district') wire()?.drill();
    }

    // ---- Panels ----------------------------------------------------------------------
    const items = () => (stats.level === 'works'
        ? stats.works.map((w) => ({ id: w.id, name: w.name, sub: w.code, value: w.physical_pct, display: w.display }))
        : stats.areas.map((a) => ({ id: a.id, name: a.name, sub: `${nf0.format(a.works)} ${L.works.toLowerCase()}`, value: m().value(a) })));

    function renderMetrics() {
        ui.metrics.innerHTML = Object.keys(METRICS).map((k) => `
            <button type="button" role="radio" aria-checked="${k === metric}" data-metric="${k}" title="${esc(L.metricHint[k])}"
                class="rounded-md px-2.5 py-1 text-xs font-medium transition-colors ${k === metric ? 'bg-surface text-ink shadow-sm' : 'text-ink-2 hover:text-ink'}">${esc(L.metrics[k])}</button>`).join('');
        ui.metrics.toggleAttribute('hidden', stats.level === 'works');
    }

    function renderCrumbs() {
        const parts = [`<button type="button" data-crumb="state" class="font-medium text-accent hover:underline">${esc(L.assam)}</button>`];
        const district = shapes.district.find((f) => f.get('id') === stats.district);
        if (stats.district) {
            parts.push(stats.level === 'works'
                ? `<button type="button" data-crumb="district" class="font-medium text-accent hover:underline">${esc(district?.get('name') ?? '…')}</button>`
                : `<span class="font-medium text-ink">${esc(district?.get('name') ?? '…')}</span>`);
        }
        if (stats.level === 'works') {
            const co = shapes.co_district.find((f) => f.get('id') === stats.coDistrict);
            parts.push(`<span class="font-medium text-ink">${esc(co?.get('name') ?? '—')}</span>`);
        }
        ui.crumbs.innerHTML = parts.join('<span class="text-ink-3" aria-hidden="true">/</span>');
    }

    const icon = (d) => `<svg aria-hidden="true" viewBox="0 0 24 24" class="size-[18px]" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">${d}</svg>`;
    function renderTools() {
        ui.tools.innerHTML = `
            <button type="button" data-tool="in" class="map-icon-btn" aria-label="${esc(L.zoomIn)}" title="${esc(L.zoomIn)}">${icon('<path d="M12 5v14M5 12h14"/>')}</button>
            <button type="button" data-tool="out" class="map-icon-btn" aria-label="${esc(L.zoomOut)}" title="${esc(L.zoomOut)}">${icon('<path d="M5 12h14"/>')}</button>
            <button type="button" data-tool="fit" class="map-icon-btn" aria-label="${esc(L.reset)}" title="${esc(L.reset)}">${icon('<path d="M4 9V4h5M20 9V4h-5M4 15v5h5M20 15v5h-5"/>')}</button>
            ${stats.level !== 'district' ? `<button type="button" data-tool="up" class="map-icon-btn" aria-label="${esc(L.up)}" title="${esc(L.up)}">${icon('<path d="M12 19V5M5 12l7-7 7 7"/>')}</button>` : ''}`;
    }

    function renderLegend() {
        if (stats.level === 'works') {
            const present = [...new Set(stats.works.map((w) => w.display))];
            ui.legend.innerHTML = `<div class="flex flex-wrap gap-x-3 gap-y-1">${present.map((s) => `
                <span class="inline-flex items-center gap-1.5 text-ink-2"><span class="inline-block size-2.5 rounded-full" style="background:${STATUS_COLOR[s]}"></span>${esc(L.status[s])}</span>`).join('')}</div>`;
            ui.legend.classList.toggle('hidden', present.length === 0);
            return;
        }
        ui.legend.classList.remove('hidden');
        const ramp = RAMPS[m().ramp];
        ui.legend.innerHTML = `
            <div class="mb-1 font-medium text-ink-2">${esc(L.metricHint[metric])}</div>
            <div class="flex items-end gap-0.5">
                ${ramp.map((c, i) => `<div class="text-center"><span class="block h-2.5 w-11 first:rounded-l" style="background:${c}"></span><span class="mt-0.5 block whitespace-nowrap tabular-nums text-ink-3">${esc(boundLabel(bounds, i, m().fmt))}</span></div>`).join('')}
                <div class="ml-2 text-center"><span class="block h-2.5 w-8 rounded border border-line" style="background:${alpha('#75746f', 0.08)}"></span><span class="mt-0.5 block whitespace-nowrap text-ink-3">${esc(L.noData)}</span></div>
            </div>`;
    }

    function detailCard(id) {
        if (stats.level === 'works') {
            const w = stats.works.find((x) => x.id === id);
            if (!w) return '';
            return `
                <div class="text-xs text-ink-3">${esc(w.code)}</div>
                <div class="font-semibold text-ink">${esc(w.name)}</div>
                <div class="mt-1 inline-flex items-center gap-1.5 text-xs text-ink-2"><span class="inline-block size-2 rounded-full" style="background:${STATUS_COLOR[w.display]}"></span>${esc(L.status[w.display])}</div>
                ${bars(w.physical_pct, w.financial_pct)}
                <div class="mt-2 text-xs text-ink-3">${esc(L.clickToOpen)}</div>`;
        }
        const a = byId.get(id);
        const f = areaSource.getFeatures().find((x) => x.get('id') === id);
        if (!a) return f ? `<div class="font-semibold text-ink">${esc(f.get('name'))}</div><div class="mt-1 text-xs text-ink-3">${esc(L.noData)}</div>` : '';
        const v = m().value(a);
        return `
            <div class="flex items-baseline justify-between gap-2">
                <div class="font-semibold text-ink">${esc(a.name)}</div>
                <div class="text-lg font-semibold tabular-nums text-ink">${esc(m().fmt(v))}</div>
            </div>
            <div class="text-[11px] text-ink-3">${esc(L.metricHint[metric])}</div>
            ${bars(a.physical_pct, a.financial_pct)}
            <dl class="mt-2 grid grid-cols-2 gap-x-3 gap-y-1 text-xs">
                <dt class="text-ink-3">${esc(L.works)}</dt><dd class="text-right font-medium tabular-nums text-ink">${nf0.format(a.works)}</dd>
                <dt class="text-ink-3">${esc(L.sanctioned)}</dt><dd class="text-right font-medium tabular-nums text-ink">${money(a.sanctioned)}</dd>
                <dt class="text-ink-3">${esc(L.delayed)}</dt><dd class="text-right font-medium tabular-nums ${a.delayed ? 'text-warning' : 'text-ink'}">${a.delayed}</dd>
                <dt class="text-ink-3">${esc(L.stalled)}</dt><dd class="text-right font-medium tabular-nums ${a.stalled ? 'text-critical' : 'text-ink'}">${a.stalled}</dd>
                <dt class="text-ink-3">${esc(L.stale)}</dt><dd class="text-right font-medium tabular-nums ${a.stale ? 'text-serious' : 'text-ink'}">${a.stale}</dd>
            </dl>
            <div class="mt-2 text-xs text-ink-3">${esc(L.clickToDrill)}</div>`;
    }
    const bars = (phys, fin) => `
        <div class="mt-2 space-y-1.5">
            ${[[L.physical, phys, '#2a78d6'], [L.financial, fin, '#1c5cab']].map(([label, v, c]) => `
            <div>
                <div class="flex justify-between text-[11px]"><span class="text-ink-3">${esc(label)}</span><span class="font-medium tabular-nums text-ink">${pct(v)}</span></div>
                <div class="mt-0.5 h-1.5 overflow-hidden rounded-full bg-surface-2"><div class="h-full rounded-full" style="width:${Math.min(100, Math.max(0, v ?? 0))}%;background:${c}"></div></div>
            </div>`).join('')}
        </div>`;

    function renderSide() {
        const list = items().sort((x, y) => (y.value ?? -1) - (x.value ?? -1));
        const max = Math.max(1, ...list.map((i) => i.value ?? 0));
        const ramp = RAMPS[m().ramp];
        const head = stats.level === 'works'
            ? `${esc(L.physical)}`
            : `${esc(L.ranked)} ${esc(L.metrics[metric].toLowerCase())}`;
        const located = stats.level === 'works' ? `<div class="text-[11px] text-ink-3">${esc(L.worksOnMap.replace('__N__', stats.works.length).replace('__T__', stats.worksTotal ?? stats.works.length))}</div>` : '';
        ui.side.innerHTML = `
            <div id="dash-map-detail" class="min-h-[8.5rem] border-b border-line p-4">${hoverId !== null ? detailCard(hoverId) : `<div class="text-sm text-ink-2">${esc(L.hoverHint)}</div>`}</div>
            <div class="flex items-center justify-between px-4 pb-1 pt-3"><span class="text-xs font-semibold uppercase tracking-wide text-ink-3">${head}</span></div>
            ${located ? `<div class="px-4">${located}</div>` : ''}
            <ol class="min-h-0 flex-1 overflow-y-auto px-2 pb-2">
                ${list.length ? list.map((i, n) => `
                <li>
                    <button type="button" data-item="${i.id}" class="group flex w-full items-center gap-2 rounded-lg px-2 py-1.5 text-left hover:bg-surface-2 ${i.id === hoverId ? 'bg-surface-2' : ''}">
                        <span class="w-5 shrink-0 text-right text-[11px] tabular-nums text-ink-3">${n + 1}</span>
                        <span class="min-w-0 flex-1">
                            <span class="flex items-baseline justify-between gap-2">
                                <span class="truncate text-sm text-ink group-hover:text-accent">${i.display ? `<span class="mr-1 inline-block size-2 rounded-full align-middle" style="background:${STATUS_COLOR[i.display]}"></span>` : ''}${esc(i.name)}</span>
                                <span class="shrink-0 text-xs font-semibold tabular-nums text-ink">${esc(stats.level === 'works' ? pct(i.value) : m().fmt(i.value))}</span>
                            </span>
                            <span class="mt-0.5 block h-1 overflow-hidden rounded-full bg-surface-2"><span class="block h-full rounded-full" style="width:${((i.value ?? 0) / max) * 100}%;background:${stats.level === 'works' ? '#2a78d6' : ramp[Math.max(0, binOf(i.value ?? 0, bounds))]}"></span></span>
                        </span>
                    </button>
                </li>`).join('') : `<li class="px-2 py-6 text-center text-sm text-ink-3">${esc(L.noData)}</li>`}
            </ol>
            <a href="${esc(cfg.fullMapUrl)}" wire:navigate class="border-t border-line px-4 py-2.5 text-sm text-accent hover:underline">${esc(L.openMap)} →</a>`;
    }

    function setHover(id, fromList = false) {
        if (hoverId === id) return;
        hoverId = id;
        areaLayer.changed();
        workLayer.changed();
        const detail = document.getElementById('dash-map-detail');
        if (detail) detail.innerHTML = id !== null ? detailCard(id) : `<div class="text-sm text-ink-2">${esc(L.hoverHint)}</div>`;
        ui.side.querySelectorAll('[data-item]').forEach((b) => b.classList.toggle('bg-surface-2', Number(b.dataset.item) === id));
        if (!fromList) ui.side.querySelector(`[data-item="${id}"]`)?.scrollIntoView({ block: 'nearest' });
    }

    function renderAll() {
        renderMetrics();
        renderCrumbs();
        renderTools();
        renderLegend();
        renderSide();
        areaLayer.changed();
    }

    // ---- Events ------------------------------------------------------------------------
    ui.metrics.addEventListener('click', (e) => {
        const b = e.target.closest('[data-metric]');
        if (!b || b.dataset.metric === metric) return;
        metric = b.dataset.metric;
        store.set('dcp-dash-metric', metric);
        bounds = m().bounds(stats.areas);
        renderAll();
    });
    ui.metrics.addEventListener('keydown', (e) => {
        if (!['ArrowLeft', 'ArrowRight'].includes(e.key)) return;
        const keys = Object.keys(METRICS);
        const next = keys[(keys.indexOf(metric) + (e.key === 'ArrowRight' ? 1 : keys.length - 1)) % keys.length];
        ui.metrics.querySelector(`[data-metric="${next}"]`)?.click();
        ui.metrics.querySelector(`[data-metric="${next}"]`)?.focus();
    });
    ui.crumbs.addEventListener('click', (e) => {
        const b = e.target.closest('[data-crumb]');
        if (b?.dataset.crumb === 'state') wire()?.drill();
        if (b?.dataset.crumb === 'district') wire()?.drill(stats.district);
    });
    ui.tools.addEventListener('click', (e) => {
        const b = e.target.closest('[data-tool]');
        if (!b) return;
        const view = map.getView();
        if (b.dataset.tool === 'in') view.animate({ zoom: view.getZoom() + 1, duration: 200 });
        if (b.dataset.tool === 'out') view.animate({ zoom: view.getZoom() - 1, duration: 200 });
        if (b.dataset.tool === 'fit') fitView();
        if (b.dataset.tool === 'up') up();
    });
    ui.side.addEventListener('mouseover', (e) => {
        const b = e.target.closest('[data-item]');
        if (b) setHover(Number(b.dataset.item), true);
    });
    ui.side.addEventListener('focusin', (e) => {
        const b = e.target.closest('[data-item]');
        if (b) setHover(Number(b.dataset.item), true);
    });
    ui.side.addEventListener('click', (e) => {
        const b = e.target.closest('[data-item]');
        if (b) open(Number(b.dataset.item));
    });

    function tip(px, html) {
        ui.tip.innerHTML = html;
        const w = el.clientWidth;
        ui.tip.style.left = `${Math.min(px[0] + 14, w - 200)}px`;
        ui.tip.style.top = `${Math.max(8, px[1] - 10)}px`;
        ui.tip.classList.remove('hidden');
    }
    map.on('pointermove', (evt) => {
        if (evt.dragging) return;
        let hit = null;
        map.forEachFeatureAtPixel(evt.pixel, (f) => { hit = f; return true; }, { hitTolerance: 4, layerFilter: (l) => l === areaLayer || l === workLayer });
        const id = hit ? hit.get('id') : null;
        setHover(id);
        el.style.cursor = hit ? 'pointer' : '';
        if (!hit) { ui.tip.classList.add('hidden'); return; }
        if (stats.level === 'works') {
            tip(evt.pixel, `<div class="font-semibold text-ink">${esc(hit.get('code'))}</div><div class="text-ink-2">${esc(hit.get('name'))}</div>
                <div class="mt-1 tabular-nums text-ink-2">${esc(L.physical)} ${pct(hit.get('physical_pct'))} · ${esc(L.financial)} ${pct(hit.get('financial_pct'))}</div>`);
            return;
        }
        const a = byId.get(id);
        tip(evt.pixel, a
            ? `<div class="font-semibold text-ink">${esc(a.name)}</div>
               <div class="tabular-nums text-ink-2">${esc(L.metrics[metric])}: <strong class="text-ink">${esc(m().fmt(m().value(a)))}</strong></div>
               <div class="tabular-nums text-ink-3">${nf0.format(a.works)} ${esc(L.works.toLowerCase())} · ${money(a.sanctioned)}</div>`
            : `<div class="font-semibold text-ink">${esc(hit.get('name'))}</div><div class="text-ink-3">${esc(L.noData)}</div>`);
    });
    map.on('click', (evt) => {
        let hit = null;
        map.forEachFeatureAtPixel(evt.pixel, (f) => { hit = f; return true; }, { hitTolerance: 4, layerFilter: (l) => l === areaLayer || l === workLayer });
        if (hit && (stats.level === 'works' || byId.has(hit.get('id')))) open(hit.get('id'));
    });
    map.getViewport().addEventListener('mouseleave', () => { ui.tip.classList.add('hidden'); setHover(null); });

    // Plain scroll keeps scrolling the page; Ctrl + scroll zooms. Say so once per scroll burst.
    let noteTimer = null;
    map.getViewport().addEventListener('wheel', (e) => {
        if (e.ctrlKey || e.metaKey || !ui.note.classList.contains('hidden') && ui.note.textContent !== L.ctrlZoom) return;
        ui.note.textContent = L.ctrlZoom;
        ui.note.classList.remove('hidden');
        clearTimeout(noteTimer);
        noteTimer = setTimeout(() => { if (ui.note.textContent === L.ctrlZoom) ui.note.classList.add('hidden'); }, 1300);
    }, { passive: true });

    new MutationObserver(() => { setBase(); renderAll(); maskLayer.changed(); focusLayer.changed(); stateLayer.changed(); })
        .observe(document.documentElement, { attributes: true, attributeFilter: ['data-theme'] });

    // ---- Load shapes once, then draw ----------------------------------------------------
    const read = (data) => new GeoJSON().readFeatures(data, { featureProjection: 'EPSG:3857' });
    const get = (url) => fetch(url, { headers: { Accept: 'application/geo+json' } }).then((r) => (r.ok ? r.json() : null)).catch(() => null);

    renderAll();
    Promise.all([
        get(cfg.stateUrl),
        get(cfg.bordersUrl.replace('__LEVEL__', 'district')),
        get(cfg.bordersUrl.replace('__LEVEL__', 'co_district')),
    ]).then(([state, districts, coDistricts]) => {
        if (state) {
            stateFeature = read(state)[0] ?? null;
            if (stateFeature) {
                stateSource.addFeature(stateFeature);
                const world = fromExtent([-20037508.34, -20037508.34, 20037508.34, 20037508.34]);
                const g = stateFeature.getGeometry();
                (g.getType() === 'MultiPolygon' ? g.getPolygons() : [g])
                    .forEach((p) => world.appendLinearRing(new LinearRing(p.getLinearRing(0).getCoordinates())));
                maskSource.addFeature(new Feature(world));
            }
        }
        if (districts) shapes.district = read(districts);
        if (coDistricts) shapes.co_district = read(coDistricts);
        apply('instant');
    });

    current = {
        el,
        update(next) {
            const moved = next.level !== stats.level || next.district !== stats.district || next.coDistrict !== stats.coDistrict;
            stats = next;
            apply(moved);
        },
    };
}

function hook() {
    if (window.__dcpDashMapHooked) return;
    window.__dcpDashMapHooked = true;
    window.Livewire.on('dashboard-map', (payload) => {
        if (current && document.body.contains(current.el)) current.update(payload);
    });
}

if (window.Livewire) hook(); else document.addEventListener('livewire:init', hook);
if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
document.addEventListener('livewire:navigated', init);
