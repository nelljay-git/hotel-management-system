<?php
ob_start();
require_once 'db.php';
$message = "";

// Update Bill Logic
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_bill'])) {
    $payment_id = $_POST['payment_id'];
    $add_services = (float) $_POST['add_services'];

    // Get room charges first
    $stmt = $pdo->prepare("SELECT room_charges FROM hotel_payments WHERE id = ?");
    $stmt->execute([$payment_id]);
    $charge = $stmt->fetchColumn();

    $total_amount = $charge + $add_services;

    if ($pdo->prepare("UPDATE hotel_payments SET additional_services = ?, total_amount = ? WHERE id = ?")->execute([$add_services, $total_amount, $payment_id])) {
        $message = "<div class='alert alert-success'>Bill successfully updated.</div>";
    }
}

// Mark Paid Logic
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['mark_paid'])) {
    $payment_id = $_POST['payment_id'];
    $now = date('Y-m-d H:i:s');
    if ($pdo->prepare("UPDATE hotel_payments SET payment_status = 'Paid', paid_at = ? WHERE id = ?")->execute([$now, $payment_id])) {
        $message = "<div class='alert alert-success'>Invoice marked as PAID.</div>";
    }
}

// Add Inventory Item to Bill Logic
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_inventory_item'])) {
    $payment_id = $_POST['payment_id'];
    $item_id = $_POST['item_id'];

    try {
        $pdo->beginTransaction();
        
        // 1. Get item details
        $stmt = $pdo->prepare("SELECT item_name, unit_price, selling_price, quantity FROM hotel_inventory WHERE id = ?");
        $stmt->execute([$item_id]);
        $item = $stmt->fetch();

        if ($item && $item['quantity'] > 0) {
            $price = (float)$item['unit_price'];
            
            // 2. Update Payment
            $pdo->prepare("UPDATE hotel_payments SET additional_services = additional_services + ?, total_amount = total_amount + ? WHERE id = ?")
                ->execute([$price, $price, $payment_id]);
            
            // 3. Deduct Inventory
            $pdo->prepare("UPDATE hotel_inventory SET quantity = quantity - 1 WHERE id = ?")
                ->execute([$item_id]);

            // 4. Log History
            // Find Guest Name for the note
            $stmt = $pdo->prepare("SELECT g.name FROM hotel_checkins c JOIN hotel_bookings b ON c.booking_id = b.id JOIN hotel_guests g ON b.guest_id = g.id WHERE c.id = (SELECT checkin_id FROM hotel_payments WHERE id = ?)");
            $stmt->execute([$payment_id]);
            $gname = $stmt->fetchColumn() ?: "Guest";

            $pdo->prepare("INSERT INTO hotel_inventory_history (item_id, change_amount, change_type, notes) VALUES (?, -1, 'Guest Sale', ?)")
                ->execute([$item_id, "Sold to $gname"]);
            
            $pdo->commit();
            $message = "<div class='alert alert-success'>Added <strong>{$item['item_name']}</strong> (₱" . number_format($price, 2) . ") to bill and updated inventory.</div>";
        } else {
            throw new Exception("Item out of stock or not found.");
        }
    } catch (Exception $e) {
        $pdo->rollBack();
        $message = "<div class='alert alert-danger'>Error: " . $e->getMessage() . "</div>";
    }
}

include 'header.php';
?>

<?= $message ?>

