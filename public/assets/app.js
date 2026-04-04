const czechBounds = L.latLngBounds(
  L.latLng(48.45, 12.0),
  L.latLng(51.1, 18.95),
);
const POLYGON_ZOOM_THRESHOLD = 14;
/** RUIAN parcel identify + Nahlížení do KN only from this zoom (inclusive). */
const CADASTRAL_KN_CLICK_MIN_ZOOM = 17;

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

/** MTB map tiles: at z18 some XYZ cells 404; load parent z17 tile and upscale the matching quadrant. */
const MtbMapTileLayer = L.TileLayer.extend({
  /** Leaflet's getTileUrl() always uses _getZoomForUrl(), ignoring coords.z — needed for z17 fallback URLs. */
  _tileUrlAtZoom(z, x, y) {
    const coords = { z, x, y };
    const data = {
      r: L.Browser.retina ? "@2x" : "",
      s: this._getSubdomain(coords),
      x,
      y,
      z,
    };
    if (this._map && !this._map.options.crs.infinite) {
      const invertedY = this._globalTileRange.max.y - y;
      if (this.options.tms) {
        data.y = invertedY;
      }
      data["-y"] = invertedY;
    }
    return L.Util.template(this._url, L.extend(data, this.options));
  },

  createTile(coords, done) {
    const size = this.getTileSize();
    const w = size.x;
    const h = size.y;
    const root = L.DomUtil.create("div", "leaflet-tile");
    root.style.width = `${w}px`;
    root.style.height = `${h}px`;
    root.style.overflow = "hidden";

    const markLoaded = () => {
      L.DomUtil.addClass(root, "leaflet-tile-loaded");
      done(undefined, root);
    };
    const markError = () => {
      done(new Error("tile load failed"), root);
    };

    const img = L.DomUtil.create("img");
    img.alt = "";
    img.setAttribute("role", "presentation");
    img.style.display = "block";
    img.style.width = `${w}px`;
    img.style.height = `${h}px`;

    img.onload = markLoaded;
    img.onerror = () => {
      if (coords.z !== 18) {
        markError();
        return;
      }
      const px = Math.floor(coords.x / 2);
      const py = Math.floor(coords.y / 2);
      const parentUrl = this._tileUrlAtZoom(17, px, py);
      if (img.parentNode === root) {
        root.removeChild(img);
      }
      const inner = L.DomUtil.create("img");
      inner.alt = "";
      inner.setAttribute("role", "presentation");
      inner.style.display = "block";
      inner.style.width = `${w * 2}px`;
      inner.style.height = `${h * 2}px`;
      inner.style.marginLeft = `${-(coords.x % 2) * w}px`;
      inner.style.marginTop = `${-(coords.y % 2) * h}px`;
      inner.onload = markLoaded;
      inner.onerror = markError;
      inner.src = parentUrl;
      root.appendChild(inner);
    };

    root.appendChild(img);
    img.src = this.getTileUrl(coords);
    return root;
  },
});

const touristCycleLayer = new MtbMapTileLayer("https://tile.mtbmap.cz/mtbmap_tiles/{z}/{x}/{y}.png", {
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
const mobileSidebarQuery = window.matchMedia("(max-width: 1100px)");
const phoneLayoutQuery = window.matchMedia("(max-width: 720px)");
let activeBasemap = "street";
let basemapButtons = [];
let basemapSummaryLabel = null;
let basemapControlContainer = null;
let basemapSummaryButton = null;

const RUIAN_IDENTIFY_URL =
  "https://ags.cuzk.cz/arcgis/rest/services/RUIAN/Prohlizeci_sluzba_nad_daty_RUIAN/MapServer/identify";
const RUIAN_PARCELA_LAYER_ID = 5;
const NAHLIZENI_PARCELA_PAGE = "https://nahlizenidokn.cuzk.gov.cz/ZobrazObjekt.aspx";
const RUIAN_PARCEL_UNIQUE_ID_FIELD = "Jednoznačný identifikátor parcely";
const KN_VIEWER_POPUP_NAME = "mfdKnParcel";

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

function syncBasemapControlState() {
  if (!basemapControlContainer || !basemapSummaryButton) {
    return;
  }

  const expanded = isPhoneLayoutViewport() && document.body.classList.contains("mobile-layers-open");
  basemapControlContainer.classList.toggle("is-open", expanded);
  basemapSummaryButton.setAttribute("aria-expanded", String(expanded));
}

function setBasemap(layerName) {
  if (layerName === activeBasemap || !basemapLayers[layerName]) {
    return;
  }

  map.removeLayer(basemapLayers[activeBasemap]);
  basemapLayers[layerName].addTo(map);
  activeBasemap = layerName;
  updateBasemapButtons();
  syncCadastralKnPointerCursor();
}

function syncCadastralKnPointerCursor() {
  const showPointer =
    activeBasemap === "cadastral" && map.getZoom() >= CADASTRAL_KN_CLICK_MIN_ZOOM;
  map.getContainer().classList.toggle("cadastral-kn-pointer", showPointer);
}

const BasemapControl = L.Control.extend({
  onAdd() {
    const root = L.DomUtil.create("div", "basemap-control-stack");
    const container = L.DomUtil.create("div", "basemap-control", root);
    basemapControlContainer = container;
    const title = L.DomUtil.create("div", "basemap-control-title", container);
    title.textContent = "Vrstvy";

    const summary = L.DomUtil.create("button", "basemap-control-summary", container);
    summary.type = "button";
    summary.setAttribute("aria-label", "Vybrat vrstvu mapy");
    summary.setAttribute("aria-expanded", "false");
    basemapSummaryButton = summary;
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
        closeMobileMapPanels();
        syncBasemapControlState();
        button.blur();
      });
      return button;
    });

    const statsBox = L.DomUtil.create("div", "basemap-stats-box", root);
    const statsDl = L.DomUtil.create("dl", "stats", statsBox);
    const statsRow = L.DomUtil.create("div", "", statsDl);
    const statsDt = L.DomUtil.create("dt", "", statsRow);
    statsDt.textContent = "Zobrazené louky";
    const statsDd = L.DomUtil.create("dd", "", statsRow);
    statsDd.id = "countText";
    statsDd.textContent = "0";

    L.DomEvent.disableClickPropagation(root);
    L.DomEvent.disableScrollPropagation(root);
    updateBasemapButtons();
    syncBasemapControlState();
    return root;
  },
});

