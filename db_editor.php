<?php
ob_start();
require_once 'db.php';

// if (($_GET['password'] ?? '') !== 'WneljaeGianganOwner1442') {
//     http_response_code(403);
//     die("
//         <div style='padding:40px;font-family:sans-serif;color:#ef4444;'>
//             <h2>🚫 Access Denied</h2>
//             <p>Only the owner can access the Database Editor.</p>
//             <a href='index.php'>← Go Back</a>
//         </div>
//     ");
// }

if (($_SESSION['role'] ?? '') !== 'Admin') {
    http_response_code(403);
    die("<div style='padding:40px;font-family:sans-serif;color:#ef4444;'><h2>🚫 Access Denied</h2><p>Only Administrators can access the Database Editor.</p><a href='index.php'>← Go Back</a></div>");
}

$message = '';
$error   = '';

// ─── Handle AJAX / POST actions ───────────────────────────────────────────────
$action = $_POST['action'] ?? $_GET['action'] ?? '';

// Delete a single row
if ($action === 'delete_row' && isset($_POST['table'], $_POST['rowid'])) {
    $table = preg_replace('/[^a-zA-Z0-9_]/', '', $_POST['table']);
    $rowid = (int)$_POST['rowid'];
    try {
        $pdo->prepare("DELETE FROM {$table} WHERE rowid = ?")->execute([$rowid]);
        header('Content-Type: application/json');
        echo json_encode(['success' => true]);
        exit;
    } catch (Exception $e) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        exit;
    }
}

// Update a single cell
if ($action === 'update_cell' && isset($_POST['table'], $_POST['rowid'], $_POST['column'], $_POST['value'])) {
    $table  = preg_replace('/[^a-zA-Z0-9_]/', '', $_POST['table']);
    $col    = preg_replace('/[^a-zA-Z0-9_]/', '', $_POST['column']);
    $rowid  = (int)$_POST['rowid'];
    $value  = $_POST['value'];
    try {
        $pdo->prepare("UPDATE {$table} SET {$col} = ? WHERE rowid = ?")->execute([$value, $rowid]);
        header('Content-Type: application/json');
        echo json_encode(['success' => true]);
        exit;
    } catch (Exception $e) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        exit;
    }
}

