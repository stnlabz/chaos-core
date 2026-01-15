<?php

declare(strict_types=1);

/**
 * Chaos CMS DB — Admin: Finance
 * Route:
 *   /admin?action=finance
 *
 * Purpose:
 * - Ledger only (records)
 * - Manual add entries
 * - Void / refund / mark paid (status changes)
 *
 * finance_ledger columns assumed:
 * id, user_id, creator_id, creator_username, amount_cents, currency, kind, status, ref_type, ref_id, note, created_at
 */

(function (): void {
    global $db, $auth;

    echo '<div class="admin-wrap">';

    $h = static function (string $v): string {
        return htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
    };

    $crumb = '<small><a href="/admin">Admin</a> &raquo; Finance</small>';

    if (!isset($db) || !$db instanceof db) {
        echo '<div class="container my-4">' . $crumb . '<div class="alert alert-danger mt-2">DB is not available.</div></div>';
        return;
    }

    $conn = $db->connect();
    if ($conn === false) {
        echo '<div class="container my-4">' . $crumb . '<div class="alert alert-danger mt-2">DB connection failed.</div></div>';
        return;
    }

    $notice = '';
    $error  = '';

    // -------------------------------------------------------------------------
    // POST actions
    // -------------------------------------------------------------------------
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        $do = (string)($_POST['do'] ?? '');

        if ($do === 'add') {
            $userId = (int)($_POST['user_id'] ?? 0);
            $refType = (string)($_POST['ref_type'] ?? '');
            $refId = (int)($_POST['ref_id'] ?? 0);

            $creatorId = 0;
            $creatorUsername = '';

            // Resolve creator automatically for posts
            if ($refType === 'post') {
                $stmtC = $conn->prepare("
                    SELECT u.id, u.username
                    FROM posts p
                    LEFT JOIN users u ON u.id = p.author_id
                    WHERE p.id=? LIMIT 1
                ");
                if ($stmtC !== false) {
                    $stmtC->bind_param('i', $refId);
                    $stmtC->execute();
                    $row = $stmtC->get_result()->fetch_assoc();
                    $stmtC->close();

                    if (is_array($row)) {
                        $creatorId = (int)($row['id'] ?? 0);
                        $creatorUsername = (string)($row['username'] ?? '');
                    }
                }
            }

            // Media: must be provided manually (no uploader column exists)
            if ($refType === 'media') {
                $creatorId = (int)($_POST['creator_id'] ?? 0);
                if ($creatorId > 0) {
                    $stmtU = $conn->prepare("SELECT username FROM users WHERE id=? LIMIT 1");
                    if ($stmtU !== false) {
                        $stmtU->bind_param('i', $creatorId);
                        $stmtU->execute();
                        $row = $stmtU->get_result()->fetch_assoc();
                        $stmtU->close();
                        if (is_array($row)) {
                            $creatorUsername = (string)($row['username'] ?? '');
                        }
                    }
                }
            }

            $amountCents = (int)($_POST['amount_cents'] ?? 0);
            $currency = strtoupper(trim((string)($_POST['currency'] ?? 'USD')));
            if ($currency === '' || strlen($currency) !== 3) {
                $currency = 'USD';
            }

            $kind = (string)($_POST['kind'] ?? 'purchase');
            if (!in_array($kind, ['purchase', 'credit', 'refund'], true)) {
                $kind = 'purchase';
            }

            $status = (string)($_POST['status'] ?? 'paid');
            if (!in_array($status, ['pending', 'paid', 'void', 'refunded'], true)) {
                $status = 'paid';
            }

            $note = trim((string)($_POST['note'] ?? ''));
            if (strlen($note) > 255) {
                $note = substr($note, 0, 255);
            }

            if ($userId <= 0 || $refId <= 0 || !in_array($refType, ['post', 'media'], true)) {
                $error = 'Missing/invalid fields.';
            } else {
                $sql = "
                    INSERT INTO finance_ledger
                    (user_id, creator_id, creator_username, amount_cents, currency, kind, status, ref_type, ref_id, note)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ";
                $stmt = $conn->prepare($sql);
                if ($stmt === false) {
                    $error = 'DB prepare failed.';
                } else {
                    $stmt->bind_param(
                        'iisissssis',
                        $userId,
                        $creatorId,
                        $creatorUsername,
                        $amountCents,
                        $currency,
                        $kind,
                        $status,
                        $refType,
                        $refId,
                        $note
                    );
                    $stmt->execute();
                    $stmt->close();
                    $notice = 'Ledger entry added.';
                }
            }

            header('Location: /admin?action=finance');
            exit;
        }

        if ($do === 'set_status') {
            $id = (int)($_POST['id'] ?? 0);
            $status = (string)($_POST['status'] ?? '');
            if (in_array($status, ['pending', 'paid', 'void', 'refunded'], true) && $id > 0) {
                $stmt = $conn->prepare("UPDATE finance_ledger SET status=? WHERE id=? LIMIT 1");
                if ($stmt !== false) {
                    $stmt->bind_param('si', $status, $id);
                    $stmt->execute();
                    $stmt->close();
                }
            }

            header('Location: /admin?action=finance');
            exit;
        }
    }

    // -------------------------------------------------------------------------
    // Fetch ledger rows
    // -------------------------------------------------------------------------
    $sql = "
        SELECT
            fl.id,
            fl.user_id,
            u.username AS buyer,
            fl.creator_id,
            fl.creator_username,
            fl.amount_cents,
            fl.currency,
            fl.kind,
            fl.status,
            fl.ref_type,
            fl.ref_id,
            fl.note,
            fl.created_at
        FROM finance_ledger fl
        LEFT JOIN users u ON u.id = fl.user_id
        ORDER BY fl.id DESC
        LIMIT 300
    ";

    $rows = [];
    $res = $conn->query($sql);
    if ($res instanceof mysqli_result) {
        while ($r = $res->fetch_assoc()) {
            if (is_array($r)) {
                $rows[] = $r;
            }
        }
        $res->close();
    }

    ?>
    <div class="container my-4 admin-finance">
        <?= $crumb; ?>

        <h1 class="h3 mt-2">Finance Ledger</h1>

        <div class="card mt-3">
            <div class="card-body">

                <div class="fw-semibold mb-2">Ledger</div>

                <?php if (empty($rows)): ?>
                    <div class="alert small mt-3">No ledger entries.</div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-sm">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Buyer</th>
                                    <th>Creator</th>
                                    <th>Type</th>
                                    <th>Ref</th>
                                    <th>Amount</th>
                                    <th>Kind</th>
                                    <th>Status</th>
                                    <th>Note</th>
                                    <th>Created</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($rows as $r): ?>
                                    <tr>
                                        <td><?= (int)$r['id']; ?></td>
                                        <td>
                                            <?= (int)$r['user_id']; ?>
                                            <div class="small text-muted"><?= $h((string)$r['buyer']); ?></div>
                                        </td>
                                        <td>
                                            <?= (int)$r['creator_id']; ?>
                                            <div class="small text-muted"><?= $h((string)$r['creator_username']); ?></div>
                                        </td>
                                        <td><?= $h((string)$r['ref_type']); ?></td>
                                        <td><?= (int)$r['ref_id']; ?></td>
                                        <td><?= (int)$r['amount_cents']; ?> <?= $h((string)$r['currency']); ?></td>
                                        <td><?= $h((string)$r['kind']); ?></td>
                                        <td><strong><?= $h((string)$r['status']); ?></strong></td>
                                        <td class="small"><?= $h((string)$r['note']); ?></td>
                                        <td class="small"><?= $h((string)$r['created_at']); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>

            </div>
        </div>
    </div>
    <?php
})();

