<?php

declare(strict_types=1);

namespace Velk\Admin\Controllers;

use Velk\Models\Language;
use Velk\Request;
use Velk\Response;

/**
 * Admin CRUD for additional (non-default) site languages. The default
 * language lives in site.json — not editable here. This controller
 * only manages the languages declared on top of the default.
 *
 * Code-level guard: editor + admin both have access. Languages
 * configuration is more like content config than user management, so
 * editors who manage translations should be able to add languages too.
 */
final class LanguageController extends Controller
{
    public function index(Request $request): Response
    {
        $auth = $this->requireAuth($request);
        if ($auth !== null) return $auth;

        if (!$this->app->config->isI18nEnabled()) {
            return $this->render('languages/disabled', []);
        }

        return $this->render('languages/index', [
            'languages' => Language::all(),
            'defaultCode' => $this->app->config->defaultLanguage(),
        ]);
    }

    public function create(Request $request): Response
    {
        return $this->editOrCreate($request, null);
    }

    public function edit(Request $request, array $params): Response
    {
        return $this->editOrCreate($request, (string) ($params['code'] ?? ''));
    }

    private function editOrCreate(Request $request, ?string $code): Response
    {
        $auth = $this->requireAuth($request);
        if ($auth !== null) return $auth;

        if (!$this->app->config->isI18nEnabled()) {
            return $this->render('languages/disabled', []);
        }

        $language = ($code !== null && $code !== '') ? Language::find($code) : null;
        if ($code !== null && $code !== '' && $language === null) {
            return Response::notFound(__('languages.error_not_found'));
        }

        $values = [
            'code'       => $language?->code ?? '',
            'label'      => $language?->label ?? '',
            'url_prefix' => $language?->urlPrefix ?? '',
            'sort_order' => (string) ($language?->sortOrder ?? 0),
            'enabled'    => $language?->enabled ?? true,
        ];
        $errors = [];

        if ($request->isPost()) {
            $csrf = $this->verifyCsrfOrFail($request);
            if ($csrf !== null) return $csrf;

            $values['code']       = strtolower(trim((string) ($request->post['code'] ?? '')));
            $values['label']      = trim((string) ($request->post['label'] ?? ''));
            $rawPrefix            = trim((string) ($request->post['url_prefix'] ?? ''));
            $values['url_prefix'] = $rawPrefix === '' ? '' : '/' . trim($rawPrefix, '/');
            $values['sort_order'] = (string) ((int) ($request->post['sort_order'] ?? 0));
            $values['enabled']    = !empty($request->post['enabled']);

            // Language code: lowercase alphanumerics with optional region
            // tag (e.g., "en", "pt-BR" → "pt-br"). Strict format avoids
            // surprises like "EN-us " or a full language name.
            if (!preg_match('/^[a-z]{2,3}(-[a-z]{2,4})?$/', $values['code'])) {
                $errors['code'] = __('languages.error_invalid_code');
            }
            if ($values['code'] === $this->app->config->defaultLanguage()) {
                $errors['code'] = __('languages.error_is_default');
            }
            if ($values['label'] === '') {
                $errors['label'] = __('languages.error_name_required');
            }
            if ($values['url_prefix'] !== '' && !preg_match('#^/[a-z0-9\-]+$#', $values['url_prefix'])) {
                $errors['url_prefix'] = __('languages.error_invalid_prefix');
            }
            // Uniqueness checks
            if (!isset($errors['code'])) {
                $clash = Language::find($values['code']);
                if ($clash !== null && ($language === null || $clash->code !== $language->code)) {
                    $errors['code'] = __('languages.error_code_taken', ['code' => $values['code']]);
                }
            }
            if (!isset($errors['url_prefix']) && $values['url_prefix'] !== '') {
                $clash = Language::findByPrefix($values['url_prefix']);
                if ($clash !== null && ($language === null || $clash->code !== $language->code)) {
                    $errors['url_prefix'] = __('languages.error_prefix_taken', ['prefix' => $values['url_prefix'], 'code' => $clash->code]);
                }
            }

            if ($errors === []) {
                if ($language === null) {
                    Language::create(
                        $values['code'],
                        $values['label'],
                        $values['url_prefix'] !== '' ? $values['url_prefix'] : null,
                        (int) $values['sort_order'],
                        $values['enabled']
                    );
                } else {
                    $language->save([
                        'label'      => $values['label'],
                        'url_prefix' => $values['url_prefix'] !== '' ? $values['url_prefix'] : null,
                        'sort_order' => $values['sort_order'],
                        'enabled'    => $values['enabled'],
                    ]);
                }
                $this->app->session->flash('success', __('languages.flash_saved'));
                return Response::redirect(admin_url('languages'));
            }
        }

        return $this->render('languages/edit', [
            'language' => $language,
            'values' => $values,
            'errors' => $errors,
        ]);
    }

    public function delete(Request $request, array $params): Response
    {
        $auth = $this->requireAuth($request);
        if ($auth !== null) return $auth;
        $csrf = $this->verifyCsrfOrFail($request);
        if ($csrf !== null) return $csrf;

        $code = (string) ($params['code'] ?? '');
        $language = Language::find($code);
        if ($language !== null) {
            $language->delete();
            $this->app->session->flash('success', __('languages.flash_deleted'));
        }
        return Response::redirect(admin_url('languages'));
    }
}
