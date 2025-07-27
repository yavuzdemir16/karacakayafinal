<?php
session_start();
require_once 'config.php';

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Bu sayfaya erişebilecek roller: Genel Müdür, Genel Müdür Yardımcısı, Satış Müdürü, Sevkiyat Sorumlusu
check_permission(['genel_mudur', 'genel_mudur_yardimcisi', 'satis_muduru', 'sevkiyat_sorumlusu']);

$message = '';
$message_type = '';

$action = isset($_GET['action']) ? $_GET['action'] : 'list';
$shipment_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

$shipment_details = null;

// Sevkiyat durumu seçenekleri (shipments tablosundaki ENUM ile uyumlu olmalı)
$shipment_statuses = [
    'Hazırlanıyor',
    'Sevkiyatta',
    'Teslim Edildi',
    'İptal Edildi'
];

$pdo = connect_db(); // config.php'den PDO bağlantısını al

// Onaylanmış veya faturalanmış siparişleri çekelim (Sevkiyat oluşturmak için)
// Bu kısım, henüz sevkiyatı olmayan ve 'Faturalandı' durumundaki siparişleri gösterecek
$eligible_orders = [];
try {
    $sql_eligible_orders = "SELECT o.id, o.order_code, o.invoice_number, c.customer_name as dealer_name, o.order_date
                            FROM orders o
                            JOIN customers c ON o.dealer_id = c.id
                            WHERE o.order_status = 'Faturalandı' -- Sadece faturalanmış siparişler
                            AND o.id NOT IN (SELECT order_id FROM shipments WHERE is_active = TRUE) -- Aktif sevkiyatı olmayanlar
                            ORDER BY o.order_date DESC";
    $stmt_eligible_orders = $pdo->query($sql_eligible_orders);
    $eligible_orders = $stmt_eligible_orders->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Sevkiyat oluşturulabilecek siparişler çekilirken hata: " . $e->getMessage());
    $message = "Sevkiyat oluşturulabilecek siparişler alınamadı: " . $e->getMessage();
    $message_type = 'error';
}


