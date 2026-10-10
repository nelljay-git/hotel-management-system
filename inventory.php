<?php
ob_start();
require_once 'db.php';

$message = "";

// 1. Handle Add / Edit Item
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['save_item'])) {
    $item_name = trim($_POST['item_name']);
    $category = $_POST['category'];
    $quantity = (int)$_POST['quantity'];
    $unit = $_POST['unit'];
    $unit_price = (float)$_POST['unit_price'];
    $selling_price = (float)$_POST['selling_price'];
    $min_stock = (int)$_POST['min_stock'];
    $item_id = $_POST['item_id'] ?? null;

    if ($item_id) {
        $old_stmt = $pdo->prepare("SELECT quantity FROM hotel_inventory WHERE id = ?");
        $old_stmt->execute([$item_id]);
        $old_quantity = (int)$old_stmt->fetchColumn();
        $change = $quantity - $old_quantity;

        $stmt = $pdo->prepare("UPDATE hotel_inventory SET item_name=?, category=?, quantity=?, unit=?, unit_price=?, selling_price=?, min_stock_level=? WHERE id=?");
        if ($stmt->execute([$item_name, $category, $quantity, $unit, $unit_price, $selling_price, $min_stock, $item_id])) {
            $pdo->prepare("INSERT INTO hotel_inventory_history (item_id, change_amount, change_type, historical_unit_price, historical_selling_price, notes, user_id) VALUES (?, ?, 'Adjustment', ?, ?, 'Manual update', ?)")
                ->execute([$item_id, $change, $unit_price, $selling_price, $_SESSION['user_id']]); 
            $message = "<div class='alert alert-success'>Item updated successfully.</div>";
        }
    } else {
        $stmt = $pdo->prepare("INSERT INTO hotel_inventory (item_name, category, quantity, unit, unit_price, selling_price, min_stock_level) VALUES (?, ?, ?, ?, ?, ?, ?)");
        if ($stmt->execute([$item_name, $category, $quantity, $unit, $unit_price, $selling_price, $min_stock])) {
            $last_id = $pdo->lastInsertId();
            $pdo->prepare("INSERT INTO hotel_inventory_history (item_id, change_amount, change_type, historical_unit_price, historical_selling_price, notes, user_id) VALUES (?, ?, 'Stock-In', ?, ?, 'Initial stock', ?)")
                ->execute([$last_id, $quantity, $unit_price, $selling_price, $_SESSION['user_id']]);
            $message = "<div class='alert alert-success'>Item added to inventory.</div>";
        }
    }
}

// 2. Handle Quick Restock
if (isset($_GET['restock_id']) && isset($_GET['amount'])) {
    $id = (int)$_GET['restock_id'];
    $amount = (int)$_GET['amount'];
    
    // Fetch prices to capture snapshot
    $stmt = $pdo->prepare("SELECT unit_price, selling_price FROM hotel_inventory WHERE id = ?");
    $stmt->execute([$id]);
    $item = $stmt->fetch();
    
    if ($item) {
        $pdo->prepare("UPDATE hotel_inventory SET quantity = quantity + ? WHERE id = ?")->execute([$amount, $id]);
        $pdo->prepare("INSERT INTO hotel_inventory_history (item_id, change_amount, change_type, historical_unit_price, historical_selling_price, notes, user_id) VALUES (?, ?, 'Stock-In', ?, ?, 'Quick restock', ?)")
            ->execute([$id, $amount, $item['unit_price'], $item['selling_price'], $_SESSION['user_id']]);
    }
    ob_end_clean();
    header("Location: inventory.php?success=restocked");
    exit;
}

// 3. Handle Delete
if (isset($_GET['delete'])) {
    if ($_SESSION['role'] === 'Admin') {
        if ($pdo->prepare("DELETE FROM hotel_inventory WHERE id = ?")->execute([$_GET['delete']])) {
            $message = "<div class='alert alert-success'>Item removed from inventory.</div>";
        }
    } else {
        $message = "<div class='alert alert-danger'>Unauthorized Action: Only Administrators can delete inventory items.</div>";
    }
}

$edit_item = null;
if (isset($_GET['edit'])) {
    $stmt = $pdo->prepare("SELECT * FROM hotel_inventory WHERE id = ?");
    $stmt->execute([$_GET['edit']]);
    $edit_item = $stmt->fetch();
}

include 'header.php';
?>

<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px; flex-wrap:wrap; gap:10px;">
    <div>
        <h2 style="margin:0; font-size:1.8rem; font-weight:700; color:var(--dark);">Inventory</h2>
        <p style="margin:4px 0 0 0; color:#64748b; font-size:0.9rem;">Keep track of supplies, restock on time and see what each item earns.</p>
    </div>
    <a href="#item-form-card" class="btn btn-primary btn-sm"><i class='bx bx-plus'></i> Add New Item</a>
</div>