// Execute arbitrary SQL (admin power)
if ($action === 'run_sql' && isset($_POST['sql'])) {
    header('Content-Type: application/json');
    $sql = trim($_POST['sql']);
    if (empty($sql)) { echo json_encode(['error' => 'Empty query.']); exit; }
    try {
        $lower = strtolower(ltrim($sql));
        if (str_starts_with($lower, 'select')) {
            $stmt = $pdo->query($sql);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $cols = $rows ? array_keys($rows[0]) : ($stmt->getColumnMeta ? [] : []);
            echo json_encode(['type' => 'select', 'rows' => $rows, 'cols' => $cols]);
        } else {
            $affected = $pdo->exec($sql);
            echo json_encode(['type' => 'exec', 'affected' => $affected]);
        }
    } catch (Exception $e) {
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// ─── List all tables ──────────────────────────────────────────────────────────
$tables = [];
try {
    $rows = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name ASC")->fetchAll();
    foreach ($rows as $r) $tables[] = $r['name'];
} catch (Exception $e) { $error = $e->getMessage(); }

// ─── Fetch rows for selected table ───────────────────────────────────────────
$active_table = preg_replace('/[^a-zA-Z0-9_]/', '', $_GET['table'] ?? ($tables[0] ?? ''));
$table_data   = [];
$table_cols   = [];
$total_rows   = 0;
$per_page     = 50;
$page         = max(1, (int)($_GET['p'] ?? 1));
$search       = trim($_GET['q'] ?? '');

if ($active_table) {
    try {
        // Columns
        $col_stmt = $pdo->query("PRAGMA table_info({$active_table})");
        $table_cols = $col_stmt->fetchAll();

        // Count
        $where_sql = '';
        $bind = [];
        if ($search && count($table_cols)) {
            $parts = array_map(fn($c) => "CAST({$c['name']} AS TEXT) LIKE ?", $table_cols);
            $where_sql = ' WHERE ' . implode(' OR ', $parts);
            $bind = array_fill(0, count($table_cols), "%{$search}%");
        }
        $total_rows = (int)$pdo->prepare("SELECT COUNT(*) FROM {$active_table}{$where_sql}")->execute($bind) ? $pdo->prepare("SELECT COUNT(*) FROM {$active_table}{$where_sql}")->execute($bind) && 0 : 0;

        $cnt_stmt = $pdo->prepare("SELECT COUNT(*) FROM {$active_table}{$where_sql}");
        $cnt_stmt->execute($bind);
        $total_rows = (int)$cnt_stmt->fetchColumn();

        $offset = ($page - 1) * $per_page;
        try {
            $data_stmt = $pdo->prepare("SELECT rowid, * FROM {$active_table}{$where_sql} LIMIT {$per_page} OFFSET {$offset}");
            $data_stmt->execute($bind);
            $table_data = $data_stmt->fetchAll();
        } catch (Exception $e2) {
            // WITHOUT ROWID tables — fall back without explicit rowid
            $data_stmt = $pdo->prepare("SELECT * FROM {$active_table}{$where_sql} LIMIT {$per_page} OFFSET {$offset}");
            $data_stmt->execute($bind);
            $table_data = $data_stmt->fetchAll();
        }
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

$total_pages = $total_rows > 0 ? ceil($total_rows / $per_page) : 1;

include 'header.php';
?>

<style>
/* ── DB Editor Styles ── */
.db-layout          { display: flex; gap: 20px; align-items: flex-start; }
.db-sidebar         { width: 220px; flex-shrink: 0; }
.db-sidebar ul      { list-style: none; margin: 0; padding: 0; }
.db-sidebar li a    { display: flex; align-items: center; gap: 8px; padding: 9px 14px; border-radius: 7px;
                       text-decoration: none; font-size: 0.875rem; color: #475569; transition: all 0.15s;
                       font-weight: 500; white-space: nowrap; overflow: hidden; }
.db-sidebar li a:hover           { background: rgba(var(--primary-rgb,79,70,229),0.08); color: var(--primary); }
.db-sidebar li a.active          { background: var(--primary); color: #fff; }
.db-sidebar li a .tbl-badge      { margin-left: auto; font-size: 0.7rem; background: rgba(255,255,255,0.25); 
                                    padding: 1px 6px; border-radius: 10px; }
.db-sidebar li a:not(.active) .tbl-badge { background: #e2e8f0; color: #64748b; }

.db-main            { flex: 1; min-width: 0; }
.db-toolbar         { display: flex; gap: 10px; flex-wrap: wrap; align-items: center; margin-bottom: 14px; }
.db-search          { flex: 1; min-width: 160px; max-width: 300px; }

/* Inline edit cells */
.cell-value         { cursor: pointer; padding: 3px 6px; border-radius: 4px; transition: background 0.15s;
                      min-width: 60px; display: inline-block; max-width: 280px;
                      white-space: nowrap; overflow: hidden; text-overflow: ellipsis; vertical-align: middle; }
.cell-value:hover   { background: rgba(var(--primary-rgb,79,70,229),0.08); }
.cell-edit-input    { width: 100%; padding: 4px 8px; border: 2px solid var(--primary); border-radius: 5px;
                      font-size: 0.85rem; font-family: inherit; outline: none; min-width: 80px; }

/* SQL Console */
.sql-console        { background: #0f172a; border-radius: 10px; padding: 16px; color: #e2e8f0; }
.sql-console textarea { width: 100%; background: transparent; border: none; outline: none; color: #a5f3fc;
                        font-family: 'Fira Code', 'Courier New', monospace; font-size: 0.875rem;
                        resize: vertical; min-height: 90px; line-height: 1.6; }
.sql-result-table   { font-size: 0.8rem; }
.sql-result-table th { background: #1e293b; color: #94a3b8; padding: 6px 10px; }
.sql-result-table td { padding: 5px 10px; border-bottom: 1px solid #1e293b; }

/* Delete confirm */
.row-delete-btn     { background: none; border: none; color: #ef4444; cursor: pointer; padding: 4px 8px;
                      border-radius: 5px; font-size: 0.85rem; transition: background 0.15s; }
.row-delete-btn:hover { background: #fef2f2; }

/* Pagination */
.pagination         { display: flex; gap: 5px; flex-wrap: wrap; }
.pagination a, .pagination span { padding: 5px 11px; border-radius: 6px; text-decoration: none;
                      font-size: 0.85rem; border: 1px solid #e2e8f0; color: #475569; background: #fff; }
.pagination a:hover { border-color: var(--primary); color: var(--primary); }
.pagination .current { background: var(--primary); color: #fff; border-color: var(--primary); }

.null-cell          { color: #94a3b8; font-style: italic; font-size: 0.8rem; }
.tag-pk             { font-size: 0.65rem; background: #fef3c7; color: #92400e; padding: 1px 5px; 
                      border-radius: 3px; vertical-align: middle; margin-left: 4px; }
</style>

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;">
    <div>
        <h2 style="margin:0;font-size:1.8rem;font-weight:700;color:var(--dark);">
            <i class='bx bx-data'></i> Database Editor
        </h2>
        <small style="color:#64748b;">hotel.db — Admin Only</small>
    </div>
    <button class="btn btn-dark" onclick="toggleSqlConsole()" id="sqlToggleBtn" style="gap:8px;">
        <i class='bx bx-terminal'></i> SQL Console
    </button>
</div>

<?php if ($error): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<!-- SQL Console Panel -->
<div id="sqlConsolePanel" style="display:none; margin-bottom:20px;">
    <div class="card-panel">
        <div class="card-panel-header"><h2><i class='bx bx-terminal'></i> Raw SQL Console</h2></div>
        <div class="sql-console" style="margin-top:14px;">
            <div style="color:#64748b;font-size:0.75rem;margin-bottom:6px;">⚠️ Dangerous — changes are permanent. SELECT, UPDATE, DELETE, INSERT allowed.</div>
            <textarea id="sqlInput" placeholder="SELECT * FROM hotel_bookings LIMIT 10;"></textarea>
            <div style="display:flex;gap:10px;margin-top:10px;align-items:center;">
                <button class="btn btn-primary btn-sm" onclick="runSQL()"><i class='bx bx-play'></i> Run</button>
                <button class="btn btn-secondary btn-sm" onclick="document.getElementById('sqlInput').value='';document.getElementById('sqlOutput').innerHTML='';">Clear</button>
                <span id="sqlStatus" style="font-size:0.8rem;color:#94a3b8;"></span>
            </div>
        </div>
        <div id="sqlOutput" style="margin-top:14px; overflow-x:auto; max-height:350px; overflow-y:auto;"></div>
    </div>
</div>

<!-- Main Layout -->
<div class="db-layout">

    <!-- Table List Sidebar -->
    <div class="db-sidebar card-panel" style="padding:14px;">
        <div style="font-size:0.75rem;font-weight:700;color:#94a3b8;text-transform:uppercase;letter-spacing:.05em;margin-bottom:10px;padding:0 6px;">Tables</div>
        <ul>
            <?php foreach ($tables as $tbl): ?>
                <?php
                    $cnt = 0;
                    try { $cnt = (int)$pdo->query("SELECT COUNT(*) FROM {$tbl}")->fetchColumn(); } catch(Exception $e){}
                ?>
                <li>
                    <a href="?table=<?= urlencode($tbl) ?>" class="<?= $active_table === $tbl ? 'active' : '' ?>">
                        <i class='bx bx-table' style="font-size:1rem;"></i>
                        <?= htmlspecialchars($tbl) ?>
                        <span class="tbl-badge"><?= $cnt ?></span>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>

    <!-- Table Viewer -->
    <div class="db-main">
        <?php if ($active_table): ?>
        <div class="card-panel">
            <div class="card-panel-header" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;">
                <h2 style="margin:0;"><i class='bx bx-table'></i> <?= htmlspecialchars($active_table) ?>
                    <span style="font-size:0.85rem;font-weight:400;color:#64748b;"><?= number_format($total_rows) ?> row<?= $total_rows !== 1 ? 's' : '' ?></span>
                </h2>
            </div>

            <!-- Toolbar -->
            <div class="db-toolbar" style="margin-top:14px;">
                <form method="GET" style="display:flex;gap:8px;flex:1;min-width:0;">
                    <input type="hidden" name="table" value="<?= htmlspecialchars($active_table) ?>">
                    <input type="text" name="q" class="form-control db-search" value="<?= htmlspecialchars($search) ?>"
                           placeholder="Search all columns…" style="height:38px;">
                    <button type="submit" class="btn btn-primary btn-sm"><i class='bx bx-search'></i> Search</button>
                    <?php if ($search): ?>
                        <a href="?table=<?= urlencode($active_table) ?>" class="btn btn-secondary btn-sm">Clear</a>
                    <?php endif; ?>
                </form>
            </div>

            <!-- Data Table -->
            <div class="table-responsive" style="margin-top:4px;">
                <table id="dataTable">
                    <thead>
                        <tr>
                            <th style="width:40px;text-align:center;">#</th>
                            <?php foreach ($table_cols as $col): ?>
                                <th>
                                    <?= htmlspecialchars($col['name']) ?>
                                    <?php if ($col['pk']): ?><span class="tag-pk">PK</span><?php endif; ?>
                                </th>
                            <?php endforeach; ?>
                            <th style="width:70px;text-align:center;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($table_data)): ?>
                            <tr><td colspan="<?= count($table_cols) + 2 ?>" style="text-align:center;padding:30px;color:#94a3b8;">No rows found.</td></tr>
                        <?php else: ?>
                            <?php foreach ($table_data as $row): ?>
                                <?php
                                    // Use rowid if available, otherwise fall back to first PK column value
                                    if (isset($row['rowid'])) {
                                        $rowid = $row['rowid'];
                                    } else {
                                        $rowid = null;
                                        foreach ($table_cols as $_c) {
                                            if ($_c['pk']) { $rowid = $row[$_c['name']] ?? null; break; }
                                        }
                                        if ($rowid === null) $rowid = array_values($row)[0] ?? 0;
                                    }
                                ?>
                                <tr id="row-<?= $rowid ?>" data-rowid="<?= $rowid ?>">
                                    <td style="text-align:center;color:#94a3b8;font-size:0.78rem;"><?= $rowid ?></td>
                                    <?php foreach ($table_cols as $col): ?>
                                        <td>
                                            <?php
                                                $colname = $col['name'];
                                                $val     = $row[$colname] ?? null;
                                                $is_pk   = (bool)$col['pk'];
                                                $display = $val === null ? '<span class="null-cell">NULL</span>'
                                                         : htmlspecialchars((string)$val);
                                            ?>
                                            <?php if ($is_pk): ?>
                                                <span class="cell-value" title="Primary Key – not editable"><?= $display ?></span>
                                            <?php else: ?>
                                                <span class="cell-value"
                                                      data-rowid="<?= htmlspecialchars((string)$rowid) ?>"
                                                      data-table="<?= htmlspecialchars((string)$active_table) ?>"
                                                      data-col="<?= htmlspecialchars((string)$colname) ?>"
                                                      data-val="<?= htmlspecialchars((string)($val ?? '')) ?>"
                                                      onclick="startEditFromData(this)"
                                                      title="Click to edit"><?= $display ?></span>
                                            <?php endif; ?>
                                        </td>
                                    <?php endforeach; ?>
                                    <td style="text-align:center;">
                                        <button class="row-delete-btn" title="Delete this row"
                                                data-rowid="<?= htmlspecialchars((string)$rowid) ?>"
                                                data-table="<?= htmlspecialchars((string)$active_table) ?>"
                                                onclick="deleteRowFromData(this)">
                                            <i class='bx bx-trash'></i>
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <?php if ($total_pages > 1): ?>
            <div style="display:flex;justify-content:space-between;align-items:center;margin-top:14px;flex-wrap:wrap;gap:10px;">
                <small style="color:#64748b;">
                    Showing <?= ($page-1)*$per_page+1 ?>–<?= min($page*$per_page, $total_rows) ?> of <?= number_format($total_rows) ?>
                </small>
                <div class="pagination">
                    <?php if ($page > 1): ?>
                        <a href="?table=<?= urlencode($active_table) ?>&p=<?= $page-1 ?>&q=<?= urlencode($search) ?>">‹ Prev</a>
                    <?php endif; ?>
                    <?php
                        $start = max(1, $page - 2);
                        $end   = min($total_pages, $page + 2);
                        if ($start > 1) echo '<span>…</span>';
                        for ($i = $start; $i <= $end; $i++):
                    ?>
                        <?php if ($i === $page): ?>
                            <span class="current"><?= $i ?></span>
                        <?php else: ?>
                            <a href="?table=<?= urlencode($active_table) ?>&p=<?= $i ?>&q=<?= urlencode($search) ?>"><?= $i ?></a>
                        <?php endif; ?>
                    <?php endfor; ?>
                    <?php if ($end < $total_pages) echo '<span>…</span>'; ?>
                    <?php if ($page < $total_pages): ?>
                        <a href="?table=<?= urlencode($active_table) ?>&p=<?= $page+1 ?>&q=<?= urlencode($search) ?>">Next ›</a>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>

</div>

<!-- Toast notification -->
<div id="dbToast" style="display:none;position:fixed;bottom:24px;right:24px;padding:12px 20px;border-radius:9px;font-size:0.875rem;font-weight:500;z-index:99999;box-shadow:0 4px 15px rgba(0,0,0,0.15);transition:opacity 0.3s;"></div>

<script>
// ── Toast ─────────────────────────────────────────────────────────────────────
function showToast(msg, type = 'success') {
    const t = document.getElementById('dbToast');
    const colors = {
        success : { bg: '#f0fdf4', color: '#166534', border: '1px solid #bbf7d0' },
        error   : { bg: '#fef2f2', color: '#991b1b', border: '1px solid #fecaca' },
        info    : { bg: '#eff6ff', color: '#1d4ed8', border: '1px solid #bfdbfe' },
    };
    const c = colors[type] || colors.success;
    t.style.background = c.bg; t.style.color = c.color; t.style.border = c.border;
    t.style.display = 'block'; t.style.opacity = '1';
    t.innerHTML = msg;
    clearTimeout(t._to);
    t._to = setTimeout(() => {
        t.style.opacity = '0';
        setTimeout(() => t.style.display = 'none', 300);
    }, 3500);
}

// ── Inline Edit ───────────────────────────────────────────────────────────────
function startEditFromData(span) {
    const rowid = span.getAttribute('data-rowid');
    const table = span.getAttribute('data-table');
    const column = span.getAttribute('data-col');
    const currentVal = span.getAttribute('data-val');
    startEdit(span, rowid, table, column, currentVal);
}

function startEdit(span, rowid, table, column, currentVal) {
    // If already editing another cell, cancel it
    const existing = document.querySelector('.cell-edit-input');
    if (existing) existing.closest('td').querySelector('.cell-value').replaceWith(restoreSpan(existing));

    const input = document.createElement('input');
    input.className = 'cell-edit-input';
    input.value = currentVal;
    input.title = 'Press Enter to save, Escape to cancel';

    const saveEdit = () => {
        const newVal = input.value;
        fetch('db_editor.php', {
            method: 'POST',
            headers: {'Content-Type':'application/x-www-form-urlencoded'},
            body: new URLSearchParams({action:'update_cell', table, rowid, column, value: newVal})
        })
        .then(r => r.json())
        .then(data => {
            const newSpan = document.createElement('span');
            newSpan.className = 'cell-value';
            newSpan.setAttribute('data-rowid', rowid);
            newSpan.setAttribute('data-table', table);
            newSpan.setAttribute('data-col', column);
            newSpan.setAttribute('data-val', newVal);
            newSpan.onclick = () => startEditFromData(newSpan);
            newSpan.title = 'Click to edit';
            newSpan.style.maxWidth = '280px';
            newSpan.style.whiteSpace = 'nowrap';
            newSpan.style.overflow = 'hidden';
            newSpan.style.textOverflow = 'ellipsis';
            newSpan.style.display = 'inline-block';
            newSpan.style.verticalAlign = 'middle';
            newSpan.innerHTML = newVal === '' ? '<span class="null-cell">NULL</span>' : escHtml(newVal);
            input.replaceWith(newSpan);
            if (data.success) {
                newSpan.style.background = '#dcfce7';
                setTimeout(() => newSpan.style.background = '', 1200);
                showToast('✓ Cell updated', 'success');
            } else {
                showToast('✗ ' + (data.error || 'Update failed'), 'error');
            }
        })
        .catch(() => { input.replaceWith(span); showToast('Network error', 'error'); });
    };

    input.addEventListener('keydown', e => {
        if (e.key === 'Enter') { e.preventDefault(); saveEdit(); }
        if (e.key === 'Escape') { input.replaceWith(span); }
    });
    input.addEventListener('blur', saveEdit);

    span.replaceWith(input);
    input.focus();
    input.select();
}

function restoreSpan(input) {
    const span = document.createElement('span');
    span.className = 'cell-value';
    span.innerHTML = escHtml(input.value);
    return span;
}

function escHtml(s) {
    return s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// ── Delete Row ────────────────────────────────────────────────────────────────
function deleteRowFromData(btn) {
    const rowid = btn.getAttribute('data-rowid');
    const table = btn.getAttribute('data-table');
    deleteRow(rowid, table, btn);
}

function deleteRow(rowid, table, btn) {
    if (!confirm(`Delete row #${rowid} from "${table}"? This cannot be undone.`)) return;
    btn.innerHTML = '<i class="bx bx-loader-alt bx-spin"></i>';
    btn.disabled = true;
    fetch('db_editor.php', {
        method: 'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded'},
        body: new URLSearchParams({action:'delete_row', table, rowid})
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            const row = document.getElementById('row-' + rowid);
            row.style.transition = 'opacity 0.3s, transform 0.3s';
            row.style.opacity = '0';
            row.style.transform = 'translateX(20px)';
            setTimeout(() => row.remove(), 320);
            showToast('Row deleted.', 'success');
        } else {
            btn.innerHTML = '<i class="bx bx-trash"></i>';
            btn.disabled = false;
            showToast('✗ ' + (data.error || 'Delete failed'), 'error');
        }
    })
    .catch(() => { btn.innerHTML = '<i class="bx bx-trash"></i>'; btn.disabled = false; showToast('Network error','error'); });
}

// ── SQL Console ───────────────────────────────────────────────────────────────
function toggleSqlConsole() {
    const panel = document.getElementById('sqlConsolePanel');
    const btn   = document.getElementById('sqlToggleBtn');
    const open  = panel.style.display === 'none';
    panel.style.display = open ? 'block' : 'none';
    btn.innerHTML = open ? "<i class='bx bx-x'></i> Close Console" : "<i class='bx bx-terminal'></i> SQL Console";
}

function runSQL() {
    const sql    = document.getElementById('sqlInput').value.trim();
    const output = document.getElementById('sqlOutput');
    const status = document.getElementById('sqlStatus');
    if (!sql) return;
    status.textContent = 'Running…';
    output.innerHTML = '';
    const t0 = Date.now();
    fetch('db_editor.php', {
        method: 'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded'},
        body: new URLSearchParams({action:'run_sql', sql})
    })
    .then(r => r.json())
    .then(data => {
        const ms = Date.now() - t0;
        if (data.error) {
            status.textContent = `Error (${ms}ms)`;
            output.innerHTML = `<div style="color:#ef4444;padding:10px;background:#fef2f2;border-radius:6px;">${escHtml(data.error)}</div>`;
            return;
        }
        if (data.type === 'select') {
            status.textContent = `${data.rows.length} row(s) returned in ${ms}ms`;
            if (!data.rows.length) {
                output.innerHTML = '<div style="color:#94a3b8;padding:10px;">No results.</div>';
                return;
            }
            const th = data.cols.map(c => `<th>${escHtml(c)}</th>`).join('');
            const tb = data.rows.map(r =>
                '<tr>' + data.cols.map(c => `<td>${r[c] === null ? '<span class="null-cell">NULL</span>' : escHtml(String(r[c]))}</td>`).join('') + '</tr>'
            ).join('');
            output.innerHTML = `<table class="sql-result-table" style="width:100%;border-collapse:collapse;"><thead><tr>${th}</tr></thead><tbody>${tb}</tbody></table>`;
        } else {
            status.textContent = `Done in ${ms}ms`;
            output.innerHTML = `<div style="color:#10b981;padding:10px;background:#f0fdf4;border-radius:6px;">✓ ${data.affected} row(s) affected.</div>`;
        }
    })
    .catch(e => {
        status.textContent = 'Network error';
        output.innerHTML = `<div style="color:#ef4444;padding:10px;">${e.message}</div>`;
    });
}

// Ctrl+Enter to run SQL
document.getElementById('sqlInput')?.addEventListener('keydown', e => {
    if (e.ctrlKey && e.key === 'Enter') runSQL();
});
</script>

<?php include 'footer.php'; ?>