const PlaceSearchControl = L.Control.extend({
  onAdd(map) {
    const root = L.DomUtil.create("div", "map-search-control");
    const title = L.DomUtil.create("div", "map-search-control-title", root);
    title.textContent = "Vyhledat místo";
    const form = L.DomUtil.create("form", "map-search-form", root);
    const row = L.DomUtil.create("div", "map-search-row", form);
    const input = L.DomUtil.create("input", "map-search-input", row);
    input.type = "search";
    input.name = "q";
    input.autocomplete = "off";
    input.placeholder = "Město, adresa…";
    input.setAttribute("aria-label", "Vyhledat místo na mapě");
    const submitBtn = L.DomUtil.create("button", "map-search-submit", row);
    submitBtn.type = "submit";
    submitBtn.textContent = "Hledat";
    const errorEl = L.DomUtil.create("div", "map-search-error", root);
    errorEl.hidden = true;
    const resultsWrap = L.DomUtil.create("div", "map-search-results", root);
    resultsWrap.hidden = true;
    const attr = L.DomUtil.create("div", "map-search-attribution", root);

    const clearError = () => {
      errorEl.textContent = "";
      errorEl.hidden = true;
    };
    const clearResults = () => {
      resultsWrap.innerHTML = "";
      resultsWrap.hidden = true;
    };
    const showError = (msg) => {
      clearResults();
      errorEl.textContent = msg;
      errorEl.hidden = false;
    };

    function applyGeocodeHit(hit) {
      const lat = Number(hit.lat);
      const lng = Number(hit.lon);
      if (!Number.isFinite(lat) || !Number.isFinite(lng)) {
        showError("Neplatná odpověď serveru.");
        return;
      }
      if (hit.boundingbox && hit.boundingbox.length === 4) {
        const [south, north, west, east] = hit.boundingbox.map(Number);
        if (
          [south, north, west, east].every(Number.isFinite) &&
          south < north &&
          west < east
        ) {
          const bb = L.latLngBounds(L.latLng(south, west), L.latLng(north, east));
          map.fitBounds(bb, { maxZoom: 16, padding: [24, 24] });
          closeMobileMapPanels();
          return;
        }
      }
      map.setView([lat, lng], 15, { animate: true });
      closeMobileMapPanels();
    }

    L.DomEvent.on(form, "submit", L.DomEvent.stopPropagation);
    form.addEventListener("submit", async (e) => {
      e.preventDefault();
      const q = input.value.trim();
      clearError();
      clearResults();
      if (!q) {
        showError("Zadejte hledaný text.");
        return;
      }
      submitBtn.disabled = true;
      try {
        const response = await fetch(`api/geocode.php?${new URLSearchParams({ q })}`, {
          headers: { Accept: "application/json" },
        });
        const data = await response.json();
        if (!response.ok) {
          showError(typeof data.error === "string" ? data.error : "Hledání se nezdařilo.");
          return;
        }
        if (!Array.isArray(data) || data.length === 0) {
          showError("Nic nenalezeno. Zkuste jiný dotaz.");
          return;
        }
        if (data.length > 1) {
          const hint = document.createElement("div");
          hint.className = "map-search-results-hint";
          hint.textContent = "Nalezeno více míst — vyberte správné:";
          resultsWrap.appendChild(hint);
          const list = document.createElement("div");
          list.className = "map-search-results-list";
          data.forEach((hit, index) => {
            const btn = document.createElement("button");
            btn.type = "button";
            btn.className = "map-search-result-btn";
            const name = hit.display_name || `Výsledek ${index + 1}`;
            btn.textContent = name.length > 80 ? `${name.slice(0, 77)}…` : name;
            if (name.length > 80) {
              btn.title = name;
            }
            btn.addEventListener("click", () => {
              applyGeocodeHit(hit);
              clearResults();
              input.blur();
            });
            list.appendChild(btn);
          });
          resultsWrap.appendChild(list);
          resultsWrap.hidden = false;
          input.blur();
          return;
        }
        applyGeocodeHit(data[0]);
        input.blur();
      } catch {
        showError("Spojení se nezdařilo.");
      } finally {
        submitBtn.disabled = false;
      }
    });

    L.DomEvent.disableClickPropagation(root);
    L.DomEvent.disableScrollPropagation(root);
    return root;
  },
});

const zoomControl = map.zoomControl;
if (zoomControl) {
  map.removeControl(zoomControl);
}
new PlaceSearchControl({ position: "topleft" }).addTo(map);
if (zoomControl) {
  map.addControl(zoomControl);
}

new BasemapControl({ position: "topright" }).addTo(map);

const filtersForm = document.getElementById("filters");
const filtersPanel = document.querySelector(".filters-panel");
const filtersPanelBody = filtersPanel?.querySelector(".filters-panel-body");
const resetButton = document.getElementById("resetFilters");
const countText = document.getElementById("countText");
const searchToggle = document.getElementById("searchToggle");
const sidebar = document.getElementById("sidebarDrawer");
const sidebarToggle = document.getElementById("sidebarToggle");
const layersToggle = document.getElementById("layersToggle");
const sidebarClose = document.getElementById("sidebarClose");
const sidebarBackdrop = document.getElementById("sidebarBackdrop");
const selection = document.getElementById("selection");
const userPanel = document.getElementById("userPanel");
const loginModal = document.getElementById("loginModal");
const slopeFilterInput = document.getElementById("slopeFilter");
const slopeFilterValueEl = document.getElementById("slopeFilterValue");
const slopeRangeSliderEl = document.getElementById("slopeRangeSlider");
const emptySelectionHtml = "<strong>Klikněte na louku</strong><p>Zde se zobrazí podrobnosti o parcele.</p>";
const mapContextMenu = document.createElement("div");
const mapSearchControl = document.querySelector(".map-search-control");
const basemapControlStack = document.querySelector(".basemap-control-stack");

let authUser = null;
let oauthConfigured = true;
const favouriteMeadowIds = new Set();
const pendingFavouriteMeadowIds = new Set();
/** Last single-meadow properties shown in sidebar (for refresh after favourite toggle). */
let lastSelectedMeadowProperties = null;

const AUTH_ERROR_MESSAGES = {
  oauth_not_configured: "Přihlášení není na serveru nastavené.",
  invalid_state: "Relace vypršela. Zkuste přihlášení znovu.",
  provider_denied: "Přihlášení bylo zrušeno.",
  missing_code: "Chybí autorizační kód.",
  token_exchange_failed: "Nepodařilo se dokončit přihlášení u Google.",
  token_invalid: "Neplatná odpověď od Google.",
  no_access_token: "Google nepředal přístupový token.",
  userinfo_failed: "Nepodařilo se načíst údaje účtu.",
  userinfo_invalid: "Neplatná odpověď profilu.",
  missing_sub: "Chybí identifikátor účtu.",
  email_not_verified: "E-mail u Google účtu není ověřený.",
  invalid_email: "Neplatný e-mail z účtu.",
  database_error: "Chyba při ukládání účtu.",
  user_persist_failed: "Nepodařilo se uložit účet.",
};

function isMobileSidebarViewport() {
  return mobileSidebarQuery.matches;
}

function isPhoneLayoutViewport() {
  return phoneLayoutQuery.matches;
}

function syncMobileMapPanels() {
  if (!searchToggle || !layersToggle) {
    return;
  }

  const isPhone = isPhoneLayoutViewport();
  if (!isPhone) {
    document.body.classList.remove("mobile-search-open", "mobile-layers-open");
  }

  const searchOpen = isPhone && document.body.classList.contains("mobile-search-open");
  const layersOpen = isPhone && document.body.classList.contains("mobile-layers-open");

  searchToggle.setAttribute("aria-expanded", searchOpen ? "true" : "false");
  layersToggle.setAttribute("aria-expanded", layersOpen ? "true" : "false");
  searchToggle.classList.toggle("is-active", searchOpen);
  layersToggle.classList.toggle("is-active", layersOpen);
  mapSearchControl?.classList.toggle("is-mobile-open", !isPhone || searchOpen);
  basemapControlStack?.classList.toggle("is-mobile-open", !isPhone || layersOpen);
}

function setMobileMapPanel(panelName) {
  const isPhone = isPhoneLayoutViewport();
  document.body.classList.toggle("mobile-search-open", isPhone && panelName === "search");
  document.body.classList.toggle("mobile-layers-open", isPhone && panelName === "layers");
  syncMobileMapPanels();
  syncBasemapControlState();
}

function closeMobileMapPanels() {
  setMobileMapPanel(null);
}

function syncMobileSidebarState() {
  if (!sidebar || !sidebarToggle || !sidebarBackdrop) {
    return;
  }

  const isMobile = isMobileSidebarViewport();
  const isOpen = isMobile && document.body.classList.contains("mobile-sidebar-open");
  sidebarToggle.setAttribute("aria-expanded", isOpen ? "true" : "false");
  sidebarBackdrop.hidden = !isOpen;

  if (!isMobile) {
    document.body.classList.remove("mobile-sidebar-open");
    sidebar.removeAttribute("aria-hidden");
    if ("inert" in sidebar) {
      sidebar.inert = false;
    }
    return;
  }

  sidebar.setAttribute("aria-hidden", isOpen ? "false" : "true");
  if ("inert" in sidebar) {
    sidebar.inert = !isOpen;
  }
}

