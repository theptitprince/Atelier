<?php
/**
 * Import CSV de points GPS.
 * @var int $maxRows
 * @var array<string, list<string>> $headers
 */
?>
<div class="module module-geo">
    <div class="geo__form-layout">
        <form class="card" data-action="import" enctype="multipart/form-data" novalidate data-geo-import>
            <div class="card__header"><h2 class="card__title"><svg class="icon" aria-hidden="true"><use href="#i-upload"></use></svg> Fichier CSV</h2></div>
            <div class="card__body">
                <div class="field">
                    <label class="field__label" for="geo-import-file">Fichier <span class="required" aria-hidden="true">*</span></label>
                    <input class="input" type="file" id="geo-import-file" name="file" accept=".csv,text/csv,text/plain" required>
                    <span class="field__help">UTF-8 (avec ou sans BOM), séparateur « ; », « , » ou tabulation détecté automatiquement, première ligne = en-têtes. <?= (int) $maxRows ?> lignes au plus par fichier.</span>
                    <span class="field__error"></span>
                </div>
                <div class="form-actions form-actions--end">
                    <a class="btn btn--ghost" href="#" data-route="list">Annuler</a>
                    <button type="submit" class="btn btn--primary"><svg class="icon" aria-hidden="true"><use href="#i-upload"></use></svg> Importer</button>
                </div>
            </div>
        </form>

        <aside class="card">
            <div class="card__header"><h2 class="card__title"><svg class="icon" aria-hidden="true"><use href="#i-info"></use></svg> Colonnes reconnues</h2></div>
            <div class="card__body">
                <p>Les en-têtes sont reconnus sans tenir compte de la casse ni des accents. Le nom et les coordonnées sont obligatoires ; les coordonnées peuvent être données en deux colonnes (latitude, longitude) ou en une seule colonne libre.</p>
                <dl class="dl">
                    <?php foreach ($headers as $field => $aliases): ?>
                        <dt><code><?= $e($field) ?></code></dt>
                        <dd class="text-small"><?= $e(implode(', ', $aliases)) ?></dd>
                    <?php endforeach; ?>
                </dl>
                <h4 class="mt-3">Exemple</h4>
<pre class="mb-0">nom;code;latitude;longitude;altitude;adresse
Tour Eiffel;TE;48.858370;2.294481;330;Champ de Mars, Paris
Pointe du Raz;PR;48°02'15"N;4°44'17"W;70;Plogoff</pre>
            </div>
        </aside>
    </div>

    <section class="card" data-geo-import-report hidden aria-live="polite">
        <div class="card__header"><h2 class="card__title"><svg class="icon" aria-hidden="true"><use href="#i-activity"></use></svg> Résultat de l’import</h2></div>
        <div class="card__body">
            <p data-geo-import-summary></p>
            <div class="table-wrap" data-geo-import-errors hidden>
                <table class="table table--compact">
                    <thead><tr><th>Ligne</th><th>Nom</th><th>Motif du rejet</th></tr></thead>
                    <tbody data-geo-import-errors-body></tbody>
                </table>
            </div>
            <a class="btn btn--sm" href="#" data-route="list"><svg class="icon" aria-hidden="true"><use href="#i-list"></use></svg> Voir la liste des points</a>
        </div>
    </section>
</div>
