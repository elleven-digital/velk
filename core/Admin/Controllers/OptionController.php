<?php

declare(strict_types=1);

namespace Velk\Admin\Controllers;

use Velk\FieldRenderer;
use Velk\Models\Option;
use Velk\Request;
use Velk\Response;
use Velk\Services\VariantService;

final class OptionController extends Controller
{
    public function edit(Request $request, array $params): Response
    {
        $auth = $this->requireAuth($request);
        if ($auth !== null) return $auth;

        $key = (string) ($params['key'] ?? '');
        // Look up theme-defined options first, fall back to built-ins
        // (e.g. the global tracking/scripts page that ships with core).
        $pageDef = $this->app->config->optionGroup($key);
        if ($pageDef === null) {
            return Response::notFound(__('options.error_not_found', ['key' => $key]));
        }

        $fieldDefs = $this->app->config->resolveFields((array) ($pageDef['fields'] ?? []));
        $values = Option::getGroup($key);
        $translations = Option::getTranslations($key);
        // Um grupo com `translatable: false` (o de assinaturas) não tem abas de
        // idioma nem grava traduções: quem o lê só usa o idioma padrão.
        $i18n = $this->app->config->isI18nEnabled() && ($pageDef['translatable'] ?? true) !== false;

        if ($request->isPost()) {
            $csrf = $this->verifyCsrfOrFail($request);
            if ($csrf !== null) return $csrf;

            $newValues = FieldRenderer::collect($fieldDefs, $request->post['fields'] ?? []);
            VariantService::ensureForFields($fieldDefs, $newValues);
            Option::setGroup($key, $newValues);

            // i18n Phase 3 — collect per-language translation bundles.
            if ($i18n) {
                $defaultLang = $this->app->config->defaultLanguage();
                $postedTranslations = (array) ($request->post['fields_translations'] ?? []);
                $updates = [];

                foreach (\Velk\Models\Language::all(onlyEnabled: true) as $lang) {
                    if ($lang->code === $defaultLang) continue;
                    // Only touch languages actually present in the POST —
                    // a language whose panel wasn't submitted (disabled at
                    // render time, or a trimmed/tampered form) must keep
                    // its existing stored translation, not get wiped.
                    if (!array_key_exists($lang->code, $postedTranslations)) continue;
                    $f = FieldRenderer::collect($fieldDefs, (array) $postedTranslations[$lang->code]);
                    // Type-aware: only writes real overrides — empty boolean/image
                    // fields don't pollute the fallback (see translationOverrides).
                    $f = FieldRenderer::translationOverrides($fieldDefs, $f, $newValues);
                    $updates[$lang->code] = $f !== [] ? $f : null;
                }
                Option::setTranslations($key, self::mergeTranslations($translations, $updates));
            }

            $this->app->session->flash('success', __('options.flash_saved'));
            return Response::redirect(admin_url('options/' . $key));
        }

        return $this->render('options/edit', [
            'key'       => $key,
            'pageDef'   => $pageDef,
            'fieldDefs' => $fieldDefs,
            'values'    => $values,
            'translations' => $translations,
            'i18nEnabled' => $i18n,
            'defaultLanguage' => $this->app->config->defaultLanguage(),
            'languages' => $i18n
                ? \Velk\Models\Language::all(onlyEnabled: true)
                : [],
        ]);
    }
}