if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $current_logged_in_user_id = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : null;
    if ($current_logged_in_user_id === null) {
         $message = "Oturum kullanıcı ID'si bulunamadı. Lütfen tekrar giriş yapın.";
         $message_type = 'error';
         $action = 'list';
         goto end_post_processing_shipments;
    }

    if (isset($_POST['add_shipment'])) {
        $order_id = intval($_POST['order_id']);
        $shipment_status = trim($_POST['shipment_status']);
        $waybill_number = trim($_POST['waybill_number']); // Yeni kolon
        $vehicle_plate = trim($_POST['vehicle_plate']);
        $delivery_date = empty($_POST['delivery_date']) ? null : $_POST['delivery_date'];
        $notes = trim($_POST['notes']);
        // Shipment date otomatik current_timestamp() alıyor veritabanında

        if (empty($order_id) || empty($shipment_status) || empty($waybill_number)) {
            $message = "Sipariş Kodu, Sevkiyat Durumu ve İrsaliye Numarası alanları zorunludur.";
            $message_type = 'error';
        } else {
            try {
                $pdo->beginTransaction();
                // SQL sorgusunu waybill_number kolonu için güncelledik
                $stmt = $pdo->prepare("INSERT INTO shipments (order_id, shipment_status, waybill_number, vehicle_plate, delivery_date, notes, created_by, last_updated_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$order_id, $shipment_status, $waybill_number, $vehicle_plate, $delivery_date, $notes, $current_logged_in_user_id, $current_logged_in_user_id]);

                // Siparişin durumunu sevkiyat başladıysa 'Sevkiyatta' olarak güncelle
                $stmt_update_order = $pdo->prepare("UPDATE orders SET order_status = ?, last_updated_by = ? WHERE id = ?");
                $new_order_status = ($shipment_status == 'Teslim Edildi') ? 'Teslim Edildi' : 'Sevkiyatta';
                $stmt_update_order->execute([$new_order_status, $current_logged_in_user_id, $order_id]);

                $pdo->commit();
                $message = "Sevkiyat başarıyla eklendi.";
                $message_type = 'success';
                $action = 'list';
            } catch (PDOException $e) {
                $pdo->rollBack();
                if ($e->getCode() == '23000') { // Duplicate entry
                    $message = "Bu sipariş için zaten bir sevkiyat oluşturulmuş veya bu irsaliye numarası zaten mevcut.";
                    $message_type = 'error';
                } else {
                    error_log("Sevkiyat eklenirken hata: " . $e->getMessage());
                    $message = "Sevkiyat eklenirken bir hata oluştu: " . $e->getMessage();
                    $message_type = 'error';
                }
            }
        }
    } elseif (isset($_POST['edit_shipment'])) {
        $shipment_id = intval($_POST['shipment_id']);
        $shipment_status = trim($_POST['shipment_status']);
        $waybill_number = trim($_POST['waybill_number']); // Yeni kolon
        $vehicle_plate = trim($_POST['vehicle_plate']);
        $delivery_date = empty($_POST['delivery_date']) ? null : $_POST['delivery_date'];
        $notes = trim($_POST['notes']);

        if (empty($shipment_id) || empty($shipment_status) || empty($waybill_number)) {
            $message = "Sevkiyat ID, Sevkiyat Durumu ve İrsaliye Numarası alanları zorunludur.";
            $message_type = 'error';
        } else {
            try {
                $pdo->beginTransaction();

                $stmt_get_order_id = $pdo->prepare("SELECT order_id FROM shipments WHERE id = ?");
                $stmt_get_order_id->execute([$shipment_id]);
                $order_id_for_update = $stmt_get_order_id->fetchColumn();

                // SQL sorgusunu waybill_number kolonu için güncelledik
                $stmt = $pdo->prepare("UPDATE shipments SET shipment_status = ?, waybill_number = ?, vehicle_plate = ?, delivery_date = ?, notes = ?, last_updated_by = ?, updated_at = NOW() WHERE id = ?");
                $stmt->execute([$shipment_status, $waybill_number, $vehicle_plate, $delivery_date, $notes, $current_logged_in_user_id, $shipment_id]);

                if ($order_id_for_update) {
                    $stmt_update_order = $pdo->prepare("UPDATE orders SET order_status = ?, last_updated_by = ? WHERE id = ?");
                    $new_order_status = '';
                    if ($shipment_status == 'Teslim Edildi') {
                        $new_order_status = 'Teslim Edildi';
                    } elseif ($shipment_status == 'İptal Edildi') { // Sevkiyat iptal edilirse sipariş de iptal edilsin
                        $new_order_status = 'İptal Edildi';
                    } else { // Diğer durumlarda (Hazırlanıyor, Sevkiyatta)
                        $new_order_status = 'Sevkiyatta';
                    }
                    $stmt_update_order->execute([$new_order_status, $current_logged_in_user_id, $order_id_for_update]);
                }

                $pdo->commit();
                $message = "Sevkiyat başarıyla güncellendi.";
                $message_type = 'success';
                $action = 'list';
            } catch (PDOException $e) {
                $pdo->rollBack();
                if ($e->getCode() == '23000') {
                    $message = "Bu irsaliye numarası zaten başka bir sevkiyat tarafından kullanılıyor.";
                    $message_type = 'error';
                } else {
                    error_log("Sevkiyat güncellenirken hata: " . $e->getMessage());
                    $message = "Sevkiyat güncellenirken bir hata oluştu: " . $e->getMessage();
                    $message_type = 'error';
                }
            }
        }
    } elseif (isset($_POST['delete_shipment'])) {
        $shipment_id = intval($_POST['shipment_id']);
        if (empty($shipment_id)) {
            $message = "Silinecek sevkiyat ID'si belirtilmedi.";
            $message_type = 'error';
        } else {
            try {
                $pdo->beginTransaction();

                $stmt_get_order_id = $pdo->prepare("SELECT order_id FROM shipments WHERE id = ?");
                $stmt_get_order_id->execute([$shipment_id]);
                $order_id_for_update = $stmt_get_order_id->fetchColumn();

                // Sevkiyatı is_active = FALSE yaparak pasifize et ve durumunu 'İptal Edildi' yap
                $stmt = $pdo->prepare("UPDATE shipments SET is_active = FALSE, shipment_status = 'İptal Edildi', last_updated_by = ?, updated_at = NOW() WHERE id = ?");
                $stmt->execute([$current_logged_in_user_id, $shipment_id]);

                // İlişkili siparişin durumunu da 'İptal Edildi' yap
                if ($order_id_for_update) {
                    $stmt_update_order = $pdo->prepare("UPDATE orders SET order_status = 'İptal Edildi', last_updated_by = ? WHERE id = ?");
                    $stmt_update_order->execute([$current_logged_in_user_id, $order_id_for_update]);
                }

                $pdo->commit();
                $message = "Sevkiyat başarıyla pasifize edildi ve sipariş durumu güncellendi.";
                $message_type = 'success';
                $action = 'list';
            } catch (PDOException $e) {
                $pdo->rollBack();
                error_log("Sevkiyat pasifize edilirken hata: " . $e->getMessage());
                $message = "Sevkiyat pasifize edilirken bir hata oluştu: " . $e->getMessage();
                $message_type = 'error';
            }
        }
    }
}
end_post_processing_shipments:;


