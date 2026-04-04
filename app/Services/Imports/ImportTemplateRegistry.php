<?php

namespace App\Services\Imports;

/**
 * Resolves import_sessions.template_version to column / sheet expectations.
 */
class ImportTemplateRegistry
{
    /**
     * @return array{
     *     entity_type: string,
     *     allowed_extensions: list<string>,
     *     sheet_names: list<string>,
     *     required_headers: list<string>,
     *     external_key_column: string,
     *     parent_external_key_column: string|null
     * }|null
     */
    public function get(string $templateVersion): ?array
    {
        $templates = config('import_templates.templates', []);

        return $templates[$templateVersion] ?? null;
    }

    /** @return list<string> */
    public function knownTemplateVersions(): array
    {
        return array_keys(config('import_templates.templates', []));
    }
}
