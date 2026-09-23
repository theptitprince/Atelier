<?php
/**
 * Liste des fichiers joints (portée « mine » ou « all ») : dossiers virtuels à gauche, filtres, tableau.
 * @var list<array<string, mixed>> $rows
 * @var array<string, list<string>> $rowTags
 * @var int $total
 * @var array{q: string, kind: string, linked: string, tag: string, folder: string, sort: string, dir: string, page: int, per_page: int} $query
 * @var string $scope
 * @var array<string, bool> $rights
 * @var bool $assist
 * @var int $currentUserId
 * @var array<string, array{0: string, 1: list<string>}> $kinds
 * @var list<int> $perPageChoices
 * @var string $route
 * @var array{mine: array<string, int>, quota: int, global: array<string, int>|null, max_total: int} $usage
 * @var bool $encryption
 * @var list<array<string, mixed>> $folderTree
 * @var list<array{id: int, name: string, path: string, depth: int}> $folderOptions
 * @var array{all: int, root: int} $folderCounts
 * @var array<string, mixed>|null $currentFolder
 * @var list<array<string, mixed>> $breadcrumb
 * @var list<array{name: string, normalized: string, count: int}> $fileTags
 * @var \Atelier\Modules\Attachments\AttachmentsModule $module
 */
$base = $scope === 'all' ? 'all' : 'list';
$filtered = $query['q'] !== '' || $query['kind'] !== '' || $query['linked'] !== '' || $query['tag'] !== '';
$keep = static fn (array $values): array => array_filter($values, static fn ($v): bool => $v !== null && $v !== '');
$sortQuery = $keep(['folder' => $query['folder'], 'q' => $query['q'], 'kind' => $query['kind'], 'linked' => $query['linked'], 'tag' => $query['tag'], 'per_page' => $query['per_page'] !== 25 ? $query['per_page'] : null]);
$sortHeader = static fn (string $label, string $column): string => $module->renderCore('sort_header', ['label' => $label, 'column' => $column, 'sort' => $query['sort'], 'direction' => $query['dir'], 'route' => $base, 'query' => $sortQuery]);
$pageQuery = $sortQuery + $keep(['sort' => $query['sort'] !== 'created_at' ? $query['sort'] : null, 'dir' => $query['dir'] !== 'desc' ? $query['dir'] : null]);
$percent = static fn (int $used, int $max): int => $max > 0 ? (int) min(100, round($used * 100 / $max)) : 0;
$folderRoute = static fn (int|string $folder): string => $base . '?' . http_build_query($keep(['folder' => $folder, 'q' => $query['q'], 'kind' => $query['kind'], 'linked' => $query['linked'], 'tag' => $query['tag']]));
$tagRoute = static fn (string $tag): string => $base . '?' . http_build_query(['tag' => $tag]);
$currentId = $currentFolder !== null ? (int) $currentFolder['id'] : null;
$canFolders = $rights['update'];
$scopeJson = json_encode($scope, JSON_THROW_ON_ERROR);
$uploadRoute = 'upload' . ($currentId !== null ? '?folder=' . $currentId : '');
$renderTree = static function (array $nodes) use (&$renderTree, $e, $folderRoute, $currentId): string {
    $html = '<ul>';
    foreach ($nodes as $node) {
        $selected = $currentId === $node['id'];
        $html .= '<li class="is-open"><a href="#" class="tree__row' . ($selected ? ' is-selected' : '') . '" data-route="' . $e($folderRoute($node['id'])) . '" title="' . $e($node['path']) . '"' . ($selected ? ' aria-current="true"' : '') . '>'
            . '<svg class="icon icon--sm text-muted" aria-hidden="true"><use href="#i-folder"></use></svg><span class="truncate grow">' . $e($node['name']) . '</span>'
            . '<span class="badge badge--muted">' . (int) $node['file_count'] . '</span></a>';
        if ($node['children'] !== []) {
            $html .= $renderTree($node['children']);
        }
        $html .= '</li>';
    }
    return $html . '</ul>';
};
?>
<div class="module module-attachments">
    <div class="attachments__layout">
        <aside class="attachments__folders">
            <section class="card" aria-labelledby="att-folders-title">
                <div class="card__header">
                    <h2 class="card__title" id="att-folders-title"><svg class="icon" aria-hidden="true"><use href="#i-folder"></use></svg> Dossiers</h2>
                    <?php if ($canFolders): ?>
                        <button type="button" class="btn btn--sm btn--icon btn--ghost" data-action="folder-create" data-params='{"parent_id":"","scope":<?= $e($scopeJson) ?>}' data-prompt="Nom du nouveau dossier (à la racine)" data-prompt-field="name" data-confirm-title="Nouveau dossier" title="Nouveau dossier à la racine" aria-label="Nouveau dossier à la racine"><svg class="icon" aria-hidden="true"><use href="#i-plus"></use></svg></button>
                    <?php endif; ?>
                </div>
                <div class="card__body attachments__tree-body">
                    <ul class="tree attachments__tree">
                        <li><a href="#" class="tree__row<?= $query['folder'] === '' ? ' is-selected' : '' ?>" data-route="<?= $e($folderRoute('')) ?>"<?= $query['folder'] === '' ? ' aria-current="true"' : '' ?>><svg class="icon icon--sm text-muted" aria-hidden="true"><use href="#i-layers"></use></svg><span class="grow">Tous les fichiers</span><span class="badge badge--muted"><?= (int) $folderCounts['all'] ?></span></a></li>
                        <li><a href="#" class="tree__row<?= $query['folder'] === 'root' ? ' is-selected' : '' ?>" data-route="<?= $e($folderRoute('root')) ?>"<?= $query['folder'] === 'root' ? ' aria-current="true"' : '' ?>><svg class="icon icon--sm text-muted" aria-hidden="true"><use href="#i-file"></use></svg><span class="grow">Non rangés</span><span class="badge badge--muted"><?= (int) $folderCounts['root'] ?></span></a></li>
                    </ul>
                    <?php if ($folderTree === []): ?>
                        <p class="text-muted text-small mt-2 mb-0">Aucun dossier. Les dossiers sont virtuels : ils classent les fichiers sans les déplacer sur le disque.</p>
                    <?php else: ?>
                        <div class="tree attachments__tree attachments__tree--folders"><?= $renderTree($folderTree) ?></div>
                    <?php endif; ?>
                </div>
            </section>
        </aside>

        <div class="attachments__main">
            <div class="attachments__kpis">
                <div class="card card--compact">
                    <div class="card__body kpi">
                        <span class="kpi__value"><?= $e($module->humanSize($usage['mine']['size'])) ?> <span class="text-muted text-small">/ <?= $e($module->humanSize($usage['quota'])) ?></span></span>
                        <span class="kpi__label">Mon espace utilisé · <?= (int) $usage['mine']['count'] ?> fichier(s)</span>
                        <progress class="attachments__quota" max="100" value="<?= $percent($usage['mine']['size'], $usage['quota']) ?>" aria-label="Quota personnel utilisé"></progress>
                    </div>
                </div>
                <?php if ($usage['global'] !== null): ?>
                    <div class="card card--compact">
                        <div class="card__body kpi">
                            <span class="kpi__value"><?= $e($module->humanSize($usage['global']['size'])) ?> <span class="text-muted text-small">/ <?= $e($module->humanSize($usage['max_total'])) ?></span></span>
                            <span class="kpi__label">Espace global · <?= (int) $usage['global']['count'] ?> fichier(s), <?= (int) $usage['global']['trashed'] ?> en corbeille</span>
                            <progress class="attachments__quota" max="100" value="<?= $percent($usage['global']['size'], $usage['max_total']) ?>" aria-label="Quota global utilisé"></progress>
                        </div>
                    </div>
                <?php endif; ?>
                <div class="card card--compact">
                    <div class="card__body kpi">
                        <span class="kpi__value"><svg class="icon icon--lg <?= $encryption ? 'text-success' : 'text-warning' ?>" aria-hidden="true"><use href="#i-<?= $encryption ? 'lock' : 'unlock' ?>"></use></svg> <?= $encryption ? 'Chiffrés' : 'Non chiffrés' ?></span>
                        <span class="kpi__label"><?= $encryption ? 'Stockage AES-256-GCM, déchiffrement à la volée après contrôle des droits' : 'Le chiffrement au repos est désactivé dans la configuration' ?></span>
                    </div>
                </div>
            </div>

            <nav class="card attachments__breadcrumb" aria-label="Dossier courant">
                <div class="card__body toolbar mb-0">
                    <ol class="attachments__crumbs">
                        <li><a href="#" data-route="<?= $e($folderRoute('')) ?>"><svg class="icon icon--sm" aria-hidden="true"><use href="#i-layers"></use></svg> Tous les fichiers</a></li>
                        <?php if ($query['folder'] === 'root'): ?>
                            <li aria-current="page">Non rangés</li>
                        <?php endif; ?>
                        <?php foreach ($breadcrumb as $i => $crumb): ?>
                            <?php if ($i === count($breadcrumb) - 1): ?>
                                <li aria-current="page"><svg class="icon icon--sm" aria-hidden="true"><use href="#i-folder"></use></svg> <?= $e($crumb['name']) ?></li>
                            <?php else: ?>
                                <li><a href="#" data-route="<?= $e($folderRoute((int) $crumb['id'])) ?>"><?= $e($crumb['name']) ?></a></li>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </ol>
                    <span class="toolbar__spacer"></span>
                    <?php if ($canFolders && $currentFolder !== null): ?>
                        <button type="button" class="btn btn--sm btn--ghost" data-action="folder-create" data-params='{"parent_id":<?= $currentId ?>,"scope":<?= $e($scopeJson) ?>}' data-prompt="Nom du sous-dossier de « <?= $e($currentFolder['name']) ?> »" data-prompt-field="name" data-confirm-title="Nouveau sous-dossier"><svg class="icon" aria-hidden="true"><use href="#i-plus"></use></svg> Sous-dossier</button>
                        <button type="button" class="btn btn--sm btn--ghost" data-action="folder-rename" data-params='{"id":<?= $currentId ?>}' data-prompt="Nouveau nom du dossier" data-prompt-field="name" data-prompt-value="<?= $e($currentFolder['name']) ?>" data-confirm-title="Renommer le dossier"><svg class="icon" aria-hidden="true"><use href="#i-edit"></use></svg> Renommer</button>
                        <details class="attachments__folder-move">
                            <summary class="btn btn--sm btn--ghost"><svg class="icon" aria-hidden="true"><use href="#i-arrow-up"></use></svg> Déplacer le dossier</summary>
                            <form class="attachments__folder-move-form" data-action="folder-move" novalidate>
                                <input type="hidden" name="id" value="<?= $currentId ?>">
                                <div class="field mb-0">
                                    <label class="sr-only" for="att-folder-parent">Dossier de destination</label>
                                    <select class="select select--sm" id="att-folder-parent" name="parent_id">
                                        <option value="">Racine</option>
                                        <?php foreach ($folderOptions as $option): ?>
                                            <?php if ($option['id'] === $currentId || str_starts_with($option['path'] . '/', $currentFolder['path'] . '/')) { continue; } ?>
                                            <option value="<?= (int) $option['id'] ?>"<?= (int) ($currentFolder['parent_id'] ?? 0) === $option['id'] ? ' selected' : '' ?>><?= $e(str_repeat('— ', $option['depth']) . $option['name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <span class="field__error"></span>
                                </div>
                                <button type="submit" class="btn btn--sm btn--primary">Déplacer</button>
                            </form>
                        </details>
                        <button type="button" class="btn btn--sm btn--ghost text-danger" data-action="folder-delete" data-params='{"id":<?= $currentId ?>,"scope":<?= $e($scopeJson) ?>}' data-confirm="Supprimer le dossier « <?= $e($currentFolder['name']) ?> » ? Ses fichiers et sous-dossiers seront remontés dans <?= $e(count($breadcrumb) > 1 ? '« ' . $breadcrumb[count($breadcrumb) - 2]['name'] . ' »' : 'la racine (non rangés)') ?> ; aucun fichier ne sera supprimé." data-confirm-title="Supprimer le dossier" data-danger><svg class="icon" aria-hidden="true"><use href="#i-trash"></use></svg> Supprimer</button>
                    <?php endif; ?>
                </div>
            </nav>

            <form class="card attachments__filters" data-action="filter" data-auto-submit novalidate>
                <input type="hidden" name="scope" value="<?= $e($scope) ?>">
                <input type="hidden" name="folder" value="<?= $e($query['folder']) ?>">
                <div class="card__body toolbar mb-0">
                    <div class="field grow">
                        <label class="sr-only" for="att-q">Recherche</label>
                        <div class="input-icon">
                            <svg class="icon icon--sm" aria-hidden="true"><use href="#i-search"></use></svg>
                            <input class="input" type="search" id="att-q" name="q" value="<?= $e($query['q']) ?>" placeholder="Nom, nom d’affichage, description ou information rattachée" autocomplete="off">
                        </div>
                    </div>
                    <label class="field field--inline">
                        <span class="field__label">Type</span>
                        <select class="select select--sm" name="kind" aria-label="Type de fichier">
                            <option value="">Tous</option>
                            <?php foreach ($kinds as $code => [$label]): ?>
                                <option value="<?= $e($code) ?>"<?= $query['kind'] === $code ? ' selected' : '' ?>><?= $e($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label class="field field--inline">
                        <span class="field__label">Tag</span>
                        <select class="select select--sm" name="tag" aria-label="Tag">
                            <option value="">Tous</option>
                            <?php $tagListed = false; ?>
                            <?php foreach ($fileTags as $tag): ?>
                                <?php $selected = $query['tag'] !== '' && \Atelier\Support\Str::normalizeTag($query['tag']) === $tag['normalized']; $tagListed = $tagListed || $selected; ?>
                                <option value="<?= $e($tag['name']) ?>"<?= $selected ? ' selected' : '' ?>><?= $e($tag['name']) ?> (<?= (int) $tag['count'] ?>)</option>
                            <?php endforeach; ?>
                            <?php if ($query['tag'] !== '' && !$tagListed): ?>
                                <option value="<?= $e($query['tag']) ?>" selected><?= $e($query['tag']) ?></option>
                            <?php endif; ?>
                        </select>
                    </label>
                    <label class="field field--inline">
                        <span class="field__label">Rattachement</span>
                        <select class="select select--sm" name="linked" aria-label="Rattachement">
                            <option value="">Indifférent</option>
                            <option value="1"<?= $query['linked'] === '1' ? ' selected' : '' ?>>Rattachés</option>
                            <option value="0"<?= $query['linked'] === '0' ? ' selected' : '' ?>>Orphelins</option>
                        </select>
                    </label>
                    <a class="btn btn--sm btn--ghost" href="#" data-route="<?= $e($folderRoute($query['folder'])) ?>"<?= $filtered ? '' : ' aria-disabled="true"' ?>><svg class="icon" aria-hidden="true"><use href="#i-close"></use></svg> Réinitialiser</a>
                    <label class="field field--inline">
                        <span class="field__label">Par page</span>
                        <select class="select select--sm attachments__per-page" data-route-select aria-label="Fichiers par page">
                            <?php foreach ($perPageChoices as $option): ?>
                                <option value="<?= $e($base . '?' . http_build_query($keep($pageQuery + ['per_page' => $option, 'page' => null]))) ?>"<?= $query['per_page'] === $option ? ' selected' : '' ?>><?= (int) $option ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                </div>
            </form>

            <?php if ($rows === []): ?>
                <?= $module->renderCore('state', [
                    'type' => 'empty',
                    'title' => $filtered ? 'Aucun fichier ne correspond aux filtres' : ($currentFolder !== null ? 'Ce dossier est vide' : 'Aucun fichier'),
                    'message' => $filtered ? 'Modifiez la recherche ou les filtres.' : ($currentFolder !== null ? 'Téléversez un fichier dans ce dossier ou déplacez-y des fichiers existants.' : 'Téléversez un premier fichier ; il sera chiffré et accessible uniquement après contrôle des droits.'),
                    'actions' => $rights['create'] ? '<a class="btn btn--sm btn--primary" href="#" data-route="' . $e($uploadRoute) . '">Téléverser</a>' : '',
                ]) ?>
            <?php else: ?>
                <form data-action="move" data-attachments-bulk novalidate>
                    <?php if ($rights['update']): ?>
                        <div class="card attachments__bulk">
                            <div class="card__body toolbar mb-0">
                                <label class="checkbox"><input type="checkbox" data-attachments-select-all aria-label="Tout sélectionner"> <span data-attachments-selected-count>0 sélectionné</span></label>
                                <span class="toolbar__spacer"></span>
                                <label class="field field--inline mb-0">
                                    <span class="field__label">Déplacer vers</span>
                                    <select class="select select--sm" name="folder_id" aria-label="Dossier de destination">
                                        <option value="">Racine (non rangés)</option>
                                        <?php foreach ($folderOptions as $option): ?>
                                            <option value="<?= (int) $option['id'] ?>"<?= $option['id'] === $currentId ? ' selected' : '' ?>><?= $e(str_repeat('— ', $option['depth']) . $option['name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </label>
                                <button type="submit" class="btn btn--sm btn--primary" data-attachments-bulk-submit disabled><svg class="icon" aria-hidden="true"><use href="#i-folder"></use></svg> Déplacer</button>
                            </div>
                        </div>
                    <?php endif; ?>
                    <div class="table-wrap">
                        <table class="table attachments__table">
                            <thead>
                            <tr>
                                <?php if ($rights['update']): ?><th class="col-check"></th><?php endif; ?>
                                <th class="col-icon"></th>
                                <th><?= $sortHeader('Nom', 'original_name') ?></th>
                                <th>Tags</th>
                                <th class="col-num"><?= $sortHeader('Taille', 'size') ?></th>
                                <th><?= $sortHeader('Téléversé', 'created_at') ?></th>
                                <?php if ($scope === 'all'): ?><th><?= $sortHeader('Auteur', 'uploader') ?></th><?php endif; ?>
                                <?php if ($currentFolder === null && $query['folder'] !== 'root'): ?><th>Dossier</th><?php endif; ?>
                                <th>Rattaché à</th>
                                <th class="col-num" title="Téléchargements"><?= $sortHeader('Téléch.', 'downloads') ?></th>
                                <th class="col-actions">Actions</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($rows as $row): ?>
                                <?php
                                $manage = $assist || (int) ($row['uploaded_by'] ?? 0) === $currentUserId;
                                $name = $module::displayName($row);
                                $hasLabel = $name !== (string) $row['original_name'];
                                $tags = $rowTags[(string) $row['id']] ?? [];
                                $rowFolder = null;
                                if ($row['folder_id'] !== null) {
                                    foreach ($folderOptions as $option) {
                                        if ($option['id'] === (int) $row['folder_id']) {
                                            $rowFolder = $option;
                                            break;
                                        }
                                    }
                                }
                                ?>
                                <tr>
                                    <?php if ($rights['update']): ?>
                                        <td class="col-check"><?php if ($manage): ?><input type="checkbox" name="ids[]" value="<?= $e($row['id']) ?>" aria-label="Sélectionner <?= $e($name) ?>" data-attachments-select><?php endif; ?></td>
                                    <?php endif; ?>
                                    <td class="col-icon"><svg class="icon text-muted" aria-hidden="true"><use href="#i-<?= $e($module::kindIcon((string) $row['mime'])) ?>"></use></svg></td>
                                    <td class="attachments__cell-name">
                                        <a href="#" data-route="show/<?= $e($row['id']) ?>" class="attachments__name truncate" title="<?= $e($hasLabel ? 'Fichier : ' . $row['original_name'] : $row['original_name']) ?>"><?= $e($name) ?></a>
                                        <?php if ($hasLabel): ?><span class="text-muted text-small truncate" title="Nom du fichier d’origine"><?= $e($row['original_name']) ?></span><?php endif; ?>
                                        <?php if ($row['description'] !== null && $row['description'] !== ''): ?><span class="text-muted text-small truncate" title="<?= $e($row['description']) ?>"><?= $e($row['description']) ?></span><?php endif; ?>
                                    </td>
                                    <td class="attachments__cell-tags">
                                        <?php foreach ($tags as $tag): ?>
                                            <a class="chip" href="#" data-route="<?= $e($tagRoute($tag)) ?>" title="Filtrer sur le tag <?= $e($tag) ?>"><svg class="icon icon--sm" aria-hidden="true"><use href="#i-tag"></use></svg><?= $e($tag) ?></a>
                                        <?php endforeach; ?>
                                        <?php if ($tags === []): ?><span class="text-muted">—</span><?php endif; ?>
                                    </td>
                                    <td class="col-num text-nowrap"><?= $e($module->humanSize((int) $row['size'])) ?></td>
                                    <td class="text-nowrap"><?= $e($datetime($row['created_at'])) ?></td>
                                    <?php if ($scope === 'all'): ?><td class="text-nowrap"><?= $row['uploader'] !== null ? $e($row['uploader_name'] ?? $row['uploader']) : '<span class="text-muted">—</span>' ?></td><?php endif; ?>
                                    <?php if ($currentFolder === null && $query['folder'] !== 'root'): ?>
                                        <td class="truncate attachments__cell-folder"><?= $rowFolder !== null ? '<a href="#" data-route="' . $e($folderRoute($rowFolder['id'])) . '" title="' . $e($rowFolder['path']) . '"><svg class="icon icon--sm" aria-hidden="true"><use href="#i-folder"></use></svg> ' . $e($rowFolder['name']) . '</a>' : '<span class="text-muted">—</span>' ?></td>
                                    <?php endif; ?>
                                    <td class="truncate attachments__cell-info">
                                        <?php if ($row['info_id'] !== null): ?>
                                            <span title="<?= $e($row['info_dataset']) ?>"><?= $e($row['info_label'] !== null && $row['info_label'] !== '' ? $row['info_label'] : $row['info_dataset'] . ' #' . $row['info_key']) ?></span>
                                            <span class="text-muted text-small">· <?= $e($row['info_module']) ?></span>
                                        <?php else: ?>
                                            <span class="text-muted">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="col-num"><?= (int) $row['downloads'] ?></td>
                                    <td class="col-actions">
                                        <span class="table-actions">
                                            <?php if ($rights['read']): ?>
                                                <a class="btn btn--sm btn--icon btn--ghost" href="<?= $e($module->url('download/' . $row['id'])) ?>" download title="Télécharger <?= $e($row['original_name']) ?>" aria-label="Télécharger <?= $e($name) ?>"><svg class="icon" aria-hidden="true"><use href="#i-download"></use></svg></a>
                                            <?php endif; ?>
                                            <a class="btn btn--sm btn--icon btn--ghost" href="#" data-route="show/<?= $e($row['id']) ?>" title="Détail" aria-label="Détail de <?= $e($name) ?>"><svg class="icon" aria-hidden="true"><use href="#i-eye"></use></svg></a>
                                            <?php if ($manage && $rights['delete']): ?>
                                                <button type="button" class="btn btn--sm btn--icon btn--ghost" data-action="delete" data-params='{"id":"<?= $e($row['id']) ?>"}' data-confirm="Mettre « <?= $e($name) ?> » à la corbeille ?" data-danger title="Supprimer" aria-label="Supprimer <?= $e($name) ?>"><svg class="icon" aria-hidden="true"><use href="#i-trash"></use></svg></button>
                                            <?php endif; ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </form>
                <?= $module->renderCore('pagination', ['page' => $query['page'], 'perPage' => $query['per_page'], 'total' => $total, 'route' => $base, 'query' => $pageQuery]) ?>
            <?php endif; ?>
        </div>
    </div>
</div>
