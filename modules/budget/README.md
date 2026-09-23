# Module Budget (comptabilité domestique)

Livre de comptes du foyer : comptes, opérations, budgets par catégorie, prévisionnel, épargne et économies.

## Fonctions

- **Comptes** : courant, épargne, espèces, autre ; solde initial, solde courant et solde pointé calculés ; archivage (compte clos conservé dans l'historique) ou mise en corbeille.
- **Corbeille** (1.1.0) : la suppression d'une opération (unitaire ou groupée), d'un compte, d'une récurrence, d'un objectif ou d'une économie est **logique** (`deleted_at`, `deleted_by`) : l'élément disparaît de toutes les listes, soldes, totaux, budgets réalisés, prévisionnel, exports, badge et du service intermodule, et reste restaurable pendant `trash.retention_days` (30 jours) depuis l'écran « Corbeille » du module ou la corbeille globale (module `trash`, `TrashProviderInterface`). Un compte en corbeille **masque** ses opérations et ses récurrences (sans les supprimer) ; les objectifs qui lui sont liés repassent en progression manuelle. La suppression définitive (bouton, ou rétention via `maintenance:purge`) d'un compte efface ses opérations (registre commun nettoyé, pièces jointes rendues orphelines) et ses récurrences. Les justificatifs joints à une opération en corbeille ne sont pas touchés. Une opération importée reste un doublon connu tant qu'elle est en corbeille. Identifiants de corbeille : `transaction:<id>`, `account:<id>`, `recurring:<id>`, `goal:<id>`, `saving:<id>` ; restauration = `update`, purge = `delete`. **Catégories et budgets (enveloppes) restent en suppression physique** : une catégorie ne se supprime que si aucune opération (corbeille comprise), budget, récurrence ou économie ne s'y rattache (sinon `409`), l'archivage étant la voie normale ; un budget est un réglage daté, supprimable directement.
- **Opérations** : montant signé en centimes (dépense négative, recette positive), catégorie, tiers, notes BBCode, pointage, origine (saisie, import, récurrence, Entretien), justificatifs joints (mécanisme commun et module Fichiers joints). Filtres (compte, catégorie, mois, origine, non pointées, texte), sélection et actions groupées (pointer, catégoriser, supprimer), export CSV.
- **Import CSV** de relevés bancaires : séparateur et encodage détectés, en-têtes tolérants (date, libellé, montant ou débit/crédit, tiers, catégorie), doublons ignorés (empreinte compte + date + montant + libellé), catégorie devinée à partir des opérations déjà catégorisées portant le même libellé.
- **Catégories** à deux niveaux, dépenses et recettes, jeu courant proposé ; **budgets (enveloppes)** mensuels ou annuels, datés (un budget s'applique à partir d'un mois donné). Vue « Budget du mois » : réalisé / budget / reste par catégorie, barre de consommation, saisie directe des budgets.
- **Prévisionnel** : opérations récurrentes (périodicité libre, fin éventuelle) à « poster » quand l'échéance est atteinte (badge du module, tableau de bord, tout poster) ; projection du solde sur 6, 12 ou 24 mois à partir des récurrences actives et des coûts estimés des entretiens à venir (service du module Entretien).
- **Épargne et économies** : objectifs (cible, échéance, compte associé ou montant manuel, progression) ; économies **calculées** (budget cumulé − dépensé par catégorie sur les mois écoulés de l'année) et économies **enregistrées** (registre manuel : gain ponctuel, mensuel ou annuel avec date d'effet, cumulé sur l'année).
- **Lien avec l'Entretien** : le coût réel d'une intervention enregistrée dans le module Entretien crée ou met à jour une opération d'origine « Entretien » (référence `maintenance_log:<id>`), sur le compte et la catégorie définis dans les réglages (repli : premier compte courant actif, catégorie « Entretien et réparations »). La suppression de l'intervention retire l'opération. Désactivable dans les réglages.

## Droits

Permissions génériques sur `atelier/budget` (`open`, `create`, `update`, `delete`, `admin` pour les réglages) ; `import` sur `atelier/budget/action/import`, `export` sur `atelier/budget/action/export`. Jeux partagés : `budget.account`, `budget.category`, `budget.transaction` ; privés : `budget.recurring`, `budget.saving`.

## Service intermodule

`$this->ctx->moduleService('budget')` : `accounts($userId)`, `recordExternal($userId, $sourceRef, $source, ['label', 'amount', 'done_at', 'payee', 'notes'])`, `removeExternal($userId, $sourceRef)` (mise en corbeille), `externalTransaction($userId, $sourceRef)`, `isExternalRecordingEnabled()`. Le service vérifie les droits du demandeur sur le jeu concerné et ignore les opérations en corbeille.

## Données

Tables `budget_account`, `budget_category`, `budget_envelope`, `budget_transaction`, `budget_recurring`, `budget_goal`, `budget_saving`. Montants en centimes ; dates calendaires en texte `AAAA-MM-JJ`. Migration `002_soft_delete` : colonnes `deleted_at` (indexée) et `deleted_by` sur les comptes, opérations, récurrences, objectifs et économies.

Hook `purge()` (`console maintenance:purge`) : suppression physique des éléments en corbeille depuis plus de `trash.retention_days` jours.

`console db:seed` crée deux comptes, les catégories courantes, six budgets, treize opérations, cinq récurrences, deux objectifs et deux économies d'exemple.
