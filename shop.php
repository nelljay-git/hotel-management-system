<?php
ob_start();
require_once 'db.php';

$message = "";

// Handle Cart Purchase
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['checkout_cart'])) {
    $checkin_id = (int)$_POST['checkin_id'];
    $cart_items = $_POST['quantities'] ?? []; // Array of item_id => quantity
    
    $processed_items = 0;
    $total_cart_price = 0;
    
    // Filter out items with 0 quantity
    $items_to_buy = [];
    foreach($cart_items as $item_id => $qty) {
        $qty = (int)$qty;
        if ($qty > 0) {
            $items_to_buy[(int)$item_id] = $qty;
        }
    }

    if (empty($items_to_buy)) {
        $message = "<div class='alert alert-danger'>Your cart is empty. Please select at least one item.</div>";
    } else {
        try {
            $pdo->beginTransaction();
            
            // Validate all items before making any changes
            $purchased_details = [];
            foreach($items_to_buy as $item_id => $buy_qty) {
                $stmt = $pdo->prepare("SELECT * FROM hotel_inventory WHERE id = ? AND category = 'Mini-bar'");
                $stmt->execute([$item_id]);
                $item = $stmt->fetch();
                
                if (!$item || $item['quantity'] < $buy_qty) {
                    throw new Exception("Insufficient stock for " . ($item['item_name'] ?? 'an item') . ". Only " . ($item['quantity'] ?? 0) . " left.");
                }
                
                $total_price = $item['selling_price'] * $buy_qty;
                $total_cart_price += $total_price;
                $purchased_details[] = "{$item['item_name']} (x$buy_qty)";
                
                // Decrement Inventory
                $pdo->prepare("UPDATE hotel_inventory SET quantity = quantity - ? WHERE id = ?")
                    ->execute([$buy_qty, $item_id]);
                    
                // Determine Notes
                if ($checkin_id > 0) {
                    $notes = "Sold to Check-in #$checkin_id";
                } else {
                    $notes = "Over the counter sale";
                }

                // Log Inventory History
                $pdo->prepare("INSERT INTO hotel_inventory_history (item_id, change_amount, change_type, historical_unit_price, historical_selling_price, notes, user_id) VALUES (?, ?, 'Guest Sale', ?, ?, ?, ?)")
                    ->execute([$item_id, -$buy_qty, $item['unit_price'], $item['selling_price'], $notes, $_SESSION['user_id']]);
                    
                $processed_items++;
            }

            // Update Billing if assigned to room
            if ($checkin_id > 0) {
                $pdo->prepare("UPDATE hotel_payments SET additional_services = additional_services + ?, total_amount = total_amount + ? WHERE checkin_id = ?")
                    ->execute([$total_cart_price, $total_cart_price, $checkin_id]);
            }

            $pdo->commit();
            
            $details_str = implode(", ", $purchased_details);
            if ($checkin_id > 0) {
                $message = "<div class='alert alert-success'>Successfully added <strong>$details_str</strong> to the guest's bill! (Total: ₱".number_format($total_cart_price, 2).")</div>";
            } else {
                $message = "<div class='alert alert-success'>Successfully sold <strong>$details_str</strong> over the counter! (Total: ₱".number_format($total_cart_price, 2).")</div>";
            }
        } catch (Exception $e) {
            $pdo->rollBack();
            $message = "<div class='alert alert-danger'>Transaction Failed: " . $e->getMessage() . "</div>";
        }
    }
}

include 'header.php';
?>

