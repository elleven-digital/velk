<?php

declare(strict_types=1);

namespace Velk\Admin\Controllers;

use Velk\FieldRenderer;
use Velk\Models\Member;
use Velk\Models\MemberSubscription;
use Velk\Request;
use Velk\Response;

/**
 * Admin panel CRUD for site members.
 *
 * Only mounts when `site.json → members.enabled` is true (the router
 * checks that before registering the routes). Both admins and editors
 * can manage members — it's content management, not user management
 * of admin-panel access (which only admins handle, in UserController).
 *
 * Custom fields render through FieldRenderer using a schema resolved
 * per-member-type. Global fields appear for everyone; type-specific
 * fields appear only when the member has that type set.
 */
final class MemberController extends Controller
{
    private const MIN_PASSWORD = 8;

    public function index(Request $request): Response
    {
        $auth = $this->requireAuth($request);
        if ($auth !== null) return $auth;

        if (!$this->app->config->isMembersEnabled()) {
            return Response::notFound(__('members.error_not_enabled'));
        }

        $typeFilter = (string) ($request->query['type'] ?? '');
        $typeFilter = $typeFilter !== '' ? $typeFilter : null;

        $showPlans = $this->app->config->isSubscriptionsEnabled();
        $subscriptions = [];
        $subscriptionConflicts = [];
        $planLabels = [];
        if ($showPlans) {
            foreach (\Velk\Services\SubscriptionService::make()->plans(false) as $p) {
                $planLabels[$p['key']] = $p['name'];
            }
            $rows = $this->app->db->fetchAll(
                "SELECT member_id, plan_key, status FROM member_subscriptions
                 WHERE status IN ('active','trialing','past_due')
                 ORDER BY id DESC"
            );
            // Todas as ativas por membro — antes ficava só a mais recente e o
            // resto sumia, então cobrança dupla era invisível aqui. Marca quem
            // excede a regra de cardinalidade (spec 2026-09-07, decisão 3:
            // detectar e mostrar, não cancelar).
            $subscriptions = MemberSubscription::groupActiveByMember($rows);
            $multiple = $this->app->config->allowsMultiplePlans();
            foreach ($subscriptions as $mid => $subs) {
                $subscriptionConflicts[$mid] = MemberSubscription::violatesCardinality(array_column($subs, 'plan_key'), $multiple);
            }
        }

        $total = Member::count($typeFilter);
        $pg = \Velk\Paginator::make($request->query['page'] ?? null, $total);

        return $this->render('members/index', [
            'members' => Member::all($pg['perPage'], $pg['offset'], $typeFilter),
            'types' => $this->app->config->memberTypes(),
            'typeFilter' => $typeFilter,
            'total' => $total,
            'pg' => $pg,
            'pgQuery' => array_diff_key($request->query, ['page' => '']),
            'publicRegistration' => $this->app->config->isMemberPublicRegistration(),
            'showPlans' => $showPlans,
            'subscriptions' => $subscriptions,
            'subscriptionConflicts' => $subscriptionConflicts,
            'planLabels' => $planLabels,
        ]);
    }

    public function create(Request $request): Response
    {
        return $this->editOrCreate($request, null);
    }

    public function edit(Request $request, array $params): Response
    {
        return $this->editOrCreate($request, (int) ($params['id'] ?? 0));
    }

