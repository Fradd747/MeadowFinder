const czechBounds = L.latLngBounds(
  L.latLng(48.45, 12.0),
  L.latLng(51.1, 18.95),
);
const POLYGON_ZOOM_THRESHOLD = 14;

const map = L.map("map", {
  preferCanvas: true,
  maxBounds: czechBounds.pad(0.2),
}).fitBounds(czechBounds);

map.createPane("cadastralPane");
map.getPane("cadastralPane").style.zIndex = "450";
map.getPane("cadastralPane").style.pointerEvents = "none";

const streetLayer = L.tileLayer("https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png", {
  attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
  maxZoom: 19,
});

const satelliteLayer = L.tileLayer(
  "https://ags.cuzk.gov.cz/arcgis1/rest/services/ORTOFOTO_WM/MapServer/tile/{z}/{y}/{x}",
  {
    attribution:
      "Tiles &copy; Esri, Maxar, Earthstar Geographics, and the GIS User Community",
    maxNativeZoom: 20,
    maxZoom: 21,
  },
);

const touristCycleLayer = L.tileLayer("https://tile.mtbmap.cz/mtbmap_tiles/{z}/{x}/{y}.png", {
  attribution:
    '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
  maxZoom: 18,
});

const cadastralLayer = L.tileLayer.wms("https://services.cuzk.cz/wms/wms.asp", {
  layers: "prehledky,KN_I",
  styles: "",
  format: "image/png",
  transparent: true,
  version: "1.3.0",
  crs: L.CRS.EPSG3857,
  uppercase: false,
  maxZoom: 22,
  pane: "cadastralPane",
});
const cadastralBasemapLayer = L.layerGroup([satelliteLayer, cadastralLayer]);

const basemapLayers = {
  street: streetLayer,
  satellite: satelliteLayer,
  cadastral: cadastralBasemapLayer,
  touristCycle: touristCycleLayer,
};
const basemapOptions = {
  street: { label: "Běžná" },
  satellite: { label: "Satelitní" },
  cadastral: { label: "Katastrální" },
  touristCycle: { label: "Turistická + Cyklo" },
};
let activeBasemap = "street";
let basemapButtons = [];
let basemapSummaryLabel = null;

streetLayer.addTo(map);

function updateBasemapButtons() {
  basemapButtons.forEach((button) => {
    const isActive = button.dataset.layer === activeBasemap;
    button.classList.toggle("is-active", isActive);
    button.setAttribute("aria-pressed", String(isActive));
  });

  if (basemapSummaryLabel) {
    basemapSummaryLabel.textContent = basemapOptions[activeBasemap].label;
  }
}

function setBasemap(layerName) {
  if (layerName === activeBasemap || !basemapLayers[layerName]) {
    return;
  }

  map.removeLayer(basemapLayers[activeBasemap]);
  basemapLayers[layerName].addTo(map);
  activeBasemap = layerName;
  updateBasemapButtons();
}

const BasemapControl = L.Control.extend({
  onAdd() {
    const container = L.DomUtil.create("div", "basemap-control");
    const title = L.DomUtil.create("div", "basemap-control-title", container);
    title.textContent = "Vrstvy";

    const summary = L.DomUtil.create("div", "basemap-control-summary", container);
    basemapSummaryLabel = L.DomUtil.create("span", "basemap-control-summary-label", summary);

    const buttonRow = L.DomUtil.create("div", "basemap-control-buttons", container);
    const options = Object.entries(basemapOptions).map(([key, value]) => ({ key, ...value }));

    basemapButtons = options.map((option) => {
      const button = L.DomUtil.create("button", "basemap-button", buttonRow);
      button.type = "button";
      button.dataset.layer = option.key;
      button.innerHTML = `
        <span class="basemap-button-label">${option.label}</span>
      `;
      button.addEventListener("click", () => {
        setBasemap(option.key);
        button.blur();
      });
      return button;
    });

    L.DomEvent.disableClickPropagation(container);
    L.DomEvent.disableScrollPropagation(container);
    updateBasemapButtons();
    return container;
  },
});

new BasemapControl({ position: "topright" }).addTo(map);

