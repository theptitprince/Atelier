# Module Budget (comptabilité domestique)

Livre de comptes du foyer : comptes, opérations, budgets par catégorie, prévisionnel, épargne et économies.

## Fonctions

- **Comptes** : courant, épargne, espèces, autre ; solde initial, solde courant et solde pointé calculés ; archivage (un compte avec opérations ne se supprime pas).
- **Opérations** : montant signé en centimes (dépense négative, recette positive), catégorie, tiers, notes BBCode, pointage, origine (saisie, import, récurrence, Entretien), justificatifs joints (mécanisme commun et module Fichiers joints). Filtres (compte, catégorie, mois, origine, non pointées, texte), sélection et actions groupées (pointer, catégoriser, supprimer), export CSV.
- **Import CSV** de relevés bancaires : séparateur et encodage détectés, en-têtes tolérants (date, libellé, montant ou débit/crédit, tiers, catégorie), doublons ignorés (empreinte compte + date + montant + libellé), catégorie devinée à partir des opérations déjà catégorisées portant le même libellé.
- **Catégories** à deux niveaux, dépenses et recettes, jeu courant proposé ; **budgets (enveloppes)** mensuels ou annuels, datés (un budget s'applique à partir d'un mois donné). Vue « Budget du mois » : réalisé / budget / reste par catégorie, barre de consommation, saisie directe des budgets.
- **Prévisionnel** : opérations récurrentes (périodicité libre, fin éventuelle) à « poster » quand l'échéance est atteinte (badge du module, tableau de bord, tout poster) ; projection du solde sur 6, 12 ou 24 mois à partir des récurrences actives et des coûts estimés des entretiens à venir (service du module Entretien).
- **Épargne et économies** : objectifs (cible, échéance, compte associé ou montant manuel, progression) ; économies **calculées** (budget cumulé − dépensé par catégorie sur les mois écoulés de l'année) et économies **enregistrées** (registre manuel : gain ponctuel, mensuel ou annuel avec date d'effet, cumulé sur l'année).
- **Lien avec l'Entretien** : le coût réel d'une intervention enregistrée dans le module Entretien crée ou met à jour une opération d'origine « Entretien » (référence `maintenance_log:<id>`), sur le compte et la catégorie définis dans les réglages (repli : premier compte courant actif, catégorie « Entretien et réparations »). La suppression de l'intervention retire l'opération. Désactivable dans les réglages.

## Droits

Permissions génériques sur `atelier/budget` (`open`, `create`, `update`, `delete`, `admin` pour les réglages) ; `import` sur `atelier/budget/action/import`, `export` sur `atelier/budget/action/export`. Jeux partagés : `budget.account`, `budget.category`, `budget.transaction` ; privés : `budget.recurring`, `budget.saving`.

## Service intermodule

`$this->ctx->moduleService('budget')` : `accounts($userId)`, `recordExternal($userId, $sourceRef, $source, ['label', 'amount', 'done_at', 'payee', 'notes'])`, `removeExternal($userId, $sourceRef)`, `externalTransaction($userId, $sourceRef)`, `isExternalRecordingEnabled()`. Le service vérifie les droits du demandeur sur le jeu concerné.

## Données

Tables `budget_account`, `budget_category`, `budget_envelope`, `budget_transaction`, `budget_recurring`, `budget_goal`, `budget_saving`. Montants en centimes ; dates calendaires en texte `AAAA-MM-JJ`.

`console db:seed` crée deux comptes, les catégories courantes, six budgets, treize opérations, cinq récurrences, deux objectifs et deux économies d'exemple.
