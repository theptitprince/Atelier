<?php

declare(strict_types=1);

namespace Atelier\Modules\Tags;

use Atelier\Error\NotFoundException;
use Atelier\Error\ValidationException;
use Atelier\Http\Request;
use Atelier\Modules\AbstractModule;
use Atelier\Modules\ActionResult;
use Atelier\Modules\ModuleView;
use Atelier\Modules\RouteCollection;
use Atelier\Shared\TagService;
use Atelier\Support\Clock;
use Atelier\Support\Str;

/**
 * Tags partagés : nuage et tableau des tags de portée « shared », détail d'un tag avec les
 * informations qui le portent (jeux de données partagés et lisibles uniquement), et gestion
 * (renommage, fusion, suppression, doublons probables, tags inutilisés) réservée à la
 * permission propre « manage » (permission tags.manage du cahier des charges).
 *
 * Le module ne possède aucune table : il s'appuie sur TagService, InfoRegistry et
 * DatasetCatalog du noyau, complétés par TagQueries pour les lectures manquantes.
 */
final class TagsModule extends AbstractModule
{
    public const SEARCH_LIMIT = 500;
    public const INFOS_LIMIT = 200;
    public const NEIGHBORS_LIMIT = 12;
    public const CLOUD_LEVELS = 5;
    public const SEED_TAGS = ['exemple', 'atelier', 'urgent', 'à-classer'];

    private ?TagQueries $queries = null;

    /** @var array<int, array<string, mixed>>|null cache des utilisateurs (id => ligne) */
    private ?array $usersById = null;

    public function routes(RouteCollection $r): void
    {
        // Vues
        $r->view('list', [$this, 'listTags'], permission: 'open');
        $r->view('detail/{id}', [$this, 'detail'], permission: 'open');
        $r->view('manage', [$this, 'manage'], permission: 'manage');

        // Indicateur de la colonne (GET) : nombre de tags inutilisés.
        $r->action('badge', [$this, 'badge'], permission: 'manage', methods: ['GET']);

        // Actions (POST)
        $r->action('search', [$this, 'search'], permission: 'open');
        $r->action('rename', [$this, 'rename'], permission: 'manage');
        $r->action('merge', [$this, 'merge'], permission: 'manage');
        $r->action('merge-many', [$this, 'mergeMany'], permission: 'manage');
        $r->action('delete', [$this, 'delete'], permission: 'manage');
        $r->action('delete-many', [$this, 'deleteMany'], permission: 'manage');
        $r->action('delete-unused', [$this, 'deleteUnused'], permission: 'manage');
    }

    // =====================================================================
    // Vues
    // =====================================================================

    /** Liste : nuage et tableau de tous les tags partagés (list?q=…&sort=name|usage&dir=asc|desc). */
    public function listTags(Request $request, array $params): ModuleView
    {
        $q = trim((string) $request->query('q', ''));
        $sort = (string) $request->query('sort', 'name') === 'usage' ? 'usage' : 'name';
        $dir = strtolower((string) $request->query('dir', $sort === 'usage' ? 'desc' : 'asc')) === 'desc' ? 'desc' : 'asc';

        $tags = $q === ''
            ? $this->ctx->shared->tags->all(TagService::SHARED)
            : $this->ctx->shared->tags->search($q, TagService::SHARED, self::SEARCH_LIMIT);
        $tags = $this->decorate($this->sortTags($tags, $sort, $dir));
        $total = $q === '' ? count($tags) : count($this->ctx->shared->tags->all(TagService::SHARED));
        $canManage = $this->can('manage');

        $query = array_filter(['q' => $q, 'sort' => $sort, 'dir' => $dir], static fn (string $v): bool => $v !== '');
        $content = $this->render('list', [
            'tags' => $tags,
            'q' => $q,
            'sort' => $sort,
            'dir' => $dir,
            'query' => $query,
            'total' => $total,
            'canManage' => $canManage,
        ]);

        $actions = '<a class="btn" href="#" data-route="list' . ($query === [] ? '' : '?' . $this->e(http_build_query($query))) . '" title="Recharger la liste">'
            . '<svg class="icon" aria-hidden="true"><use href="#i-refresh"></use></svg> Actualiser</a>';
        if ($canManage) {
            $actions .= '<a class="btn btn--ghost" href="#" data-route="manage" title="Fusionner, supprimer, doublons"><svg class="icon" aria-hidden="true"><use href="#i-sliders"></use></svg> Gestion</a>';
        }

        return ModuleView::make('Tous les tags')
            ->banner($this->banner('Tags partagés', $this->plural($total, 'tag'), $actions))
            ->content($content)
            ->status($q === '' ? $this->plural(count($tags), 'tag') : $this->plural(count($tags), 'tag trouvé', 'tags trouvés') . ' pour « ' . $q . ' »');
    }