const filtersForm = document.getElementById("filters");
const resetButton = document.getElementById("resetFilters");
const countText = document.getElementById("countText");
const selection = document.getElementById("selection");
const emptySelectionHtml = "<strong>Klikněte na louku</strong><p>Zde se zobrazí podrobnosti o parcele.</p>";
const formatSliderArea = (value) => `${Number(value).toLocaleString("cs-CZ")} m²`;
const formatSliderDistance = (value) => `${Number(value).toLocaleString("cs-CZ")} m`;
const rangeSliderConfig = {
  area: {
    sliderId: "areaRange",
    minInputId: "minArea",
    maxInputId: "maxArea",
    outputId: "areaRangeValue",
    defaultMinValue: 1000,
    defaultMaxValue: 50000,
    format: formatSliderArea,
  },
  road: {
    sliderId: "roadRange",
    minInputId: "minRoad",
    maxInputId: "maxRoad",
    outputId: "roadRangeValue",
    defaultMinValue: 0,
    defaultMaxValue: 5000,
    format: formatSliderDistance,
  },
  path: {
    sliderId: "pathRange",
    minInputId: "minPath",
    maxInputId: "maxPath",
    outputId: "pathRangeValue",
    defaultMinValue: 0,
    defaultMaxValue: 5000,
    format: formatSliderDistance,
  },
  water: {
    sliderId: "waterRange",
    minInputId: "minWater",
    maxInputId: "maxWater",
    outputId: "waterRangeValue",
    defaultMinValue: 0,
    defaultMaxValue: 5000,
    format: formatSliderDistance,
  },
  river: {
    sliderId: "riverRange",
    minInputId: "minRiver",
    maxInputId: "maxRiver",
    outputId: "riverRangeValue",
    defaultMinValue: 0,
    defaultMaxValue: 5000,
    format: formatSliderDistance,
  },
  settlement: {
    sliderId: "settlementRange",
    minInputId: "minSettlement",
    maxInputId: "maxSettlement",
    outputId: "settlementRangeValue",
    defaultMinValue: 0,
    defaultMaxValue: 5000,
    format: formatSliderDistance,
  },
};
const sliderConfig = {
  minArea: {
    defaultValue: 1000,
    format: formatSliderArea,
  },
  maxArea: {
    defaultValue: 50000,
    isUnlimitedAtMax: true,
    format: (value, input) => (Number(value) >= Number(input.max) ? "Bez limitu" : formatSliderArea(value)),
  },
  minRoad: {
    defaultValue: 0,
    format: formatSliderDistance,
  },
  maxRoad: {
    defaultValue: 5000,
    isUnlimitedAtMax: true,
    format: (value, input) => (Number(value) >= Number(input.max) ? "Bez limitu" : formatSliderDistance(value)),
  },
  minPath: {
    defaultValue: 0,
    format: formatSliderDistance,
  },
  maxPath: {
    defaultValue: 5000,
    isUnlimitedAtMax: true,
    format: (value, input) => (Number(value) >= Number(input.max) ? "Bez limitu" : formatSliderDistance(value)),
  },
  minWater: {
    defaultValue: 0,
    format: formatSliderDistance,
  },
  maxWater: {
    defaultValue: 5000,
    isUnlimitedAtMax: true,
    format: (value, input) => (Number(value) >= Number(input.max) ? "Bez limitu" : formatSliderDistance(value)),
  },
  minRiver: {
    defaultValue: 0,
    format: formatSliderDistance,
  },
  maxRiver: {
    defaultValue: 5000,
    isUnlimitedAtMax: true,
    format: (value, input) => (Number(value) >= Number(input.max) ? "Bez limitu" : formatSliderDistance(value)),
  },
  minSettlement: {
    defaultValue: 0,
    format: formatSliderDistance,
  },
  maxSettlement: {
    defaultValue: 5000,
    isUnlimitedAtMax: true,
    format: (value, input) => (Number(value) >= Number(input.max) ? "Bez limitu" : formatSliderDistance(value)),
  },
};
const rangeSliderIds = Object.keys(rangeSliderConfig);

const meadowLayer = L.geoJSON([], {
  style: {
    color: "#1f7a4c",
    weight: 1.5,
    fillColor: "#4fd18b",
    fillOpacity: 0.45,
  },
  onEachFeature(feature, layer) {
    layer.on("click", () => showSelection(feature.properties));
  },
});
const overviewLayer = L.layerGroup().addTo(map);
let activeRequestController = null;

function debounce(callback, delayMs) {
  let timeoutId = null;
  return (...args) => {
    window.clearTimeout(timeoutId);
    timeoutId = window.setTimeout(() => callback(...args), delayMs);
  };
}

function sliderValue(id) {
  const input = document.getElementById(id);
  const config = sliderConfig[id];
  const value = input.value.trim();

  if (value === "") {
    return null;
  }

  if (config.isUnlimitedAtMax && Number(value) >= Number(input.max)) {
    return null;
  }

  return value;
}

function rangeSliderValue(rangeId, bound) {
  const config = rangeSliderConfig[rangeId];
  const inputId = bound === "min" ? config.minInputId : config.maxInputId;
  return sliderValue(inputId);
}

function syncSliderValue(id) {
  const input = document.getElementById(id);
  const output = document.getElementById(`${id}Value`);
  if (output) {
    output.textContent = sliderConfig[id].format(input.value, input);
  }
}

