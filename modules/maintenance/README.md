# Module Entretien (GMAO domestique)

Suivi de l’entretien des biens du foyer : voiture, chaudière, électroménager, habitation, jardin, matériel informatique. Version 1.3.0.

## Fonctions

- **Équipements** : nom, catégorie, marque/modèle, immatriculation ou n° de série, date d’acquisition, emplacement, notes (BBCode), tags partagés, documents joints (notices, carte grise, contrat : bloc « Documents » de la fiche, toujours visible). Compteur facultatif (km, heures, cycles) avec relevé rapide depuis la liste ou la fiche.
- **Tâches d’entretien planifiées** (`preventive`) : périodicité en jours et/ou au compteur, prochaine échéance (date et/ou compteur), **rappel anticipé propre à chaque tâche** (`lead_days`, `lead_meter`). Quand une intervention est enregistrée, la prochaine échéance est recalculée ; une tâche sans périodicité est clôturée.
- **Pannes et défauts** (`corrective`) : intervention ponctuelle avec priorité et date cible facultative, clôturée à la réparation.
- **Fiche d’intervention** (job card) par tâche : descriptif BBCode, pièces et consommables, contacts, outillage, durée et coût estimés ; version **imprimable** (`job/{id}/print`) avec cases à cocher et zone de compte rendu.
- **Historique** des interventions : date, compteur, coût, intervenant, notes, documents joints, **tags partagés** (1.3.0 : champ commun `data-tags-input` du formulaire d'intervention, affichés dans l'historique) ; filtres par équipement, année et texte (les années proposées sont celles des interventions réellement listées : ni les interventions ni les équipements en corbeille n'en ajoutent) ; total des coûts ; export CSV. Chaque ligne porte un dépliant « documents » (liste, téléchargement `/files/{id}`, dépôt direct).
- **Rappels** : états `À jour` / `Bientôt` / `À faire` / `En retard` calculés à l’affichage (`Scheduler`), badge du module dans la colonne (nombre de rappels), tableau de bord (à faire, bientôt, pannes ouvertes, dernières interventions, dépenses sur 12 mois), export iCalendar (`reminders.ics`) avec alarme anticipée.
- **Pièces jointes** : téléversement direct depuis les fiches (équipement, tâche, intervention) via le mécanisme commun (`attach`, multipart `files[]` ou `file`, description facultative), dès la création d’une intervention (champ « Documents à joindre » du formulaire `log/new`, y compris quand elle est saisie depuis « Marquer comme fait » d’une tâche), depuis le dépliant de l’historique, ou depuis le module Fichiers joints (`upload?info=<uuid>`) ; téléchargement contrôlé par `/files/{id}`.
- **Corbeille** (1.2.0) : équipements, tâches et interventions sont supprimés logiquement (`deleted_at`) et exclus de toutes les listes, comptages, rappels, badge, calendrier, exports et replanification. Vue `trash` du module (trois sections, actions `trash-restore` / `trash-purge` avec identifiant préfixé `asset:12`, `job:5`, `log:9`) et **corbeille globale** (`TrashProviderInterface` : `trashItems`, `restoreTrashItem`, `purgeTrashItem` ; restauration = droit `update`, purge = droit `delete`). Restaurer une tâche ou une intervention dont l’équipement est en corbeille restaure aussi l’équipement (même transaction). La mise en corbeille d’un équipement conserve ses tâches et interventions ; sa purge physique les purge en cascade et retire leurs inscriptions du registre commun. La mise en corbeille d’une intervention retire son opération du module Budget ; sa restauration la reporte de nouveau. Purge automatique après `trash.retention_days` (hook `purge()`, `console maintenance:purge`).
- **Registre commun et corbeille** (1.3.0) : chaque mise en corbeille et chaque restauration d'un équipement, d'une tâche ou d'une intervention (vue du module, `asset-restore`, corbeille globale) est signalée au registre commun (`registry->trash()` / `registry->restore()`) : l'élément disparaît des tags, de l'Explorateur et des éléments liés au lieu d'y laisser un lien menant à une 404. **Cascade** : un équipement en corbeille signale aussi ses tâches et interventions ; sa restauration (directe, ou induite par celle d'une de ses tâches ou interventions) ne fait réapparaître que celles qui n'étaient pas en corbeille pour leur propre compte. La migration `003_registry_trash` rattrape l'existant (rejouable).

## Droits

Permissions génériques sur `atelier/maintenance` (`open`, `create`, `update`, `delete`) ; `export` sur `atelier/maintenance/action/export` pour le CSV et le calendrier. Les jeux `maintenance.asset`, `maintenance.job` et `maintenance.log` sont partagés : leur lecture (`atelier/maintenance/data/<jeu>`) conditionne l’accès aux fichiers rattachés et au service intermodule. Routes d’ouverture (`openRoute`) pour les vues transversales : `asset/{key}` (fiche), `job/{key}` (fiche de la tâche), `log/{key}/edit` (fiche de l’intervention, droit `update`).

## Service intermodule

`$this->ctx->moduleService('maintenance')` : `assets($userId)`, `assetLabel($userId, $id)`, `reminders($userId, $limit)` (tâches en rappel, les plus pressantes d’abord), `reminderCount()`.

## Données

Tables `maintenance_asset`, `maintenance_job`, `maintenance_log`, chacune avec une colonne `deleted_at` (corbeille ; migration `002_soft_delete` pour les tâches et interventions). Migration `003_registry_trash` : pose `info_registry.trashed_at` sur les éléments déjà en corbeille ou masqués par un équipement en corbeille. Les échéances et dates de réalisation sont des jours calendaires stockés en texte `AAAA-MM-JJ` ; les montants sont en centimes.

## Règles d’échéance (`Scheduler`)

- Par date : `days_left < 0` → en retard ; `0` → à faire ; `≤ lead_days` → bientôt ; sinon à jour.
- Par compteur : même logique avec `meter_left` et `lead_meter` (si l’équipement a un relevé).
- L’état retenu est le plus pressant des deux ; la prochaine échéance après intervention vaut `date + interval_days` et/ou `compteur + interval_meter`.

`console db:seed` crée une voiture et une chaudière d’exemple avec cinq tâches (dont une panne) et trois interventions.

## Vérification rapide (curl)

```bash
# après connexion (cookie /tmp/cj.txt, jeton $CSRF), en-tête X-Atelier-Request: json
curl -s -b /tmp/cj.txt -H "X-Atelier-Request: json" -H "X-CSRF-Token: $CSRF" -X POST http://127.0.0.1:8000/m/maintenance/job-delete -H "Content-Type: application/json" -d '{"id":5}'
curl -s -b /tmp/cj.txt -H "X-Atelier-Request: json" "http://127.0.0.1:8000/m/trash/list?module=maintenance"
curl -s -b /tmp/cj.txt -H "X-Atelier-Request: json" -H "X-CSRF-Token: $CSRF" -X POST http://127.0.0.1:8000/m/trash/restore -H "Content-Type: application/json" -d '{"source":"module","module":"maintenance","id":"job:5"}'
curl -s -b /tmp/cj.txt -H "X-Atelier-Request: json" -H "X-CSRF-Token: $CSRF" -X POST http://127.0.0.1:8000/m/maintenance/attach -F target=asset -F id=1 -F file=@notice.pdf -F "description=Notice"
```
