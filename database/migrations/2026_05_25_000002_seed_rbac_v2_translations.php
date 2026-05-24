<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase Զ.14 — RBAC v2 rewrite translation keys (HY/EN/RU).
 *
 * The new RBAC page references ~50 keys that weren't in ui_translations,
 * surfacing as literal `admin.rbac.export_matrix` text on the live page.
 * Seeds all of them idempotently (ON CONFLICT update).
 *
 * Cache invalidation: this migration runs `php artisan cache:forget`
 * via DB::afterCommit so Laravel's Cache::rememberForever() doesn't
 * keep serving stale per-language bundles.
 */
return new class extends Migration
{
    public function up(): void
    {
        $rows = [
            // Header & subtitle (override the stale read-only message)
            ['en', 'admin.rbac.title', 'Roles & permissions'],
            ['en', 'admin.rbac.subtitle', 'Manage who can do what on the platform'],
            ['hy', 'admin.rbac.title', 'Դերեր և թույլտվություններ'],
            ['hy', 'admin.rbac.subtitle', 'Կառավարեք, թե ով ինչ կարող է անել պլատֆորմում'],
            ['ru', 'admin.rbac.title', 'Роли и разрешения'],
            ['ru', 'admin.rbac.subtitle', 'Управление правами доступа на платформе'],

            // PageHeader actions
            ['en', 'admin.rbac.export_matrix', 'Export matrix'],
            ['en', 'admin.rbac.new_role', 'New role'],
            ['hy', 'admin.rbac.export_matrix', 'Արտահանել matrix-ը'],
            ['hy', 'admin.rbac.new_role', 'Նոր դեր'],
            ['ru', 'admin.rbac.export_matrix', 'Экспорт матрицы'],
            ['ru', 'admin.rbac.new_role', 'Новая роль'],

            // StatCards
            ['en', 'admin.rbac.stat_roles', 'Roles'],
            ['en', 'admin.rbac.stat_permissions', 'Permissions'],
            ['en', 'admin.rbac.stat_memberships', 'Memberships'],
            ['en', 'admin.rbac.stat_super_admins', 'Super admins'],
            ['hy', 'admin.rbac.stat_roles', 'Դերեր'],
            ['hy', 'admin.rbac.stat_permissions', 'Թույլտվություններ'],
            ['hy', 'admin.rbac.stat_memberships', 'Անդամակցություններ'],
            ['hy', 'admin.rbac.stat_super_admins', 'Super admin-ներ'],
            ['ru', 'admin.rbac.stat_roles', 'Роли'],
            ['ru', 'admin.rbac.stat_permissions', 'Разрешения'],
            ['ru', 'admin.rbac.stat_memberships', 'Членства'],
            ['ru', 'admin.rbac.stat_super_admins', 'Супер-админы'],

            // Role overview card
            ['en', 'admin.rbac.card_role_overview', 'Role overview'],
            ['en', 'admin.rbac.add_role', 'Add role'],
            ['en', 'admin.rbac.col_role', 'Role'],
            ['en', 'admin.rbac.col_description', 'Description'],
            ['en', 'admin.rbac.col_members', 'Members'],
            ['en', 'admin.rbac.col_permissions', 'Permissions'],
            ['en', 'admin.rbac.col_actions', 'Actions'],
            ['en', 'admin.rbac.empty', 'No roles yet.'],
            ['en', 'admin.rbac.empty_subtitle', 'Add a role to get started.'],
            ['hy', 'admin.rbac.card_role_overview', 'Դերերի ընդհանուր ակնարկ'],
            ['hy', 'admin.rbac.add_role', 'Ավելացնել դեր'],
            ['hy', 'admin.rbac.col_role', 'Դեր'],
            ['hy', 'admin.rbac.col_description', 'Նկարագրություն'],
            ['hy', 'admin.rbac.col_members', 'Անդամներ'],
            ['hy', 'admin.rbac.col_permissions', 'Թույլտվություններ'],
            ['hy', 'admin.rbac.col_actions', 'Գործողություններ'],
            ['hy', 'admin.rbac.empty', 'Դեռ դերեր չկան։'],
            ['hy', 'admin.rbac.empty_subtitle', 'Ավելացրեք դեր սկսելու համար։'],
            ['ru', 'admin.rbac.card_role_overview', 'Обзор ролей'],
            ['ru', 'admin.rbac.add_role', 'Добавить роль'],
            ['ru', 'admin.rbac.col_role', 'Роль'],
            ['ru', 'admin.rbac.col_description', 'Описание'],
            ['ru', 'admin.rbac.col_members', 'Участники'],
            ['ru', 'admin.rbac.col_permissions', 'Разрешения'],
            ['ru', 'admin.rbac.col_actions', 'Действия'],
            ['ru', 'admin.rbac.empty', 'Ролей пока нет.'],
            ['ru', 'admin.rbac.empty_subtitle', 'Добавьте роль, чтобы начать.'],

            // Permission matrix card
            ['en', 'admin.rbac.card_permission_matrix', 'Permission matrix'],
            ['en', 'admin.rbac.matrix_for', 'Module-level access for currently selected role:'],
            ['en', 'admin.rbac.col_module_page', 'Module / page'],
            ['en', 'admin.rbac.filter_permissions', 'Filter permissions'],
            ['en', 'admin.rbac.filter_placeholder', 'e.g. inventory, payment, voucher'],
            ['en', 'admin.rbac.matrix_pick_role', 'Pick a role above'],
            ['en', 'admin.rbac.matrix_no_role', 'Select a role from the table above to view its permissions.'],
            ['en', 'admin.rbac.matrix_no_match', 'No permissions match your filter.'],
            ['hy', 'admin.rbac.card_permission_matrix', 'Թույլտվությունների matrix'],
            ['hy', 'admin.rbac.matrix_for', 'Module-մակարդակի մուտք ընտրված դերի համար՝'],
            ['hy', 'admin.rbac.col_module_page', 'Module / էջ'],
            ['hy', 'admin.rbac.filter_permissions', 'Զտել թույլտվությունները'],
            ['hy', 'admin.rbac.filter_placeholder', 'օր.՝ inventory, payment, voucher'],
            ['hy', 'admin.rbac.matrix_pick_role', 'Ընտրեք դեր վերևից'],
            ['hy', 'admin.rbac.matrix_no_role', 'Ընտրեք դեր վերևի աղյուսակից՝ նրա թույլտվությունները տեսնելու համար։'],
            ['hy', 'admin.rbac.matrix_no_match', 'Ոչ մի թույլտվություն չի համապատասխանում զտիչին։'],
            ['ru', 'admin.rbac.card_permission_matrix', 'Матрица разрешений'],
            ['ru', 'admin.rbac.matrix_for', 'Доступ на уровне модуля для выбранной роли:'],
            ['ru', 'admin.rbac.col_module_page', 'Модуль / страница'],
            ['ru', 'admin.rbac.filter_permissions', 'Фильтр разрешений'],
            ['ru', 'admin.rbac.filter_placeholder', 'напр. inventory, payment, voucher'],
            ['ru', 'admin.rbac.matrix_pick_role', 'Выберите роль выше'],
            ['ru', 'admin.rbac.matrix_no_role', 'Выберите роль из таблицы выше, чтобы увидеть её разрешения.'],
            ['ru', 'admin.rbac.matrix_no_match', 'Нет разрешений по фильтру.'],

            // Drawer (create/edit role)
            ['en', 'admin.rbac.drawer_create_title', 'New role'],
            ['en', 'admin.rbac.drawer_create_subtitle', 'Define a role and assign permissions.'],
            ['en', 'admin.rbac.drawer_edit_title', 'Edit role'],
            ['en', 'admin.rbac.drawer_jump_matrix', 'Jump to permission matrix below'],
            ['en', 'admin.rbac.drawer_perms_hint', 'Permissions are managed in the matrix below — pick this role, then toggle checkboxes.'],
            ['en', 'admin.rbac.form_name', 'Name'],
            ['en', 'admin.rbac.form_name_help', 'Lowercase letters, digits, underscores. Used in code as the role identifier.'],
            ['en', 'admin.rbac.form_name_locked', 'Name cannot be changed after creation (used as code identifier).'],
            ['en', 'admin.rbac.form_description', 'Description'],
            ['en', 'admin.rbac.form_description_placeholder', 'What can holders of this role do?'],
            ['en', 'admin.rbac.form_scope', 'Scope'],
            ['en', 'admin.rbac.form_scope_help', 'Platform-scoped roles can be assigned only by super admins.'],
            ['en', 'admin.rbac.scope_platform', 'Platform'],
            ['en', 'admin.rbac.scope_company', 'Company'],
            ['hy', 'admin.rbac.drawer_create_title', 'Նոր դեր'],
            ['hy', 'admin.rbac.drawer_create_subtitle', 'Սահմանեք դեր և շնորհեք թույլտվություններ։'],
            ['hy', 'admin.rbac.drawer_edit_title', 'Խմբագրել դերը'],
            ['hy', 'admin.rbac.drawer_jump_matrix', 'Անցնել թույլտվությունների matrix-ին'],
            ['hy', 'admin.rbac.drawer_perms_hint', 'Թույլտվությունները կառավարվում են ներքևի matrix-ից՝ ընտրեք այս դերը և նշանակեք checkbox-ները։'],
            ['hy', 'admin.rbac.form_name', 'Անվանում'],
            ['hy', 'admin.rbac.form_name_help', 'Փոքրատառ լատիներեն, թվեր, ընդգծագիր։ Օգտագործվում է կոդում՝ որպես դերի identifier։'],
            ['hy', 'admin.rbac.form_name_locked', 'Անվանումը չի փոխվում ստեղծելուց հետո (օգտագործվում է որպես կոդի identifier)։'],
            ['hy', 'admin.rbac.form_description', 'Նկարագրություն'],
            ['hy', 'admin.rbac.form_description_placeholder', 'Ի՞նչ կարող են անել այս դերի կրողները։'],
            ['hy', 'admin.rbac.form_scope', 'Տիրույթ'],
            ['hy', 'admin.rbac.form_scope_help', 'Platform-տիրույթի դերերը կարող են շնորհվել միայն super admin-ների կողմից։'],
            ['hy', 'admin.rbac.scope_platform', 'Platform'],
            ['hy', 'admin.rbac.scope_company', 'Ընկերություն'],
            ['ru', 'admin.rbac.drawer_create_title', 'Новая роль'],
            ['ru', 'admin.rbac.drawer_create_subtitle', 'Определите роль и назначьте разрешения.'],
            ['ru', 'admin.rbac.drawer_edit_title', 'Редактировать роль'],
            ['ru', 'admin.rbac.drawer_jump_matrix', 'Перейти к матрице разрешений ниже'],
            ['ru', 'admin.rbac.drawer_perms_hint', 'Разрешения управляются в матрице ниже — выберите эту роль и переключайте чекбоксы.'],
            ['ru', 'admin.rbac.form_name', 'Имя'],
            ['ru', 'admin.rbac.form_name_help', 'Латиница в нижнем регистре, цифры, подчёркивания. Используется в коде как идентификатор роли.'],
            ['ru', 'admin.rbac.form_name_locked', 'Имя нельзя изменить после создания (используется как идентификатор кода).'],
            ['ru', 'admin.rbac.form_description', 'Описание'],
            ['ru', 'admin.rbac.form_description_placeholder', 'Что могут делать обладатели этой роли?'],
            ['ru', 'admin.rbac.form_scope', 'Область'],
            ['ru', 'admin.rbac.form_scope_help', 'Роли platform-области могут назначаться только супер-админами.'],
            ['ru', 'admin.rbac.scope_platform', 'Platform'],
            ['ru', 'admin.rbac.scope_company', 'Компания'],

            // Delete dialog
            ['en', 'admin.rbac.delete_title', 'Delete role?'],
            ['en', 'admin.rbac.delete_body_', 'This role and its permission grants will be permanently removed.'],
            ['en', 'admin.rbac.delete_blocked_members', 'Cannot delete — role has assigned members. Reassign them first.'],
            ['hy', 'admin.rbac.delete_title', 'Ջնջե՞լ դերը'],
            ['hy', 'admin.rbac.delete_body_', 'Այս դերը և իր թույլտվությունները կջնջվեն ընդմիշտ։'],
            ['hy', 'admin.rbac.delete_blocked_members', 'Չի կարող ջնջվել՝ դերն ունի նշանակված անդամներ։ Նախ վերանշանակեք նրանց։'],
            ['ru', 'admin.rbac.delete_title', 'Удалить роль?'],
            ['ru', 'admin.rbac.delete_body_', 'Эта роль и все её разрешения будут безвозвратно удалены.'],
            ['ru', 'admin.rbac.delete_blocked_members', 'Невозможно удалить — у роли есть назначенные участники. Сначала переназначьте их.'],

            // Toasts + errors
            ['en', 'admin.rbac.toast_created', 'Role created.'],
            ['en', 'admin.rbac.toast_updated', 'Role updated.'],
            ['en', 'admin.rbac.toast_deleted', 'Role deleted.'],
            ['en', 'admin.rbac.toast_granted', 'Permission granted.'],
            ['en', 'admin.rbac.toast_revoked', 'Permission revoked.'],
            ['en', 'admin.rbac.err_load', 'Failed to load RBAC data.'],
            ['en', 'admin.rbac.err_save', 'Failed to save role.'],
            ['en', 'admin.rbac.err_delete', 'Failed to delete role.'],
            ['hy', 'admin.rbac.toast_created', 'Դերը ստեղծվեց։'],
            ['hy', 'admin.rbac.toast_updated', 'Դերը թարմացվեց։'],
            ['hy', 'admin.rbac.toast_deleted', 'Դերը ջնջվեց։'],
            ['hy', 'admin.rbac.toast_granted', 'Թույլտվությունը տրվեց։'],
            ['hy', 'admin.rbac.toast_revoked', 'Թույլտվությունը վերցվեց։'],
            ['hy', 'admin.rbac.err_load', 'RBAC տվյալների բեռնումը ձախողվեց։'],
            ['hy', 'admin.rbac.err_save', 'Դերի պահպանումը ձախողվեց։'],
            ['hy', 'admin.rbac.err_delete', 'Դերի ջնջումը ձախողվեց։'],
            ['ru', 'admin.rbac.toast_created', 'Роль создана.'],
            ['ru', 'admin.rbac.toast_updated', 'Роль обновлена.'],
            ['ru', 'admin.rbac.toast_deleted', 'Роль удалена.'],
            ['ru', 'admin.rbac.toast_granted', 'Разрешение выдано.'],
            ['ru', 'admin.rbac.toast_revoked', 'Разрешение отозвано.'],
            ['ru', 'admin.rbac.err_load', 'Не удалось загрузить данные RBAC.'],
            ['ru', 'admin.rbac.err_save', 'Не удалось сохранить роль.'],
            ['ru', 'admin.rbac.err_delete', 'Не удалось удалить роль.'],
        ];

        $now = now();
        $insertRows = array_map(fn ($r) => [
            'language_code' => $r[0],
            'key' => $r[1],
            'value' => $r[2],
            'created_at' => $now,
            'updated_at' => $now,
        ], $rows);

        DB::table('ui_translations')->upsert(
            $insertRows,
            ['language_code', 'key'],
            ['value', 'updated_at']
        );

        // Per feedback_translation_cache_invalidation.md — Cache::rememberForever
        // would keep serving stale per-language bundles. Forget them explicitly.
        foreach (['en', 'hy', 'ru'] as $lang) {
            \Cache::forget("ui_translations_{$lang}");
        }
    }

    public function down(): void
    {
        // No down — translation seeds are forward-only; deleting would
        // re-show the literal keys in production.
    }
};