function clampRangeSliderValues(rangeId, activeInputId = null) {
  const { minInputId, maxInputId } = rangeSliderConfig[rangeId];
  const minInput = document.getElementById(minInputId);
  const maxInput = document.getElementById(maxInputId);
  const minValue = Number(minInput.value);
  const maxValue = Number(maxInput.value);

  if (minValue <= maxValue) {
    return;
  }

  if (activeInputId === minInputId) {
    maxInput.value = minInput.value;
    return;
  }

  minInput.value = maxInput.value;
}

function setActiveRangeThumb(rangeId, activeInputId) {
  const { minInputId, maxInputId } = rangeSliderConfig[rangeId];
  [minInputId, maxInputId].forEach((inputId) => {
    document.getElementById(inputId).classList.toggle("is-active", inputId === activeInputId);
  });
}

function syncRangeSliderValue(rangeId) {
  const config = rangeSliderConfig[rangeId];
  const minInput = document.getElementById(config.minInputId);
  const maxInput = document.getElementById(config.maxInputId);
  const output = document.getElementById(config.outputId);
  const slider = document.getElementById(config.sliderId);
  const minBound = Number(minInput.min);
  const maxBound = Number(minInput.max);
  const span = maxBound - minBound || 1;
  const rangeStart = ((Number(minInput.value) - minBound) / span) * 100;
  const rangeEnd = ((Number(maxInput.value) - minBound) / span) * 100;

  slider.style.setProperty("--range-start", `${rangeStart}%`);
  slider.style.setProperty("--range-end", `${rangeEnd}%`);
  output.textContent = `${config.format(minInput.value, minInput)} - ${sliderConfig[config.maxInputId].format(maxInput.value, maxInput)}`;
}

function syncAllSliderValues() {
  rangeSliderIds.forEach((rangeId) => {
    clampRangeSliderValues(rangeId);
    syncRangeSliderValue(rangeId);
  });
}

function currentParams() {
  const bounds = map.getBounds();
  const params = new URLSearchParams({
    bbox: [
      bounds.getWest().toFixed(6),
      bounds.getSouth().toFixed(6),
      bounds.getEast().toFixed(6),
      bounds.getNorth().toFixed(6),
    ].join(","),
    mode: currentMode(),
    zoom: String(Math.round(map.getZoom())),
  });

  [
    ["minArea", rangeSliderValue("area", "min")],
    ["maxArea", rangeSliderValue("area", "max")],
    ["minRoad", rangeSliderValue("road", "min")],
    ["maxRoad", rangeSliderValue("road", "max")],
    ["minPath", rangeSliderValue("path", "min")],
    ["maxPath", rangeSliderValue("path", "max")],
    ["minWater", rangeSliderValue("water", "min")],
    ["maxWater", rangeSliderValue("water", "max")],
    ["minRiver", rangeSliderValue("river", "min")],
    ["maxRiver", rangeSliderValue("river", "max")],
    ["minSettlement", rangeSliderValue("settlement", "min")],
    ["maxSettlement", rangeSliderValue("settlement", "max")],
  ].forEach(([key, value]) => {
    if (value !== null) {
      params.set(key, value);
    }
  });

  return params;
}

function currentMode() {
  return map.getZoom() >= POLYGON_ZOOM_THRESHOLD ? "polygons" : "clusters";
}

function formatCount(value) {
  return Number(value).toLocaleString("cs-CZ");
}

function formatDistance(value) {
  if (value === null || value === undefined || Number.isNaN(Number(value))) {
    return "není k dispozici";
  }

  return `${Math.round(Number(value))} m`;
}

function formatArea(areaM2) {
  if (areaM2 === null || areaM2 === undefined || Number.isNaN(Number(areaM2))) {
    return "není k dispozici";
  }

  return `${Math.round(Number(areaM2)).toLocaleString("cs-CZ")} m²`;
}

function showSelection(properties) {
  if (properties.cluster_count !== undefined) {
    selection.innerHTML = `
      <strong>${formatCount(properties.cluster_count)} luk v této oblasti</strong>
      <p>Přibližte mapu pro načtení jednotlivých parcel.</p>
    `;
    return;
  }

  selection.innerHTML = `
    <strong>${properties.source_id}</strong>
    <p>Plocha: ${formatArea(properties.area_m2)}</p>
    <p>Vzdálenost od silnice: ${formatDistance(properties.nearest_road_m)}</p>
    <p>Vzdálenost od cesty: ${formatDistance(properties.nearest_path_m)}</p>
    <p>Vzdálenost od vody: ${formatDistance(properties.nearest_water_m)}</p>
    <p>Vzdálenost od větší řeky: ${formatDistance(properties.nearest_river_m)}</p>
    <p>Vzdálenost od vesnice/města: ${formatDistance(properties.nearest_settlement_m)}</p>
  `;
}