<div class="card-panel">
    <div class="card-panel-header" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px;">
        <h2>Billing & Invoices</h2>
        
        <div style="display: flex; gap: 10px; align-items: center;">
            <form method="GET" action="billing.php" class="form-row" style="margin: 0; align-items: center; gap: 10px; display: flex;">
                <div style="display: flex; gap: 5px; align-items: center; border-right: 1px solid #e2e8f0; padding-right: 10px;">
                    <label for="date" style="margin: 0; font-size: 0.8rem; font-weight:600; color: #64748b;">Daily:</label>
                    <input type="date" name="date" id="date" class="form-control" value="<?= $_GET['date'] ?? '' ?>" onchange="document.getElementById('month').value=''; this.form.submit()" style="width: auto; padding: 4px 8px; height: 32px;">
                </div>
                <div style="display: flex; gap: 5px; align-items: center; padding-left: 5px;">
                    <label for="month" style="margin: 0; font-size: 0.8rem; font-weight:600; color: #64748b;">Monthly:</label>
                    <input type="month" name="month" id="month" class="form-control" value="<?= $_GET['month'] ?? '' ?>" onchange="document.getElementById('date').value=''; this.form.submit()" style="width: auto; padding: 4px 8px; height: 32px;">
                </div>
                <?php if(isset($_GET['date']) || isset($_GET['month'])): ?>
                    <a href="billing.php" class="btn btn-secondary btn-sm" style="padding: 4px 8px; height: 32px; display: flex; align-items: center;" title="Clear Filters"><i class='bx bx-x'></i></a>
                    <a href="print_billing.php?<?= http_build_query($_GET) ?>" target="_blank" class="btn btn-success btn-sm" style="padding: 4px 12px; height: 32px; display: flex; align-items: center; gap: 5px;">
                        <i class='bx bx-printer'></i> Print Report
                    </a>
                <?php endif; ?>
            </form>
        </div>
    </div>
    <div class="table-responsive">
        <table>
            <thead>
                <tr>
                    <th>Inv #</th>
                    <th>Guest</th>
                    <th>Room #</th>
                    <th>Room Type</th>
                    <th>Room Charges</th>
                    <th>Other Svcs</th>
                    <th>Total Bill</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php
                // Get all invoices logic with date filtering
                $filter_checkin = $_GET['checkin_id'] ?? null;
                $selected_date = $_GET['date'] ?? null;
                $selected_month = $_GET['month'] ?? null;

                $where_clauses = ["1=1"];
                $params = [];

                if ($filter_checkin) {
                    $where_clauses[] = "c.id = ?";
                    $params[] = $filter_checkin;
                }

                if ($selected_date) {
                    $where_clauses[] = "date(p.paid_at) = ?";
                    $params[] = $selected_date;
                } elseif ($selected_month) {
                    $where_clauses[] = "strftime('%Y-%m', p.paid_at) = ?";
                    $params[] = $selected_month;
                }

                $where_sql = implode(" AND ", $where_clauses);

                $query = "
                    SELECT p.id as pay_id, p.room_charges, p.additional_services, p.total_amount, p.payment_status, p.paid_at,
                           c.id as checkin_id, r.room_number, r.room_type, g.name as guest_name
                    FROM hotel_payments p
                    JOIN hotel_checkins c ON p.checkin_id = c.id
                    JOIN hotel_bookings b ON c.booking_id = b.id
                    JOIN hotel_guests g ON b.guest_id = g.id
                    JOIN hotel_rooms r ON c.room_id = r.id
                    WHERE $where_sql
                ";

                $stmt = $pdo->prepare($query . " ORDER BY p.payment_status ASC, p.id DESC");
                $stmt->execute($params);
                
                $all_bills = $stmt->fetchAll();

                foreach ($all_bills as $row):
                    $stat = $row['payment_status'];
                    $bclass = $stat == 'Paid' ? 'badge-success' : 'badge-danger';
                    $row_bg = ($filter_checkin == $row['checkin_id']) ? 'style="background-color: #f0fdf4;"' : '';
                    ?>
                    <tr <?= $row_bg ?>>
                        <td>INV-10<?= $row['pay_id'] ?></td>
                        <td><strong><?= htmlspecialchars($row['guest_name']) ?></strong></td>
                        <td><?= htmlspecialchars($row['room_number']) ?></td>
                        <td><span class="badge badge-info" style="background-color: #0dcaf0; color: #fff;"><?= htmlspecialchars($row['room_type']) ?></span></td>
                        <td>₱<?= number_format($row['room_charges'], 2) ?></td>
                        <td>₱<?= number_format($row['additional_services'], 2) ?></td>
                        <td style="font-weight:700; color:var(--primary);">₱<?= number_format($row['total_amount'], 2) ?>
                        </td>
                        <td><span class="badge <?= $bclass ?>"><?= $stat ?></span></td>
                        <td class="action-links">
                            <?php if ($stat == 'Unpaid'): ?>
                                <form method="POST" action="billing.php" style="display:inline-block;">
                                    <input type="hidden" name="payment_id" value="<?= $row['pay_id'] ?>">
                                    <input type="number" step="0.01" name="add_services" placeholder="Add Svcs ₱"
                                        value="<?= $row['additional_services'] ?>" style="width:100px; padding:4px;" required>
                                    <button type="submit" name="update_bill" class="btn btn-secondary btn-sm"
                                        title="Update Additional Services"><i class='bx bx-refresh'></i></button>
                                </form>

                                <form method="POST" action="billing.php" style="display:inline-block; margin-left: 10px;">
                                    <input type="hidden" name="payment_id" value="<?= $row['pay_id'] ?>">
                                    <button type="submit" name="mark_paid" class="btn btn-success btn-sm"
                                        onclick="return confirm('Confirm payment received for total of ₱<?= number_format($row["total_amount"], 2) ?>?')">
                                        <i class='bx bx-check-circle'></i> Mark Paid
                                    </button>
                                </form>

                                <div style="margin-top: 10px; padding-top: 10px; border-top: 1px solid #eee;">
                                    <form method="POST" action="billing.php" style="display:flex; gap:5px;">
                                        <input type="hidden" name="payment_id" value="<?= $row['pay_id'] ?>">
                                        <select name="item_id" class="form-select" style="font-size: 11px; padding: 2px 5px; height: auto;" required>
                                            <option value="" disabled selected>Add Item (Mini-bar/Svcs)</option>
                                            <?php
                                            $items = $pdo->query("SELECT id, item_name, unit_price, quantity FROM hotel_inventory WHERE quantity > 0 ORDER BY category, item_name")->fetchAll();
                                            foreach($items as $i):
                                            ?>
                                                <option value="<?= $i['id'] ?>"><?= htmlspecialchars($i['item_name']) ?> (₱<?= number_format($i['unit_price'], 0) ?>)</option>
                                            <?php endforeach; ?>
                                            <?php if(count($items) == 0): ?>
                                                <option value="" disabled>No inventory items available</option>
                                            <?php endif; ?>
                                        </select>
                                        <button type="submit" name="add_inventory_item" class="btn btn-primary btn-sm" style="padding: 2px 8px;" title="Add from Inventory"><i class='bx bx-plus'></i></button>
                                    </form>
                                </div>
                            <?php else: ?>
                                <a href="receipt.php?id=<?= $row['pay_id'] ?>" target="_blank"
                                    class="btn btn-success btn-sm" style="display:inline-flex; align-items:center; gap:4px;">
                                    <i class='bx bx-printer'></i> Print Receipt
                                </a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (count($all_bills) == 0): ?>
                    <tr>
                        <td colspan="9" style="text-align:center; padding: 25px;">No billing records found.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include 'footer.php'; ?>