<div class="card-panel" style="position: relative; padding-bottom: 80px;">
    <div class="card-panel-header">
        <h2><i class='bx bx-cart' style="color: var(--primary);"></i> Mini-bar Shop Cart</h2>
        <p>Set quantities for multiple items, then check out in a single transaction.</p>
    </div>

    <?= $message ?>

    <form method="POST" action="shop.php" id="shop-form">
        <div class="form-group" style="max-width: 400px; margin-bottom: 30px;">
            <label style="font-weight: 700;">Select Active Room / Guest (Optional)</label>
            <select name="checkin_id" id="checkin_id" class="form-select" style="border: 2px solid var(--primary);">
                <option value="">-- Over the Counter Sale --</option>
                <?php
                $active_checkins = $pdo->query("
                    SELECT c.id, r.room_number, g.name as guest_name 
                    FROM hotel_checkins c
                    JOIN hotel_rooms r ON c.room_id = r.id
                    JOIN hotel_bookings b ON c.booking_id = b.id
                    JOIN hotel_guests g ON b.guest_id = g.id
                    WHERE c.status = 'Checked-in'
                    ORDER BY r.room_number ASC
                ")->fetchAll();
                
                foreach($active_checkins as $checkin): ?>
                    <option value="<?= $checkin['id'] ?>">Room <?= $checkin['room_number'] ?> - <?= htmlspecialchars($checkin['guest_name']) ?></option>
                <?php endforeach; ?>
            </select>
            <?php if(count($active_checkins) == 0): ?>
                <small style="color: #64748b; font-weight: 500;">No rooms occupied. Sales will be recorded as Walk-in/OTC.</small>
            <?php endif; ?>
        </div>

        <div class="shop-grid" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 25px;">
            <?php
            $minibar_items = $pdo->query("SELECT * FROM hotel_inventory WHERE category = 'Mini-bar' ORDER BY item_name ASC")->fetchAll();
            foreach($minibar_items as $item):
                $is_out = $item['quantity'] <= 0;
            ?>
            <div class="shop-item card-panel" style="margin: 0; padding: 20px; border: 1px solid #e2e8f0; transition: transform 0.3s;">
                <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 15px;">
                    <div>
                        <h3 style="font-size: 1.1rem; margin-bottom: 5px;"><?= htmlspecialchars($item['item_name']) ?></h3>
                        <span class="badge <?= $is_out ? 'badge-danger' : 'badge-primary' ?>" style="font-size: 0.75rem;">
                            <?= $is_out ? 'Out of Stock' : $item['quantity'] . ' ' . $item['unit'] . ' available' ?>
                        </span>
                    </div>
                    <div style="font-size: 1.25rem; font-weight: 800; color: var(--primary);">₱<?= number_format($item['selling_price'], 2) ?></div>
                </div>

                <div style="display: flex; align-items: center; justify-content: space-between; margin-top: 15px;">
                    <label style="font-weight: 600; color: #64748b; margin:0;">Quantity:</label>
                    <input type="number" 
                           name="quantities[<?= $item['id'] ?>]" 
                           class="form-control cart-qty" 
                           value="0" 
                           min="0" 
                           max="<?= $item['quantity'] ?>" 
                           data-price="<?= $item['selling_price'] ?>"
                           <?= $is_out ? 'disabled' : '' ?> 
                           style="width: 100px; text-align: center; border: 2px solid <?= $is_out ? '#e2e8f0' : 'var(--primary)' ?>;"
                           onchange="calculateCartTotal()">
                </div>
            </div>
            <?php endforeach; ?>
            
            <?php if(count($minibar_items) == 0): ?>
                <div style="grid-column: 1/-1; text-align: center; padding: 50px; color: #94a3b8;">
                    <i class='bx bx-package' style="font-size: 4rem; display: block; margin-bottom:15px;"></i>
                    <p>No Mini-bar items found in inventory.</p>
                    <a href="inventory.php" class="btn btn-primary btn-sm" style="margin-top: 10px;">Go to Inventory</a>
                </div>
            <?php endif; ?>
        </div>
        
        <input type="hidden" name="checkout_cart" value="1">

        <!-- Sticky Bottom Cart Bar -->
        <div class="cart-summary-bar" style="z-index: 99;">
            <div style="display: flex; justify-content: space-between; align-items: center; max-width: 1200px; margin: 0 auto; width: 100%;">
                <div style="display: flex; align-items: center; gap: 15px;">
                    <i class='bx bx-cart' style="font-size: 2rem; color: var(--primary);"></i>
                    <div>
                        <div style="font-size: 0.9rem; color: #64748b; font-weight: 600;">Total Order Value</div>
                        <div style="font-size: 1.5rem; font-weight: 800; color: #1e293b;">₱<span id="cart-total">0.00</span></div>
                    </div>
                </div>
                <button type="button" class="btn btn-success" onclick="confirmCartCheckout()" style="font-size: 1.1rem; padding: 10px 25px; border-radius: 8px; box-shadow: var(--shadow-md);">
                    <i class='bx bx-check-circle'></i> Complete Sale
                </button>
            </div>
        </div>
    </form>
</div>

<?php include 'footer.php'; ?>

<script>
    document.getElementById('page-title').innerText = 'Mini-bar Shop';

    function calculateCartTotal() {
        const inputs = document.querySelectorAll('.cart-qty');
        let total = 0;
        
        inputs.forEach(input => {
            const qty = parseInt(input.value) || 0;
            const price = parseFloat(input.getAttribute('data-price')) || 0;
            if (qty > 0) {
                total += (qty * price);
            }
        });
        
        document.getElementById('cart-total').innerText = total.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    function confirmCartCheckout() {
        const totalText = document.getElementById('cart-total').innerText;
        if (totalText === '0.00' || totalText === '0') {
            alert('Your cart is empty! Please add quantities to at least one item.');
            return;
        }

        const checkinId = document.getElementById('checkin_id').value;
        let confirmMsg = '';
        
        if (!checkinId) {
            confirmMsg = 'Process walk-in sale for ₱' + totalText + '? (No guest bill will be updated)';
        } else {
            const roomText = document.getElementById('checkin_id').options[document.getElementById('checkin_id').selectedIndex].text;
            confirmMsg = 'Add ₱' + totalText + ' to the bill for ' + roomText + '?';
        }

        if (confirm(confirmMsg)) {
            document.getElementById('shop-form').submit();
        }
    }
</script>

<style>
    .shop-item:hover {
        transform: translateY(-5px);
        box-shadow: var(--shadow-md);
        border-color: var(--primary) !important;
    }
    
    .cart-summary-bar {
        position: fixed;
        bottom: 0px;
        left: 250px; /* Offset by sidebar width */
        right: 0;
        background: #fff;
        padding: 15px 30px;
        box-shadow: 0 -4px 6px -1px rgba(0, 0, 0, 0.1);
        border-top: 2px solid var(--primary);
        z-index: 100;
        display: flex;
        justify-content: center;
        transition: all 0.3s ease;
    }

    @media (max-width: 768px) {
        .cart-summary-bar {
            left: 0;
        }
    }
</style>