    /** Détail d'un tag : métadonnées, informations le portant (filtrées), tags voisins, actions de gestion. */
    public function detail(Request $request, array $params): ModuleView
    {
        $tag = $this->findSharedTag((int) ($params['id'] ?? 0));
        $tagId = (int) $tag['id'];
        $userId = $this->ctx->userId();
        $canManage = $this->can('manage');

        $usage = $this->queries()->usageCount($tagId);
        $author = $this->displayName($tag['created_by'] ?? null);

        // Informations portant le tag : uniquement les jeux partagés ET lisibles par l'utilisateur.
        $infos = [];
        $hidden = 0;
        $catalog = $this->ctx->shared->catalog;
        $datasetCache = [];
        foreach ($this->ctx->shared->tags->infosWithTag($tagId, self::INFOS_LIMIT) as $info) {
            $code = (string) $info['dataset_code'];
            if (!array_key_exists($code, $datasetCache)) {
                $dataset = $catalog->findShared($code);
                $datasetCache[$code] = $dataset !== null && $catalog->canAccess($userId, $code, 'read') ? $dataset : null;
            }
            $dataset = $datasetCache[$code];
            if ($dataset === null) {
                $hidden++;
                continue;
            }
            $moduleId = (string) $info['module_id'];
            $descriptor = $this->ctx->modules()->get($moduleId);
            $openRoute = $this->openRouteFor($moduleId, $code, (string) $info['local_key']);
            $infos[] = [
                'id' => (string) $info['id'],
                'label' => (string) ($info['label'] ?? ''),
                'dataset_code' => $code,
                'dataset_name' => (string) ($dataset['name'] ?? $code),
                'module_id' => $moduleId,
                'module_name' => $descriptor !== null ? $descriptor->name() : $moduleId,
                'open_route' => $descriptor !== null && $descriptor->isUsable() ? $openRoute : null,
            ];
        }

        $neighbors = $this->queries()->neighbors($tagId, self::NEIGHBORS_LIMIT);
        $others = $canManage
            ? array_values(array_filter($this->ctx->shared->tags->all(TagService::SHARED), static fn (array $t): bool => (int) $t['id'] !== $tagId))
            : [];

        $content = $this->render('detail', [
            'tag' => $tag,
            'usage' => $usage,
            'author' => $author,
            'infos' => $infos,
            'hidden' => $hidden,
            'neighbors' => $neighbors,
            'others' => $others,
            'canManage' => $canManage,
        ]);

        $actions = '<a class="btn" href="#" data-route="list"><svg class="icon" aria-hidden="true"><use href="#i-chevron-left"></use></svg> Tous les tags</a>';
        return ModuleView::make('Tag « ' . $tag['name'] . ' »')
            ->banner($this->banner((string) $tag['name'], $this->plural($usage, 'utilisation'), $actions))
            ->content($content)
            ->status($this->plural(count($infos), 'information affichée', 'informations affichées') . ($hidden > 0 ? ', ' . $this->plural($hidden, 'non affichée', 'non affichées') : ''));
    }