<style>
    .inv-step { font-size:0.72rem; font-weight:700; text-transform:uppercase; letter-spacing:0.6px; color:var(--primary); margin:18px 0 4px 0; }
    .inv-step:first-of-type { margin-top:0; }
    .inv-step-title { margin:0 0 10px 0; font-size:1.05rem; font-weight:700; color:var(--dark); }
    .cat-picker { display:flex; gap:10px; flex-wrap:wrap; }
    .cat-box { flex:1 1 130px; border:2px solid #e2e8f0; border-radius:10px; padding:12px 8px; text-align:center; cursor:pointer; background:#f8fafc; transition:border-color .15s, background .15s, transform .15s; }
    .cat-box:hover { border-color:var(--primary); transform:translateY(-2px); }
    .cat-box:has(input:checked) { border-color:var(--primary); background:rgba(var(--primary-rgb), 0.07); box-shadow:var(--shadow-sm); }
    .cat-box input { display:none; }
    .cat-box i { font-size:1.6rem; color:var(--primary); display:block; }
    .cat-box span { display:block; font-weight:700; font-size:0.85rem; color:var(--dark); margin-top:4px; }
    .cat-box small { display:block; font-size:0.7rem; color:#64748b; margin-top:2px; line-height:1.35; }
    .input-prefix { display:flex; align-items:stretch; border:2px solid #e2e8f0; border-radius:8px; overflow:hidden; background:#fff; transition:border-color .2s; }
    .input-prefix:focus-within { border-color:var(--primary); }
    .input-prefix > span { display:flex; align-items:center; padding:0 14px; font-weight:700; color:var(--primary); background:#f8fafc; border-right:2px solid #e2e8f0; }
    .input-prefix input { flex:1; border:none; outline:none; padding:10px 12px; font-size:1rem; font-family:inherit; background:transparent; min-width:0; }
    .stock-chip { border:2px solid #e2e8f0; background:#fff; color:#475569; font-weight:600; font-size:0.78rem; border-radius:20px; padding:5px 14px; cursor:pointer; transition:all .15s; }
    .stock-chip:hover { border-color:var(--primary); color:var(--primary); }
    .stock-chip.active { background:var(--primary); border-color:var(--primary); color:#fff; }
    .stock-bar { height:8px; background:#e2e8f0; border-radius:4px; overflow:hidden; margin-top:6px; min-width:120px; }
    .stock-bar > div { height:100%; border-radius:4px; transition:width .3s; }
    .search-wrap { position:relative; }
    .search-wrap i { position:absolute; left:12px; top:50%; transform:translateY(-50%); color:#94a3b8; font-size:1.1rem; }
    .search-wrap input { padding-left:36px !important; }
    .rs-quick { border:2px solid #e2e8f0; background:#f8fafc; border-radius:8px; font-weight:700; padding:8px 0; flex:1; cursor:pointer; color:var(--dark); }
    .rs-quick:hover { border-color:var(--primary); color:var(--primary); }
</style>

<div class="overview-boxes" style="margin-bottom: 25px;">
    <?php
    $low_stock_count = $pdo->query("SELECT COUNT(*) FROM hotel_inventory WHERE quantity <= min_stock_level")->fetchColumn();
    $total_items = $pdo->query("SELECT COUNT(*) FROM hotel_inventory")->fetchColumn();
    $stock_value = $pdo->query("SELECT COALESCE(SUM(quantity * unit_price), 0) FROM hotel_inventory")->fetchColumn();
    ?>
    <div class="box">
        <div class="right-side">
            <div class="box-topic">Total Items</div>
            <div class="number"><?= $total_items ?></div>
        </div>
        <i class='bx bx-package' ></i>
    </div>
    <div class="box">
        <div class="right-side">
            <div class="box-topic">Low Stock Alerts</div>
            <div class="number"><?= $low_stock_count ?></div>
        </div>
        <i class='bx bx-error' style="background: #fee2e2; color: #dc2626;"></i>
    </div>
    <div class="box">
        <div class="right-side">
            <div class="box-topic">Stock Value (Cost)</div>
            <div class="number">₱<?= number_format($stock_value, 2) ?></div>
        </div>
        <i class='bx bx-wallet'></i>
    </div>
</div>

<div class="card-panel" id="item-form-card">
    <div class="card-panel-header">
        <h2><i class='bx <?= $edit_item ? 'bx-edit' : 'bx-plus-circle' ?>' style="color:var(--primary);"></i> <?= $edit_item ? 'Edit Item: ' . htmlspecialchars($edit_item['item_name']) : 'Add New Inventory Item' ?></h2>
        <?php if($edit_item): ?>
            <a href="inventory.php" class="btn btn-secondary btn-sm">Cancel Editing</a>
        <?php endif; ?>
    </div>
    <form method="POST" action="inventory.php" style="margin-top:5px;">
        <?php if($edit_item): ?>
            <input type="hidden" name="item_id" value="<?= $edit_item['id'] ?>">
        <?php endif; ?>

        <div class="inv-step">Step 1 of 4</div>
        <p class="inv-step-title">What is the item?</p>
        <div class="form-group">
            <label>Item Name</label>
            <input type="text" name="item_name" class="form-control" required placeholder="e.g., Bottled Water" value="<?= htmlspecialchars($edit_item['item_name'] ?? '', ENT_QUOTES) ?>">
        </div>
        <div class="form-group">
            <label>Category — click the box that fits best</label>
            <div class="cat-picker">
                <?php
                $cat_meta = [
                    'Toiletries' => ['icon' => 'bx-bath',       'desc' => 'Soap, shampoo, toothbrush'],
                    'Mini-bar'   => ['icon' => 'bx-drink',      'desc' => 'Drinks & snacks for sale'],
                    'Cleaning'   => ['icon' => 'bx-spray-can',  'desc' => 'Detergents, tissue, tools'],
                    'Linens'     => ['icon' => 'bx-bed',        'desc' => 'Towels, sheets, pillows'],
                    'Other'      => ['icon' => 'bx-package',    'desc' => 'Anything else'],
                ];
                $current_cat = $edit_item['category'] ?? 'Other';
                foreach($cat_meta as $cat => $meta): ?>
                    <label class="cat-box">
                        <input type="radio" name="category" value="<?= $cat ?>" <?= ($current_cat == $cat) ? 'checked' : '' ?> required>
                        <i class='bx <?= $meta['icon'] ?>'></i>
                        <span><?= $cat ?></span>
                        <small><?= $meta['desc'] ?></small>
                    </label>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="inv-step">Step 2 of 4</div>
        <p class="inv-step-title">How much do you have?</p>
        <div class="form-row">
            <div class="form-group">
                <label><?= $edit_item ? 'Current Quantity' : 'Starting Quantity' ?></label>
                <input type="number" name="quantity" class="form-control" required min="0" value="<?= $edit_item['quantity'] ?? 0 ?>">
                <small style="color:#888;">Count what is on the shelf right now.</small>
            </div>
            <div class="form-group">
                <label>Counted In (Unit)</label>
                <select name="unit" class="form-select" required>
                    <?php
                    $units = ['pc' => 'Piece (pc)', 'kg' => 'Kilogram (kg)', 'g' => 'Gram (g)', 'liter' => 'Liter', 'ml' => 'Milliliter (ml)', 'bottle' => 'Bottle', 'can' => 'Can', 'pack' => 'Pack', 'set' => 'Set', 'roll' => 'Roll', 'box' => 'Box'];
                    $current_unit = $edit_item['unit'] ?? 'pc';
                    foreach($units as $val => $label): ?>
                        <option value="<?= $val ?>" <?= ($current_unit == $val) ? 'selected' : '' ?>><?= $label ?></option>
                    <?php endforeach; ?>
                </select>
                <small style="color:#888;">How do you count this item?</small>
            </div>
        </div>

        <div class="inv-step">Step 3 of 4</div>
        <p class="inv-step-title">What does it cost?</p>
        <div class="form-row">
            <div class="form-group">
                <label>Cost Price (per 1 unit)</label>
                <div class="input-prefix"><span>₱</span><input type="number" step="0.01" id="f_unit_price" name="unit_price" required min="0" value="<?= $edit_item['unit_price'] ?? 0 ?>"></div>
                <small style="color:#888;">What you paid the supplier.</small>
            </div>
            <div class="form-group">
                <label>Selling Price (per 1 unit)</label>
                <div class="input-prefix"><span>₱</span><input type="number" step="0.01" id="f_selling_price" name="selling_price" required min="0" value="<?= $edit_item['selling_price'] ?? 0 ?>"></div>
                <small style="color:#888;">What a guest pays. Set 0 if it is free / not for sale.</small>
            </div>
        </div>
        <div id="profit_hint" style="display:none; margin:0 0 15px 0; padding:10px 14px; border-radius:8px; font-size:0.85rem; font-weight:600;"></div>

        <div class="inv-step">Step 4 of 4</div>
        <p class="inv-step-title">When should we warn you?</p>
        <div class="form-row">
            <div class="form-group">
                <label>Low-Stock Alert Level</label>
                <input type="number" name="min_stock" class="form-control" required min="0" value="<?= $edit_item['min_stock_level'] ?? 5 ?>">
                <small style="color:#888;">This item gets a red <i class='bx bxs-error-circle' style="color:#dc2626;"></i> warning when stock falls to this number.</small>
            </div>
        </div>
        <div style="display:flex; gap:10px; margin-top:5px;">
            <button type="submit" name="save_item" class="btn btn-primary"><i class='bx bx-save'></i> <?= $edit_item ? 'Save Changes' : 'Add Item to Inventory' ?></button>
            <?php if($edit_item): ?>
                <a href="inventory.php" class="btn btn-secondary">Cancel</a>
            <?php endif; ?>
        </div>
    </form>
</div>

<div class="card-panel" style="margin-top:20px;">
    <div class="card-panel-header" style="flex-wrap:wrap; gap:10px;">
        <h2><i class='bx bx-box' style="color:var(--primary);"></i> Your Stock <span id="stock_count" style="font-size:0.8rem; color:#64748b; font-weight:500;"></span></h2>
        <div class="search-wrap">
            <i class='bx bx-search'></i>
            <input type="text" id="stock_search" class="form-control" placeholder="Search items..." oninput="filterStock()" style="width:220px;">
        </div>
    </div>
    <div id="stock_chips" style="display:flex; gap:8px; flex-wrap:wrap; margin:12px 0 4px 0;">
        <button type="button" class="stock-chip active" onclick="setStockCat('all', this)">All Items</button>
        <button type="button" class="stock-chip" onclick="setStockCat('low', this)">⚠ Low Stock Only</button>
        <button type="button" class="stock-chip" onclick="setStockCat('Toiletries', this)">Toiletries</button>
        <button type="button" class="stock-chip" onclick="setStockCat('Mini-bar', this)">Mini-bar</button>
        <button type="button" class="stock-chip" onclick="setStockCat('Cleaning', this)">Cleaning</button>
        <button type="button" class="stock-chip" onclick="setStockCat('Linens', this)">Linens</button>
        <button type="button" class="stock-chip" onclick="setStockCat('Other', this)">Other</button>
    </div>
    <div class="table-responsive">
        <table>
            <thead>
                <tr>
                    <th>Item</th>
                    <th>Stock Level</th>
                    <th>Prices &amp; Profit</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody id="stock_tbody">
                <?php
                $stmt = $pdo->query("SELECT * FROM hotel_inventory ORDER BY category ASC, item_name ASC");
                $all_items = $stmt->fetchAll();
                $cat_icons = ['Toiletries' => 'bx-bath', 'Mini-bar' => 'bx-drink', 'Cleaning' => 'bx-spray-can', 'Linens' => 'bx-bed', 'Other' => 'bx-package'];

                foreach($all_items as $row):
                    $is_low = $row['quantity'] <= $row['min_stock_level'];
                    $pct = min(100, round($row['quantity'] / max($row['min_stock_level'] * 2, 1) * 100));
                    $bar_color = $is_low ? '#ef4444' : ($row['quantity'] <= $row['min_stock_level'] * 2 ? '#f59e0b' : '#10b981');
                    $margin = $row['selling_price'] - $row['unit_price'];
                ?>
                <tr data-item="1" data-name="<?= htmlspecialchars(strtolower($row['item_name']), ENT_QUOTES) ?>" data-cat="<?= $row['category'] ?>" data-low="<?= $is_low ? '1' : '0' ?>">
                    <td>
                        <strong><?= htmlspecialchars($row['item_name']) ?></strong><br>
                        <span class="badge badge-secondary" style="font-size:10px; margin-top:4px; display:inline-block;"><i class='bx <?= $cat_icons[$row['category']] ?? 'bx-package' ?>'></i> <?= $row['category'] ?></span>
                    </td>
                    <td>
                        <span style="font-weight:700; font-size:1rem; color:<?= $is_low ? '#dc2626' : 'var(--dark)' ?>;">
                            <?= $row['quantity'] ?> <?= $row['unit'] ?>
                        </span>
                        <?php if($is_low): ?>
                            <span class="badge badge-danger" style="font-size:10px; margin-left:4px;">LOW · min <?= $row['min_stock_level'] ?></span>
                        <?php else: ?>
                            <small style="color:#94a3b8;"> · min <?= $row['min_stock_level'] ?></small>
                        <?php endif; ?>
                        <div class="stock-bar" title="<?= $row['quantity'] ?> of ~<?= max($row['min_stock_level'] * 2, 1) ?>"><div style="width:<?= $pct ?>%; background:<?= $bar_color ?>;"></div></div>
                    </td>
                    <td>
                        <small style="color:#64748b;">Cost ₱<?= number_format($row['unit_price'], 2) ?> · Sell <strong style="color:var(--primary);">₱<?= number_format($row['selling_price'], 2) ?></strong></small><br>
                        <?php if($margin > 0): ?>
                            <small style="color:#15803d; font-weight:600;">+₱<?= number_format($margin, 2) ?> profit each</small>
                        <?php elseif($margin < 0): ?>
                            <small style="color:#dc2626; font-weight:600;">−₱<?= number_format(abs($margin), 2) ?> loss each</small>
                        <?php else: ?>
                            <small style="color:#94a3b8;">No markup</small>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div style="display:flex; gap:6px; flex-wrap:wrap;">
                            <button type="button" class="btn btn-sm btn-success" title="Add more stock"
                                data-id="<?= $row['id'] ?>" data-name="<?= htmlspecialchars($row['item_name'], ENT_QUOTES) ?>"
                                data-qty="<?= $row['quantity'] ?>" data-unit="<?= $row['unit'] ?>"
                                onclick="openRestock(this)"><i class='bx bx-plus'></i> Restock</button>
                            <a href="inventory.php?edit=<?= $row['id'] ?>#item-form-card" class="btn btn-sm btn-secondary" title="Edit item"><i class='bx bx-edit'></i></a>
                            <?php if($_SESSION['role'] === 'Admin'): ?>
                                <a href="inventory.php?delete=<?= $row['id'] ?>" class="btn btn-sm btn-danger" title="Delete item" onclick="return confirm('Delete <?= htmlspecialchars($row['item_name'], ENT_QUOTES) ?>? This cannot be undone.')"><i class='bx bx-trash'></i></a>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                <tr id="stock_empty" style="display:none;"><td colspan="4" style="text-align:center; padding:35px; color:#94a3b8;">
                    <i class='bx bx-search-alt' style="font-size:2.5rem; display:block; margin-bottom:10px;"></i>
                    <strong>No items match your search.</strong><br><small>Try a different keyword or category.</small>
                </td></tr>
                <?php if(count($all_items) == 0): ?>
                    <tr><td colspan="4" style="text-align:center; padding:35px; color:#94a3b8;">
                        <i class='bx bx-package' style="font-size:2.5rem; display:block; margin-bottom:10px;"></i>
                        <strong>Your inventory is empty.</strong><br><small>Click <a href="#item-form-card"><strong>Add New Item</strong></a> above and follow the 4 steps — it takes less than a minute.</small>
                    </td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Restock Modal -->
<div id="restockModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:9999; overflow-y:auto;">
    <div style="background:#fff; width:420px; max-width:92%; margin:8% auto; border-radius:12px; overflow:hidden; box-shadow:0 10px 30px rgba(0,0,0,0.3);">
        <div style="display:flex; justify-content:space-between; align-items:center; padding:15px 20px; border-bottom:1px solid #e2e8f0; background:#f8fafc;">
            <h3 style="margin:0; font-size:1.15rem;"><i class='bx bx-plus-circle' style="color:var(--primary);"></i> Restock Item</h3>
            <button type="button" onclick="closeRestock()" style="background:none; border:none; font-size:1.5rem; cursor:pointer; color:#64748b;">&times;</button>
        </div>
        <div style="padding:20px;">
            <p style="margin:0 0 4px 0; font-size:1.05rem; font-weight:700; color:var(--dark);" id="rs_name"></p>
            <p style="margin:0 0 14px 0; color:#64748b; font-size:0.85rem;">Currently on shelf: <strong id="rs_current"></strong></p>
            <label style="font-weight:600; font-size:0.85rem; color:#475569; display:block; margin-bottom:6px;">How many are you adding?</label>
            <div style="display:flex; gap:8px; margin-bottom:10px;">
                <button type="button" class="rs-quick" onclick="rsAdd(5)">+5</button>
                <button type="button" class="rs-quick" onclick="rsAdd(10)">+10</button>
                <button type="button" class="rs-quick" onclick="rsAdd(20)">+20</button>
            </div>
            <input type="number" id="rs_amount" class="form-control" value="10" min="1" oninput="rsUpdate()">
            <div style="margin-top:12px; padding:10px 14px; border-radius:8px; background:#f0fdf4; border:1px solid #bbf7d0; font-size:0.9rem; font-weight:600; color:#15803d;">
                New total: <span id="rs_new"></span>
            </div>
        </div>
        <div style="padding:12px 20px; border-top:1px solid #e2e8f0; text-align:right; background:#f8fafc; display:flex; gap:8px; justify-content:flex-end;">
            <button type="button" class="btn btn-secondary btn-sm" onclick="closeRestock()">Cancel</button>
            <a href="#" id="rs_confirm" class="btn btn-success btn-sm"><i class='bx bx-check'></i> Add Stock</a>
        </div>
    </div>
</div>

<div class="card-panel" style="margin-top:20px;" id="stock-history-section">
    <div class="card-panel-header" style="display: flex; justify-content: space-between; align-items: flex-start; gap: 20px; flex-wrap: wrap;">
        <div style="flex: 1; min-width: 250px;">
            <h2><i class='bx bx-history' style="color:var(--primary);"></i> Stock Movement History</h2>
            <p>A diary of every stock change — newest first. Click the green boxes on the right to see per-item breakdowns.</p>
            <form method="GET" action="inventory.php#stock-history-section" style="margin-top: 10px; display: flex; align-items: center; gap: 15px; flex-wrap: wrap;">
                <div style="display: flex; align-items: center; gap: 8px;">
                    <label for="month-filter" style="margin: 0; font-size: 0.9rem;">Month:</label>
                    <input type="month" name="history_month" id="month-filter" class="form-control" value="<?= $_GET['history_month'] ?? '' ?>" style="width: auto; padding: 5px 10px;">
                </div>

                <div style="display: flex; align-items: center; gap: 8px;">
                    <label for="staff-filter" style="margin: 0; font-size: 0.9rem;">Staff:</label>
                    <select name="history_staff" id="staff-filter" class="form-select" style="width: auto; padding: 5px 10px; height: 38px;">
                        <option value="">All Staff</option>
                        <?php 
                        $all_staff = $pdo->query("SELECT id, full_name FROM hotel_users ORDER BY full_name ASC")->fetchAll();
                        foreach($all_staff as $s): ?>
                            <option value="<?= $s['id'] ?>" <?= (isset($_GET['history_staff']) && $_GET['history_staff'] == $s['id']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($s['full_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <button type="submit" class="btn btn-primary btn-sm">Apply Filters</button>
                <?php if(isset($_GET['history_month']) || isset($_GET['history_staff'])): ?>
                    <a href="inventory.php#stock-history-section" class="btn btn-secondary btn-sm">Clear</a>
                <?php endif; ?>
            </form>
        </div>
        <?php
        // Filter Logic
        $filter_month = $_GET['history_month'] ?? null;
        $filter_staff = $_GET['history_staff'] ?? null;
        
        $where_clauses = [];
        $params = [];

        if ($filter_month) {
            $where_clauses[] = "strftime('%Y-%m', h.created_at) = :month";
            $params['month'] = $filter_month;
        }
        if ($filter_staff) {
            $where_clauses[] = "h.user_id = :staff";
            $params['staff'] = $filter_staff;
        }

        $query_condition = count($where_clauses) > 0 ? "WHERE " . implode(" AND ", $where_clauses) : "";
        $query_limit = count($where_clauses) > 0 ? "" : "LIMIT 20";
        
        $stmt_history = $pdo->prepare("
            SELECT h.*, i.item_name, i.unit, u.full_name as staff_name
            FROM hotel_inventory_history h 
            JOIN hotel_inventory i ON h.item_id = i.id 
            LEFT JOIN hotel_users u ON h.user_id = u.id
            $query_condition
            ORDER BY h.created_at DESC 
            $query_limit
        ");
        
        $stmt_history->execute($params);
        
        $history = $stmt_history->fetchAll();
        $grand_total_in_value = 0;
        $total_profit = 0;
        $profit_breakdown = [];
        $stockin_breakdown = [];
        foreach($history as $h) {
            if ($h['change_amount'] > 0) {
                $value = $h['change_amount'] * $h['historical_unit_price'];
                $grand_total_in_value += $value;
                
                $item_name = $h['item_name'];
                if(!isset($stockin_breakdown[$item_name])) {
                    $stockin_breakdown[$item_name] = ['qty' => 0, 'value' => 0, 'unit_price' => $h['historical_unit_price']];
                }
                $stockin_breakdown[$item_name]['qty'] += $h['change_amount'];
                $stockin_breakdown[$item_name]['value'] += $value;
            } elseif ($h['change_type'] == 'Guest Sale') {
                $profit_for_item = (abs($h['change_amount']) * ($h['historical_selling_price'] - $h['historical_unit_price']));
                $total_profit += $profit_for_item;
                
                $item_name = $h['item_name'];
                if(!isset($profit_breakdown[$item_name])) {
                    $profit_breakdown[$item_name] = ['qty' => 0, 'profit' => 0];
                }
                $profit_breakdown[$item_name]['qty'] += abs($h['change_amount']);
                $profit_breakdown[$item_name]['profit'] += $profit_for_item;
            }
        }
        ?>
        <div style="display: flex; justify-content: flex-end; gap: 15px;">
            <div style="text-align: right; background: #f8fafc; padding: 15px; border-radius: 12px; border: 1px solid #e2e8f0; min-width: 200px; cursor: pointer; transition: transform 0.2s; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05);" onclick="document.getElementById('stockinModal').style.display='flex'" onmouseover="this.style.transform='scale(1.03)'" onmouseout="this.style.transform='scale(1)'" title="Click to view detailed stock-in breakdown">
                <div style="font-size: 0.85rem; color: #64748b; font-weight: 500; display: flex; align-items: center; justify-content: flex-end; gap: 5px;">
                    <?= $filter_month ? 'Total Stock-In Value for ' . date('M Y', strtotime($filter_month)) : 'Total Stock-In Value (Latest 20)' ?>
                    <i class='bx bx-zoom-in'></i>
                </div>
                <div style="font-size: 1.75rem; font-weight: 800; color: #10b981;">₱<?= number_format($grand_total_in_value, 2) ?></div>
            </div>
            <div style="text-align: right; background: #f0fdf4; padding: 15px; border-radius: 12px; border: 1px solid #bbf7d0; min-width: 200px; cursor: pointer; transition: transform 0.2s; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05);" onclick="document.getElementById('profitModal').style.display='flex'" onmouseover="this.style.transform='scale(1.03)'" onmouseout="this.style.transform='scale(1)'" title="Click to view detailed item breakdown">
                <div style="font-size: 0.85rem; color: #166534; font-weight: 500; display: flex; align-items: center; justify-content: flex-end; gap: 5px;">
                    <?= $filter_month ? 'Est. Profit for ' . date('M Y', strtotime($filter_month)) : 'Est. Profit (Latest 20)' ?>
                    <i class='bx bx-zoom-in'></i>
                </div>
                <div style="font-size: 1.75rem; font-weight: 800; color: #059669;">₱<?= number_format($total_profit, 2) ?></div>
            </div>
        </div>
    </div>
    <div class="table-responsive" style="margin-top: 15px;">
        <table>
            <thead>
                <tr>
                    <th>Date & Time</th>
                    <th>Item Name</th>
                    <th>Rate / Price</th>
                    <th>Change</th>
                    <th>Total Value</th>
                    <th>Staff</th>
                    <th>Type</th>
                    <th>Notes</th>
                </tr>
            </thead>
            <tbody>
                <?php
                foreach($history as $h):
                    $badge_class = 'badge-primary';
                    if($h['change_type'] == 'Stock-In') $badge_class = 'badge-success';
                    if($h['change_type'] == 'Stock-Out' || $h['change_type'] == 'Guest Sale') $badge_class = 'badge-danger';
                ?>
                <tr>
                    <td><small><?= date('M d, h:i A', strtotime($h['created_at'])) ?></small></td>
                    <td><strong><?= htmlspecialchars($h['item_name']) ?></strong></td>
                    <td>
                        <small style="color: #64748b;">Cost: ₱<?= number_format($h['historical_unit_price'], 2) ?></small>
                        <?php if($h['change_type'] == 'Guest Sale'): ?>
                            <br><small style="color: var(--primary); font-weight: 600;">Sell: ₱<?= number_format($h['historical_selling_price'], 2) ?></small>
                        <?php endif; ?>
                    </td>
                    <td>
                        <span style="font-weight:700; color: <?= $h['change_amount'] >= 0 ? '#10b981' : '#ef4444' ?>;">
                            <?= ($h['change_amount'] > 0 ? '+' : '') . $h['change_amount'] ?> <?= $h['unit'] ?>
                        </span>
                    </td>
                    <td style="font-weight: 600;">
                        <?php if($h['change_type'] == 'Guest Sale'): ?>
                            ₱<?= number_format(abs($h['change_amount']) * $h['historical_selling_price'], 2) ?>
                        <?php else: ?>
                            ₱<?= number_format(abs($h['change_amount']) * $h['historical_unit_price'], 2) ?>
                        <?php endif; ?>
                    </td>
                    <td><small><?= htmlspecialchars($h['staff_name'] ?? 'System') ?></small></td>
                    <td><span class="badge <?= $badge_class ?>"><?= $h['change_type'] ?></span></td>
                    <td><small><?= htmlspecialchars($h['notes']) ?></small></td>
                </tr>
                <?php endforeach; ?>

                <?php if(count($history) == 0): ?>
                    <tr><td colspan="8" style="text-align:center; padding: 35px; color: #94a3b8;">
                        <i class='bx bx-history' style="font-size: 2.5rem; display: block; margin-bottom: 10px;"></i>
                        No history found for this <?= $filter_month ? "month" : "period" ?>.
                    </td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Stock-In Breakdown Modal -->
<div id="stockinModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:9999; align-items:center; justify-content:center;">
    <div style="background:#fff; width:90%; max-width:600px; border-radius:12px; padding:25px; box-shadow:0 10px 25px rgba(0,0,0,0.15); max-height: 80vh; overflow-y: auto;">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:15px; border-bottom:1px solid #e2e8f0; padding-bottom:15px;">
            <h3 style="margin:0; font-size:1.25rem; color: #1e293b; display: flex; align-items: center; gap: 8px;">
                <i class='bx bx-package' style="color: #10b981;"></i> Stock-In Breakdown
            </h3>
            <button onclick="document.getElementById('stockinModal').style.display='none'" style="background:none; border:none; font-size:1.75rem; cursor:pointer; color:#64748b; padding: 0; line-height: 1;">&times;</button>
        </div>
        
        <div class="table-responsive">
            <table style="width:100%; border-collapse:collapse; text-align: left;">
                <thead>
                    <tr style="background:#f8fafc;">
                        <th style="padding:12px; border-bottom:2px solid #e2e8f0;">Item Name</th>
                        <th style="padding:12px; border-bottom:2px solid #e2e8f0; text-align:center;">Qty Added</th>
                        <th style="padding:12px; border-bottom:2px solid #e2e8f0; text-align:right;">Cost/Unit</th>
                        <th style="padding:12px; border-bottom:2px solid #e2e8f0; text-align:right;">Total Value</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    arsort($stockin_breakdown);
                    foreach($stockin_breakdown as $item_name => $data): 
                    ?>
                    <tr>
                        <td style="padding:12px; border-bottom:1px solid #f1f5f9; font-weight: 500;"><?= htmlspecialchars($item_name) ?></td>
                        <td style="padding:12px; border-bottom:1px solid #f1f5f9; text-align:center;">
                            <span class="badge badge-success"><?= $data['qty'] ?></span>
                        </td>
                        <td style="padding:12px; border-bottom:1px solid #f1f5f9; text-align:right; color:#64748b;">₱<?= number_format($data['unit_price'], 2) ?></td>
                        <td style="padding:12px; border-bottom:1px solid #f1f5f9; text-align:right; font-weight:700; color:#10b981;">₱<?= number_format($data['value'], 2) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    
                    <?php if(empty($stockin_breakdown)): ?>
                    <tr><td colspan="4" style="padding:30px; text-align:center; color:#94a3b8;">
                        <i class='bx bx-package' style="font-size: 2.5rem; display: block; margin-bottom: 10px;"></i>
                        No stock-in records found for this period.
                    </td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        
        <div style="display: flex; justify-content: space-between; align-items: center; margin-top:20px; padding-top: 15px; border-top: 2px dashed #e2e8f0;">
            <div style="font-weight: 600; color: #64748b;">Total Stock-In Value</div>
            <div style="font-size: 1.5rem; font-weight: 800; color: #10b981;">₱<?= number_format($grand_total_in_value, 2) ?></div>
        </div>
        
        <div style="margin-top: 20px; text-align: right;">
            <button class="btn btn-secondary" onclick="document.getElementById('stockinModal').style.display='none'">Close</button>
        </div>
    </div>
</div>

<!-- Profit Breakdown Modal -->
<div id="profitModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:9999; align-items:center; justify-content:center;">
    <div style="background:#fff; width:90%; max-width:600px; border-radius:12px; padding:25px; box-shadow:0 10px 25px rgba(0,0,0,0.15); max-height: 80vh; overflow-y: auto;">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:15px; border-bottom:1px solid #e2e8f0; padding-bottom:15px;">
            <h3 style="margin:0; font-size:1.25rem; color: #1e293b; display: flex; align-items: center; gap: 8px;">
                <i class='bx bx-wallet' style="color: #059669;"></i> Profit Breakdown
            </h3>
            <button onclick="document.getElementById('profitModal').style.display='none'" style="background:none; border:none; font-size:1.75rem; cursor:pointer; color:#64748b; padding: 0; line-height: 1;">&times;</button>
        </div>
        
        <div class="table-responsive">
            <table style="width:100%; border-collapse:collapse; text-align: left;">
                <thead>
                    <tr style="background:#f8fafc;">
                        <th style="padding:12px; border-bottom:2px solid #e2e8f0;">Item Name</th>
                        <th style="padding:12px; border-bottom:2px solid #e2e8f0; text-align:center;">Qty Sold</th>
                        <th style="padding:12px; border-bottom:2px solid #e2e8f0; text-align:right;">Profit/Unit</th>
                        <th style="padding:12px; border-bottom:2px solid #e2e8f0; text-align:right;">Total Profit</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    arsort($profit_breakdown); // Sort by highest profit (you can sort by qty or name)
                    foreach($profit_breakdown as $item_name => $data): 
                    ?>
                    <tr>
                        <td style="padding:12px; border-bottom:1px solid #f1f5f9; font-weight: 500;"><?= htmlspecialchars($item_name) ?></td>
                        <td style="padding:12px; border-bottom:1px solid #f1f5f9; text-align:center;">
                            <span class="badge badge-primary"><?= $data['qty'] ?></span>
                        </td>
                        <td style="padding:12px; border-bottom:1px solid #f1f5f9; text-align:right; color:#64748b;">₱<?= number_format($data['profit'] / $data['qty'], 2) ?></td>
                        <td style="padding:12px; border-bottom:1px solid #f1f5f9; text-align:right; font-weight:700; color:#059669;">₱<?= number_format($data['profit'], 2) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    
                    <?php if(empty($profit_breakdown)): ?>
                    <tr><td colspan="4" style="padding:30px; text-align:center; color:#94a3b8;">
                        <i class='bx bx-cart' style="font-size: 2.5rem; display: block; margin-bottom: 10px;"></i>
                        No sales found to calculate profit.
                    </td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        
        <div style="display: flex; justify-content: space-between; align-items: center; margin-top:20px; padding-top: 15px; border-top: 2px dashed #e2e8f0;">
            <div style="font-weight: 600; color: #64748b;">Total Estimated</div>
            <div style="font-size: 1.5rem; font-weight: 800; color: #059669;">₱<?= number_format($total_profit, 2) ?></div>
        </div>
        
        <div style="margin-top: 20px; text-align: right;">
            <button class="btn btn-secondary" onclick="document.getElementById('profitModal').style.display='none'">Close</button>
        </div>
    </div>
</div>

<?php include 'footer.php'; ?>
<script>
    document.getElementById('page-title').innerText = 'Inventory Management';

    // ── Live profit hint on the add/edit form ─────────────────────────
    function updateProfitHint() {
        const costEl = document.getElementById('f_unit_price');
        const sellEl = document.getElementById('f_selling_price');
        const hint = document.getElementById('profit_hint');
        if (!costEl || !sellEl || !hint) return;
        const cost = parseFloat(costEl.value) || 0;
        const sell = parseFloat(sellEl.value) || 0;
        if (cost <= 0 && sell <= 0) { hint.style.display = 'none'; return; }
        const margin = sell - cost;
        hint.style.display = 'block';
        if (sell === 0) {
            hint.style.background = '#f1f5f9'; hint.style.color = '#64748b';
            hint.textContent = 'This item is free / not for sale — no profit to track.';
        } else if (margin > 0) {
            hint.style.background = '#f0fdf4'; hint.style.color = '#15803d';
            hint.textContent = 'You earn ₱' + margin.toFixed(2) + ' for every 1 unit sold.';
        } else if (margin < 0) {
            hint.style.background = '#fef2f2'; hint.style.color = '#dc2626';
            hint.textContent = 'Careful: you lose ₱' + Math.abs(margin).toFixed(2) + ' on every 1 unit sold.';
        } else {
            hint.style.background = '#f1f5f9'; hint.style.color = '#64748b';
            hint.textContent = 'Selling at cost — no profit, no loss.';
        }
    }
    ['f_unit_price', 'f_selling_price'].forEach(id => {
        const el = document.getElementById(id);
        if (el) el.addEventListener('input', updateProfitHint);
    });
    updateProfitHint();

    // ── Stock list search + category filter ───────────────────────────
    let stockCat = 'all';
    function setStockCat(cat, btn) {
        stockCat = cat;
        document.querySelectorAll('.stock-chip').forEach(b => b.classList.remove('active'));
        if (btn) btn.classList.add('active');
        filterStock();
    }
    function filterStock() {
        const q = document.getElementById('stock_search').value.toLowerCase().trim();
        let visible = 0;
        document.querySelectorAll('#stock_tbody tr[data-item]').forEach(tr => {
            const matchQ = !q || tr.dataset.name.indexOf(q) !== -1;
            const matchC = stockCat === 'all' || tr.dataset.cat === stockCat || (stockCat === 'low' && tr.dataset.low === '1');
            const show = matchQ && matchC;
            tr.style.display = show ? '' : 'none';
            if (show) visible++;
        });
        const total = document.querySelectorAll('#stock_tbody tr[data-item]').length;
        document.getElementById('stock_empty').style.display = (visible === 0 && total > 0) ? '' : 'none';
        document.getElementById('stock_count').textContent = '· ' + visible + ' item' + (visible === 1 ? '' : 's') + ' shown';
    }
    filterStock();

    // ── Restock modal (uses the existing ?restock_id=&amount= handler) ─
    let rsId = 0, rsQty = 0, rsUnit = '';
    function openRestock(btn) {
        rsId = btn.dataset.id;
        rsQty = parseInt(btn.dataset.qty, 10) || 0;
        rsUnit = btn.dataset.unit || '';
        document.getElementById('rs_name').textContent = btn.dataset.name;
        document.getElementById('rs_current').textContent = rsQty + ' ' + rsUnit;
        document.getElementById('rs_amount').value = 10;
        rsUpdate();
        document.getElementById('restockModal').style.display = 'block';
    }
    function closeRestock() {
        document.getElementById('restockModal').style.display = 'none';
    }
    function rsAdd(n) {
        const input = document.getElementById('rs_amount');
        input.value = (parseInt(input.value, 10) || 0) + n;
        rsUpdate();
    }
    function rsUpdate() {
        const amt = Math.max(0, parseInt(document.getElementById('rs_amount').value, 10) || 0);
        document.getElementById('rs_new').textContent = (rsQty + amt) + ' ' + rsUnit;
        document.getElementById('rs_confirm').href = 'inventory.php?restock_id=' + rsId + '&amount=' + amt;
    }
    document.getElementById('restockModal').addEventListener('click', function(e) {
        if (e.target === this) closeRestock();
    });
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            closeRestock();
            document.getElementById('stockinModal').style.display = 'none';
            document.getElementById('profitModal').style.display = 'none';
        }
    });
</script>