function clusterClassName(count) {
  let sizeClass = "meadow-cluster-small";
  if (count >= 50) {
    sizeClass = "meadow-cluster-large";
  } else if (count >= 10) {
    sizeClass = "meadow-cluster-medium";
  }
  return `meadow-cluster ${sizeClass}`;
}

function createOverviewMarker(feature) {
  const { centroid_lat: lat, centroid_lng: lng, cluster_count: count } = feature.properties;
  const marker = L.marker([lat, lng], {
    icon: L.divIcon({
      html: `<div><span>${formatCount(count)}</span></div>`,
      className: clusterClassName(count),
      iconSize: L.point(56, 56),
    }),
  });

  marker.on("click", () => {
    showSelection(feature.properties);
    map.flyTo([lat, lng], POLYGON_ZOOM_THRESHOLD);
  });

  return marker;
}

function redrawMeadows(featureCollection) {
  meadowLayer.clearLayers();
  overviewLayer.clearLayers();

  if (featureCollection.meta?.mode === "polygons") {
    meadowLayer.addData(featureCollection);
  } else {
    featureCollection.features.forEach((feature) => {
      overviewLayer.addLayer(createOverviewMarker(feature));
    });
  }

  syncVisibleLayer(featureCollection.meta?.mode || currentMode());
}

function syncVisibleLayer(mode = currentMode()) {
  const showPolygons = mode === "polygons";

  if (showPolygons) {
    if (map.hasLayer(overviewLayer)) {
      map.removeLayer(overviewLayer);
    }
    if (!map.hasLayer(meadowLayer)) {
      map.addLayer(meadowLayer);
    }
  } else {
    if (map.hasLayer(meadowLayer)) {
      map.removeLayer(meadowLayer);
    }
    if (!map.hasLayer(overviewLayer)) {
      map.addLayer(overviewLayer);
    }
  }
}

async function loadMeadows() {
  if (activeRequestController) {
    activeRequestController.abort();
  }

  const controller = new AbortController();
  activeRequestController = controller;

  try {
    const response = await fetch(`api/meadows.php?${currentParams().toString()}`, {
      headers: { Accept: "application/json" },
      signal: controller.signal,
    });

    if (!response.ok) {
      const body = await response.json().catch(() => ({ error: "Požadavek selhal" }));
      throw new Error(body.error || "Požadavek selhal");
    }

    return await response.json();
  } finally {
    if (activeRequestController === controller) {
      activeRequestController = null;
    }
  }
}

async function refreshMeadows() {
  try {
    const featureCollection = await loadMeadows();
    redrawMeadows(featureCollection);
    const totalCount = featureCollection.meta?.total_count ?? featureCollection.meta?.count ?? featureCollection.features.length;
    const visibleCount = featureCollection.meta?.count ?? featureCollection.features.length;
    countText.textContent = featureCollection.meta?.truncated
      ? `${formatCount(visibleCount)} / ${formatCount(totalCount)}`
      : formatCount(totalCount);
  } catch (error) {
    if (error.name === "AbortError") {
      return;
    }
    console.error(error);
  }
}

const debouncedRefresh = debounce(refreshMeadows, 300);
const debouncedFilterRefresh = debounce(refreshMeadows, 150);

rangeSliderIds.forEach((rangeId) => {
  const { minInputId, maxInputId } = rangeSliderConfig[rangeId];

  [minInputId, maxInputId].forEach((inputId) => {
    const input = document.getElementById(inputId);

    input.addEventListener("input", () => {
      clampRangeSliderValues(rangeId, inputId);
      setActiveRangeThumb(rangeId, inputId);
      syncRangeSliderValue(rangeId);
      debouncedFilterRefresh();
    });
    input.addEventListener("pointerdown", () => setActiveRangeThumb(rangeId, inputId));
    input.addEventListener("focus", () => setActiveRangeThumb(rangeId, inputId));
  });
});

resetButton.addEventListener("click", () => {
  filtersForm.reset();
  rangeSliderIds.forEach((rangeId) => {
    const config = rangeSliderConfig[rangeId];
    document.getElementById(config.minInputId).value = String(config.defaultMinValue);
    document.getElementById(config.maxInputId).value = String(config.defaultMaxValue);
    setActiveRangeThumb(rangeId, config.maxInputId);
  });
  syncAllSliderValues();
  selection.innerHTML = emptySelectionHtml;
  refreshMeadows();
});

map.on("zoomend", syncVisibleLayer);
map.on("moveend", debouncedRefresh);
rangeSliderIds.forEach((rangeId) => setActiveRangeThumb(rangeId, rangeSliderConfig[rangeId].maxInputId));
selection.innerHTML = emptySelectionHtml;
syncAllSliderValues();
refreshMeadows();