    /** Gestion : sélection multiple, doublons probables, tags inutilisés. */
    public function manage(Request $request, array $params): ModuleView
    {
        $this->require('manage');
        $tags = $this->decorate($this->ctx->shared->tags->all(TagService::SHARED));
        $unused = array_values(array_filter($tags, static fn (array $t): bool => (int) $t['usage_count'] === 0));
        $duplicates = $this->detectDuplicates($tags);

        $content = $this->render('manage', [
            'tags' => $tags,
            'unused' => $unused,
            'duplicates' => $duplicates,
        ]);

        $actions = '<a class="btn" href="#" data-route="list"><svg class="icon" aria-hidden="true"><use href="#i-chevron-left"></use></svg> Tous les tags</a>'
            . '<a class="btn" href="#" data-route="manage"><svg class="icon" aria-hidden="true"><use href="#i-refresh"></use></svg> Actualiser</a>';
        $subtitle = $this->plural(count($tags), 'tag') . ' · ' . $this->plural(count($unused), 'inutilisé') . ' · ' . $this->plural(count($duplicates), 'doublon probable', 'doublons probables');

        return ModuleView::make('Gestion des tags')
            ->banner($this->banner('Gestion des tags', $subtitle, $actions, 'sliders'))
            ->content($content)
            ->status($this->plural(count($tags), 'tag'));
    }

    // =====================================================================
    // Indicateur et recherche
    // =====================================================================

    /**
     * Badge de la colonne : nombre de tags inutilisés. Le noyau ne vérifie que « open »
     * avant d'appeler cette route : on revérifie « manage » et on renvoie 0 sinon.
     *
     * @return array{count: int, label: string}
     */
    public function badge(Request $request, array $params): array
    {
        if (!$this->can('manage')) {
            return ['count' => 0, 'label' => ''];
        }
        $count = count(array_filter($this->ctx->shared->tags->all(TagService::SHARED), static fn (array $t): bool => (int) $t['usage_count'] === 0));
        return ['count' => $count, 'label' => $this->plural($count, 'tag inutilisé', 'tags inutilisés')];
    }

    /** Formulaire de recherche : navigue vers la liste filtrée (tri conservé). */
    public function search(Request $request, array $params): ActionResult
    {
        $query = array_filter([
            'q' => $request->string('q'),
            'sort' => $request->string('sort'),
            'dir' => $request->string('dir'),
        ], static fn (string $v): bool => $v !== '');
        return ActionResult::ok()->navigate('list' . ($query === [] ? '' : '?' . http_build_query($query)));
    }

    // =====================================================================
    // Actions de gestion (permission manage, journalisées en catégorie admin)
    // =====================================================================

    public function rename(Request $request, array $params): ActionResult
    {
        $this->require('manage');
        $tag = $this->findSharedTag((int) ($request->int('id') ?? 0));
        $newName = $request->string('name');
        $normalized = Str::normalizeTag($newName);
        if ($normalized === '') {
            throw ValidationException::single('name', 'Le nom du tag est obligatoire.');
        }
        if (mb_strlen($normalized, 'UTF-8') > 60) {
            throw ValidationException::single('name', 'Le tag doit comporter au plus 60 caractères.');
        }
        $conflict = $this->findByNormalized($normalized);
        if ($conflict !== null && (int) $conflict['id'] !== (int) $tag['id']) {
            throw ValidationException::single('name', 'Le tag « ' . $conflict['name'] . ' » existe déjà : fusionnez plutôt « ' . $tag['name'] . ' » dans « ' . $conflict['name'] . ' ».');
        }

        $oldName = (string) $tag['name'];
        $this->ctx->shared->tags->rename((int) $tag['id'], $newName);
        $renamed = $this->ctx->shared->tags->find((int) $tag['id']);
        $this->log('tags.rename', 'success', 'tag:' . $tag['id'], 'Tag renommé', ['old_name' => $oldName, 'new_name' => (string) ($renamed['name'] ?? $newName)], 'admin');

        return ActionResult::ok(['id' => (int) $tag['id'], 'name' => (string) ($renamed['name'] ?? $newName)], 'Tag renommé en « ' . ($renamed['name'] ?? $newName) . ' ».')->refresh();
    }

    /** Fusionne le tag `id` dans la cible (`target_id` ou nom `target`). */
    public function merge(Request $request, array $params): ActionResult
    {
        $this->require('manage');
        $source = $this->findSharedTag((int) ($request->int('id') ?? 0));
        $target = $this->resolveTarget($request);
        if ((int) $target['id'] === (int) $source['id']) {
            throw ValidationException::single('target', 'Le tag cible doit être différent du tag fusionné.');
        }

        $this->mergeOne($source, $target);
        return ActionResult::ok(['id' => (int) $target['id']], '« ' . $source['name'] . ' » fusionné dans « ' . $target['name'] . ' ».')->navigate('detail/' . (int) $target['id']);
    }

