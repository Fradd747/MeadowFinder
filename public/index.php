<?php

declare(strict_types=1);
?><!DOCTYPE html>
<html lang="cs">
<head>
    <!-- Google tag (gtag.js) -->
    <script async src="https://www.googletagmanager.com/gtag/js?id=G-R3RDVL5T7B"></script>
    <script>
        window.dataLayer = window.dataLayer || [];
        function gtag(){dataLayer.push(arguments);}
        gtag('js', new Date());

        gtag('config', 'G-R3RDVL5T7B');
    </script>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Vyhledávač luk</title>
    <link
        rel="stylesheet"
        href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"
        integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY="
        crossorigin=""
    >
    <link rel="stylesheet" href="assets/app.css">
    <link rel="icon" href="assets/images/favicon.svg" type="image/svg+xml" sizes="any">
</head>
<body>
    <div class="layout">
        <aside class="sidebar" id="sidebarDrawer" aria-label="Filtry a podrobnosti">
            <div class="sidebar-header">
                <h1 class="sidebar-title">Vyhledávač luk</h1>
                <button
                    type="button"
                    class="sidebar-close"
                    id="sidebarClose"
                    aria-controls="sidebarDrawer"
                    aria-label="Zavřít filtry a podrobnosti"
                >
                    Zavřít
                </button>
            </div>
            <form id="filters" class="filters">
                <details class="filters-panel" open>
                    <summary class="filters-panel-summary">Filtry</summary>
                    <div class="filters-panel-body">
                        <div class="range-group">
                            <div class="range-label-row">
                                <span class="filter-label">Plocha (m²)</span>
                                <span class="range-value" id="areaRangeValue">500 m² - Bez limitu</span>
                            </div>
                            <div class="range-field range-field-dual">
                                <div class="range-slider" id="areaRange">
                                    <input
                                        type="range"
                                        id="minArea"
                                        name="minArea"
                                        min="0"
                                        max="50000"
                                        step="100"
                                        value="500"
                                        aria-label="Minimální plocha"
                                    >
                                    <input
                                        type="range"
                                        id="maxArea"
                                        name="maxArea"
                                        min="0"
                                        max="50000"
                                        step="100"
                                        value="50000"
                                        aria-label="Maximální plocha"
                                    >
                                </div>
                            </div>
                        </div>

                        <div class="range-group">
                            <div class="range-label-row">
                                <span class="filter-label">Vzdálenost od silnice (m)</span>
                                <span class="range-value" id="roadRangeValue">0 m - 2 000 m</span>
                            </div>
                            <div class="range-field range-field-dual">
                                <div class="range-slider" id="roadRange">
                                    <input
                                        type="range"
                                        id="minRoad"
                                        name="minRoad"
                                        min="0"
                                        max="3000"
                                        step="10"
                                        value="0"
                                        aria-label="Minimální vzdálenost od silnice"
                                    >
                                    <input
                                        type="range"
                                        id="maxRoad"
                                        name="maxRoad"
                                        min="0"
                                        max="3000"
                                        step="10"
                                        value="2000"
                                        aria-label="Maximální vzdálenost od silnice"
                                    >
                                </div>
                            </div>
                        </div>

                        <div class="range-group">
                            <div class="range-label-row">
                                <span class="filter-label">Vzdálenost od cesty (m)</span>
                                <span class="range-value" id="pathRangeValue">0 m - 250 m</span>
                            </div>
                            <div class="range-field range-field-dual">
                                <div class="range-slider" id="pathRange">
                                    <input
                                        type="range"
                                        id="minPath"
                                        name="minPath"
                                        min="0"
                                        max="3000"
                                        step="10"
                                        value="0"
                                        aria-label="Minimální vzdálenost od cesty"
                                    >
                                    <input
                                        type="range"
                                        id="maxPath"
                                        name="maxPath"
                                        min="0"
                                        max="3000"
                                        step="10"
                                        value="250"
                                        aria-label="Maximální vzdálenost od cesty"
                                    >
                                </div>
                            </div>
                        </div>

                        <div class="range-group">
                            <div class="range-label-row">
                                <span class="filter-label">Vzdálenost od vody (m)</span>
                                <span class="range-value" id="waterRangeValue">0 m - 150 m</span>
                            </div>
                            <div class="range-field range-field-dual">
                                <div class="range-slider" id="waterRange">
                                    <input
                                        type="range"
                                        id="minWater"
                                        name="minWater"
                                        min="0"
                                        max="3000"
                                        step="10"
                                        value="0"
                                        aria-label="Minimální vzdálenost od vody"
                                    >
                                    <input
                                        type="range"
                                        id="maxWater"
                                        name="maxWater"
                                        min="0"
                                        max="3000"
                                        step="10"
                                        value="150"
                                        aria-label="Maximální vzdálenost od vody"
                                    >
                                </div>
                            </div>
                        </div>

                        <div class="range-group">
                            <div class="range-label-row">
                                <span class="filter-label">Vzdálenost od větší řeky (m)</span>
                                <span class="range-value" id="riverRangeValue">0 m - 500 m</span>
                            </div>
                            <div class="range-field range-field-dual">
                                <div class="range-slider" id="riverRange">
                                    <input
                                        type="range"
                                        id="minRiver"
                                        name="minRiver"
                                        min="0"
                                        max="3000"
                                        step="10"
                                        value="0"
                                        aria-label="Minimální vzdálenost od větší řeky"
                                    >
                                    <input
                                        type="range"
                                        id="maxRiver"
                                        name="maxRiver"
                                        min="0"
                                        max="3000"
                                        step="10"
                                        value="500"
                                        aria-label="Maximální vzdálenost od větší řeky"
                                    >
                                </div>
                            </div>
                        </div>

                        <div class="range-group">
                            <div class="range-label-row">
                                <span class="filter-label">Vzdálenost od vesnice/města (m)</span>
                                <span class="range-value" id="settlementRangeValue">600 m - Bez limitu</span>
                            </div>
                            <div class="range-field range-field-dual">
                                <div class="range-slider" id="settlementRange">
                                    <input
                                        type="range"
                                        id="minSettlement"
                                        name="minSettlement"
                                        min="0"
                                        max="3000"
                                        step="10"
                                        value="600"
                                        aria-label="Minimální vzdálenost od vesnice nebo města"
                                    >
                                    <input
                                        type="range"
                                        id="maxSettlement"
                                        name="maxSettlement"
                                        min="0"
                                        max="3000"
                                        step="10"
                                        value="3000"
                                        aria-label="Maximální vzdálenost od vesnice nebo města"
                                    >
                                </div>
                            </div>
                        </div>

                        <div class="range-group">
                            <div class="range-label-row">
                                <span class="filter-label">Vzdálenost od nejbližší budovy (m)</span>
                                <span class="range-value" id="buildingRangeValue">150 m - Bez limitu</span>
                            </div>
                            <div class="range-field range-field-dual">
                                <div class="range-slider" id="buildingRange">
                                    <input
                                        type="range"
                                        id="minBuilding"
                                        name="minBuilding"
                                        min="0"
                                        max="3000"
                                        step="10"
                                        value="150"
                                        aria-label="Minimální vzdálenost od budovy"
                                    >
                                    <input
                                        type="range"
                                        id="maxBuilding"
                                        name="maxBuilding"
                                        min="0"
                                        max="3000"
                                        step="10"
                                        value="3000"
                                        aria-label="Maximální vzdálenost od budovy"
                                    >
                                </div>
                            </div>
                        </div>

                        <section class="terrain-panel" aria-labelledby="terrainHeading">
                            <div class="terrain-panel-header">
                                <h2 id="terrainHeading">Rovinatost</h2>
                            </div>

                            <div class="terrain-panel-info" role="note">
                                <p>
                                Doporučujeme filtr ne méně jak 3. Data spojují kopce i roviny do velkých ploch, takže přísnější nastavení může vyřadit i lokality, kde je pro stanování vhodná jen jejich část.
                                </p>
                            </div>

                            <div class="range-group slope-filter-group">
                                <div class="range-label-row">
                                    <span class="filter-label">Rovinatost</span>
                                    <span class="range-value" id="slopeFilterValue">Smíšený terén</span>
                                </div>
                                <div class="slope-range-end-labels" aria-hidden="true">
                                    <span>Úplně rovná</span>
                                    <span>Strmá</span>
                                </div>
                                <div class="range-field">
                                    <div class="range-slider slope-range-slider" id="slopeRangeSlider">
                                        <input
                                            type="range"
                                            id="slopeFilter"
                                            name="slopeFilter"
                                            min="0"
                                            max="5"
                                            step="1"
                                            value="3"
                                            aria-label="Filtr rovinatosti od úplně rovné po strmou"
                                            aria-valuemin="0"
                                            aria-valuemax="5"
                                        >
                                    </div>
                                </div>
                                <div class="slope-tick-labels" aria-hidden="true">
                                    <span>1</span>
                                    <span>2</span>
                                    <span>3</span>
                                    <span>4</span>
                                    <span>5</span>
                                    <span>6</span>
                                </div>
                            </div>

                            <details class="advanced-flatness">
                                <summary>Pokročilé nastavení rovinatosti</summary>
                                <div class="advanced-flatness-fields">
                                    <label class="advanced-field" data-metric-help="largestFlatPatchShare">
                                        <span class="filter-label">Minimální souvislá rovná plocha (%)</span>
                                        <input
                                            type="number"
                                            id="minLargestFlatPatchShare"
                                            name="minLargestFlatPatchShare"
                                            min="0"
                                            max="100"
                                            step="1"
                                            inputmode="numeric"
                                        >
                                    </label>
                                    <label class="advanced-field" data-metric-help="flatAreaShare">
                                        <span class="filter-label">Minimální rovná část louky (%)</span>
                                        <input
                                            type="number"
                                            id="minFlatAreaShare"
                                            name="minFlatAreaShare"
                                            min="0"
                                            max="100"
                                            step="1"
                                            inputmode="numeric"
                                        >
                                    </label>
                                    <label class="advanced-field" data-metric-help="terrainRoughnessP80">
                                        <span class="filter-label">Maximální členitost terénu P80 (m)</span>
                                        <input
                                            type="number"
                                            id="maxTerrainRoughnessP80M"
                                            name="maxTerrainRoughnessP80M"
                                            min="0"
                                            step="0.1"
                                            inputmode="decimal"
                                        >
                                    </label>
                                </div>
                            </details>
                        </section>

                        <div class="button-row">
                            <button type="button" id="resetFilters">Obnovit</button>
                        </div>
                    </div>
                </details>
            </form>

            <div id="selection" class="selection">
                <strong>Klikněte na louku</strong>
                <p>Zde se zobrazí podrobnosti o parcele.</p>
            </div>

            <div id="userPanel" class="user-panel" aria-label="Účet"></div>

            <div class="welcome-modal-credit">
                <div class="welcome-modal-credit-author">
                    <span>Vytvořil Jan Korbay</span>
                    <a class="welcome-modal-email" href="mailto:jan.korbay@skaut.cz">jan.korbay@skaut.cz</a>
                </div>
                <div class="welcome-modal-socials" aria-label="Sociální sítě autora">
                    <a
                        class="welcome-modal-social"
                        href="https://www.facebook.com/jankorbay/"
                        target="_blank"
                        rel="noopener noreferrer"
                        aria-label="Facebook Jana Korbaye"
                    >
                        <svg viewBox="0 0 24 24" focusable="false" aria-hidden="true">
                            <path d="M13.5 21v-7h2.4l0.4-3h-2.8V9.2c0-0.9 0.2-1.5 1.5-1.5H16V5.1c-0.2 0-0.9-0.1-1.8-0.1-2.7 0-4.4 1.6-4.4 4.6V11H7v3h2.8v7h3.7Z"></path>
                        </svg>
                    </a>
                    <a
                        class="welcome-modal-social"
                        href="https://www.linkedin.com/in/jan-korbay/"
                        target="_blank"
                        rel="noopener noreferrer"
                        aria-label="LinkedIn Jana Korbaye"
                    >
                        <svg viewBox="0 0 24 24" focusable="false" aria-hidden="true">
                            <path d="M6.8 8.7A1.8 1.8 0 1 1 6.8 5a1.8 1.8 0 0 1 0 3.7Zm1.5 1.7H5.3V19h3V10.4Zm4.8 0h-2.9V19h3v-4.5c0-1.2 0.2-2.4 1.7-2.4s1.6 1.4 1.6 2.5V19h3v-5.5c0-2.7-1.4-4-3.5-4-1.5 0-2.2 0.8-2.6 1.4h0V10.4Z"></path>
                        </svg>
                    </a>
                </div>
            </div>
            <p class="sidebar-legal">
                <a href="privacy.php">Zásady ochrany osobních údajů</a>
                <a href="terms.php">Obchodní podmínky</a>
            </p>
        </aside>

        <button
            type="button"
            class="sidebar-backdrop"
            id="sidebarBackdrop"
            aria-label="Zavřít filtry a podrobnosti"
            hidden
        ></button>

        <main class="map-shell">
            <div class="map-toolbar">
                <button
                    type="button"
                    class="map-action-button map-action-button-search"
                    id="searchToggle"
                    aria-controls="map"
                    aria-expanded="false"
                    aria-label="Otevřít hledání"
                >
                    <span class="map-action-icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24" focusable="false">
                            <circle cx="11" cy="11" r="6"></circle>
                            <path d="M16 16l4.5 4.5"></path>
                        </svg>
                    </span>
                    <span class="map-action-label">Hledat</span>
                </button>
                <button
                    type="button"
                    class="map-action-button sidebar-toggle"
                    id="sidebarToggle"
                    aria-controls="sidebarDrawer"
                    aria-expanded="false"
                    aria-label="Otevřít filtry a podrobnosti"
                >
                    <span class="map-action-icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24" focusable="false">
                            <path d="M4 6h16"></path>
                            <path d="M7 12h10"></path>
                            <path d="M10 18h4"></path>
                        </svg>
                    </span>
                    <span class="map-action-label">Filtry</span>
                </button>
                <button
                    type="button"
                    class="map-action-button map-action-button-layers"
                    id="layersToggle"
                    aria-controls="map"
                    aria-expanded="false"
                    aria-label="Otevřít vrstvy mapy"
                >
                    <span class="map-action-icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24" focusable="false">
                            <path d="M12 5l7 4-7 4-7-4 7-4Z"></path>
                            <path d="M5 13l7 4 7-4"></path>
                            <path d="M5 17l7 4 7-4"></path>
                        </svg>
                    </span>
                    <span class="map-action-label">Vrstvy</span>
                </button>
            </div>
            <div id="map"></div>
        </main>
    </div>

    <div id="welcomeModal" class="welcome-modal" hidden>
        <div class="welcome-modal-backdrop" tabindex="-1"></div>
        <div
            class="welcome-modal-dialog"
            role="dialog"
            aria-modal="true"
            aria-labelledby="welcomeModalTitle"
        >
            <h1 id="welcomeModalTitle">Vítejte ve Vyhledávači luk</h1>
            <p class="welcome-modal-lead">
                Najděte louky v Česku podle polohy, velikosti, okolí a přibližné rovinnosti.
                Začněte vyhledáním místa nebo posunem mapy.
            </p>
            <ul class="welcome-modal-tips">
                <li>Filtrujte podle plochy, vzdálenosti od cest, vody a zástavby.</li>
                <li>Přepínejte mezi běžnou, satelitní, katastrální a turistickou mapou.</li>
                <li>Klikněte na louku pro detail a po přihlášení si ji uložte mezi oblíbené.</li>
            </ul>
            <div class="welcome-modal-note">
                <p>
                    Data slouží jako orientační podklad. Vhodnost místa vždy ověřte podle skutečného
                    stavu, přístupu a pravidel v terénu.
                </p>
            </div>
            <div class="welcome-modal-note">
                <p>
                    <strong>
                        Chcete něco opravit nebo vylepšit?
                    </strong>
                    <br>
                    Napište mi zprávu - třeba na e-mail níže.
                </p>
            </div>
            <div class="welcome-modal-footer">
                <label class="welcome-modal-toggle">
                    <input type="checkbox" id="welcomeModalDontShow">
                    <span>Příště nezobrazovat</span>
                </label>
                <button type="button" class="welcome-modal-start">Začít</button>
            </div>
            <div class="welcome-modal-credit">
                <div class="welcome-modal-credit-author">
                    <span>Vytvořil Jan Korbay</span>
                    <a class="welcome-modal-email" href="mailto:jan.korbay@skaut.cz">jan.korbay@skaut.cz</a>
                </div>
                <div class="welcome-modal-socials" aria-label="Sociální sítě autora">
                    <a
                        class="welcome-modal-social"
                        href="https://www.facebook.com/jankorbay/"
                        target="_blank"
                        rel="noopener noreferrer"
                        aria-label="Facebook Jana Korbaye"
                    >
                        <svg viewBox="0 0 24 24" focusable="false" aria-hidden="true">
                            <path d="M13.5 21v-7h2.4l0.4-3h-2.8V9.2c0-0.9 0.2-1.5 1.5-1.5H16V5.1c-0.2 0-0.9-0.1-1.8-0.1-2.7 0-4.4 1.6-4.4 4.6V11H7v3h2.8v7h3.7Z"></path>
                        </svg>
                    </a>
                    <a
                        class="welcome-modal-social"
                        href="https://www.linkedin.com/in/jan-korbay/"
                        target="_blank"
                        rel="noopener noreferrer"
                        aria-label="LinkedIn Jana Korbaye"
                    >
                        <svg viewBox="0 0 24 24" focusable="false" aria-hidden="true">
                            <path d="M6.8 8.7A1.8 1.8 0 1 1 6.8 5a1.8 1.8 0 0 1 0 3.7Zm1.5 1.7H5.3V19h3V10.4Zm4.8 0h-2.9V19h3v-4.5c0-1.2 0.2-2.4 1.7-2.4s1.6 1.4 1.6 2.5V19h3v-5.5c0-2.7-1.4-4-3.5-4-1.5 0-2.2 0.8-2.6 1.4h0V10.4Z"></path>
                        </svg>
                    </a>
                </div>
            </div>
        </div>
    </div>

    <div id="loginModal" class="login-modal" hidden>
        <div class="login-modal-backdrop" tabindex="-1"></div>
        <div
            class="login-modal-dialog"
            role="dialog"
            aria-modal="true"
            aria-labelledby="loginModalTitle"
        >
            <h2 id="loginModalTitle">Přihlášení</h2>
            <p class="login-modal-lead">Pro ukládání oblíbených luk se přihlaste Google účtem.</p>
            <a class="button login-modal-google" href="api/auth_start.php">Pokračovat přes Google</a>
        </div>
    </div>

    <script
        src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"
        integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo="
        crossorigin=""
    ></script>
    <script src="assets/app.js"></script>
</body>
</html>
