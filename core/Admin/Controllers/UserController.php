<?php

declare(strict_types=1);

namespace Velk\Admin\Controllers;

use Velk\Models\User;
use Velk\Request;
use Velk\Response;

final class UserController extends Controller
{
    private const MIN_PASSWORD = 8;

    public function index(Request $request): Response
    {
        $auth = $this->requireAuth($request);
        if ($auth !== null) return $auth;

        // Editors don't get the user list — bounce them to their own
        // profile (the only user record they're allowed to touch).
        $current = $this->app->auth->user();
        if ($current !== null && !$current->isAdmin()) {
            return Response::redirect(admin_url('users/' . $current->id));
        }

        $total = User::count();
        $pg = \Velk\Paginator::make($request->query['page'] ?? null, $total);

        return $this->render('users/index', [
            'users' => User::all($pg['perPage'], $pg['offset']),
            'currentUserId' => $current?->id,
            'total' => $total,
            'pg' => $pg,
            'pgQuery' => array_diff_key($request->query, ['page' => '']),
        ]);
    }

    public function create(Request $request): Response
    {
        $gate = $this->requireAdmin($request);
        if ($gate !== null) return $gate;

        return $this->editOrCreate($request, null);
    }

    public function edit(Request $request, array $params): Response
    {
        $auth = $this->requireAuth($request);
        if ($auth !== null) return $auth;

        $id = (int) ($params['id'] ?? 0);
        $current = $this->app->auth->user();

        // Editors can only edit themselves. Trying to reach another
        // user's edit page bounces them back to their own profile
        // with a clear message — both for GET and POST.
        if ($current !== null && !$current->isAdmin() && $current->id !== $id) {
            $this->app->session->flash('error', __('users.error_cannot_edit_others'));
            return Response::redirect(admin_url('users/' . $current->id));
        }

        return $this->editOrCreate($request, $id);
    }

