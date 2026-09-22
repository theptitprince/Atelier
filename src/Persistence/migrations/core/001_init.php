<?php

declare(strict_types=1);

use Atelier\Persistence\Database;

/**
 * Schéma initial du noyau Atelier.
 *
 * Conventions : identifiants entiers auto-incrémentés, dates UTC "Y-m-d H:i:s",
 * booléens en entier 0/1, texte UTF-8. Les tables des modules sont créées par
 * leurs propres migrations, préfixées par l'identifiant du module.
 */
return static function (Database $db): void {
    $pk = $db->primaryKey();
    $vc = $db->varchar();
    $vc64 = $db->varchar(64);
    $text = $db->text();
    $bool = $db->boolean();
    $dt = $db->datetime();
    $int = $db->integer();
    $big = $db->bigint();
    $opt = $db->tableOptions();

    // ----- Comptes, groupes -----
    $db->execute("CREATE TABLE users (
        id $pk,
        username $vc64 NOT NULL,
        display_name $vc NOT NULL,
        email $vc NULL,
        password_hash $vc NOT NULL,
        status $vc64 NOT NULL DEFAULT 'active',          -- active | disabled
        must_change_password $bool NOT NULL DEFAULT 0,
        failed_attempts $int NOT NULL DEFAULT 0,
        locked_until $dt NULL,
        last_login_at $dt NULL,
        password_changed_at $dt NULL,
        created_at $dt NOT NULL,
        updated_at $dt NOT NULL,
        disabled_at $dt NULL,
        UNIQUE (username)
    )$opt");

    $db->execute("CREATE TABLE groups (
        id $pk,
        name $vc64 NOT NULL,
        label $vc NOT NULL,
        description $text NULL,
        is_system $bool NOT NULL DEFAULT 0,
        created_at $dt NOT NULL,
        updated_at $dt NOT NULL,
        UNIQUE (name)
    )$opt");

    $db->execute("CREATE TABLE user_groups (
        user_id $int NOT NULL,
        group_id $int NOT NULL,
        added_at $dt NOT NULL,
        PRIMARY KEY (user_id, group_id),
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (group_id) REFERENCES groups(id) ON DELETE CASCADE
    )$opt");

    // ----- Ressources protégées, permissions, règles ACL -----
    // Les ressources sont synchronisées depuis les manifestes des modules à chaque découverte ;
    // le chemin est hiérarchique : atelier, atelier/notes, atelier/notes/screen/list, atelier/notes/data/note...
    $db->execute("CREATE TABLE resources (
        id $pk,
        path $vc NOT NULL,
        parent_path $vc NULL,
        module_id $vc64 NULL,
        kind $vc64 NOT NULL,                              -- app | group | module | screen | dataset | action
        label $vc NOT NULL,
        description $text NULL,
        permissions $text NULL,                           -- JSON : permissions pertinentes pour cette ressource
        is_present $bool NOT NULL DEFAULT 1,              -- 0 si le manifeste ne la déclare plus
        updated_at $dt NOT NULL,
        UNIQUE (path)
    )$opt");
    $db->execute("CREATE INDEX idx_resources_parent ON resources (parent_path)");
    $db->execute("CREATE INDEX idx_resources_module ON resources (module_id)");

    $db->execute("CREATE TABLE permissions (
        id $pk,
        code $vc64 NOT NULL,                              -- view, open, read, create, update, delete, import, export, admin, execute, ou code module
        module_id $vc64 NULL,                             -- NULL pour les permissions génériques du socle
        label $vc NOT NULL,
        description $text NULL,
        UNIQUE (code, module_id)
    )$opt");

    $db->execute("CREATE TABLE acl_rules (
        id $pk,
        subject_type $vc64 NOT NULL,                      -- user | group | all
        subject_id $int NULL,                             -- id utilisateur ou groupe ; NULL pour all
        resource $vc NOT NULL,                            -- chemin de ressource
        permission $vc64 NOT NULL,
        effect $vc64 NOT NULL,                            -- allow | deny
        comment $text NULL,
        created_by $int NULL,
        created_at $dt NOT NULL,
        UNIQUE (subject_type, subject_id, resource, permission)
    )$opt");
    $db->execute("CREATE INDEX idx_acl_resource ON acl_rules (resource)");
    $db->execute("CREATE INDEX idx_acl_subject ON acl_rules (subject_type, subject_id)");

    // ----- Paramètres et préférences -----
    $db->execute("CREATE TABLE settings (
        id $pk,
        module_id $vc64 NOT NULL DEFAULT 'core',
        name $vc NOT NULL,
        value $text NULL,
        updated_by $int NULL,
        updated_at $dt NOT NULL,
        UNIQUE (module_id, name)
    )$opt");

    $db->execute("CREATE TABLE user_preferences (
        id $pk,
        user_id $int NOT NULL,
        module_id $vc64 NOT NULL DEFAULT 'core',
        name $vc NOT NULL,
        value $text NULL,
        updated_at $dt NOT NULL,
        UNIQUE (user_id, module_id, name),
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    )$opt");

    // ----- Journal d'activité -----
    $db->execute("CREATE TABLE activity_log (
        id $pk,
        occurred_at $dt NOT NULL,
        user_id $int NULL,
        username $vc64 NULL,
        module_id $vc64 NOT NULL,
        action $vc NOT NULL,
        result $vc64 NOT NULL,                            -- success | failure | denied | error
        resource_ref $vc NULL,
        message $text NULL,
        details $text NULL,                               -- JSON, sans secret
        ip $vc64 NULL,
        error_id $vc64 NULL
    )$opt");
    $db->execute("CREATE INDEX idx_activity_occurred ON activity_log (occurred_at)");
    $db->execute("CREATE INDEX idx_activity_user ON activity_log (user_id)");
    $db->execute("CREATE INDEX idx_activity_module_action ON activity_log (module_id, action)");
    $db->execute("CREATE INDEX idx_activity_result ON activity_log (result)");

    // ----- Catalogue des jeux de données partagés et dépendances -----
    $db->execute("CREATE TABLE datasets (
        id $pk,
        code $vc NOT NULL,                                -- ex. notes.note
        module_id $vc64 NOT NULL,
        name $vc NOT NULL,
        description $text NULL,
        visibility $vc64 NOT NULL,                        -- shared | private
        tables $text NULL,                                -- JSON : tables ou vues concernées
        fields $text NULL,                                -- JSON : champs principaux et signification
        operations $text NULL,                            -- JSON : read, create, update, delete
        structure_version $int NOT NULL DEFAULT 1,
        is_present $bool NOT NULL DEFAULT 1,
        updated_at $dt NOT NULL,
        UNIQUE (code)
    )$opt");

    $db->execute("CREATE TABLE dataset_dependencies (
        id $pk,
        dataset_code $vc NOT NULL,
        module_id $vc64 NOT NULL,
        role $vc64 NOT NULL,                              -- producer | consumer
        updated_at $dt NOT NULL,
        UNIQUE (dataset_code, module_id, role)
    )$opt");

    // ----- Registre transversal des informations, tags, relations, pièces jointes -----
    $db->execute("CREATE TABLE info_registry (
        id $vc64 NOT NULL PRIMARY KEY,                    -- UUID stable et global
        dataset_code $vc NOT NULL,
        module_id $vc64 NOT NULL,
        local_key $vc NOT NULL,                           -- identifiant dans la table du module
        label $vc NULL,
        created_by $int NULL,
        created_at $dt NOT NULL,
        UNIQUE (dataset_code, local_key)
    )$opt");

    $db->execute("CREATE TABLE tags (
        id $pk,
        name $vc NOT NULL,                                -- forme d'affichage
        normalized $vc NOT NULL,                          -- minuscule, sans #, espaces réduits
        scope $vc64 NOT NULL DEFAULT 'shared',            -- shared | identifiant du module pour des tags privés
        created_by $int NULL,
        created_at $dt NOT NULL,
        UNIQUE (scope, normalized)
    )$opt");

    $db->execute("CREATE TABLE info_tags (
        info_id $vc64 NOT NULL,
        tag_id $int NOT NULL,
        added_by $int NULL,
        added_at $dt NOT NULL,
        PRIMARY KEY (info_id, tag_id),
        FOREIGN KEY (info_id) REFERENCES info_registry(id) ON DELETE CASCADE,
        FOREIGN KEY (tag_id) REFERENCES tags(id) ON DELETE CASCADE
    )$opt");

    $db->execute("CREATE TABLE relations (
        id $pk,
        type $vc64 NOT NULL,                              -- ex. related, parent, references, duplicates
        from_info $vc64 NOT NULL,
        to_info $vc64 NOT NULL,
        comment $text NULL,
        created_by $int NULL,
        created_at $dt NOT NULL,
        UNIQUE (type, from_info, to_info),
        FOREIGN KEY (from_info) REFERENCES info_registry(id) ON DELETE CASCADE,
        FOREIGN KEY (to_info) REFERENCES info_registry(id) ON DELETE CASCADE
    )$opt");

    $db->execute("CREATE TABLE attachments (
        id $vc64 NOT NULL PRIMARY KEY,                    -- identifiant interne imprévisible
        info_id $vc64 NULL,
        original_name $vc NOT NULL,
        mime $vc NOT NULL,
        size $big NOT NULL,
        sha256 $vc64 NOT NULL,
        storage_path $vc NOT NULL,                        -- chemin relatif dans var/attachments
        uploaded_by $int NULL,
        created_at $dt NOT NULL,
        deleted_at $dt NULL,
        FOREIGN KEY (info_id) REFERENCES info_registry(id) ON DELETE SET NULL
    )$opt");
    $db->execute("CREATE INDEX idx_attachments_info ON attachments (info_id)");
    $db->execute("CREATE INDEX idx_attachments_user ON attachments (uploaded_by)");

    // ----- Permissions génériques du socle -----
    $now = \Atelier\Support\Clock::utc();
    $generic = [
        ['view', 'Voir', 'Voir la ressource dans l’interface'],
        ['open', 'Ouvrir', 'Ouvrir ou utiliser la ressource'],
        ['read', 'Consulter', 'Consulter les données'],
        ['create', 'Créer', 'Créer des données'],
        ['update', 'Modifier', 'Modifier des données'],
        ['delete', 'Supprimer', 'Supprimer des données'],
        ['import', 'Importer', 'Importer des données'],
        ['export', 'Exporter', 'Exporter ou imprimer'],
        ['admin', 'Administrer', 'Administrer la ressource'],
        ['execute', 'Exécuter', 'Exécuter une action particulière'],
    ];
    foreach ($generic as [$code, $label, $description]) {
        $db->insert('permissions', ['code' => $code, 'module_id' => null, 'label' => $label, 'description' => $description]);
    }

    // Groupe d'administration système : ses membres reçoivent la règle allow admin sur la racine.
    $db->insert('groups', [
        'name' => 'admins',
        'label' => 'Administrateurs',
        'description' => 'Administration complète de l’application',
        'is_system' => 1,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $db->insert('groups', [
        'name' => 'users',
        'label' => 'Utilisateurs',
        'description' => 'Utilisateurs ordinaires',
        'is_system' => 1,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    // Ressource racine
    $db->insert('resources', [
        'path' => 'atelier',
        'parent_path' => null,
        'module_id' => null,
        'kind' => 'app',
        'label' => 'Application entière',
        'description' => 'Racine de l’arborescence des ressources protégées',
        'permissions' => null,
        'is_present' => 1,
        'updated_at' => $now,
    ]);
};