$shipments = [];
if ($action == 'list') {
    try {
        // SELECT sorgusunda o.order_code, o.invoice_number, o.order_status ve s.shipment_status doğru şekilde seçildiğinden emin oluyoruz
        $sql = "SELECT s.*, o.order_code, o.invoice_number, o.order_status as order_main_status, c.customer_name as dealer_name,
                       u_created.username as created_by_username,
                       COALESCE(u_updated.username, 'N/A') as last_updated_by_username
                FROM shipments s
                JOIN orders o ON s.order_id = o.id
                JOIN customers c ON o.dealer_id = c.id
                JOIN users u_created ON s.created_by = u_created.id
                LEFT JOIN users u_updated ON s.last_updated_by = u_updated.id
                WHERE s.is_active = TRUE";

        if (isset($_SESSION['user_role_id']) && $_SESSION['user_role_id'] == 3) { // Rol 3 = Satış Müdürü
            $current_user_id = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : null;
            if ($current_user_id !== null) {
                // Satış müdürünün sadece kendi bayilerine ait sevkiyatları görmesini sağla
                $sql .= " AND c.id IN (SELECT smc.customer_id FROM sales_managers_to_customers smc WHERE smc.sales_manager_id = " . $pdo->quote($current_user_id) . ")";
            }
        }
        $sql .= " ORDER BY s.created_at DESC";

        $stmt = $pdo->query($sql);
        $shipments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Sevkiyatlar çekilirken hata: " . $e->getMessage());
        $message = "Sevkiyat listesi alınırken bir hata oluştu: " . $e->getMessage();
        $message_type = 'error';
    }
} elseif (($action == 'edit' || $action == 'view') && $shipment_id > 0) {
    try {
        // SELECT sorgusunda o.order_code, o.invoice_number, o.order_status ve s.shipment_status doğru şekilde seçildiğinden emin oluyoruz
        $sql_detail = "SELECT s.*, o.order_code, o.invoice_number, o.order_status as order_main_status, c.customer_name as dealer_name,
                                      u_created.username as created_by_username, r_created.role_name as created_by_role,
                                      COALESCE(u_updated.username, 'N/A') as last_updated_by_username, COALESCE(r_updated.role_name, 'N/A') as last_updated_by_role
                               FROM shipments s
                               JOIN orders o ON s.order_id = o.id
                               JOIN customers c ON o.dealer_id = c.id
                               JOIN users u_created ON s.created_by = u_created.id
                               JOIN roles r_created ON u_created.role_id = r_created.id
                               LEFT JOIN users u_updated ON s.last_updated_by = u_updated.id
                               LEFT JOIN roles r_updated ON u_updated.role_id = r_updated.id
                               WHERE s.id = ? AND s.is_active = TRUE";
        $stmt = $pdo->prepare($sql_detail);
        $stmt->execute([$shipment_id]);
        $shipment_details = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$shipment_details) {
            $message = "Sevkiyat bulunamadı veya pasif durumda.";
            $message_type = 'error';
            $action = 'list';
        }
    } catch (PDOException $e) {
        error_log("Sevkiyat detayları çekilirken hata: " . $e->getMessage());
        $message = "Sevkiyat detayları alınırken bir hata oluştu: " . $e->getMessage();
        $message_type = 'error';
        $action = 'list';
    }
}

$page_title = "Sevkiyat Yönetimi";
include 'includes/header.php';
?>