function setMobileSidebarOpen(isOpen) {
  if (!sidebar || !sidebarToggle) {
    return;
  }

  const shouldOpen = isOpen && isMobileSidebarViewport();
  if (shouldOpen) {
    closeMobileMapPanels();
  }
  document.body.classList.toggle("mobile-sidebar-open", shouldOpen);
  syncMobileSidebarState();

  if (shouldOpen) {
    window.requestAnimationFrame(() => {
      sidebarClose?.focus();
    });
    return;
  }

  if (document.activeElement instanceof HTMLElement && sidebar.contains(document.activeElement)) {
    sidebarToggle.focus();
  }
}

function closeMobileSidebarIfNeeded() {
  if (isMobileSidebarViewport()) {
    setMobileSidebarOpen(false);
  }
}

async function loadAuthStatus() {
  try {
    const response = await fetch("api/auth_status.php", { headers: { Accept: "application/json" } });
    const data = await response.json();
    authUser = data.user ?? null;
    oauthConfigured = data.oauth_configured !== false;
    if (authUser) {
      await loadFavouriteIds();
    } else {
      favouriteMeadowIds.clear();
    }
  } catch {
    authUser = null;
    oauthConfigured = false;
    favouriteMeadowIds.clear();
  }
  renderUserPanel();
}

async function loadFavouriteIds() {
  try {
    const response = await fetch("api/favourites.php", { headers: { Accept: "application/json" } });
    if (!response.ok) {
      return;
    }
    const data = await response.json();
    favouriteMeadowIds.clear();
    (data.ids || []).forEach((id) => favouriteMeadowIds.add(Number(id)));
  } catch {
    /* ignore */
  }
}

function isMeadowFavourite(properties) {
  if (!properties || properties.id == null) {
    return false;
  }
  if (properties.is_favourite === true) {
    return true;
  }
  return favouriteMeadowIds.has(Number(properties.id));
}

function meadowPolygonStyleFromFeature(feature) {
  const fav = isMeadowFavourite(feature.properties);
  if (fav) {
    return {
      color: "#b71c1c",
      weight: 1.5,
      fillColor: "#e53935",
      fillOpacity: 0.5,
    };
  }
  return {
    color: "#1f7a4c",
    weight: 1.5,
    fillColor: "#4fd18b",
    fillOpacity: 0.45,
  };
}

function mergeFavouritesFromFeatureCollection(featureCollection) {
  if (!authUser || featureCollection.meta?.mode !== "polygons") {
    return;
  }
  for (const f of featureCollection.features) {
    const id = f.properties?.id;
    if (id == null) {
      continue;
    }
    if (f.properties.is_favourite) {
      favouriteMeadowIds.add(Number(id));
    } else {
      favouriteMeadowIds.delete(Number(id));
    }
  }
}

function restyleAllMeadowPolygons() {
  meadowLayer.eachLayer((layer) => {
    if (layer.feature) {
      layer.setStyle(meadowPolygonStyleFromFeature(layer.feature));
    }
  });
}

function applyFavouriteState(meadowId, isFavourite) {
  if (isFavourite) {
    favouriteMeadowIds.add(meadowId);
  } else {
    favouriteMeadowIds.delete(meadowId);
  }

  meadowLayer.eachLayer((layer) => {
    const layerProperties = layer.feature?.properties;
    if (!layerProperties || Number(layerProperties.id) !== meadowId) {
      return;
    }
    layerProperties.is_favourite = isFavourite;
    layer.setStyle(meadowPolygonStyleFromFeature(layer.feature));
  });

  if (lastSelectedMeadowProperties && Number(lastSelectedMeadowProperties.id) === meadowId) {
    lastSelectedMeadowProperties.is_favourite = isFavourite;
    showSelection(lastSelectedMeadowProperties);
  }
}

function renderUserPanel() {
  if (!userPanel) {
    return;
  }
  const googleMark = `
    <span class="user-panel-google-mark" aria-hidden="true">
      <svg viewBox="0 0 24 24" focusable="false">
        <path fill="#4285F4" d="M21.6 12.23c0-.68-.06-1.33-.18-1.95H12v3.69h5.39a4.6 4.6 0 0 1-1.99 3.02v2.5h3.22c1.88-1.73 2.98-4.28 2.98-7.26Z"></path>
        <path fill="#34A853" d="M12 22c2.7 0 4.96-.9 6.61-2.44l-3.22-2.5c-.89.6-2.03.95-3.39.95-2.61 0-4.82-1.76-5.61-4.12H3.06v2.58A9.99 9.99 0 0 0 12 22Z"></path>
        <path fill="#FBBC05" d="M6.39 13.89A5.98 5.98 0 0 1 6.08 12c0-.66.11-1.31.31-1.89V7.53H3.06A9.99 9.99 0 0 0 2 12c0 1.61.39 3.13 1.06 4.47l3.33-2.58Z"></path>
        <path fill="#EA4335" d="M12 5.98c1.47 0 2.78.5 3.82 1.48l2.86-2.86C16.95 2.98 14.69 2 12 2a9.99 9.99 0 0 0-8.94 5.53l3.33 2.58c.79-2.36 3-4.13 5.61-4.13Z"></path>
      </svg>
    </span>`;
  if (!oauthConfigured) {
    userPanel.innerHTML = `
      <div class="user-panel-content">
        <span class="user-panel-kicker">Účet</span>
        <strong class="user-panel-title">Přihlášení není k dispozici</strong>
        <p class="user-panel-copy">Google přihlášení zatím není pro tuto instalaci nastavené.</p>
      </div>`;
    return;
  }
  if (!authUser) {
    userPanel.innerHTML = `
      <div class="user-panel-content">
        <span class="user-panel-kicker">Účet</span>
        <strong class="user-panel-title">Ukládejte si oblíbené louky</strong>
        <p class="user-panel-copy">Přihlaste se a mějte své vybrané louky po ruce na každém zařízení.</p>
      </div>
      <a class="user-panel-login" href="api/auth_start.php">
        ${googleMark}
        <span>Přihlásit se přes Google</span>
      </a>`;
    return;
  }
  const safeName = escapeHtml(authUser.display_name || "");
  const avatar = authUser.avatar_url
    ? `<img src="${escapeHtml(authUser.avatar_url)}" alt="" width="44" height="44" class="user-panel-avatar" referrerpolicy="no-referrer">`
    : `<span class="user-panel-avatar-fallback" aria-hidden="true"></span>`;
  userPanel.innerHTML = `
    <div class="user-panel-profile">
      ${avatar}
      <div class="user-panel-content">
        <span class="user-panel-kicker">Přihlášeno přes Google</span>
        <span class="user-panel-name">${safeName}</span>
        <p class="user-panel-copy">Oblíbené louky se ukládají k vašemu účtu.</p>
      </div>
    </div>
    <button type="button" class="user-panel-logout">Odhlásit se</button>`;
}

function openLoginModal() {
  if (!oauthConfigured) {
    return;
  }
  if (loginModal) {
    loginModal.hidden = false;
  }
}

function closeLoginModal() {
  if (loginModal) {
    loginModal.hidden = true;
  }
}

async function logout() {
  try {
    await fetch("api/auth_logout.php", { headers: { Accept: "application/json" } });
  } catch {
    /* ignore */
  }
  authUser = null;
  favouriteMeadowIds.clear();
  renderUserPanel();
  closeLoginModal();
  await refreshMeadows();
}