    /** Fusionne les tags `ids[]` dans la cible (`target_id` ou nom `target`). */
    public function mergeMany(Request $request, array $params): ActionResult
    {
        $this->require('manage');
        $ids = $this->idsFrom($request);
        if ($ids === []) {
            return ActionResult::warning(null, 'Aucun tag sélectionné.');
        }
        $target = $this->resolveTarget($request);
        $merged = [];
        foreach ($ids as $id) {
            if ($id === (int) $target['id']) {
                continue;
            }
            $source = $this->ctx->shared->tags->find($id);
            if ($source === null || $source['scope'] !== TagService::SHARED) {
                continue;
            }
            $this->mergeOne($source, $target);
            $merged[] = (string) $source['name'];
        }
        if ($merged === []) {
            return ActionResult::warning(null, 'Aucun tag à fusionner (la sélection ne contient que la cible).');
        }
        return ActionResult::ok(['merged' => $merged, 'target_id' => (int) $target['id']], $this->plural(count($merged), 'tag fusionné', 'tags fusionnés') . ' dans « ' . $target['name'] . ' ».')->refresh();
    }

    public function delete(Request $request, array $params): ActionResult
    {
        $this->require('manage');
        $tag = $this->findSharedTag((int) ($request->int('id') ?? 0));
        $this->deleteOne($tag);
        return ActionResult::ok(['id' => (int) $tag['id']], 'Tag « ' . $tag['name'] . ' » supprimé.')->navigate('list');
    }

    public function deleteMany(Request $request, array $params): ActionResult
    {
        $this->require('manage');
        $ids = $this->idsFrom($request);
        if ($ids === []) {
            return ActionResult::warning(null, 'Aucun tag sélectionné.');
        }
        $deleted = 0;
        foreach ($ids as $id) {
            $tag = $this->ctx->shared->tags->find($id);
            if ($tag === null || $tag['scope'] !== TagService::SHARED) {
                continue;
            }
            $this->deleteOne($tag);
            $deleted++;
        }
        return ActionResult::ok(['deleted' => $deleted], $this->plural($deleted, 'tag supprimé', 'tags supprimés') . '.')->refresh();
    }

    public function deleteUnused(Request $request, array $params): ActionResult
    {
        $this->require('manage');
        $deleted = 0;
        foreach ($this->ctx->shared->tags->all(TagService::SHARED) as $tag) {
            if ((int) $tag['usage_count'] !== 0) {
                continue;
            }
            $this->deleteOne($tag);
            $deleted++;
        }
        if ($deleted === 0) {
            return ActionResult::info(null, 'Aucun tag inutilisé.');
        }
        return ActionResult::ok(['deleted' => $deleted], $this->plural($deleted, 'tag inutilisé supprimé', 'tags inutilisés supprimés') . '.')->refresh();
    }

    // =====================================================================
    // Hooks
    // =====================================================================

    /** Données de démonstration : quelques tags partagés courants s'ils n'existent pas. */
    public function seed(): string
    {
        $existing = [];
        foreach ($this->ctx->shared->tags->all(TagService::SHARED) as $tag) {
            $existing[(string) $tag['normalized']] = true;
        }
        $created = [];
        foreach (self::SEED_TAGS as $name) {
            if (isset($existing[Str::normalizeTag($name)])) {
                continue;
            }
            $this->ctx->shared->tags->findOrCreate($name, TagService::SHARED, null);
            $created[] = $name;
        }
        return $created === []
            ? 'tags d’exemple déjà présents (' . implode(', ', self::SEED_TAGS) . ')'
            : count($created) . ' tag(s) partagé(s) créé(s) : ' . implode(', ', $created);
    }

    // =====================================================================
    // Helpers privés
    // =====================================================================

    private function queries(): TagQueries
    {
        return $this->queries ??= new TagQueries($this->ctx->db);
    }

    /** @return array<string, mixed> tag partagé ou NotFoundException */
    private function findSharedTag(int $id): array
    {
        $tag = $id > 0 ? $this->ctx->shared->tags->find($id) : null;
        if ($tag === null || $tag['scope'] !== TagService::SHARED) {
            throw new NotFoundException('Tag introuvable.');
        }
        return $tag;
    }

