<?php

declare(strict_types=1);

namespace Atelier\Modules\Budget;

use Atelier\Error\ForbiddenException;
use Atelier\Modules\ModuleContext;

/**
 * Service intermodule du module Budget.
 *
 *  - lecture des comptes et soldes par les autres modules (jeu partagé budget.account) ;
 *  - enregistrement d'opérations « externes » par un autre module (jeu partagé budget.transaction),
 *    identifiées par une référence d'origine stable (ex. « maintenance_log:12 ») : création ou mise à
 *    jour idempotente, suppression. Le compte et la catégorie utilisés sont ceux des réglages du module.
 * Chaque méthode vérifie via le catalogue le droit de l'utilisateur sur le jeu concerné.
 */
final class BudgetService
{
    public const DATASET_ACCOUNT = 'budget.account';
    public const DATASET_TRANSACTION = 'budget.transaction';
    public const SETTING_ACCOUNT = 'maintenance_account_id';
    public const SETTING_CATEGORY = 'maintenance_category_id';
    public const SETTING_AUTO = 'maintenance_auto';
    public const FALLBACK_CATEGORY = 'Entretien et réparations';

    public function __construct(
        private readonly ModuleContext $ctx,
        private readonly AccountRepository $accounts,
        private readonly CategoryRepository $categories,
        private readonly TransactionRepository $transactions,
    ) {
    }

    /**
     * Comptes actifs et leurs soldes (centimes).
     *
     * @return list<array{id: int, name: string, kind: string, balance: int}>
     */
    public function accounts(int $viewerUserId): array
    {
        $this->assertAccess($viewerUserId, self::DATASET_ACCOUNT, 'read');
        return array_map(static fn (array $a): array => ['id' => $a['id'], 'name' => (string) $a['name'], 'kind' => (string) $a['kind'], 'balance' => $a['balance']], $this->accounts->all());
    }

    /** Le report automatique des coûts du module Entretien est-il activé ? */
    public function isExternalRecordingEnabled(): bool
    {
        return (bool) $this->ctx->settings->get(self::SETTING_AUTO, true, 'budget');
    }

    /**
     * Crée ou met à jour l'opération liée à une référence d'origine. Retourne l'identifiant de
     * l'opération, ou null si le report est désactivé ou qu'aucun compte n'existe.
     *
     * @param array{label: string, amount: int, done_at: string, payee?: ?string, notes?: ?string} $data amount signé (négatif = dépense)
     */
    public function recordExternal(int $userId, string $sourceRef, string $source, array $data): ?int
    {
        $existing = $this->transactions->findBySourceRef($sourceRef);
        $this->assertAccess($userId, self::DATASET_TRANSACTION, $existing === null ? 'create' : 'update');
        if (!$this->isExternalRecordingEnabled()) {
            return $existing['id'] ?? null;
        }
        $account = $this->resolveAccount($existing);
        if ($account === null) {
            return null;
        }
        $category = $existing !== null && $existing['category_id'] !== null ? $existing['category_id'] : $this->resolveCategoryId();
        $columns = [
            'account_id' => $account['id'],
            'category_id' => $category,
            'done_at' => $data['done_at'],
            'amount' => (int) $data['amount'],
            'label' => mb_substr($data['label'], 0, 200, 'UTF-8'),
            'payee' => isset($data['payee']) && $data['payee'] !== '' ? mb_substr((string) $data['payee'], 0, 150, 'UTF-8') : null,
            'notes' => $data['notes'] ?? null,
            'cleared' => $existing['cleared'] ?? false,
            'source' => $source,
            'source_ref' => $sourceRef,
        ];
        if ($existing !== null) {
            $this->transactions->update($existing['id'], $columns);
            return $existing['id'];
        }
        return $this->transactions->create($columns, $userId);
    }

    /** Place dans la corbeille l'opération liée à une référence d'origine (si elle existe) ; restaurable 30 jours. */
    public function removeExternal(int $userId, string $sourceRef): bool
    {
        $existing = $this->transactions->findBySourceRef($sourceRef);
        if ($existing === null) {
            return false;
        }
        $this->assertAccess($userId, self::DATASET_TRANSACTION, 'delete');
        return $this->transactions->softDelete($existing['id'], $userId);
    }

    /** Opération liée à une référence d'origine (champs publics), ou null. @return array{id: int, amount: int, done_at: string, account_name: string, label: string}|null */
    public function externalTransaction(int $viewerUserId, string $sourceRef): ?array
    {
        $this->assertAccess($viewerUserId, self::DATASET_TRANSACTION, 'read');
        $row = $this->transactions->findBySourceRef($sourceRef);
        return $row === null ? null : ['id' => $row['id'], 'amount' => $row['amount'], 'done_at' => (string) $row['done_at'], 'account_name' => (string) $row['account_name'], 'label' => (string) $row['label']];
    }

    /** @param array<string, mixed>|null $existing */
    private function resolveAccount(?array $existing): ?array
    {
        if ($existing !== null) {
            $account = $this->accounts->find($existing['account_id']);
            if ($account !== null) {
                return $account;
            }
        }
        $configured = (int) $this->ctx->settings->get(self::SETTING_ACCOUNT, 0, 'budget');
        $account = $configured > 0 ? $this->accounts->find($configured) : null;
        if ($account !== null && !$account['archived']) {
            return $account;
        }
        return $this->accounts->findDefault();
    }

    private function resolveCategoryId(): ?int
    {
        $configured = (int) $this->ctx->settings->get(self::SETTING_CATEGORY, 0, 'budget');
        if ($configured > 0 && $this->categories->find($configured) !== null) {
            return $configured;
        }
        $category = $this->categories->findByName(self::FALLBACK_CATEGORY, 'expense');
        if ($category !== null) {
            return $category['id'];
        }
        return $this->categories->create(['name' => self::FALLBACK_CATEGORY, 'kind' => 'expense', 'parent_id' => null, 'sort_order' => 900]);
    }

    private function assertAccess(int $userId, string $dataset, string $operation): void
    {
        if (!$this->ctx->shared->catalog->canAccess($userId, $dataset, $operation)) {
            throw new ForbiddenException('Accès « ' . $operation . ' » au jeu de données « ' . $dataset . ' » refusé.', 'atelier/budget/data/' . explode('.', $dataset)[1], $operation);
        }
    }
}
