<?php
/**
 * Carte des points GPS : panneau latéral (recherche, tri, fonds, calques, liste) et carte Leaflet.
 * @var true|string $available @var bool $geoModule
 * @var array<string, string> $baseLayers @var array<string, string> $sorts @var array<string, mixed> $prefs
 * @var \Atelier\Modules\Map\MapModule $module
 */
?>
<div class="module module-map">
    <?php if ($available !== true): ?>
        <?= $module->renderCore('state', ['type' => $geoModule ? 'denied' : 'unavailable', 'title' => 'Carte indisponible', 'message' => $available]) ?>
    <?php else: ?>
        <div class="map__layout">
            <aside class="map__side">
                <div class="card card--compact">
                    <div class="card__body">
                        <div class="field">
                            <label class="sr-only" for="map-search">Rechercher un point</label>
                            <div class="input-icon"><svg class="icon icon--sm" aria-hidden="true"><use href="#i-search"></use></svg><input class="input input--sm" type="search" id="map-search" placeholder="Nom, code, adresse, tag…" autocomplete="off" data-map-search></div>
                        </div>
                        <div class="field mb-0">
                            <label class="field__label" for="map-sort">Trier la liste</label>
                            <select class="select select--sm" id="map-sort" data-map-sort>
                                <?php foreach ($sorts as $key => $label): ?><option value="<?= $e($key) ?>"<?= $prefs['sort'] === $key ? ' selected' : '' ?>><?= $e($label) ?></option><?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="card card--compact">
                    <div class="card__body">
                        <h4>Fond de carte</h4>
                        <?php foreach ($baseLayers as $key => $label): ?>
                            <label class="radio map__option"><input type="radio" name="map-base" value="<?= $e($key) ?>"<?= $prefs['base'] === $key ? ' checked' : '' ?> data-map-base> <span><?= $e($label) ?></span></label>
                        <?php endforeach; ?>
                        <label class="checkbox map__option mt-2"><input type="checkbox" data-map-seamarks<?= $prefs['seamarks'] ? ' checked' : '' ?>> <span>Balisage maritime OpenSeaMap en surimpression</span></label>
                        <h4 class="mt-3">Calques</h4>
                        <div class="map__layers" data-map-layers><p class="text-muted text-small mb-0">Chargement…</p></div>
                    </div>
                </div>
                <div class="card card--compact map__list-card">
                    <div class="card__header"><h2 class="card__title">Points</h2><span class="badge badge--muted" data-map-count>0</span></div>
                    <ul class="list map__list" data-map-list role="listbox" aria-label="Points GPS"></ul>
                </div>
            </aside>
            <div class="map__main">
                <div class="map__canvas" data-map-canvas role="application" aria-label="Carte interactive"></div>
                <p class="text-muted text-small mt-2 mb-0">Fonds : © contributeurs <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener noreferrer">OpenStreetMap</a> (ODbL) · balisage © <a href="https://www.openseamap.org/" target="_blank" rel="noopener noreferrer">OpenSeaMap</a> (CC BY-SA). Les tuiles sont chargées depuis ces serveurs : la carte nécessite un accès Internet.</p>
            </div>
        </div>
    <?php endif; ?>
</div>