    /** @return array<string, mixed>|null */
    private function findByNormalized(string $normalized): ?array
    {
        foreach ($this->ctx->shared->tags->all(TagService::SHARED) as $tag) {
            if ((string) $tag['normalized'] === $normalized) {
                return $tag;
            }
        }
        return null;
    }

    /**
     * Cible d'une fusion : `target_id` (entier) ou `target` (nom saisi dans le composant de tags).
     *
     * @return array<string, mixed>
     */
    private function resolveTarget(Request $request): array
    {
        $targetId = $request->int('target_id');
        if ($targetId !== null && $targetId > 0) {
            $target = $this->ctx->shared->tags->find($targetId);
            if ($target === null || $target['scope'] !== TagService::SHARED) {
                throw ValidationException::single('target', 'Tag cible introuvable.');
            }
            return $target;
        }
        $raw = $request->input('target', '');
        $name = is_array($raw) ? (string) ($raw[0] ?? '') : (string) $raw;
        // Le composant renvoie « a, b » : on ne retient que le premier nom.
        $name = trim((string) explode(',', $name)[0]);
        $normalized = Str::normalizeTag($name);
        if ($normalized === '') {
            throw ValidationException::single('target', 'Indiquez le tag cible de la fusion.');
        }
        $target = $this->findByNormalized($normalized);
        if ($target === null) {
            throw ValidationException::single('target', 'Le tag cible « ' . $name . ' » n’existe pas : choisissez un tag existant.');
        }
        return $target;
    }

    /** @return list<int> identifiants distincts et positifs de `ids` (tableau ou liste « 1,2,3 »). */
    private function idsFrom(Request $request): array
    {
        $raw = $request->input('ids', []);
        if (is_string($raw)) {
            $raw = explode(',', $raw);
        }
        if (!is_array($raw)) {
            return [];
        }
        $ids = [];
        foreach ($raw as $value) {
            if (is_scalar($value) && (int) $value > 0) {
                $ids[(int) $value] = true;
            }
        }
        return array_keys($ids);
    }

    /**
     * @param array<string, mixed> $source
     * @param array<string, mixed> $target
     */
    private function mergeOne(array $source, array $target): void
    {
        $usage = $this->queries()->usageCount((int) $source['id']);
        $this->ctx->shared->tags->merge((int) $source['id'], (int) $target['id']);
        $this->log('tags.merge', 'success', 'tag:' . $target['id'], 'Tag fusionné', [
            'source_id' => (int) $source['id'],
            'source_name' => (string) $source['name'],
            'target_id' => (int) $target['id'],
            'target_name' => (string) $target['name'],
            'moved_usages' => $usage,
        ], 'admin');
    }

    /** @param array<string, mixed> $tag */
    private function deleteOne(array $tag): void
    {
        $usage = $this->queries()->usageCount((int) $tag['id']);
        $this->ctx->shared->tags->delete((int) $tag['id']);
        $this->log('tags.delete', 'success', 'tag:' . $tag['id'], 'Tag supprimé', ['name' => (string) $tag['name'], 'usage_count' => $usage], 'admin');
    }

    /**
     * Route d'ouverture d'une information dans son module : `openRoute` du jeu de données
     * avec `{key}` remplacé par la clé locale ; null si le jeu n'en déclare pas.
     */
    private function openRouteFor(string $moduleId, string $datasetCode, string $localKey): ?string
    {
        $manifest = $this->ctx->modules()->get($moduleId)?->manifest;
        if ($manifest === null) {
            return null;
        }
        foreach ($manifest->datasets() as $dataset) {
            if (($dataset['code'] ?? null) === $datasetCode) {
                $route = $dataset['openRoute'] ?? null;
                return $route === null || $route === '' ? null : str_replace('{key}', rawurlencode($localKey), (string) $route);
            }
        }
        return null;
    }

