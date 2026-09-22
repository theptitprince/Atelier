# Module Entretien (GMAO domestique)

Suivi de l’entretien des biens du foyer : voiture, chaudière, électroménager, habitation, jardin, matériel informatique.

## Fonctions

- **Équipements** : nom, catégorie, marque/modèle, immatriculation ou n° de série, date d’acquisition, emplacement, notes (BBCode), tags partagés, documents joints (notices, carte grise, contrat). Compteur facultatif (km, heures, cycles) avec relevé rapide depuis la liste ou la fiche. Corbeille avec restauration et purge automatique (`trash.retention_days`).
- **Tâches d’entretien planifiées** (`preventive`) : périodicité en jours et/ou au compteur, prochaine échéance (date et/ou compteur), **rappel anticipé propre à chaque tâche** (`lead_days`, `lead_meter`). Quand une intervention est enregistrée, la prochaine échéance est recalculée ; une tâche sans périodicité est clôturée.
- **Pannes et défauts** (`corrective`) : intervention ponctuelle avec priorité et date cible facultative, clôturée à la réparation.
- **Fiche d’intervention** (job card) par tâche : descriptif BBCode, pièces et consommables, contacts, outillage, durée et coût estimés ; version **imprimable** (`job/{id}/print`) avec cases à cocher et zone de compte rendu.
- **Historique** des interventions : date, compteur, coût, intervenant, notes, factures jointes ; filtres par équipement, année et texte ; total des coûts ; export CSV.
- **Rappels** : états `À jour` / `Bientôt` / `À faire` / `En retard` calculés à l’affichage (`Scheduler`), badge du module dans la colonne (nombre de rappels), tableau de bord (à faire, bientôt, pannes ouvertes, dernières interventions, dépenses sur 12 mois), export iCalendar (`reminders.ics`) avec alarme anticipée.
- **Pièces jointes** : téléversement direct depuis les fiches (équipement, tâche, intervention) via le mécanisme commun, ou depuis le module Fichiers joints (`upload?info=<uuid>`) ; téléchargement contrôlé par `/files/{id}`.

## Droits

Permissions génériques sur `atelier/maintenance` (`open`, `create`, `update`, `delete`) ; `export` sur `atelier/maintenance/action/export` pour le CSV et le calendrier. Les jeux `maintenance.asset`, `maintenance.job` et `maintenance.log` sont partagés : leur lecture (`atelier/maintenance/data/<jeu>`) conditionne l’accès aux fichiers rattachés et au service intermodule.

## Service intermodule

`$this->ctx->moduleService('maintenance')` : `assets($userId)`, `assetLabel($userId, $id)`, `reminders($userId, $limit)` (tâches en rappel, les plus pressantes d’abord), `reminderCount()`.

## Données

Tables `maintenance_asset`, `maintenance_job`, `maintenance_log`. Les échéances et dates de réalisation sont des jours calendaires stockés en texte `AAAA-MM-JJ` ; les montants sont en centimes.

## Règles d’échéance (`Scheduler`)

- Par date : `days_left < 0` → en retard ; `0` → à faire ; `≤ lead_days` → bientôt ; sinon à jour.
- Par compteur : même logique avec `meter_left` et `lead_meter` (si l’équipement a un relevé).
- L’état retenu est le plus pressant des deux ; la prochaine échéance après intervention vaut `date + interval_days` et/ou `compteur + interval_meter`.

`console db:seed` crée une voiture et une chaudière d’exemple avec cinq tâches (dont une panne) et trois interventions.
