<?php
require_once __DIR__ . '/database.php';

function getActiveSubscription(int $userId): array|false {
    return fetchOne(
        "SELECT s.*, p.name AS plan_name, p.slug AS plan_slug, p.duration_days
         FROM subscriptions s
         JOIN subscription_plans p ON p.id = s.plan_id
         WHERE s.user_id = ?
           AND s.status = 'active'
           AND s.expires_at > NOW()
         ORDER BY s.expires_at DESC
         LIMIT 1",
        [$userId]
    );
}

function userHasActiveSubscription(int $userId): bool {
    return (bool) getActiveSubscription($userId);
}

function daysRemaining(int $userId): int {
    // Find the furthest expires_at across all active subs for this user
    $row = fetchOne(
        "SELECT MAX(expires_at) AS furthest
         FROM subscriptions
         WHERE user_id = ?
           AND status = 'active'
           AND expires_at > NOW()",
        [$userId]
    );
    if (empty($row['furthest'])) return 0;
    $diff = (new DateTime($row['furthest']))->diff(new DateTime());
    return max(0, (int)$diff->days);
}

function activateSubscription(int $subscriptionId, string $esewaRefId): bool {
    $sub = fetchOne("SELECT * FROM subscriptions WHERE id = ?", [$subscriptionId]);
    if (!$sub) return false;

    $plan = fetchOne("SELECT * FROM subscription_plans WHERE id = ?", [$sub['plan_id']]);
    if (!$plan) return false;

    $days = (int)$plan['duration_days'];

    // Check if the user already has an active subscription that expires in the future
    $existing = fetchOne(
        "SELECT MAX(expires_at) AS furthest
         FROM subscriptions
         WHERE user_id = ?
           AND status = 'active'
           AND expires_at > NOW()
           AND id != ?",
        [$sub['user_id'], $subscriptionId]
    );

    // If there's an existing active sub, stack on top of it; otherwise start from now
    if (!empty($existing['furthest'])) {
        $start = new DateTime($existing['furthest']);
    } else {
        $start = new DateTime();
    }

    $startStr = $start->format('Y-m-d H:i:s');
    $end      = (clone $start)->modify("+{$days} days");
    $endStr   = $end->format('Y-m-d H:i:s');

    $rows = execute(
        "UPDATE subscriptions
         SET status='active', starts_at=?, expires_at=?, esewa_ref_id=?, updated_at=NOW()
         WHERE id=?",
        [$startStr, $endStr, $esewaRefId, $subscriptionId]
    );
    return $rows > 0;
}

function createPendingSubscription(int $userId, int $planId): int {
    $plan = fetchOne("SELECT * FROM subscription_plans WHERE id=?", [$planId]);
    return (int) insert(
        "INSERT INTO subscriptions (user_id, plan_id, amount, status) VALUES (?,?,?,'pending')",
        [$userId, $planId, $plan['price']]
    );
}

function getAllSubscriptions(string $status = '', int $limit = 100, int $offset = 0): array {
    $where  = $status ? "WHERE s.status = ?" : "WHERE 1";
    $params = $status ? [$status, $limit, $offset] : [$limit, $offset];
    return fetchAll(
        "SELECT s.*, u.full_name, u.email,
                p.name AS plan_name, p.slug AS plan_slug, p.price AS plan_price
         FROM subscriptions s
         JOIN users u ON u.id = s.user_id
         JOIN subscription_plans p ON p.id = s.plan_id
         {$where}
         ORDER BY s.created_at DESC
         LIMIT ? OFFSET ?",
        $params
    );
}

function getRevenueSummary(): array {
    $today = fetchOne(
        "SELECT COALESCE(SUM(amount),0) as total FROM subscriptions
         WHERE status='active' AND DATE(created_at)=CURDATE()"
    );
    $month = fetchOne(
        "SELECT COALESCE(SUM(amount),0) as total FROM subscriptions
         WHERE status='active' AND created_at>=DATE_SUB(NOW(),INTERVAL 30 DAY)"
    );
    $all   = fetchOne(
        "SELECT COALESCE(SUM(amount),0) as total FROM subscriptions WHERE status IN('active','expired')"
    );
    $active = fetchOne("SELECT COUNT(*) as c FROM subscriptions WHERE status='active' AND expires_at>NOW()");
    return [
        'today'   => (float)$today['total'],
        'month'   => (float)$month['total'],
        'all'     => (float)$all['total'],
        'active'  => (int)$active['c'],
    ];
}

function getExpiringSoon(int $days = 5): array {
    return fetchAll(
        "SELECT s.*, u.full_name, u.email
         FROM subscriptions s
         JOIN users u ON u.id = s.user_id
         WHERE s.status='active'
           AND s.reminder_sent=0
           AND s.expires_at BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL ? DAY)",
        [$days]
    );
}

function markReminderSent(int $subscriptionId): void {
    execute("UPDATE subscriptions SET reminder_sent=1 WHERE id=?", [$subscriptionId]);
}

function expireOldSubscriptions(): int {
    return execute(
        "UPDATE subscriptions SET status='expired', updated_at=NOW()
         WHERE status='active' AND expires_at <= NOW()"
    );
}

function getUserConsultants(int $userId): array {
    return fetchAll(
        "SELECT c.*, uc.assigned_at
         FROM user_consultants uc
         JOIN consultants c ON c.id = uc.consultant_id
         WHERE uc.user_id = ? AND c.is_active = 1",
        [$userId]
    );
}

function getAllConsultants(string $role = ''): array {
    $where  = $role ? "AND role=?" : "";
    $params = $role ? [$role] : [];
    return fetchAll(
        "SELECT c.*,
            (SELECT COUNT(*) FROM user_consultants uc WHERE uc.consultant_id=c.id) AS user_count
         FROM consultants c
         WHERE c.is_active=1 {$where}
         ORDER BY c.full_name",
        $params
    );
}

function assignConsultant(int $userId, int $consultantId, int $adminId = 0): bool {
    try {
        insert(
            "INSERT IGNORE INTO user_consultants (user_id, consultant_id, assigned_by) VALUES (?,?,?)",
            [$userId, $consultantId, $adminId]
        );
        return true;
    } catch (Exception $e) {
        return false;
    }
}


function removeConsultant(int $userId, int $consultantId): bool {
    return execute(
        "DELETE FROM user_consultants WHERE user_id=? AND consultant_id=?",
        [$userId, $consultantId]
    ) > 0;
}