<div class="container">
    <h1>Sevkiyat Yönetimi</h1>

    <?php if (!empty($message)) : ?>
        <div class="message <?php echo $message_type; ?>"><?php echo $message; ?></div>
    <?php endif; ?>

    <!-- Yeni Sevkiyat Oluşturma Bölümü -->
    <div class="card" style="margin-bottom: 20px;">
        <div class="card-header">
            <h3>Yeni Sevkiyat Oluştur</h3>
        </div>
        <div class="card-body">
            <?php if (!empty($eligible_orders)) : ?>
                <form id="newShipmentForm" action="shipments.php" method="post">
                    <div class="form-group">
                        <label for="order_id">Sipariş Kodu:</label>
                        <select id="order_id" name="order_id" required>
                            <option value="">Seçiniz</option>
                            <?php foreach ($eligible_orders as $order) : ?>
                                <option value="<?php echo htmlspecialchars($order['id']); ?>">
                                    <?php echo htmlspecialchars($order['order_code'] . ' - Fatura No: ' . ($order['invoice_number'] ?: 'Yok') . ' - ' . $order['dealer_name'] . ' (' . date('d.m.Y', strtotime($order['order_date'])) . ')'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="shipment_status_new">Sevkiyat Durumu:</label>
                        <select id="shipment_status_new" name="shipment_status" required>
                            <?php foreach ($shipment_statuses as $status) : ?>
                                <option value="<?php echo htmlspecialchars($status); ?>" <?php echo ($status == 'Hazırlanıyor') ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($status); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="waybill_number_new">İrsaliye Numarası:</label>
                        <input type="text" id="waybill_number_new" name="waybill_number" required>
                    </div>
                    <div class="form-group">
                        <label for="vehicle_plate_new">Araç Plakası:</label>
                        <input type="text" id="vehicle_plate_new" name="vehicle_plate">
                    </div>
                    <div class="form-group">
                        <label for="delivery_date_new">Teslimat Tarihi:</label>
                        <input type="date" id="delivery_date_new" name="delivery_date">
                    </div>
                    <div class="form-group">
                        <label for="notes_new">Notlar:</label>
                        <textarea id="notes_new" name="notes" rows="3"></textarea>
                    </div>
                    <button type="submit" name="add_shipment" class="btn btn-primary">Sevkiyat Oluştur</button>
                </form>
            <?php else : ?>
                <p>Yeni sevkiyat oluşturulabilecek faturalanmış sipariş bulunmamaktadır.</p>
            <?php endif; ?>
        </div>
    </div>

    <!-- Mevcut Sevkiyatlar Bölümü -->
    <div class="card">
        <div class="card-header">
            <h3>Mevcut Sevkiyatlar</h3>
        </div>
        <div class="card-body">
            <?php if (!empty($shipments)) : ?>
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Sipariş Kodu</th> <!-- Başlık olarak sipariş kodu -->
                            <th>Fatura Numarası</th> <!-- Fatura Numarası ayrı bir kolon -->
                            <th>Bayi Adı</th>
                            <th>Sipariş Durumu</th> <!-- Siparişin genel durumu -->
                            <th>Sevkiyat Durumu</th> <!-- Sevkiyatın kendi durumu -->
                            <th>İrsaliye No</th>
                            <th>Araç Plaka</th>
                            <th>Teslimat Tarihi</th>
                            <th>Oluşturma Tarihi</th>
                            <th>İşlemler</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($shipments as $shipment) : ?>
                            <tr>
                                <td><?php echo htmlspecialchars($shipment['id']); ?></td>
                                <td><?php echo htmlspecialchars($shipment['order_code'] ?: 'N/A'); ?></td>
                                <td><?php echo htmlspecialchars($shipment['invoice_number'] ?: 'N/A'); ?></td>
                                <td><?php echo htmlspecialchars($shipment['dealer_name']); ?></td>
                                <td><?php echo htmlspecialchars($shipment['order_main_status'] ?: 'N/A'); ?></td>
                                <td><?php echo htmlspecialchars($shipment['shipment_status'] ?: 'N/A'); ?></td>
                                <td><?php echo htmlspecialchars($shipment['waybill_number'] ?: 'N/A'); ?></td>
                                <td><?php echo htmlspecialchars($shipment['vehicle_plate'] ?: 'N/A'); ?></td>
                                <td><?php echo htmlspecialchars($shipment['delivery_date'] ? date('d.m.Y', strtotime($shipment['delivery_date'])) : 'N/A'); ?></td>
                                <td><?php echo htmlspecialchars(date('d.m.Y H:i', strtotime($shipment['created_at']))); ?></td>
                                <td>
                                    <a href="shipments.php?action=view&id=<?php echo htmlspecialchars($shipment['id']); ?>" class="btn btn-primary btn-sm">Görüntele</a>
                                    <a href="shipments.php?action=edit&id=<?php echo htmlspecialchars($shipment['id']); ?>" class="btn btn-warning btn-sm">Düzenle</a>
                                    <form action="shipments.php" method="post" style="display:inline-block;" onsubmit="return confirm('Bu sevkiyatı pasifize etmek istediğinizden emin misiniz?');">
                                        <input type="hidden" name="shipment_id" value="<?php echo htmlspecialchars($shipment['id']); ?>">
                                        <button type="submit" name="delete_shipment" class="btn btn-danger btn-sm">Pasif Yap</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else : ?>
                <p>Henüz sevkiyat kaydı bulunmamaktadır.</p>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($action == 'edit' && $shipment_details) : ?>
    <!-- Sevkiyat Düzenleme Modalı -->
    <div id="editShipmentModal" class="modal" style="display: block;">
        <div class="modal-content">
            <span class="close-button" onclick="window.location.href='shipments.php'">&times;</span>
            <h3>Sevkiyat Düzenle (<?php echo htmlspecialchars($shipment_details['order_code']); ?>)</h3>
            <form action="shipments.php" method="post">
                <input type="hidden" name="shipment_id" value="<?php echo htmlspecialchars($shipment_details['id']); ?>">
                <div class="form-group">
                    <label for="order_code_modal">Sipariş Kodu:</label>
                    <input type="text" id="order_code_modal" value="<?php echo htmlspecialchars($shipment_details['order_code']); ?>" disabled>
                </div>
                <div class="form-group">
                    <label for="invoice_number_modal">Fatura Numarası:</label>
                    <input type="text" id="invoice_number_modal" value="<?php echo htmlspecialchars($shipment_details['invoice_number'] ?: 'N/A'); ?>" disabled>
                </div>
                <div class="form-group">
                    <label for="dealer_name_modal">Bayi Adı:</label>
                    <input type="text" id="dealer_name_modal" value="<?php echo htmlspecialchars($shipment_details['dealer_name']); ?>" disabled>
                </div>
                <div class="form-group">
                    <label for="shipment_status_modal">Sevkiyat Durumu:</label>
                    <select id="shipment_status_modal" name="shipment_status" required>
                        <?php foreach ($shipment_statuses as $status) : ?>
                            <option value="<?php echo htmlspecialchars($status); ?>" <?php echo ($status == $shipment_details['shipment_status']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($status); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="waybill_number_modal">İrsaliye Numarası:</label>
                    <input type="text" id="waybill_number_modal" name="waybill_number" value="<?php echo htmlspecialchars($shipment_details['waybill_number']); ?>" required>
                </div>
                <div class="form-group">
                    <label for="vehicle_plate_modal">Araç Plakası:</label>
                    <input type="text" id="vehicle_plate_modal" name="vehicle_plate" value="<?php echo htmlspecialchars($shipment_details['vehicle_plate']); ?>">
                </div>
                <div class="form-group">
                    <label for="delivery_date_modal">Teslimat Tarihi:</label>
                    <input type="date" id="delivery_date_modal" name="delivery_date" value="<?php echo htmlspecialchars($shipment_details['delivery_date']); ?>">
                </div>
                <div class="form-group">
                    <label for="notes_modal">Notlar:</label>
                    <textarea id="notes_modal" name="notes" rows="3"><?php echo htmlspecialchars($shipment_details['notes']); ?></textarea>
                </div>
                <button type="submit" name="edit_shipment" class="btn btn-primary">Güncelle</button>
            </form>
        </div>
    </div>
    <?php elseif ($action == 'view' && $shipment_details) : ?>
    <!-- Sevkiyat Detay Görüntüleme Modalı -->
    <div id="viewShipmentModal" class="modal" style="display: block;">
        <div class="modal-content">
            <span class="close-button" onclick="window.location.href='shipments.php'">&times;</span>
            <h3>Sevkiyat Detayları (<?php echo htmlspecialchars($shipment_details['order_code']); ?>)</h3>
            <div class="detail-summary">
                <p><strong>Sipariş Kodu:</strong> <?php echo htmlspecialchars($shipment_details['order_code']); ?></p>
                <p><strong>Fatura Numarası:</strong> <?php echo htmlspecialchars($shipment_details['invoice_number'] ?: 'N/A'); ?></p>
                <p><strong>Bayi Adı:</strong> <?php echo htmlspecialchars($shipment_details['dealer_name']); ?></p>
                <p><strong>Sipariş Durumu:</strong> <?php echo htmlspecialchars($shipment_details['order_main_status'] ?: 'N/A'); ?></p>
                <p><strong>Sevkiyat Durumu:</strong> <?php echo htmlspecialchars($shipment_details['shipment_status'] ?: 'N/A'); ?></p>
                <p><strong>İrsaliye Numarası:</strong> <?php echo htmlspecialchars($shipment_details['waybill_number'] ?: 'N/A'); ?></p>
                <p><strong>Araç Plakası:</strong> <?php echo htmlspecialchars($shipment_details['vehicle_plate'] ?: 'N/A'); ?></p>
                <p><strong>Teslimat Tarihi:</strong> <?php echo htmlspecialchars($shipment_details['delivery_date'] ? date('d.m.Y', strtotime($shipment_details['delivery_date'])) : 'N/A'); ?></p>
                <p><strong>Notlar:</strong> <?php echo nl2br(htmlspecialchars($shipment_details['notes'] ?: 'N/A')); ?></p>
                <p><strong>Oluşturma Tarihi:</strong> <?php echo htmlspecialchars(date('d.m.Y H:i', strtotime($shipment_details['created_at']))); ?></p>
                <p><strong>Oluşturan:</strong> <?php echo htmlspecialchars($shipment_details['created_by_username']); ?> (<?php echo htmlspecialchars($shipment_details['created_by_role']); ?>)</p>
                <p><strong>Son Güncelleme Tarihi:</strong> <?php echo htmlspecialchars($shipment_details['updated_at'] ? date('d.m.Y H:i', strtotime($shipment_details['updated_at'])) : 'N/A'); ?></p>
                <p><strong>Son Güncelleyen:</strong> <?php echo htmlspecialchars($shipment_details['last_updated_by_username'] ?: 'N/A'); ?> (<?php echo htmlspecialchars($shipment_details['last_updated_by_username'] ? $shipment_details['last_updated_by_role'] : 'N/A'); ?>)</p>
            </div>
            <button type="button" onclick="window.location.href='shipments.php'" class="btn btn-secondary">Kapat</button>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- Modal için CSS (eğer style.css'te yoksa) -->
<style>
    .modal {
        display: none; /* Varsayılan olarak gizli */
        position: fixed; /* Sabit konum */
        z-index: 1000; /* En önde olmalı */
        left: 0;
        top: 0;
        width: 100%; /* Tam genişlik */
        height: 100%; /* Tam yükseklik */
        overflow: auto; /* İçerik taşarsa kaydırma çubuğu */
        background-color: rgba(0,0,0,0.4); /* Yarı şeffaf arka plan */
        padding-top: 60px;
    }

    .modal-content {
        background-color: #fefefe;
        margin: 5% auto; /* Yüzde 5 yukarıdan, ortalanmış */
        padding: 20px;
        border: 1px solid #888;
        width: 80%; /* Genişlik */
        max-width: 600px; /* Maksimum genişlik */
        border-radius: 8px;
        box-shadow: 0 4px 8px rgba(0,0,0,0.2);
        position: relative;
    }

    .close-button {
        color: #aaa;
        float: right;
        font-size: 28px;
        font-weight: bold;
    }

    .close-button:hover,
    .close-button:focus {
        color: black;
        text-decoration: none;
        cursor: pointer;
    }

    .card {
        background-color: #fff;
        border-radius: 8px;
        box-shadow: 0 2px 10px rgba(0, 0, 0, 0.08);
        margin-bottom: 20px;
    }

    .card-header {
        padding: 15px 20px;
        border-bottom: 1px solid #eee;
        background-color: #f9f9f9;
        border-top-left-radius: 8px;
        border-top-right-radius: 8px;
    }

    .card-header h3 {
        margin: 0;
        color: #3f51b5;
        border-bottom: none;
        padding-bottom: 0;
    }

    .card-body {
        padding: 20px;
    }

    .btn-sm {
        padding: 5px 10px;
        font-size: 14px;
    }
</style>

<?php include 'includes/footer.php'; ?>