async function toggleFavouriteOnServer(meadowId, remove) {
  if (remove) {
    const response = await fetch(`api/favourites.php?meadow_id=${encodeURIComponent(String(meadowId))}`, {
      method: "DELETE",
      headers: { Accept: "application/json" },
    });
    if (!response.ok) {
      const body = await response.json().catch(() => ({}));
      throw new Error(body.error || "Odebrání se nezdařilo");
    }
    return;
  }
  const response = await fetch("api/favourites.php", {
    method: "POST",
    headers: { Accept: "application/json", "Content-Type": "application/json" },
    body: JSON.stringify({ meadow_id: meadowId }),
  });
  if (!response.ok) {
    const body = await response.json().catch(() => ({}));
    throw new Error(body.error || "Přidání se nezdařilo");
  }
}

async function handleFavouriteToggle(meadowId, currentlyFavourite) {
  const nextFavourite = !currentlyFavourite;
  pendingFavouriteMeadowIds.add(meadowId);
  applyFavouriteState(meadowId, nextFavourite);

  try {
    await toggleFavouriteOnServer(meadowId, currentlyFavourite);
    await refreshMeadows();
  } catch (err) {
    applyFavouriteState(meadowId, currentlyFavourite);
    console.error(err);
    alert(err instanceof Error ? err.message : "Akce se nezdařila");
  } finally {
    pendingFavouriteMeadowIds.delete(meadowId);
    if (lastSelectedMeadowProperties && Number(lastSelectedMeadowProperties.id) === meadowId) {
      showSelection(lastSelectedMeadowProperties);
    }
  }
}

function readAuthErrorFromUrl() {
  try {
    const params = new URLSearchParams(window.location.search);
    const code = params.get("auth_error");
    if (!code) {
      return;
    }
    params.delete("auth_error");
    const next =
      `${window.location.pathname}${params.toString() ? `?${params.toString()}` : ""}${window.location.hash}`;
    window.history.replaceState({}, "", next);
    const msg = AUTH_ERROR_MESSAGES[code] || "Přihlášení se nezdařilo.";
    openLoginModal();
    const dialog = loginModal?.querySelector(".login-modal-dialog");
    let note = dialog?.querySelector(".login-modal-error");
    if (dialog && !note) {
      note = document.createElement("p");
      note.className = "login-modal-error";
      note.setAttribute("role", "alert");
      const lead = dialog.querySelector(".login-modal-lead");
      lead?.insertAdjacentElement("afterend", note);
    }
    if (note) {
      note.textContent = msg;
    }
  } catch {
    /* ignore */
  }
}

if (userPanel) {
  userPanel.addEventListener("click", (event) => {
    if (event.target.closest(".user-panel-logout")) {
      void logout();
    }
  });
}

if (loginModal) {
  loginModal.addEventListener("click", (event) => {
    if (
      event.target.classList.contains("login-modal-backdrop") ||
      event.target.classList.contains("login-modal-close")
    ) {
      closeLoginModal();
    }
  });
  document.addEventListener("keydown", (event) => {
    if (event.key === "Escape" && !loginModal.hidden) {
      closeLoginModal();
    }
  });
}

sidebarToggle?.addEventListener("click", () => {
  const isOpen = document.body.classList.contains("mobile-sidebar-open");
  setMobileSidebarOpen(!isOpen);
});

searchToggle?.addEventListener("click", () => {
  if (document.body.classList.contains("mobile-search-open")) {
    closeMobileMapPanels();
    return;
  }
  setMobileSidebarOpen(false);
  setMobileMapPanel("search");
});

layersToggle?.addEventListener("click", () => {
  if (document.body.classList.contains("mobile-layers-open")) {
    closeMobileMapPanels();
    return;
  }
  setMobileSidebarOpen(false);
  setMobileMapPanel("layers");
});

sidebarClose?.addEventListener("click", () => {
  setMobileSidebarOpen(false);
});

sidebarBackdrop?.addEventListener("click", () => {
  setMobileSidebarOpen(false);
});

document.addEventListener("keydown", (event) => {
  if (event.key === "Escape" && document.body.classList.contains("mobile-sidebar-open")) {
    setMobileSidebarOpen(false);
  }
  if (event.key === "Escape" && (document.body.classList.contains("mobile-search-open") || document.body.classList.contains("mobile-layers-open"))) {
    closeMobileMapPanels();
  }
});

mobileSidebarQuery.addEventListener("change", () => {
  syncMobileSidebarState();
  syncBasemapControlState();
  syncMobileMapPanels();
});

phoneLayoutQuery.addEventListener("change", () => {
  syncMobileMapPanels();
  syncBasemapControlState();
});

if (selection) {
  selection.addEventListener("click", (event) => {
    const btn = event.target.closest("[data-action='toggle-favourite']");
    if (!btn) {
      return;
    }
    const id = Number(btn.dataset.meadowId);
    if (!Number.isFinite(id) || id <= 0) {
      return;
    }
    if (pendingFavouriteMeadowIds.has(id)) {
      return;
    }
    if (!authUser) {
      if (!oauthConfigured) {
        window.alert("Přihlášení není na serveru nastaveno.");
      } else {
        openLoginModal();
      }
      return;
    }
    const isFav = btn.dataset.isFavourite === "true";
    void handleFavouriteToggle(id, isFav);
  });
}
const formatSliderArea = (value) => `${Number(value).toLocaleString("cs-CZ")} m²`;
const formatSliderDistance = (value) => `${Number(value).toLocaleString("cs-CZ")} m`;
const DEFAULT_SLOPE_INDEX = 5;
const FILTER_STORAGE_KEY = "meadowFinder.filters.v1";
const MAP_CONTEXT_MENU_MARGIN = 12;
const advancedFlatnessFieldConfig = {
  minLargestFlatPatchShare: {
    defaultValue: "",
    queryKey: "minLargestFlatPatchShare",
    scale: 0.01,
  },
  minFlatAreaShare: {
    defaultValue: "",
    queryKey: "minFlatAreaShare",
    scale: 0.01,
  },
  maxTerrainRoughnessP80M: {
    defaultValue: "",
    queryKey: "maxTerrainRoughnessP80M",
  },
};
const advancedFlatnessFieldIds = Object.keys(advancedFlatnessFieldConfig);
/** Six discrete slope / flatness filter levels: 0 = strictest (flattest) … 5 = no terrain filter. */
const slopeFilterLevels = [
  {
    label: "Úplně rovná",
    thresholds: {
      minLargestFlatPatchShare: 42,
      minFlatAreaShare: 78,
      maxTerrainRoughnessP80M: 0.9,
    },
  },
  {
    label: "Velmi rovná",
    thresholds: {
      minLargestFlatPatchShare: 35,
      minFlatAreaShare: 70,
      maxTerrainRoughnessP80M: 1.5,
    },
  },
  {
    label: "Spíše rovná",
    thresholds: {
      minLargestFlatPatchShare: 20,
      minFlatAreaShare: 50,
      maxTerrainRoughnessP80M: 2.0,
    },
  },
  {
    label: "Smíšený terén",
    thresholds: {
      minLargestFlatPatchShare: 8,
      minFlatAreaShare: 25,
      maxTerrainRoughnessP80M: 3.5,
    },
  },
  {
    label: "Členitější",
    thresholds: {
      minLargestFlatPatchShare: 4,
      minFlatAreaShare: 12,
      maxTerrainRoughnessP80M: 5.5,
    },
  },
  {
    label: "Strmá",
    thresholds: {
      minLargestFlatPatchShare: "",
      minFlatAreaShare: "",
      maxTerrainRoughnessP80M: "",
    },
  },
];
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
  building: {
    sliderId: "buildingRange",
    minInputId: "minBuilding",
    maxInputId: "maxBuilding",
    outputId: "buildingRangeValue",
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
  minBuilding: {
    defaultValue: 0,
    format: formatSliderDistance,
  },
  maxBuilding: {
    defaultValue: 5000,
    isUnlimitedAtMax: true,
    format: (value, input) => (Number(value) >= Number(input.max) ? "Bez limitu" : formatSliderDistance(value)),
  },
};
const rangeSliderIds = Object.keys(rangeSliderConfig);
const persistedFilterInputIds = [...Object.keys(sliderConfig), ...advancedFlatnessFieldIds];