    private function editOrCreate(Request $request, ?int $id): Response
    {
        $user = $id !== null ? User::find($id) : null;
        if ($id !== null && $user === null) {
            return Response::notFound(__('users.error_not_found'));
        }

        $current = $this->app->auth->user();
        // A user is editing themselves whenever the target record is
        // their own. This drives two rules: (1) the role selector is
        // hidden in the view, and (2) the controller refuses to update
        // the role column from the submitted form. Together they
        // prevent privilege escalation (editor → admin) and accidental
        // self-demotion of the last admin.
        $isSelf = $user !== null && $current !== null && $user->id === $current->id;

        $values = [
            'name'       => $user?->name ?? '',
            'email'      => $user?->email ?? '',
            'role'       => $user?->role ?? 'admin',
            'two_factor' => $user?->twoFactorEnabled ?? false,
            'locale'     => $user?->locale ?? '',
        ];
        $errors = [];

        if ($request->isPost()) {
            $csrf = $this->verifyCsrfOrFail($request);
            if ($csrf !== null) return $csrf;

            $values['name']  = trim((string) ($request->post['name'] ?? ''));
            $values['email'] = trim((string) ($request->post['email'] ?? ''));
            // Role from the form is only trusted when an admin is
            // editing someone else. On self-edit we ignore the input
            // entirely and keep whatever the DB has.
            if (!$isSelf) {
                $values['role'] = (string) ($request->post['role'] ?? 'admin');
            }
            // Unlike role, the 2FA toggle IS accepted on self-edit —
            // it's a security setting on one's own account, and an
            // editor locked out by a typo'd email can always be rescued
            // by an admin unchecking it here.
            $values['two_factor'] = !empty($request->post['two_factor']);
            // Same reasoning for the panel language: a personal
            // preference, not a privilege, so self-edit can change it
            // too. Empty ("Use default") or anything not a known
            // catalog locale normalizes to null — null means "inherit
            // admin.locale" (see User::$locale docs).
            $rawLocale = trim((string) ($request->post['locale'] ?? ''));
            $values['locale'] = ($rawLocale !== '' && in_array($rawLocale, $this->app->adminI18n->available(), true))
                ? $rawLocale
                : null;
            $password = (string) ($request->post['password'] ?? '');

            if ($values['name'] === '') {
                $errors['name'] = __('users.error_name_required');
            }
            if ($values['email'] === '' || !filter_var($values['email'], FILTER_VALIDATE_EMAIL)) {
                $errors['email'] = __('auth.invalid_email');
            }

            $passwordRequired = $user === null;
            if ($passwordRequired && strlen($password) < self::MIN_PASSWORD) {
                $errors['password'] = __('auth.password_too_short', ['min' => (string) self::MIN_PASSWORD]);
            } elseif (!$passwordRequired && $password !== '' && strlen($password) < self::MIN_PASSWORD) {
                $errors['password'] = __('auth.password_too_short', ['min' => (string) self::MIN_PASSWORD]);
            }

            if (!isset($errors['email'])) {
                $existing = User::findByEmail($values['email']);
                if ($existing !== null && ($user === null || $existing->id !== $user->id)) {
                    $errors['email'] = __('users.error_email_taken');
                }
            }

            if ($errors === []) {
                if ($user === null) {
                    $user = User::create($values['email'], $password, $values['name'], $values['role']);
                    if ($values['two_factor']) {
                        $user->save(['two_factor_enabled' => true]);
                    }
                    if ($values['locale'] !== null) {
                        $user->save(['locale' => $values['locale']]);
                    }
                } else {
                    $saveData = [
                        'name'  => $values['name'],
                        'email' => $values['email'],
                        'two_factor_enabled' => $values['two_factor'],
                        'locale' => $values['locale'],
                    ];
                    if (!$isSelf) {
                        // Only admins editing OTHER users get to change
                        // the role — see $isSelf rationale above.
                        $saveData['role'] = $values['role'];
                    }
                    $user->save($saveData);
                    if ($password !== '') {
                        $user->setPassword($password);
                    }
                }
                $this->app->session->flash('success', __('users.flash_saved'));
                return Response::redirect(admin_url('users/' . $user->id));
            }
        }

        return $this->render('users/edit', [
            'user' => $user,
            'values' => $values,
            'errors' => $errors,
            'currentUser' => $current,
        ]);
    }

    public function delete(Request $request, array $params): Response
    {
        $gate = $this->requireAdmin($request);
        if ($gate !== null) return $gate;
        $csrf = $this->verifyCsrfOrFail($request);
        if ($csrf !== null) return $csrf;

        $id = (int) ($params['id'] ?? 0);
        $user = User::find($id);
        $current = $this->app->auth->user();

        if ($user === null) {
            return Response::redirect(admin_url('users'));
        }

        if ($current !== null && $user->id === $current->id) {
            $this->app->session->flash('error', __('users.error_cannot_delete_self'));
            return Response::redirect(admin_url('users'));
        }

        if (User::count() <= 1) {
            $this->app->session->flash('error', __('users.error_cannot_delete_last'));
            return Response::redirect(admin_url('users'));
        }

        $user->delete();
        $this->app->session->flash('success', __('users.flash_deleted'));
        return Response::redirect(admin_url('users'));
    }

    /**
     * Gate for user-management actions (create / delete / view list).
     * Editors hit this and get redirected to the dashboard with a
     * polite flash — keeps the failure mode obvious if someone has
     * a bookmark to /admin/users/new from when they were promoted.
     */
    private function requireAdmin(Request $request): ?Response
    {
        $auth = $this->requireAuth($request);
        if ($auth !== null) return $auth;

        $user = $this->app->auth->user();
        if ($user === null || !$user->isAdmin()) {
            $this->app->session->flash('error', __('users.error_admin_only'));
            return Response::redirect(admin_url(''));
        }
        return null;
    }
}