    private function editOrCreate(Request $request, ?int $id): Response
    {
        $auth = $this->requireAuth($request);
        if ($auth !== null) return $auth;

        if (!$this->app->config->isMembersEnabled()) {
            return Response::notFound(__('members.error_not_enabled'));
        }

        $member = $id !== null ? Member::find($id) : null;
        if ($id !== null && $member === null) {
            return Response::notFound(__('members.error_not_found'));
        }

        $types = $this->app->config->memberTypes();
        $hasTypes = !empty($types);

        $values = [
            'name'       => $member?->name ?? '',
            'email'      => $member?->email ?? '',
            'type'       => $member?->type ?? ($hasTypes ? (string) array_key_first($types) : ''),
            'two_factor' => $member?->twoFactorEnabled ?? false,
        ];
        $errors = [];

        $effectiveType = $values['type'] !== '' ? $values['type'] : null;
        $fieldDefs = $this->app->config->memberFieldsFor($effectiveType);

        if ($request->isPost()) {
            $csrf = $this->verifyCsrfOrFail($request);
            if ($csrf !== null) return $csrf;

            $values['name']       = trim((string) ($request->post['name'] ?? '')) ?: '';
            $values['email']      = trim((string) ($request->post['email'] ?? ''));
            $values['type']       = $hasTypes ? (string) ($request->post['type'] ?? '') : '';
            $values['two_factor'] = !empty($request->post['two_factor']);
            $password             = (string) ($request->post['password'] ?? '');

            if ($values['email'] === '' || !filter_var($values['email'], FILTER_VALIDATE_EMAIL)) {
                $errors['email'] = __('auth.invalid_email');
            }

            if ($hasTypes && !isset($types[$values['type']])) {
                $errors['type'] = __('members.error_invalid_type');
            }

            $passwordRequired = $member === null;
            if ($passwordRequired && strlen($password) < self::MIN_PASSWORD) {
                $errors['password'] = __('auth.password_too_short', ['min' => self::MIN_PASSWORD]);
            } elseif (!$passwordRequired && $password !== '' && strlen($password) < self::MIN_PASSWORD) {
                $errors['password'] = __('auth.password_too_short', ['min' => self::MIN_PASSWORD]);
            }

            if (!isset($errors['email'])) {
                $existing = Member::findByEmail($values['email']);
                if ($existing !== null && ($member === null || $existing->id !== $member->id)) {
                    $errors['email'] = __('members.error_email_taken');
                }
            }

            // Re-resolve field defs against the type the user picked in
            // THIS submission, not the one they originally loaded with.
            // Otherwise switching type + saving would collect against
            // the wrong schema.
            $finalType = $values['type'] !== '' ? $values['type'] : null;
            $fieldDefs = $this->app->config->memberFieldsFor($finalType);
            $fieldValues = FieldRenderer::collect($fieldDefs, $request->post['fields'] ?? []);

            if ($errors === []) {
                if ($member === null) {
                    $member = Member::create($values['email'], $password, $values['name'] ?: null, $finalType, $fieldValues);
                    if ($values['two_factor']) {
                        $member->save(['two_factor_enabled' => true]);
                    }
                } else {
                    $member->save([
                        'name'   => $values['name'] ?: null,
                        'email'  => $values['email'],
                        'type'   => $finalType,
                        'fields' => $fieldValues,
                        'two_factor_enabled' => $values['two_factor'],
                    ]);
                    if ($password !== '') {
                        $member->setPassword($password);
                    }
                }
                $this->app->session->flash('success', __('members.flash_saved'));
                return Response::redirect(admin_url('members/' . $member->id));
            }
        }

        return $this->render('members/edit', [
            'member' => $member,
            'values' => $values,
            'errors' => $errors,
            'fieldDefs' => $fieldDefs,
            'types' => $types,
            'hasTypes' => $hasTypes,
        ]);
    }

    public function delete(Request $request, array $params): Response
    {
        $auth = $this->requireAuth($request);
        if ($auth !== null) return $auth;
        $csrf = $this->verifyCsrfOrFail($request);
        if ($csrf !== null) return $csrf;

        if (!$this->app->config->isMembersEnabled()) {
            return Response::notFound(__('members.error_not_enabled'));
        }

        $id = (int) ($params['id'] ?? 0);
        $member = Member::find($id);
        if ($member === null) {
            return Response::redirect(admin_url('members'));
        }

        $member->delete();
        $this->app->session->flash('success', __('members.flash_deleted'));
        return Response::redirect(admin_url('members'));
    }
}