mapContextMenu.className = "map-context-menu";
mapContextMenu.hidden = true;
mapContextMenu.innerHTML = `
  <a
    class="map-context-menu-item"
    data-map-link="google"
    href="#"
    target="_blank"
    rel="noopener noreferrer"
  >
    Otevřít v Google Maps
  </a>
  <a
    class="map-context-menu-item"
    data-map-link="mapy"
    href="#"
    target="_blank"
    rel="noopener noreferrer"
  >
    Otevřít v Mapy.com
  </a>
`;
document.body.append(mapContextMenu);

function ruianIdentifyTolerancePx(zoom) {
  return Math.max(3, Math.min(10, Math.round(20 - zoom)));
}

function buildRuianIdentifyUrl(latLng) {
  const bounds = map.getBounds();
  const sw = L.CRS.EPSG3857.project(bounds.getSouthWest());
  const ne = L.CRS.EPSG3857.project(bounds.getNorthEast());
  const pt = L.CRS.EPSG3857.project(latLng);
  const size = map.getSize();
  const params = new URLSearchParams({
    f: "json",
    layers: `all:${RUIAN_PARCELA_LAYER_ID}`,
    geometryType: "esriGeometryPoint",
    geometry: JSON.stringify({ x: pt.x, y: pt.y }),
    sr: "102100",
    mapExtent: `${sw.x},${sw.y},${ne.x},${ne.y}`,
    imageDisplay: `${size.x},${size.y},96`,
    tolerance: String(ruianIdentifyTolerancePx(map.getZoom())),
    returnGeometry: "false",
  });
  return `${RUIAN_IDENTIFY_URL}?${params.toString()}`;
}

async function fetchRuianParcelUniqueId(latLng) {
  try {
    const url = buildRuianIdentifyUrl(latLng);
    const response = await fetch(url);
    if (!response.ok) {
      return null;
    }
    const data = await response.json();
    const attrs = data.results?.[0]?.attributes;
    if (!attrs) {
      return null;
    }
    const raw = attrs[RUIAN_PARCEL_UNIQUE_ID_FIELD] ?? attrs.id;
    if (raw === null || raw === undefined || raw === "") {
      return null;
    }
    const id = String(raw).trim();
    return /^\d+$/.test(id) ? id : null;
  } catch {
    return null;
  }
}

function openKnParcelWindow(url) {
  const anchor = document.querySelector(".map-shell") ?? map.getContainer();
  const rect = anchor.getBoundingClientRect();
  const maxW = 1024;
  const maxH = 800;
  const minW = 320;
  const minH = 400;
  const fraction = 0.8;
  let w = Math.round(rect.width * fraction);
  let h = Math.round(rect.height * fraction);
  w = Math.min(maxW, Math.max(minW, w));
  h = Math.min(maxH, Math.max(minH, h));
  let left = Math.round(window.screenX + rect.left + (rect.width - w) / 2);
  let top = Math.round(window.screenY + rect.top + (rect.height - h) / 2);
  const margin = 8;
  const saLeft = window.screen.availLeft ?? 0;
  const saTop = window.screen.availTop ?? 0;
  const saW = window.screen.availWidth;
  const saH = window.screen.availHeight;
  left = Math.min(Math.max(left, saLeft + margin), saLeft + saW - w - margin);
  top = Math.min(Math.max(top, saTop + margin), saTop + saH - h - margin);
  const features = [`popup=yes`, `width=${w}`, `height=${h}`, `left=${left}`, `top=${top}`].join(",");
  const win = window.open(url, KN_VIEWER_POPUP_NAME, features);
  if (!win) {
    window.open(url, "_blank", "noopener,noreferrer");
  }
}

async function handleCadastralBasemapMapClick(leafletEvent) {
  if (activeBasemap !== "cadastral") {
    return;
  }
  if (map.getZoom() < CADASTRAL_KN_CLICK_MIN_ZOOM) {
    return;
  }
  const original = leafletEvent.originalEvent;
  const domTarget = original?.target instanceof Element ? original.target : null;
  if (domTarget?.closest(".leaflet-control")) {
    return;
  }

  const parcelId = await fetchRuianParcelUniqueId(leafletEvent.latlng);
  if (!parcelId) {
    return;
  }
  const knUrl = `${NAHLIZENI_PARCELA_PAGE}?typ=parcela&id=${encodeURIComponent(parcelId)}`;
  openKnParcelWindow(knUrl);
}

