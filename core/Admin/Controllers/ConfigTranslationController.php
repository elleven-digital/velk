<?php

declare(strict_types=1);

namespace Velk\Admin\Controllers;

use Velk\Models\ConfigTranslation;
use Velk\Models\Language;
use Velk\Request;
use Velk\Response;

/**
 * Edit translations for site.json-declared config (item types and
 * taxonomies). Each editable cell is (config_type, config_key, field,
 * language) — mirroring the data model of the underlying table.
 *
 * Phase 2 only exposes `slug` as a translatable field. Labels stay
 * single-language (they're admin-facing; the staff speaks one
 * language). If a future need arises to translate other config keys
 * the editor adds a new field to the form here; the storage is
 * already generic.
 */
final class ConfigTranslationController extends Controller
{
    public function itemType(Request $request, array $params): Response
    {
        return $this->edit($request, 'item_type', (string) ($params['type'] ?? ''));
    }

    public function taxonomy(Request $request, array $params): Response
    {
        return $this->edit($request, 'taxonomy', (string) ($params['taxonomy'] ?? ''));
    }

    private function edit(Request $request, string $configType, string $configKey): Response
    {
        $auth = $this->requireAuth($request);
        if ($auth !== null) return $auth;

        if (!$this->app->config->isI18nEnabled()) {
            return Response::notFound(__('config_translations.error_i18n_disabled'));
        }

        // Validate the target config_key exists in site.json. We never
        // want to accept arbitrary keys here — only types/taxonomies
        // that actually exist (otherwise translations would orphan).
        $def = $configType === 'item_type'
            ? $this->app->config->itemType($configKey)
            : $this->app->config->taxonomy($configKey);
        if ($def === null) {
            $typeWord = $configType === 'item_type' ? __('items.edit_type') : __('terms.edit_taxonomy');
            return Response::notFound(__('config_translations.error_not_found', ['type' => $typeWord, 'key' => $configKey]));
        }

        $languages = Language::all(onlyEnabled: true);
        $defaultLang = $this->app->config->defaultLanguage();
        $defaultLabel = (string) ($def['label'] ?? $configKey);
        $defaultSlug = (string) ($def['slug'] ?? $configKey);

        $existing = ConfigTranslation::find($configType, $configKey);
        $slugTranslations = $existing?->translations['slug'] ?? [];

        $errors = [];

        if ($request->isPost()) {
            $csrf = $this->verifyCsrfOrFail($request);
            if ($csrf !== null) return $csrf;

            $newSlugs = (array) ($request->post['slug'] ?? []);
            foreach ($languages as $lang) {
                $value = trim((string) ($newSlugs[$lang->code] ?? ''));
                if ($value !== '' && !preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $value)) {
                    $errors['slug.' . $lang->code] = __('config_translations.error_invalid_slug');
                    continue;
                }
                ConfigTranslation::set($configType, $configKey, 'slug', $lang->code, $value);
                $slugTranslations[$lang->code] = $value;
            }

            if ($errors === []) {
                $this->app->session->flash('success', __('config_translations.flash_saved'));
                // Redirect back to the listing page that brought the user here
                $back = $configType === 'item_type'
                    ? admin_url('items/' . $configKey . '/translations')
                    : admin_url('taxonomies/' . $configKey . '/translations');
                return Response::redirect($back);
            }
        }

        return $this->render('config-translations/edit', [
            'configType' => $configType,
            'configKey' => $configKey,
            'defaultLanguage' => $defaultLang,
            'defaultLabel' => $defaultLabel,
            'defaultSlug' => $defaultSlug,
            'languages' => $languages,
            'slugTranslations' => $slugTranslations,
            'errors' => $errors,
            'backUrl' => $configType === 'item_type'
                ? admin_url('items/' . $configKey)
                : admin_url('taxonomies/' . $configKey),
        ]);
    }
}
