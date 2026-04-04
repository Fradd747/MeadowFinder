<?php

declare(strict_types=1);
?><!DOCTYPE html>
<html lang="cs">
<head>
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
        <aside class="sidebar">
            <h1>Vyhledávač luk</h1>

            <form id="filters" class="filters">
                <details class="filters-panel" open>
                    <summary class="filters-panel-summary">Filtry</summary>
                    <div class="filters-panel-body">
                        <div class="range-group">
                            <div class="range-label-row">
                                <span class="filter-label">Plocha (m²)</span>
                                <span class="range-value" id="areaRangeValue">1 000 m² - Bez limitu</span>
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
                                        value="1000"
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
                                <span class="range-value" id="roadRangeValue">0 m - Bez limitu</span>
                            </div>
                            <div class="range-field range-field-dual">
                                <div class="range-slider" id="roadRange">
                                    <input
                                        type="range"
                                        id="minRoad"
                                        name="minRoad"
                                        min="0"
                                        max="5000"
                                        step="10"
                                        value="0"
                                        aria-label="Minimální vzdálenost od silnice"
                                    >
                                    <input
                                        type="range"
                                        id="maxRoad"
                                        name="maxRoad"
                                        min="0"
                                        max="5000"
                                        step="10"
                                        value="5000"
                                        aria-label="Maximální vzdálenost od silnice"
                                    >
                                </div>
                            </div>
                        </div>

                        <div class="range-group">
                            <div class="range-label-row">
                                <span class="filter-label">Vzdálenost od cesty (m)</span>
                                <span class="range-value" id="pathRangeValue">0 m - Bez limitu</span>
                            </div>
                            <div class="range-field range-field-dual">
                                <div class="range-slider" id="pathRange">
                                    <input
                                        type="range"
                                        id="minPath"
                                        name="minPath"
                                        min="0"
                                        max="5000"
                                        step="10"
                                        value="0"
                                        aria-label="Minimální vzdálenost od cesty"
                                    >
                                    <input
                                        type="range"
                                        id="maxPath"
                                        name="maxPath"
                                        min="0"
                                        max="5000"
                                        step="10"
                                        value="5000"
                                        aria-label="Maximální vzdálenost od cesty"
                                    >
                                </div>
                            </div>
                        </div>

                        <div class="range-group">
                            <div class="range-label-row">
                                <span class="filter-label">Vzdálenost od vody (m)</span>
                                <span class="range-value" id="waterRangeValue">0 m - Bez limitu</span>
                            </div>
                            <div class="range-field range-field-dual">
                                <div class="range-slider" id="waterRange">
                                    <input
                                        type="range"
                                        id="minWater"
                                        name="minWater"
                                        min="0"
                                        max="5000"
                                        step="10"
                                        value="0"
                                        aria-label="Minimální vzdálenost od vody"
                                    >
                                    <input
                                        type="range"
                                        id="maxWater"
                                        name="maxWater"
                                        min="0"
                                        max="5000"
                                        step="10"
                                        value="5000"
                                        aria-label="Maximální vzdálenost od vody"
                                    >
                                </div>
                            </div>
                        </div>

                        <div class="range-group">
                            <div class="range-label-row">
                                <span class="filter-label">Vzdálenost od větší řeky (m)</span>
                                <span class="range-value" id="riverRangeValue">0 m - Bez limitu</span>
                            </div>
                            <div class="range-field range-field-dual">
                                <div class="range-slider" id="riverRange">
                                    <input
                                        type="range"
                                        id="minRiver"
                                        name="minRiver"
                                        min="0"
                                        max="5000"
                                        step="10"
                                        value="0"
                                        aria-label="Minimální vzdálenost od větší řeky"
                                    >
                                    <input
                                        type="range"
                                        id="maxRiver"
                                        name="maxRiver"
                                        min="0"
                                        max="5000"
                                        step="10"
                                        value="5000"
                                        aria-label="Maximální vzdálenost od větší řeky"
                                    >
                                </div>
                            </div>
                        </div>

                        <div class="range-group">
                            <div class="range-label-row">
                                <span class="filter-label">Vzdálenost od vesnice/města (m)</span>
                                <span class="range-value" id="settlementRangeValue">0 m - Bez limitu</span>
                            </div>
                            <div class="range-field range-field-dual">
                                <div class="range-slider" id="settlementRange">
                                    <input
                                        type="range"
                                        id="minSettlement"
                                        name="minSettlement"
                                        min="0"
                                        max="5000"
                                        step="10"
                                        value="0"
                                        aria-label="Minimální vzdálenost od vesnice nebo města"
                                    >
                                    <input
                                        type="range"
                                        id="maxSettlement"
                                        name="maxSettlement"
                                        min="0"
                                        max="5000"
                                        step="10"
                                        value="5000"
                                        aria-label="Maximální vzdálenost od vesnice nebo města"
                                    >
                                </div>
                            </div>
                        </div>

                        <div class="range-group">
                            <div class="range-label-row">
                                <span class="filter-label">Vzdálenost od nejbližší budovy (m)</span>
                                <span class="range-value" id="buildingRangeValue">0 m - Bez limitu</span>
                            </div>
                            <div class="range-field range-field-dual">
                                <div class="range-slider" id="buildingRange">
                                    <input
                                        type="range"
                                        id="minBuilding"
                                        name="minBuilding"
                                        min="0"
                                        max="5000"
                                        step="10"
                                        value="0"
                                        aria-label="Minimální vzdálenost od budovy"
                                    >
                                    <input
                                        type="range"
                                        id="maxBuilding"
                                        name="maxBuilding"
                                        min="0"
                                        max="5000"
                                        step="10"
                                        value="5000"
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
                                    <span class="range-value" id="slopeFilterValue">Strmá</span>
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
                                            value="5"
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

            <p class="sidebar-legal">
                <a href="privacy.php">Zásady ochrany osobních údajů</a>
                <a href="terms.php">Obchodní podmínky</a>
            </p>
        </aside>

        <main class="map-shell">
            <div id="map"></div>
        </main>
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
