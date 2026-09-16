<?php
/**
 * EcoCycle — Admin / NGO dashboard (owner only).
 * City-wide analytics, per-neighborhood breakdown, member management,
 * and an exportable sustainability impact report (CSV).
 */
require_once __DIR__ . '/includes/functions.php';
requireAdmin();

$admin = currentUser();
$pdo   = db();
$catalogue = materials();

/* -------------------------------------------------------------------------
 * CSV export — must run before any HTML output.
 * ---------------------------------------------------------------------- */
if (($_GET['export'] ?? '') === 'impact') {
    $rows = $pdo->query(
        'SELECT u.neighborhood,
                COUNT(DISTINCT u.id)             AS members,
                COUNT(l.id)                      AS logs,
                COALESCE(SUM(l.weight_kg), 0)    AS kg_diverted,
                COALESCE(SUM(l.co2_saved_kg), 0) AS co2_saved_kg,
                COALESCE(SUM(u.total_points), 0) AS points_issued
           FROM users u
           LEFT JOIN recycling_logs l ON l.user_id = u.id
          GROUP BY u.neighborhood
          ORDER BY kg_diverted DESC'
    )->fetchAll();

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="ecocycle-impact-report-' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Neighborhood', 'Members', 'Logs', 'Kg diverted', 'CO2 saved (kg)', 'Tree-years equiv.', 'Points issued']);
    foreach ($rows as $r) {
        fputcsv($out, [
            $r['neighborhood'],
            (int) $r['members'],
            (int) $r['logs'],
            round((float) $r['kg_diverted'], 2),
            round((float) $r['co2_saved_kg'], 2),
            round(treesEquivalent((float) $r['co2_saved_kg']), 2),
            (int) $r['points_issued'],
        ]);
    }
    fclose($out);
    exit;
}

/* -------------------------------------------------------------------------
 * Member management: promote / demote admin (CSRF-protected).
 * ---------------------------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) {
        setFlash('error', 'Your session expired. Please try again.');
        header('Location: admin.php');
        exit;
    }
    $targetId = (int) ($_POST['user_id'] ?? 0);
    $makeAdmin = ($_POST['action'] ?? '') === 'promote' ? 1 : 0;

    if ($targetId === (int) $admin['id']) {
        setFlash('error', 'You cannot change your own owner status.');
    } else {
        $pdo->prepare('UPDATE users SET is_admin = ? WHERE id = ?')->execute([$makeAdmin, $targetId]);
        setFlash('success', $makeAdmin ? 'User promoted to administrator.' : 'Administrator access removed.');
    }
    header('Location: admin.php');
    exit;
}

/* -------------------------------------------------------------------------
 * Analytics queries.
 * ---------------------------------------------------------------------- */
$overview = $pdo->query(
    'SELECT
        (SELECT COUNT(*) FROM users)                          AS members,
        (SELECT COUNT(*) FROM recycling_logs)                 AS logs,
        (SELECT COALESCE(SUM(weight_kg),0) FROM recycling_logs)    AS total_kg,
        (SELECT COALESCE(SUM(co2_saved_kg),0) FROM recycling_logs) AS total_co2,
        (SELECT COALESCE(SUM(total_points),0) FROM users)     AS points_issued,
        (SELECT COUNT(*) FROM redemptions)                    AS redemptions,
        (SELECT COALESCE(SUM(points_spent),0) FROM redemptions) AS points_spent'
)->fetch();

// Participation: members who logged an entry in the last 30 days.
$activeMembers = (int) $pdo->query(
    'SELECT COUNT(DISTINCT user_id) FROM recycling_logs
      WHERE created_at >= (NOW() - INTERVAL 30 DAY)'
)->fetchColumn();
$participation = (int) $overview['members'] > 0
    ? round(($activeMembers / (int) $overview['members']) * 100)
    : 0;

$trees = treesEquivalent((float) $overview['total_co2']);

// Recycling by material.
$byMaterial = $pdo->query(
    'SELECT material_type,
            COUNT(*) AS logs,
            COALESCE(SUM(weight_kg),0) AS kg,
            COALESCE(SUM(co2_saved_kg),0) AS co2
       FROM recycling_logs
      GROUP BY material_type
      ORDER BY kg DESC'
)->fetchAll();

// Per-neighborhood breakdown (district heatmap data).
$byHood = $pdo->query(
    'SELECT u.neighborhood,
            COUNT(DISTINCT u.id) AS members,
            COALESCE(SUM(l.weight_kg),0) AS kg,
            COALESCE(SUM(l.co2_saved_kg),0) AS co2
       FROM users u
       LEFT JOIN recycling_logs l ON l.user_id = u.id
      GROUP BY u.neighborhood
      ORDER BY kg DESC'
)->fetchAll();
$maxHoodKg = 0.0;
foreach ($byHood as $h) {
    $maxHoodKg = max($maxHoodKg, (float) $h['kg']);
}

