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

<div class="overview-boxes" style="margin-bottom: 25px;">
    <?php
    $low_stock_count = $pdo->query("SELECT COUNT(*) FROM hotel_inventory WHERE quantity <= min_stock_level")->fetchColumn();
    $total_items = $pdo->query("SELECT COUNT(*) FROM hotel_inventory")->fetchColumn();
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
</div>

<div class="card-panel">
    <div class="card-panel-header">
        <h2><?= $edit_item ? 'Edit Item' : 'Add New Inventory Item' ?></h2>
    </div>
    <form method="POST" action="inventory.php">
        <?php if($edit_item): ?>
            <input type="hidden" name="item_id" value="<?= $edit_item['id'] ?>">
        <?php endif; ?>
        <div class="form-row">
            <div class="form-group">
                <label>Item Name</label>
                <input type="text" name="item_name" class="form-control" required placeholder="e.g., Bottled Water" value="<?= $edit_item['item_name'] ?? '' ?>">
            </div>
            <div class="form-group">
                <label>Category</label>
                <select name="category" class="form-select">
                    <?php 
                    $cats = ['Toiletries', 'Mini-bar', 'Cleaning', 'Linens', 'Other'];
                    foreach($cats as $cat): ?>
                        <option <?= (isset($edit_item['category']) && $edit_item['category'] == $cat) ? 'selected' : '' ?>><?= $cat ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label>Initial Quantity</label>
                <input type="number" name="quantity" class="form-control" required value="<?= $edit_item['quantity'] ?? 0 ?>">
            </div>
            <div class="form-group">
                <label>Unit</label>
                <select name="unit" class="form-select" required>
                    <?php 
                    $units = ['pc', 'kg', 'g', 'liter', 'ml', 'bottle', 'can', 'pack', 'set', 'roll', 'box'];
                    $current_unit = $edit_item['unit'] ?? 'pc';
                    foreach($units as $u): ?>
                        <option value="<?= $u ?>" <?= ($current_unit == $u) ? 'selected' : '' ?>><?= $u ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>Cost Price (₱)</label>
                <input type="number" step="0.01" name="unit_price" class="form-control" required value="<?= $edit_item['unit_price'] ?? 0 ?>" title="What you paid for the item">
            </div>
            <div class="form-group">
                <label>Selling Price (₱)</label>
                <input type="number" step="0.01" name="selling_price" class="form-control" required value="<?= $edit_item['selling_price'] ?? 0 ?>" title="What you charge the guest">
            </div>
            <div class="form-group">
                <label>Min Stock (Alert)</label>
                <input type="number" name="min_stock" class="form-control" required value="<?= $edit_item['min_stock_level'] ?? 5 ?>">
            </div>
        </div>
        <button type="submit" name="save_item" class="btn btn-primary"><i class='bx bx-save'></i> <?= $edit_item ? 'Update Item' : 'Save Item' ?></button>
        <?php if($edit_item): ?>
            <a href="inventory.php" class="btn btn-secondary">Cancel</a>
        <?php endif; ?>
    </form>
</div>

<div class="card-panel" style="margin-top:20px;">
    <div class="card-panel-header">
        <h2>Stock Management</h2>
    </div>
    <div class="table-responsive">
        <table>
            <thead>
                <tr>
                    <th>Item Name</th>
                    <th>Category</th>
                    <th>Stock Level</th>
                    <th>Cost Price</th>
                    <th>Selling Price</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $stmt = $pdo->query("SELECT * FROM hotel_inventory ORDER BY category ASC, item_name ASC");
                $all_items = $stmt->fetchAll();
                
                foreach($all_items as $row):
                    $is_low = $row['quantity'] <= $row['min_stock_level'];
                    $stock_class = $is_low ? 'badge-danger' : 'badge-success';
                ?>
                <tr>
                    <td><strong><?= htmlspecialchars($row['item_name']) ?></strong></td>
                    <td><small><?= $row['category'] ?></small></td>
                    <td>
                        <span class="badge <?= $stock_class ?>"><?= $row['quantity'] ?> <?= $row['unit'] ?></span>
                        <?php if($is_low): ?>
                            <i class='bx bxs-error-circle' style="color: #dc2626;" title="Low Stock!"></i>
                        <?php endif; ?>
                    </td>
                    <td>₱<?= number_format($row['unit_price'], 2) ?></td>
                    <td><strong style="color: var(--primary);">₱<?= number_format($row['selling_price'], 2) ?></strong></td>
                    <td class="action-links">
                        <a href="inventory.php?restock_id=<?= $row['id'] ?>&amount=10" title="Quick +10"><i class='bx bx-plus-circle'></i> +10</a>
                        <a href="inventory.php?edit=<?= $row['id'] ?>"><i class='bx bx-edit'></i></a>
                        <?php if($_SESSION['role'] === 'Admin'): ?>
                            <a href="inventory.php?delete=<?= $row['id'] ?>" class="delete-link" onclick="return confirm('Delete this item?')"><i class='bx bx-trash'></i></a>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if(count($all_items) == 0): ?>
                    <tr><td colspan="5" style="text-align:center; padding: 25px;">No inventory items found.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="card-panel" style="margin-top:20px;" id="stock-history-section">
    <div class="card-panel-header" style="display: flex; justify-content: space-between; align-items: flex-start; gap: 20px; flex-wrap: wrap;">
        <div style="flex: 1; min-width: 250px;">
            <h2>Stock Movement History</h2>
            <p>Track all changes to your inventory stock</p>
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
</script>
