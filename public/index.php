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
</head>
<body>
    <div class="layout">
        <aside class="sidebar">
            <h1>Vyhledávač luk</h1>

            <form id="filters" class="filters">
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

                <div class="button-row">
                    <button type="button" id="resetFilters">Obnovit</button>
                </div>
            </form>

            <dl class="stats">
                <div>
                    <dt>Zobrazené louky</dt>
                    <dd id="countText">0</dd>
                </div>
            </dl>

            <div id="selection" class="selection">
                <strong>Klikněte na louku</strong>
                <p>Zde se zobrazí podrobnosti o parcele.</p>
            </div>
        </aside>

        <main class="map-shell">
            <div id="map"></div>
        </main>
    </div>

    <script
        src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"
        integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo="
        crossorigin=""
    ></script>
    <script src="assets/app.js"></script>
</body>
</html>