const meadowLayer = L.geoJSON([], {
  style: meadowPolygonStyleFromFeature,
  onEachFeature(feature, layer) {
    layer.on("click", (ev) => {
      L.DomEvent.stop(ev);
      showSelection(feature.properties);
    });
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

function readPersistedFilterState() {
  try {
    const rawState = window.localStorage.getItem(FILTER_STORAGE_KEY);
    if (!rawState) {
      return null;
    }
    const parsedState = JSON.parse(rawState);
    if (!parsedState || typeof parsedState !== "object" || Array.isArray(parsedState)) {
      return null;
    }
    return parsedState;
  } catch {
    return null;
  }
}

function normalizePersistedInputValue(input, rawValue) {
  if (rawValue === "" && input.type !== "range") {
    return "";
  }

  const numericValue = Number(rawValue);
  if (!Number.isFinite(numericValue)) {
    return null;
  }

  let normalizedValue = numericValue;
  if (input.min !== "") {
    normalizedValue = Math.max(normalizedValue, Number(input.min));
  }
  if (input.max !== "") {
    normalizedValue = Math.min(normalizedValue, Number(input.max));
  }
  return String(normalizedValue);
}

function persistFilterState() {
  const state = persistedFilterInputIds.reduce((values, id) => {
    const input = document.getElementById(id);
    if (input) {
      values[id] = input.value;
    }
    return values;
  }, {});

  try {
    window.localStorage.setItem(FILTER_STORAGE_KEY, JSON.stringify(state));
  } catch {
    /* ignore storage failures */
  }
}

function restorePersistedFilterState() {
  const persistedState = readPersistedFilterState();
  if (!persistedState) {
    return false;
  }

  persistedFilterInputIds.forEach((id) => {
    const input = document.getElementById(id);
    if (!input || !(id in persistedState)) {
      return;
    }

    const restoredValue = normalizePersistedInputValue(input, String(persistedState[id]).trim());
    if (restoredValue !== null) {
      input.value = restoredValue;
    }
  });

  syncAllSliderValues();
  syncSlopeFilterUi();
  return true;
}

function advancedFlatnessValue(id) {
  const input = document.getElementById(id);
  if (!input) {
    return null;
  }
  const value = input.value.trim();
  if (value === "") {
    return null;
  }
  return Number(value);
}

function currentFlatnessThresholds() {
  return advancedFlatnessFieldIds.reduce((thresholds, id) => {
    thresholds[id] = advancedFlatnessValue(id);
    return thresholds;
  }, {});
}

function setFlatnessThresholds(thresholds) {
  advancedFlatnessFieldIds.forEach((id) => {
    const input = document.getElementById(id);
    const value = thresholds[id];
    input.value = value === null || value === undefined || value === "" ? "" : String(value);
  });
  syncSlopeFilterUi();
}

function thresholdValuesEqual(currentValue, presetValue) {
  const normalizedCurrent = currentValue === null || currentValue === undefined ? "" : currentValue;
  return normalizedCurrent === presetValue;
}

function findSlopeIndexForCurrentThresholds() {
  const currentThresholds = currentFlatnessThresholds();
  const index = slopeFilterLevels.findIndex((level) =>
    advancedFlatnessFieldIds.every((id) =>
      thresholdValuesEqual(currentThresholds[id], level.thresholds[id])
    )
  );
  return index === -1 ? null : index;
}

function syncSlopeRangeVisual() {
  if (!slopeRangeSliderEl || !slopeFilterInput) {
    return;
  }
  const max = Number(slopeFilterInput.max);
  const value = Number(slopeFilterInput.value);
  const safeMax = Number.isFinite(max) && max > 0 ? max : 5;
  const percent = (value / safeMax) * 100;
  slopeRangeSliderEl.style.setProperty("--range-start", "0%");
  slopeRangeSliderEl.style.setProperty("--range-end", `${percent}%`);
}

function syncSlopeFilterUi() {
  if (!slopeFilterValueEl || !slopeFilterInput) {
    return;
  }
  const matchedIndex = findSlopeIndexForCurrentThresholds();
  if (matchedIndex !== null) {
    slopeFilterInput.value = String(matchedIndex);
    const level = slopeFilterLevels[matchedIndex];
    slopeFilterValueEl.textContent = level.label;
    slopeFilterInput.setAttribute("aria-valuenow", String(matchedIndex));
    slopeFilterInput.setAttribute("aria-valuetext", level.label);
  } else {
    slopeFilterValueEl.textContent = "Vlastní nastavení";
    slopeFilterInput.setAttribute("aria-valuetext", "Vlastní nastavení");
  }
  syncSlopeRangeVisual();
}

function applySlopeLevel(index) {
  const level = slopeFilterLevels[index];
  if (!level) {
    return;
  }
  setFlatnessThresholds(level.thresholds);
}

function normalizeCoordinate(value) {
  const coordinate = Number(value);
  return Number.isFinite(coordinate) ? coordinate : null;
}

function externalMapLinkPair(lat, lng) {
  const normalizedLat = normalizeCoordinate(lat);
  const normalizedLng = normalizeCoordinate(lng);
  if (normalizedLat === null || normalizedLng === null) {
    return null;
  }

  const googleMapsUrl = new URL("https://www.google.com/maps");
  googleMapsUrl.searchParams.set("q", `${normalizedLat},${normalizedLng}`);

  const mapyUrl = new URL("https://mapy.com/fnc/v1/showmap");
  mapyUrl.searchParams.set("mapset", "basic");
  mapyUrl.searchParams.set("center", `${normalizedLng},${normalizedLat}`);
  mapyUrl.searchParams.set("zoom", "17");
  mapyUrl.searchParams.set("marker", "true");

  return {
    google: googleMapsUrl.toString(),
    mapy: mapyUrl.toString(),
  };
}

function selectionMapLinksHtml(properties) {
  const links = externalMapLinkPair(properties.centroid_lat, properties.centroid_lng);
  if (!links) {
    return "";
  }

  return `
    <p class="selection-map-links">
      <a class="selection-map-link" href="${links.google}" target="_blank" rel="noopener noreferrer">Google Maps</a>
      <span class="selection-map-links-separator">|</span>
      <a class="selection-map-link" href="${links.mapy}" target="_blank" rel="noopener noreferrer">Mapy.com</a>
    </p>
  `;
}

function hideMapContextMenu() {
  if (mapContextMenu.hidden) {
    return;
  }

  mapContextMenu.hidden = true;
  mapContextMenu.style.visibility = "";
}

function showMapContextMenu(clientX, clientY, latLng) {
  const links = externalMapLinkPair(latLng.lat, latLng.lng);
  if (!links) {
    hideMapContextMenu();
    return;
  }

  mapContextMenu.querySelector('[data-map-link="google"]').href = links.google;
  mapContextMenu.querySelector('[data-map-link="mapy"]').href = links.mapy;
  mapContextMenu.hidden = false;
  mapContextMenu.style.left = "0px";
  mapContextMenu.style.top = "0px";
  mapContextMenu.style.visibility = "hidden";

  const width = mapContextMenu.offsetWidth;
  const height = mapContextMenu.offsetHeight;
  const left = Math.max(
    MAP_CONTEXT_MENU_MARGIN,
    Math.min(clientX, window.innerWidth - width - MAP_CONTEXT_MENU_MARGIN),
  );
  const top = Math.max(
    MAP_CONTEXT_MENU_MARGIN,
    Math.min(clientY, window.innerHeight - height - MAP_CONTEXT_MENU_MARGIN),
  );

  mapContextMenu.style.left = `${left}px`;
  mapContextMenu.style.top = `${top}px`;
  mapContextMenu.style.visibility = "";
}

function handleMapContextMenu(event) {
  const target = event.target instanceof Element ? event.target : null;
  if (target?.closest(".leaflet-control")) {
    return;
  }

  event.preventDefault();
  showMapContextMenu(event.clientX, event.clientY, map.mouseEventToLatLng(event));
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
    ["minBuilding", rangeSliderValue("building", "min")],
    ["maxBuilding", rangeSliderValue("building", "max")],
  ].forEach(([key, value]) => {
    if (value !== null) {
      params.set(key, value);
    }
  });

  advancedFlatnessFieldIds.forEach((id) => {
    const value = advancedFlatnessValue(id);
    if (value === null) {
      return;
    }
    const config = advancedFlatnessFieldConfig[id];
    const queryValue = config.scale ? value * config.scale : value;
    params.set(config.queryKey, String(queryValue));
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

function formatElevationDeviation(value) {
  if (value === null || value === undefined || Number.isNaN(Number(value))) {
    return "není k dispozici";
  }

  return `${Math.round(Number(value))} m`;
}

function formatShare(value) {
  if (value === null || value === undefined || Number.isNaN(Number(value))) {
    return "není k dispozici";
  }

  return `${Math.round(Number(value) * 100)} %`;
}

function formatTerrainRoughness(value) {
  if (value === null || value === undefined || Number.isNaN(Number(value))) {
    return "není k dispozici";
  }

  return `${Number(value).toFixed(1).replace(".", ",")} m`;
}

/** Czech explanations for terrain flatness metrics (aligned with DEM processing in prepare_meadows.py). */
const METRIC_DESCRIPTIONS = {
  largestFlatPatchM2:
    "Plocha největší souvislé části louky, kde je podle DEM terén považován za rovný (místní reliéf v okolí pixelu do 1,5 m). Udává rozlohu jednoho souvislého rovného celku v m².",
  largestFlatPatchShare:
    "Podíl největší souvislé rovné plochy na celkovou plochu louky v %.",
  flatAreaShare:
    "Podíl všech rovných ploch na louce v % — součet rovných pixelů, i když nejsou vzájemě souvislé (mohou být odděleny členitějšími úseky).",
  terrainRoughnessP80:
    "Výška, kterou nepřesahuje 80 % všech nerovností na louce, takže čím je toto číslo vyšší, tím je terén celkově hrbolatější.",
  averageElevationDeviation:
    "Průměr absolutních odchylek výšky každého pixelu výšky od průměrné výšky louky (v metrech). Širší ukazatel variability výšky na parcele.",
};

let metricHelpIdCounter = 0;

function nextMetricHelpDomId() {
  metricHelpIdCounter += 1;
  return `metric-help-tip-${metricHelpIdCounter}`;
}

function escapeHtml(text) {
  return text.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/"/g, "&quot;");
}

function metricHelpHtml(metricKey) {
  const description = METRIC_DESCRIPTIONS[metricKey];
  if (!description) {
    return "";
  }
  const tipId = nextMetricHelpDomId();
  return `<span class="metric-help"><button type="button" class="metric-help-btn" aria-describedby="${tipId}" aria-label="Vysvětlení metriky"><span aria-hidden="true">i</span></button><span id="${tipId}" class="metric-help-tooltip" role="tooltip">${escapeHtml(description)}</span></span>`;
}

function createMetricHelpElement(metricKey) {
  const description = METRIC_DESCRIPTIONS[metricKey];
  if (!description) {
    return document.createDocumentFragment();
  }
  const tipId = nextMetricHelpDomId();
  const wrap = document.createElement("span");
  wrap.className = "metric-help";
  const btn = document.createElement("button");
  btn.type = "button";
  btn.className = "metric-help-btn";
  btn.setAttribute("aria-describedby", tipId);
  btn.setAttribute("aria-label", "Vysvětlení metriky");
  btn.innerHTML = "<span aria-hidden=\"true\">i</span>";
  const tip = document.createElement("span");
  tip.id = tipId;
  tip.className = "metric-help-tooltip";
  tip.setAttribute("role", "tooltip");
  tip.textContent = description;
  wrap.appendChild(btn);
  wrap.appendChild(tip);
  return wrap;
}

function initAdvancedMetricHelp() {
  document.querySelectorAll(".advanced-field[data-metric-help]").forEach((label) => {
    const key = label.dataset.metricHelp;
    const textSpan = label.querySelector(".filter-label");
    if (!textSpan || !METRIC_DESCRIPTIONS[key]) {
      return;
    }
    const row = document.createElement("span");
    row.className = "filter-label-with-help";
    textSpan.replaceWith(row);
    row.appendChild(textSpan);
    row.appendChild(createMetricHelpElement(key));
  });
}

function initAnimatedDetailsPanel(detailsEl, bodyEl) {
  if (!detailsEl || !bodyEl || typeof detailsEl.animate !== "function") {
    return;
  }

  const summaryEl = detailsEl.querySelector("summary");
  if (!summaryEl) {
    return;
  }

  const reduceMotionQuery = window.matchMedia("(prefers-reduced-motion: reduce)");
  let panelAnimation = null;
  let bodyAnimation = null;
  let isClosing = false;

  function stopAnimations() {
    panelAnimation?.cancel();
    bodyAnimation?.cancel();
    panelAnimation = null;
    bodyAnimation = null;
  }

  function finishAnimation(open) {
    stopAnimations();
    isClosing = false;
    detailsEl.open = open;
    detailsEl.style.height = "";
    detailsEl.style.overflow = "";
    bodyEl.style.opacity = "";
    bodyEl.style.transform = "";
    detailsEl.removeAttribute("data-animating");
  }

  function animateBody(keyframes, options) {
    if (typeof bodyEl.animate !== "function") {
      bodyEl.style.opacity = keyframes[keyframes.length - 1].opacity;
      bodyEl.style.transform = keyframes[keyframes.length - 1].transform;
      return null;
    }
    return bodyEl.animate(keyframes, options);
  }

  function collapse() {
    isClosing = true;
    stopAnimations();
    detailsEl.setAttribute("data-animating", "true");

    const startHeight = `${detailsEl.offsetHeight}px`;
    const endHeight = `${summaryEl.offsetHeight}px`;
    detailsEl.style.height = startHeight;
    detailsEl.style.overflow = "hidden";

    panelAnimation = detailsEl.animate(
      { height: [startHeight, endHeight] },
      { duration: 220, easing: "cubic-bezier(0.4, 0, 0.2, 1)" },
    );
    bodyAnimation = animateBody(
      [
        { opacity: 1, transform: "translateY(0)" },
        { opacity: 0, transform: "translateY(-0.3rem)" },
      ],
      { duration: 180, easing: "ease-in", fill: "forwards" },
    );

    panelAnimation.onfinish = () => finishAnimation(false);
    panelAnimation.oncancel = () => {
      panelAnimation = null;
    };
  }

  function expand() {
    stopAnimations();
    detailsEl.setAttribute("data-animating", "true");

    const startHeight = `${detailsEl.offsetHeight}px`;
    detailsEl.open = true;
    const endHeight = `${summaryEl.offsetHeight + bodyEl.offsetHeight}px`;

    bodyEl.style.opacity = "0";
    bodyEl.style.transform = "translateY(-0.3rem)";
    detailsEl.style.height = startHeight;
    detailsEl.style.overflow = "hidden";

    panelAnimation = detailsEl.animate(
      { height: [startHeight, endHeight] },
      { duration: 240, easing: "cubic-bezier(0.22, 1, 0.36, 1)" },
    );
    bodyAnimation = animateBody(
      [
        { opacity: 0, transform: "translateY(-0.3rem)" },
        { opacity: 1, transform: "translateY(0)" },
      ],
      { duration: 210, easing: "ease-out", fill: "forwards" },
    );

    panelAnimation.onfinish = () => finishAnimation(true);
    panelAnimation.oncancel = () => {
      panelAnimation = null;
    };
  }

  summaryEl.addEventListener("click", (event) => {
    if (reduceMotionQuery.matches) {
      return;
    }

    event.preventDefault();
    if (isClosing || !detailsEl.open) {
      expand();
      return;
    }
    collapse();
  });
}

function meadowMatchesThresholds(properties, thresholds) {
  const largestFlatPatchShare = Number(properties.largest_flat_patch_share) * 100;
  const flatAreaShare = Number(properties.flat_area_share) * 100;
  const terrainRoughness = Number(properties.terrain_roughness_p80_m);

  if (
    thresholds.minLargestFlatPatchShare !== "" &&
    (Number.isNaN(largestFlatPatchShare) || largestFlatPatchShare < thresholds.minLargestFlatPatchShare)
  ) {
    return false;
  }
  if (
    thresholds.minFlatAreaShare !== "" &&
    (Number.isNaN(flatAreaShare) || flatAreaShare < thresholds.minFlatAreaShare)
  ) {
    return false;
  }
  if (
    thresholds.maxTerrainRoughnessP80M !== "" &&
    (Number.isNaN(terrainRoughness) || terrainRoughness > thresholds.maxTerrainRoughnessP80M)
  ) {
    return false;
  }
  return true;
}

function classifyFlatness(properties) {
  return (
    slopeFilterLevels.find((level) => meadowMatchesThresholds(properties, level.thresholds)) ||
    slopeFilterLevels[slopeFilterLevels.length - 1]
  );
}

function scrollSidebarToSelection() {
  const sidebar = selection.closest(".sidebar");
  if (!sidebar) {
    return;
  }
  window.requestAnimationFrame(() => {
    const margin = 6;
    const relTop =
      selection.getBoundingClientRect().top -
      sidebar.getBoundingClientRect().top +
      sidebar.scrollTop;
    sidebar.scrollTo({
      top: Math.max(0, relTop - margin),
      behavior: "smooth",
    });
  });
}

function showSelection(properties) {
  const linksHtml = selectionMapLinksHtml(properties);
  if (properties.cluster_count !== undefined) {
    lastSelectedMeadowProperties = null;
    selection.innerHTML = `
      <strong>${formatCount(properties.cluster_count)} luk v této oblasti</strong>
      <p>Přibližte mapu pro načtení jednotlivých parcel.</p>
      ${linksHtml}
    `;
    scrollSidebarToSelection();
    closeMobileSidebarIfNeeded();
    return;
  }

  lastSelectedMeadowProperties = properties;
  const meadowId = properties.id != null ? Number(properties.id) : null;
  const favourite = meadowId != null && isMeadowFavourite(properties);
  const favouritePending = meadowId != null && pendingFavouriteMeadowIds.has(meadowId);
  const favouriteBlock =
    meadowId != null
      ? `<p class="selection-favourite-row"><button type="button" class="selection-fav-btn" data-action="toggle-favourite" data-meadow-id="${meadowId}" data-is-favourite="${favourite ? "true" : "false"}"${favouritePending ? ' disabled aria-busy="true"' : ""}>${favourite ? "Odebrat z oblíbených" : "Přidat do oblíbených"}</button></p>`
      : "";

  const flatness = classifyFlatness(properties);
  selection.innerHTML = `
    <strong>${properties.source_id}</strong>
    <p>Rovinatost: ${flatness.label}</p>
    <p>Plocha: ${formatArea(properties.area_m2)}</p>
    <p class="selection-metric"><span class="selection-metric-label">Největší rovná plocha: ${metricHelpHtml("largestFlatPatchM2")}</span><span class="selection-metric-value">${formatArea(properties.largest_flat_patch_m2)}</span></p>
    <p class="selection-metric"><span class="selection-metric-label">Souvislá rovná část: ${metricHelpHtml("largestFlatPatchShare")}</span><span class="selection-metric-value">${formatShare(properties.largest_flat_patch_share)}</span></p>
    <p class="selection-metric"><span class="selection-metric-label">Celková rovná část: ${metricHelpHtml("flatAreaShare")}</span><span class="selection-metric-value">${formatShare(properties.flat_area_share)}</span></p>
    <p class="selection-metric"><span class="selection-metric-label">Členitost terénu P80: ${metricHelpHtml("terrainRoughnessP80")}</span><span class="selection-metric-value">${formatTerrainRoughness(properties.terrain_roughness_p80_m)}</span></p>
    <p class="selection-metric"><span class="selection-metric-label">Průměrná výšková odchylka: ${metricHelpHtml("averageElevationDeviation")}</span><span class="selection-metric-value">${formatElevationDeviation(properties.average_elevation_deviation_m)}</span></p>
    <p>Vzdálenost od silnice: ${formatDistance(properties.nearest_road_m)}</p>
    <p>Vzdálenost od cesty: ${formatDistance(properties.nearest_path_m)}</p>
    <p>Vzdálenost od vody: ${formatDistance(properties.nearest_water_m)}</p>
    <p>Vzdálenost od větší řeky: ${formatDistance(properties.nearest_river_m)}</p>
    <p>Vzdálenost od vesnice/města: ${formatDistance(properties.nearest_settlement_m)}</p>
    <p>Vzdálenost od budovy: ${formatDistance(properties.nearest_building_m)}</p>
    ${favouriteBlock}
    ${linksHtml}
  `;
  scrollSidebarToSelection();
  closeMobileSidebarIfNeeded();
}

function clusterClassName(count, hasFavourite) {
  let sizeClass = "meadow-cluster-small";
  if (count >= 50) {
    sizeClass = "meadow-cluster-large";
  } else if (count >= 10) {
    sizeClass = "meadow-cluster-medium";
  }
  const favClass = hasFavourite ? " meadow-cluster-favourite" : "";
  return `meadow-cluster ${sizeClass}${favClass}`;
}

/** Square Leaflet icon side length so cluster labels keep even horizontal padding (CSS uses 10px each side). */
function clusterIconSidePx(count) {
  const label = formatCount(count);
  const innerMin = count >= 50 ? 48 : count >= 10 ? 42 : 38;
  const innerPadX = 20;
  const charPx = count >= 50 ? 10.25 : count >= 10 ? 8.75 : 8;
  const innerW = Math.max(innerMin, Math.ceil(label.length * charPx + innerPadX));
  const outerPad = 12;
  return Math.max(56, innerW + outerPad);
}

function createOverviewMarker(feature) {
  const { centroid_lat: lat, centroid_lng: lng, cluster_count: count } = feature.properties;
  const hasFavourite = Boolean(feature.properties?.has_favourite);
  const side = clusterIconSidePx(count);
  const marker = L.marker([lat, lng], {
    icon: L.divIcon({
      html: `<div><span>${formatCount(count)}</span></div>`,
      className: clusterClassName(count, hasFavourite),
      iconSize: L.point(side, side),
      iconAnchor: L.point(side / 2, side / 2),
    }),
  });

  marker.on("click", (ev) => {
    L.DomEvent.stop(ev);
    showSelection(feature.properties);
    map.flyTo([lat, lng], POLYGON_ZOOM_THRESHOLD);
  });

  return marker;
}

function redrawMeadows(featureCollection) {
  meadowLayer.clearLayers();
  overviewLayer.clearLayers();

  if (featureCollection.meta?.mode === "polygons") {
    mergeFavouritesFromFeatureCollection(featureCollection);
    meadowLayer.addData(featureCollection);
    restyleAllMeadowPolygons();
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
      persistFilterState();
      debouncedFilterRefresh();
    });
    input.addEventListener("pointerdown", () => setActiveRangeThumb(rangeId, inputId));
    input.addEventListener("focus", () => setActiveRangeThumb(rangeId, inputId));
  });
});

slopeFilterInput.addEventListener("input", () => {
  const index = Number(slopeFilterInput.value);
  applySlopeLevel(index);
  selection.innerHTML = emptySelectionHtml;
  persistFilterState();
  debouncedFilterRefresh();
});

advancedFlatnessFieldIds.forEach((id) => {
  const input = document.getElementById(id);
  input.addEventListener("input", () => {
    syncSlopeFilterUi();
    persistFilterState();
    debouncedFilterRefresh();
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
  setFlatnessThresholds(slopeFilterLevels[DEFAULT_SLOPE_INDEX].thresholds);
  syncAllSliderValues();
  persistFilterState();
  selection.innerHTML = emptySelectionHtml;
  refreshMeadows();
});

map.getContainer().addEventListener("contextmenu", handleMapContextMenu);
mapContextMenu.addEventListener("click", hideMapContextMenu);

function dismissMapContextMenuIfOutside(event) {
  if (mapContextMenu.hidden || mapContextMenu.contains(event.target)) {
    return;
  }
  hideMapContextMenu();
}

document.addEventListener("mousedown", dismissMapContextMenuIfOutside, true);
document.addEventListener("click", dismissMapContextMenuIfOutside, true);
map.on("click", (e) => {
  hideMapContextMenu();
  closeMobileMapPanels();
  void handleCadastralBasemapMapClick(e);
});
document.addEventListener("keydown", (event) => {
  if (event.key === "Escape") {
    hideMapContextMenu();
  }
});
map.on("zoomend", () => {
  syncVisibleLayer();
  syncCadastralKnPointerCursor();
});
map.on("movestart", hideMapContextMenu);
map.on("moveend", debouncedRefresh);
map.on("zoomstart", hideMapContextMenu);
window.addEventListener("resize", hideMapContextMenu);
window.addEventListener("resize", syncBasemapControlState);
window.addEventListener("resize", syncMobileMapPanels);
rangeSliderIds.forEach((rangeId) => setActiveRangeThumb(rangeId, rangeSliderConfig[rangeId].maxInputId));
initAdvancedMetricHelp();
initAnimatedDetailsPanel(filtersPanel, filtersPanelBody);
syncMobileSidebarState();
syncMobileMapPanels();
selection.innerHTML = emptySelectionHtml;
if (!restorePersistedFilterState()) {
  syncAllSliderValues();
  setFlatnessThresholds(slopeFilterLevels[DEFAULT_SLOPE_INDEX].thresholds);
}
syncCadastralKnPointerCursor();
readAuthErrorFromUrl();
void (async () => {
  await loadAuthStatus();
  await refreshMeadows();
})();