// Recent activity across all users.
$recent = $pdo->query(
    'SELECT l.*, u.name
       FROM recycling_logs l
       JOIN users u ON u.id = l.user_id
      ORDER BY l.created_at DESC
      LIMIT 12'
)->fetchAll();

// Members table.
$members = $pdo->query(
    'SELECT u.id, u.name, u.email, u.neighborhood, u.total_points, u.is_admin,
            COALESCE(SUM(l.weight_kg),0) AS kg
       FROM users u
       LEFT JOIN recycling_logs l ON l.user_id = u.id
      GROUP BY u.id
      ORDER BY u.is_admin DESC, u.total_points DESC'
)->fetchAll();

// Chart payload for materials.
$matLabels = array_map(fn ($r) => $catalogue[$r['material_type']]['label'] ?? $r['material_type'], $byMaterial);
$matKg     = array_map(fn ($r) => round((float) $r['kg'], 2), $byMaterial);

$pageTitle = 'Admin dashboard';
require __DIR__ . '/includes/header.php';
?>
<section class="max-w-6xl mx-auto px-4 py-10">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <span class="inline-flex items-center gap-2 bg-eco-800 text-white text-xs font-bold px-3 py-1 rounded-full uppercase tracking-wide">👑 Owner / Administrator</span>
            <h1 class="mt-3 text-2xl font-extrabold text-slate-900">City-wide impact — welcome, <?= e(explode(' ', $admin['name'])[0]) ?></h1>
            <p class="mt-1 text-slate-600">Program analytics for grant &amp; CSR reporting, aligned with UN SDG 12.</p>
        </div>
        <a href="admin.php?export=impact" class="px-5 py-2.5 rounded-xl bg-eco-600 text-white font-bold hover:bg-eco-700 transition">⬇ Export impact report (CSV)</a>
    </div>

    <!-- Key metrics -->
    <div class="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <?php
        $cards = [
            ['👥', num($overview['members']), 'Registered members'],
            ['📈', $participation . '%', 'Active in last 30 days'],
            ['♻️', num($overview['total_kg'], 1) . ' kg', 'Total waste diverted'],
            ['💨', num($overview['total_co2'], 1) . ' kg', 'CO₂ emissions saved'],
            ['🌳', num($trees, 1), 'Tree-years equivalent'],
            ['📝', num($overview['logs']), 'Recycling logs'],
            ['⭐', num($overview['points_issued']), 'Points issued'],
            ['🎁', num($overview['redemptions']), 'Rewards redeemed'],
        ];
        foreach ($cards as [$icon, $value, $label]): ?>
            <div class="bg-white rounded-2xl shadow-sm border border-eco-100 p-5">
                <div class="text-2xl" aria-hidden="true"><?= $icon ?></div>
                <div class="mt-2 text-2xl font-extrabold text-slate-900"><?= $value ?></div>
                <div class="text-xs font-semibold text-slate-500"><?= e($label) ?></div>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="mt-6 grid gap-6 lg:grid-cols-2">
        <!-- Material breakdown -->
        <div class="bg-white rounded-2xl shadow-sm border border-eco-100 p-6">
            <h2 class="font-bold text-slate-900">Waste diverted by material</h2>
            <?php if (!$byMaterial): ?>
                <p class="mt-6 text-sm text-slate-500 text-center py-8">No recycling logged yet.</p>
            <?php else: ?>
                <canvas id="matChart" height="220" class="mt-4" role="img" aria-label="Bar chart of kilograms diverted by material"></canvas>
            <?php endif; ?>
        </div>

        <!-- Neighborhood breakdown -->
        <div class="bg-white rounded-2xl shadow-sm border border-eco-100 p-6">
            <h2 class="font-bold text-slate-900">By neighborhood (district heatmap)</h2>
            <?php if (!$byHood): ?>
                <p class="mt-6 text-sm text-slate-500 text-center py-8">No data yet.</p>
            <?php else: ?>
                <ul class="mt-4 space-y-3">
                    <?php foreach ($byHood as $h):
                        $pct = $maxHoodKg > 0 ? (int) round(((float) $h['kg'] / $maxHoodKg) * 100) : 0; ?>
                        <li>
                            <div class="flex items-center justify-between text-sm">
                                <span class="font-semibold text-slate-700">🏘️ <?= e($h['neighborhood']) ?> <span class="text-xs font-normal text-slate-400">(<?= (int) $h['members'] ?> members)</span></span>
                                <span class="font-bold text-eco-700"><?= num($h['kg'], 1) ?> kg</span>
                            </div>
                            <div class="mt-1 h-2.5 w-full rounded-full bg-eco-100 overflow-hidden">
                                <div class="h-full rounded-full bg-eco-500" style="width: <?= $pct ?>%"></div>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    </div>

    <!-- Recent activity -->
    <div class="mt-6 bg-white rounded-2xl shadow-sm border border-eco-100 p-6">
        <h2 class="font-bold text-slate-900">Recent activity</h2>
        <?php if (!$recent): ?>
            <p class="mt-3 text-sm text-slate-500">No activity yet.</p>
        <?php else: ?>
            <ul class="mt-4 divide-y divide-slate-50">
                <?php foreach ($recent as $r): $m = $catalogue[$r['material_type']] ?? ['icon' => '♻️', 'label' => $r['material_type']]; ?>
                    <li class="flex items-center gap-3 py-2.5 text-sm">
                        <span class="text-lg" aria-hidden="true"><?= $m['icon'] ?></span>
                        <span class="font-semibold text-slate-700"><?= e($r['name']) ?></span>
                        <span class="text-slate-500">recycled <?= num($r['weight_kg'], 2) ?> kg of <?= e(strtolower($m['label'])) ?></span>
                        <span class="ml-auto text-xs text-slate-400"><?= e(date('M j, g:i a', strtotime($r['created_at']))) ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>

    <!-- Member management -->
    <div class="mt-6 bg-white rounded-2xl shadow-sm border border-eco-100 p-6">
        <h2 class="font-bold text-slate-900">Members &amp; roles</h2>
        <p class="text-sm text-slate-500 mt-1">Grant or revoke administrator access. You cannot change your own owner status.</p>
        <div class="mt-4 overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-slate-500 border-b border-slate-100">
                        <th class="py-2 pr-4 font-semibold">Member</th>
                        <th class="py-2 pr-4 font-semibold">Neighborhood</th>
                        <th class="py-2 pr-4 font-semibold">Points</th>
                        <th class="py-2 pr-4 font-semibold">Diverted</th>
                        <th class="py-2 pr-4 font-semibold">Role</th>
                        <th class="py-2 font-semibold">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($members as $m):
                        $isMe = (int) $m['id'] === (int) $admin['id'];
                        $isMemberAdmin = (int) $m['is_admin'] === 1; ?>
                        <tr class="border-b border-slate-50 <?= $isMe ? 'bg-eco-50' : '' ?>">
                            <td class="py-2.5 pr-4">
                                <div class="font-semibold text-slate-800"><?= e($m['name']) ?><?php if ($isMe): ?> <span class="text-xs text-eco-600">(you)</span><?php endif; ?></div>
                                <div class="text-xs text-slate-400"><?= e($m['email']) ?></div>
                            </td>
                            <td class="py-2.5 pr-4 text-slate-600"><?= e($m['neighborhood']) ?></td>
                            <td class="py-2.5 pr-4"><?= num($m['total_points']) ?></td>
                            <td class="py-2.5 pr-4"><?= num($m['kg'], 1) ?> kg</td>
                            <td class="py-2.5 pr-4">
                                <?php if ($isMemberAdmin): ?>
                                    <span class="inline-block px-2 py-0.5 rounded-full text-xs font-semibold bg-eco-800 text-white">Admin</span>
                                <?php else: ?>
                                    <span class="inline-block px-2 py-0.5 rounded-full text-xs font-semibold bg-slate-100 text-slate-600">Member</span>
                                <?php endif; ?>
                            </td>
                            <td class="py-2.5">
                                <?php if ($isMe): ?>
                                    <span class="text-xs text-slate-400">Owner</span>
                                <?php else: ?>
                                    <form method="post">
                                        <?= csrfField() ?>
                                        <input type="hidden" name="user_id" value="<?= (int) $m['id'] ?>">
                                        <input type="hidden" name="action" value="<?= $isMemberAdmin ? 'demote' : 'promote' ?>">
                                        <button type="submit" class="text-xs font-semibold <?= $isMemberAdmin ? 'text-red-600 hover:underline' : 'text-eco-700 hover:underline' ?>">
                                            <?= $isMemberAdmin ? 'Revoke admin' : 'Make admin' ?>
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>

<?php if ($byMaterial): ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
    (function () {
        Chart.defaults.font.family = 'Nunito, sans-serif';
        Chart.defaults.color = '#64748b';
        new Chart(document.getElementById('matChart'), {
            type: 'bar',
            data: {
                labels: <?= json_encode($matLabels) ?>,
                datasets: [{
                    label: 'kg diverted',
                    data: <?= json_encode($matKg) ?>,
                    backgroundColor: '#16a34a',
                    borderRadius: 8
                }]
            },
            options: {
                responsive: true,
                plugins: { legend: { display: false } },
                scales: { y: { beginAtZero: true } }
            }
        });
    })();
</script>
<?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