    /**
     * Tri des tags par nom ou par usage.
     *
     * @param list<array<string, mixed>> $tags
     * @return list<array<string, mixed>>
     */
    private function sortTags(array $tags, string $sort, string $dir): array
    {
        $factor = $dir === 'desc' ? -1 : 1;
        usort($tags, static function (array $a, array $b) use ($sort, $factor): int {
            if ($sort === 'usage') {
                $cmp = (int) $a['usage_count'] <=> (int) $b['usage_count'];
                return $cmp !== 0 ? $factor * $cmp : strcmp((string) $a['normalized'], (string) $b['normalized']);
            }
            return $factor * strcmp((string) $a['normalized'], (string) $b['normalized']);
        });
        return array_values($tags);
    }

    /**
     * Ajoute le niveau de taille du nuage (1..5 selon l'usage relatif) et le nom de l'auteur.
     *
     * @param list<array<string, mixed>> $tags
     * @return list<array<string, mixed>>
     */
    private function decorate(array $tags): array
    {
        $max = 0;
        foreach ($tags as $tag) {
            $max = max($max, (int) $tag['usage_count']);
        }
        foreach ($tags as &$tag) {
            $usage = (int) $tag['usage_count'];
            $tag['usage_count'] = $usage;
            $tag['level'] = $usage === 0 || $max === 0 ? 1 : min(self::CLOUD_LEVELS, 1 + (int) ceil(($usage / $max) * (self::CLOUD_LEVELS - 1)));
            $tag['author'] = $this->displayName($tag['created_by'] ?? null);
        }
        unset($tag);
        return $tags;
    }

    /**
     * Doublons probables : tags dont la forme normalisée ne diffère que par les accents
     * ou un « s » final. Chaque paire propose la fusion du moins utilisé dans le plus utilisé.
     *
     * @param list<array<string, mixed>> $tags décorés
     * @return list<array{key: string, source: array<string, mixed>, target: array<string, mixed>}>
     */
    private function detectDuplicates(array $tags): array
    {
        $groups = [];
        foreach ($tags as $tag) {
            $groups[$this->duplicateKey((string) $tag['normalized'])][] = $tag;
        }
        $pairs = [];
        foreach ($groups as $key => $group) {
            if (count($group) < 2) {
                continue;
            }
            usort($group, static fn (array $a, array $b): int => [(int) $b['usage_count'], (string) $a['normalized']] <=> [(int) $a['usage_count'], (string) $b['normalized']]);
            $target = $group[0];
            foreach (array_slice($group, 1) as $source) {
                $pairs[] = ['key' => (string) $key, 'source' => $source, 'target' => $target];
            }
        }
        return $pairs;
    }

    /** Clé de rapprochement : sans accents, sans « s » final, sans séparateurs. */
    private function duplicateKey(string $normalized): string
    {
        $key = strtr($normalized, [
            'à' => 'a', 'á' => 'a', 'â' => 'a', 'ä' => 'a', 'ã' => 'a', 'å' => 'a',
            'ç' => 'c',
            'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e',
            'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i',
            'ñ' => 'n',
            'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'ö' => 'o', 'õ' => 'o',
            'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u',
            'ý' => 'y', 'ÿ' => 'y',
            'œ' => 'oe', 'æ' => 'ae',
        ]);
        $key = preg_replace('/[\s\-_]+/u', '', $key) ?? $key;
        if (mb_strlen($key, 'UTF-8') > 3 && str_ends_with($key, 's')) {
            $key = mb_substr($key, 0, -1, 'UTF-8');
        }
        return $key;
    }

    private function displayName(mixed $userId): string
    {
        if ($userId === null || (int) $userId <= 0) {
            return 'Système';
        }
        if ($this->usersById === null) {
            $this->usersById = [];
            foreach ($this->ctx->users->all() as $user) {
                $this->usersById[(int) $user['id']] = $user;
            }
        }
        $user = $this->usersById[(int) $userId] ?? null;
        return $user === null ? 'Utilisateur n° ' . (int) $userId : (string) ($user['display_name'] ?? $user['username'] ?? ('Utilisateur n° ' . (int) $userId));
    }

    private function banner(string $title, string $subtitle, string $actions = '', string $icon = 'tag'): string
    {
        return $this->renderCore('banner', ['icon' => $icon, 'title' => $title, 'subtitle' => $subtitle, 'actions' => $actions]);
    }

    private function plural(int $count, string $singular, ?string $plural = null): string
    {
        $plural ??= $singular . 's';
        return $count . ' ' . ($count === 1 ? $singular : $plural);
    }
}
