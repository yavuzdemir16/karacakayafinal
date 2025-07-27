<?php
// PHP hata raporlamasını açıyoruz. Geliştirme ortamında bu her zaman açık olmalı.
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start(); // Session'ı başlatıyoruz
require_once 'config.php'; // Veritabanı bağlantısı ve yardımcı fonksiyonlar

// Yetki kontrolü: Genel Müdür, Genel Müdür Yardımcısı, Muhasebe Müdürü erişebilir.
check_permission(['genel_mudur', 'genel_mudur_yardimcisi', 'muhasebe_muduru']);

// connect_db() fonksiyonu artık PDO nesnesi döndürüyor.
$pdo = connect_db(); // Bağlantı nesnesini $pdo olarak aldık

$action = isset($_GET['action']) ? $_GET['action'] : 'list'; // 'list', 'create', 'view', 'edit_payment'
$message = '';
$message_type = '';

// Firma ayarlarından varsayılan KDV oranını ve firma bilgilerini çek
$default_vat_rate = 0.00;
$company_settings = [
    'company_name' => 'Firma Adı Bilinmiyor',
    'address' => 'Adres Bilinmiyor',
    'phone' => 'Telefon Bilinmiyor',
    'email' => 'Email Bilinmiyor',
    'tax_office' => 'Vergi Dairesi Bilinmiyor',
    'tax_number' => 'Vergi Numarası Bilinmiyor',
    'logo_path' => null // Logo yolu
];

try {
    $stmt_settings = $pdo->query("SELECT company_name, address, phone, email, tax_office, tax_number, logo_path, default_vat_rate FROM company_settings WHERE id = 1 LIMIT 1");
    $settings_result = $stmt_settings->fetch(PDO::FETCH_ASSOC);
    if ($settings_result) {
        $default_vat_rate = floatval($settings_result['default_vat_rate']);
        $company_settings = array_merge($company_settings, $settings_result);
    }
} catch (PDOException $e) {
    error_log("Firma ayarları çekilirken hata: " . $e->getMessage());
    // Hata durumunda varsayılan değerlerle devam ederiz.
}

// Form gönderildiğinde işleme al
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    if (isset($_POST['create_invoice'])) {
        $order_id = $_POST['order_id'];
        $invoice_date = $_POST['invoice_date'];
        $due_date = $_POST['due_date'];
        $notes = trim($_POST['notes']);

        // Hata ayıklama için kullanıcı ID'sini kontrol edelim
        $current_user_id = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : null;
        if ($current_user_id === null) {
            $message = "Oturum bilgileri bulunamadı. Lütfen tekrar giriş yapın.";
            $message_type = 'error';
            goto end_post_processing_invoices; // İşlemi sonlandır
        }

        try {
            // Siparişin zaten faturalandırılıp faturalandırılmadığını kontrol et
            $stmt_check = $pdo->prepare("SELECT id, invoice_number FROM invoices WHERE order_id = ?");
            $stmt_check->execute([$order_id]);
            $existing_invoice = $stmt_check->fetch(PDO::FETCH_ASSOC);

            if ($existing_invoice) {
                $message = "Bu sipariş için zaten bir fatura oluşturulmuş. (Fatura No: " . htmlspecialchars($existing_invoice['invoice_number']) . ")";
                $message_type = 'error';
            } else {
                // Sipariş bilgilerini al (total_amount, order_status, invoice_status için)
                // orders.total_amount'ın burada KDV hariç tutar olduğunu varsayıyoruz.
                $stmt_order = $pdo->prepare("SELECT total_amount, order_status, invoice_status, payment_method, dealer_id FROM orders WHERE id = ?");
                $stmt_order->execute([$order_id]);
                $order_info = $stmt_order->fetch(PDO::FETCH_ASSOC);

                // Muhasebe onayından geçmiş (Onaylandı durumda) VE henüz faturalandırılmamış siparişler
                if (!$order_info || $order_info['order_status'] != 'Onaylandı' || $order_info['invoice_status'] != 'Beklemede') {
                    $message = "Fatura oluşturmak için sipariş bulunamadı veya faturalanmaya uygun durumda değil (Onaylanmamış veya zaten faturalanmış olabilir).";
                    $message_type = 'error';
                } else {
                    $subtotal_amount = $order_info['total_amount']; // Siparişin toplamı KDV hariç tutar

                    // KDV hesaplamaları
                    $vat_rate_used = $default_vat_rate; // Firma ayarlarından çekilen KDV oranı
                    $vat_amount = round($subtotal_amount * ($vat_rate_used / 100), 2);
                    $total_amount_with_vat = round($subtotal_amount + $vat_amount, 2);

                    $pdo->beginTransaction(); // İşlem başlat
                    try {
                        // Fatura numarası oluştur (basit bir örnek, gerçek sistemde daha gelişmiş olabilir)
                        $invoice_number = "INV-" . date("YmdHis") . "-" . $order_id;

                        // invoices tablosuna yeni fatura kaydı ekle (yeni kolonlar dahil)
                        $sql_invoice = "INSERT INTO invoices (order_id, invoice_number, invoice_date, due_date, subtotal_amount, vat_rate, vat_amount, total_amount, created_by, last_updated_by, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                        $stmt_invoice = $pdo->prepare($sql_invoice);
                        $stmt_invoice->execute([
                            $order_id, $invoice_number, $invoice_date, $due_date,
                            $subtotal_amount, $vat_rate_used, $vat_amount, $total_amount_with_vat,
                            $current_user_id, $current_user_id, $notes
                        ]);
                        $invoice_id = $pdo->lastInsertId();

                        // orders tablosundaki order_status ve invoice_status'u güncelle, fatura numarasını da kaydet
                        $sql_update_order = "UPDATE orders SET order_status = 'Faturalandı', invoice_status = 'Faturalandı', invoice_number = ?, last_updated_by = ? WHERE id = ?";
                        $stmt_update_order = $pdo->prepare($sql_update_order);
                        $stmt_update_order->execute([$invoice_number, $current_user_id, $order_id]);

                        $pdo->commit(); // İşlemi onayla
                        $message = "Fatura başarıyla oluşturuldu: " . $invoice_number;
                        $message_type = 'success';
                        $action = 'view';
                        $_GET['id'] = $invoice_id; // Yeni oluşturulan faturanın detayına git
                    } catch (Exception $e) {
                        $pdo->rollBack(); // Hata durumunda geri al
                        error_log("Fatura oluşturulurken hata oluştu: " . $e->getMessage());
                        $message = "Fatura oluşturulurken bir hata oluştu: " . $e->getMessage();
                        $message_type = 'error';
                        $action = 'create'; // Formu tekrar göster
                    }
                }
            }
        } catch (PDOException $e) {
            error_log("Fatura oluşturma işlemi genel veritabanı hatası: " . $e->getMessage());
            $message = "Veritabanı hatası: " . $e->getMessage();
            $message_type = 'error';
            $action = 'create'; // Formu tekrar göster
        }
    } elseif (isset($_POST['update_payment'])) {
        $invoice_id = $_POST['invoice_id'];
        $paid_amount = floatval($_POST['paid_amount']); // float'a çevir
        $notes = trim($_POST['notes']);

        $current_user_id = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : null;
        if ($current_user_id === null) {
            $message = "Oturum bilgileri bulunamadı. Lütfen tekrar giriş yapın.";
            $message_type = 'error';
            goto end_post_processing_invoices;
        }

        $pdo->beginTransaction(); // İşlem başlat
        try {
            // total_amount (KDV dahil toplam) üzerinden ödeme kontrolü yapılacak
            $stmt_invoice = $pdo->prepare("SELECT total_amount, paid_amount, order_id FROM invoices WHERE id = ? FOR UPDATE"); // FOR UPDATE ile kilit
            $stmt_invoice->execute([$invoice_id]);
            $invoice_info = $stmt_invoice->fetch(PDO::FETCH_ASSOC);

            if (!$invoice_info) {
                throw new Exception("Fatura bulunamadı.");
            }

            $old_paid_amount = $invoice_info['paid_amount'];
            $total_amount_kdv_dahil = $invoice_info['total_amount']; // KDV dahil fatura toplamı
            $order_id = $invoice_info['order_id'];

            $new_invoice_status = 'Ödenmedi';
            if ($paid_amount >= $total_amount_kdv_dahil) {
                $new_invoice_status = 'Ödendi';
            } elseif ($paid_amount > 0) {
                $new_invoice_status = 'Kısmen Ödendi';
            }

            // invoices tablosunu güncelle
            $sql_update = "UPDATE invoices SET paid_amount = ?, invoice_status = ?, last_updated_by = ?, notes = ? WHERE id = ?";
            $stmt_update = $pdo->prepare($sql_update);
            if (!$stmt_update->execute([$paid_amount, $new_invoice_status, $current_user_id, $notes, $invoice_id])) {
                throw new Exception("Fatura ödeme bilgileri güncellenirken bir hata oluştu.");
            }

            // orders tablosundaki invoice_status'u güncelle
            $sql_update_order_invoice_status = "UPDATE orders SET invoice_status = ?, last_updated_by = ? WHERE id = ?";
            $stmt_update_order_invoice_status = $pdo->prepare($sql_update_order_invoice_status);
            $stmt_update_order_invoice_status->execute([$new_invoice_status, $current_user_id, $order_id]);


            // Cari hesapta müşterinin borcunu güncelle
            $stmt_order_payment_method = $pdo->prepare("SELECT payment_method, dealer_id FROM orders WHERE id = ?");
            $stmt_order_payment_method->execute([$order_id]);
            $order_payment_info = $stmt_order_payment_method->fetch(PDO::FETCH_ASSOC);

            if ($order_payment_info && $order_payment_info['payment_method'] == 'Cari Hesap') {
                $debt_change = $paid_amount - $old_paid_amount; // Ödenen miktardaki değişim
                $sql_update_customer_debt = "UPDATE customers SET current_debt = current_debt - ? WHERE id = ?";
                $stmt_update_customer_debt = $pdo->prepare($sql_update_customer_debt);
                if (!$stmt_update_customer_debt->execute([$debt_change, $order_payment_info['dealer_id']])) {
                    throw new Exception("Müşteri borcu güncellenirken hata oluştu.");
                }
            }

            $pdo->commit(); // İşlemi onayla
            $message = "Fatura ödeme bilgileri başarıyla güncellendi. Yeni durum: " . $new_invoice_status;
            $message_type = 'success';
        } catch (Exception $e) {
            $pdo->rollBack(); // Hata durumunda geri al
            error_log("Fatura ödeme güncellenirken hata oluştu: " . $e->getMessage());
            $message = "Fatura ödeme bilgileri güncellenirken bir hata oluştu: " . $e->getMessage();
            $message_type = 'error';
        }
        $action = 'view';
        $_GET['id'] = $invoice_id; // Fatura detay sayfasına geri dön
    }
}
end_post_processing_invoices:;


// Fatura listeleme veya detay çekme
$invoices = [];
$invoice_details = null;

if ($action == 'list') {
    // KDV ile ilgili yeni kolonları sorguya ekliyoruz.
    $sql_invoices = "SELECT inv.id, inv.invoice_number, inv.invoice_date, inv.due_date, inv.subtotal_amount, inv.vat_rate, inv.vat_amount, inv.total_amount, inv.paid_amount, inv.invoice_status, inv.order_id,
                            o.order_date, o.order_code, c.customer_name as dealer_name,
                            u_created.username as created_by_username,
                            COALESCE(u_last_updated.username, 'N/A') as last_updated_by_username
                     FROM invoices inv
                     JOIN orders o ON inv.order_id = o.id
                     JOIN customers c ON o.dealer_id = c.id
                     JOIN users u_created ON inv.created_by = u_created.id
                     LEFT JOIN users u_last_updated ON inv.last_updated_by = u_last_updated.id
                     ORDER BY inv.invoice_date DESC";
    try {
        $stmt_invoices = $pdo->query($sql_invoices);
        $invoices = $stmt_invoices->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Fatura listesi çekilirken hata: " . $e->getMessage());
        $message = "Fatura listesi alınırken bir hata oluştu: " . $e->getMessage();
        $message_type = 'error';
    }
} elseif ($action == 'view' || $action == 'edit_payment') {
    if (isset($_GET['id'])) {
        $invoice_id = $_GET['id'];
        // invoices tablosundaki yeni kolonları sorguya ekliyoruz
        $sql_invoice_details = "SELECT inv.*, o.order_date, o.order_code, c.customer_name as dealer_name, c.address as dealer_address, c.phone as dealer_phone, c.email as dealer_email, c.tax_id as dealer_tax_id,
                                    u_created.username as created_by_name, r_created.role_name as created_by_role,
                                    COALESCE(u_last_updated.username, 'N/A') as last_updated_by_name
                               FROM invoices inv
                               JOIN orders o ON inv.order_id = o.id
                               JOIN customers c ON o.dealer_id = c.id
                               JOIN users u_created ON inv.created_by = u_created.id
                               JOIN roles r_created ON u_created.role_id = r_created.id
                               LEFT JOIN users u_last_updated ON inv.last_updated_by = u_last_updated.id
                               WHERE inv.id = ?";
        try {
            $stmt_invoice_details = $pdo->prepare($sql_invoice_details);
            $stmt_invoice_details->execute([$invoice_id]);
            $invoice_details = $stmt_invoice_details->fetch(PDO::FETCH_ASSOC);

            if ($invoice_details) {
                // Faturaya ait siparişin kalemlerini çek
                $invoice_details['order_items'] = [];
                $sql_order_items = "SELECT oi.*, p.product_name, p.sku FROM order_items oi JOIN products p ON oi.product_id = p.id WHERE oi.order_id = ?";
                $stmt_order_items = $pdo->prepare($sql_order_items);
                $stmt_order_items->execute([$invoice_details['order_id']]);
                $invoice_details['order_items'] = $stmt_order_items->fetchAll(PDO::FETCH_ASSOC);
            } else {
                $message = "Fatura bulunamadı veya görüntülemeye yetkiniz yok.";
                $message_type = 'error';
                $action = 'list';
            }
        } catch (PDOException $e) {
            error_log("Fatura detayları çekilirken hata: " . $e->getMessage());
            $message = "Fatura detayları alınırken bir hata oluştu: " . $e->getMessage();
            $message_type = 'error';
        }
    }
} elseif ($action == 'create') {
    // Fatura oluşturulabilecek, muhasebe onayından geçmiş (Onaylandı durumunda) VE henüz faturalanmamış siparişleri getir
    $approved_orders = [];
    $sql_approved_orders = "SELECT o.id, o.order_code, o.order_date, o.total_amount, c.customer_name as dealer_name
                            FROM orders o
                            JOIN customers c ON o.dealer_id = c.id
                            WHERE o.order_status = 'Onaylandı' AND o.invoice_status = 'Beklemede'
                            ORDER BY o.order_date DESC";
    try {
        $stmt_approved_orders = $pdo->query($sql_approved_orders);
        $approved_orders = $stmt_approved_orders->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Onaylı siparişler çekilirken hata: " . $e->getMessage());
        $message = "Onaylı siparişler alınırken bir hata oluştu: " . $e->getMessage();
        $message_type = 'error';
    }
}

// Sayfa başlığını ayarla
$page_title = "Faturalandırma Yönetimi";

// Header'ı dahil et
include 'includes/header.php';
?>

<div class="container">
    <h1>Faturalandırma Yönetimi</h1>

    <?php if (!empty($message)) : ?>
        <div class="message <?php echo $message_type; ?>"><?php echo $message; ?></div>
    <?php endif; ?>

    <?php if ($action == 'list') : ?>
        <p><a href="invoicing.php?action=create" class="btn btn-success">Yeni Fatura Oluştur</a></p>

        <table>
            <thead>
                <tr>
                    <th>Fatura No</th>
                    <th>Sipariş Kodu</th>
                    <th>Bayi Adı</th>
                    <th>Sipariş Tarihi</th>
                    <th>Fatura Tarihi</th>
                    <th>KDV Hariç Tutar</th>
                    <th>KDV Oranı</th>
                    <th>KDV Tutarı</th>
                    <th>Toplam Tutar (KDV Dahil)</th>
                    <th>Ödenen Tutar</th>
                    <th>Durum</th>
                    <th>Oluşturan</th>
                    <th>İşlemler</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($invoices)) : ?>
                    <?php foreach ($invoices as $invoice) : ?>
                        <tr>
                            <td><?php echo htmlspecialchars($invoice['invoice_number']); ?></td>
                            <td><?php echo htmlspecialchars($invoice['order_code'] ?: 'N/A'); ?></td>
                            <td><?php echo htmlspecialchars($invoice['dealer_name']); ?></td>
                            <td><?php echo date('d.m.Y', strtotime($invoice['order_date'])); ?></td>
                            <td><?php echo date('d.m.Y', strtotime($invoice['invoice_date'])); ?></td>
                            <td><?php echo number_format($invoice['subtotal_amount'], 2); ?> TL</td>
                            <td>%<?php echo number_format($invoice['vat_rate'], 2); ?></td>
                            <td><?php echo number_format($invoice['vat_amount'], 2); ?> TL</td>
                            <td><?php echo number_format($invoice['total_amount'], 2); ?> TL</td>
                            <td><?php echo number_format($invoice['paid_amount'], 2); ?> TL</td>
                            <td><span class="status-badge status-<?php echo str_replace(' ', '.', htmlspecialchars($invoice['invoice_status'])); ?>"><?php echo htmlspecialchars($invoice['invoice_status']); ?></span></td>
                            <td><?php echo htmlspecialchars($invoice['created_by_username']); ?></td>
                            <td>
                                <a href='invoicing.php?action=view&id=<?php echo $invoice['id']; ?>' class='btn btn-primary'>Detay</a>
                                <a href='invoicing.php?action=edit_payment&id=<?php echo $invoice['id']; ?>' class='btn btn-warning'>Ödeme Güncelle</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else : ?>
                    <tr><td colspan='13'>Henüz hiç fatura yok.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>

    <?php elseif ($action == 'create') : ?>
        <h2>Yeni Fatura Oluştur</h2>
        <form action="invoicing.php" method="post">
            <div class="form-group">
                <label for="order_id">Sipariş Seçin:</label>
                <select id="order_id" name="order_id" required>
                    <option value="">Faturalandırılacak Sipariş Seçin</option>
                    <?php if (!empty($approved_orders)) : ?>
                        <?php foreach ($approved_orders as $order) : ?>
                            <option value="<?php echo htmlspecialchars($order['id']); ?>">
                                <?php echo htmlspecialchars($order['dealer_name']); ?> - Sipariş Kodu: <?php echo htmlspecialchars($order['order_code']); ?> (<?php echo number_format($order['total_amount'], 2); ?> TL KDV Hariç) - Tarih: <?php echo date('d.m.Y', strtotime($order['order_date'])); ?>
                            </option>
                        <?php endforeach; ?>
                    <?php else : ?>
                        <option value="" disabled>Faturalandırılmayı bekleyen onaylı sipariş bulunmamaktadır.</option>
                    <?php endif; ?>
                </select>
            </div>
            <div class="form-group">
                <label for="invoice_date">Fatura Tarihi:</label>
                <input type="date" id="invoice_date" name="invoice_date" value="<?php echo date('Y-m-d'); ?>" required>
            </div>
            <div class="form-group">
                <label for="due_date">Son Ödeme Tarihi:</label>
                <input type="date" id="due_date" name="due_date" value="<?php echo date('Y-m-d', strtotime('+30 days')); ?>">
            </div>
             <div class="form-group">
                <label>Uygulanacak KDV Oranı:</label>
                <input type="text" value="%<?php echo number_format($default_vat_rate, 2); ?>" disabled>
                <small>Bu oran "Ayarlar > Firma Bilgileri" kısmından gelmektedir.</small>
            </div>
            <div class="form-group">
                <label for="notes">Notlar:</label>
                <textarea id="notes" name="notes"></textarea>
            </div>
            <div class="form-group">
                <button type="submit" name="create_invoice" class="btn btn-primary" <?php echo empty($approved_orders) ? 'disabled' : ''; ?>>Fatura Oluştur</button>
                <a href="invoicing.php" class="btn btn-secondary">İptal</a>
            </div>
        </form>

    <?php elseif ($action == 'view' && $invoice_details) : ?>
        <h2>Fatura Detayı (Fatura No: <?php echo htmlspecialchars($invoice_details['invoice_number']); ?>)</h2>

        <!-- Firma Bilgileri Kısmı -->
        <div class="company-info-box">
            <?php if (!empty($company_settings['logo_path'])) : ?>
                <img src="<?php echo htmlspecialchars($company_settings['logo_path']); ?>" alt="<?php echo htmlspecialchars($company_settings['company_name']); ?> Logo" style="max-width: 150px; margin-bottom: 10px;">
            <?php endif; ?>
            <h3><?php echo htmlspecialchars($company_settings['company_name']); ?></h3>
            <p><?php echo nl2br(htmlspecialchars($company_settings['address'])); ?></p>
            <p>Tel: <?php echo htmlspecialchars($company_settings['phone']); ?></p>
            <p>E-posta: <?php echo htmlspecialchars($company_settings['email']); ?></p>
            <p>Vergi Dairesi: <?php echo htmlspecialchars($company_settings['tax_office']); ?></p>
            <p>Vergi No: <?php echo htmlspecialchars($company_settings['tax_number']); ?></p>
        </div>
        <hr> <!-- Firma bilgileri ile fatura detayı arasına ayırıcı -->


        <div class="order-summary">
            <p><strong>Fatura Numarası:</strong> <?php echo htmlspecialchars($invoice_details['invoice_number']); ?></p>
            <p><strong>Sipariş Kodu:</strong> <?php echo htmlspecialchars($invoice_details['order_code'] ?: 'N/A'); ?></p>
            <p><strong>Fatura Tarihi:</strong> <?php echo date('d.m.Y', strtotime($invoice_details['invoice_date'])); ?></p>
            <p><strong>Son Ödeme Tarihi:</strong> <?php echo date('d.m.Y', strtotime($invoice_details['due_date'])); ?></p>
            <p><strong>Durum:</strong> <span class="status-badge status-<?php echo str_replace(' ', '.', htmlspecialchars($invoice_details['invoice_status'])); ?>"><?php echo htmlspecialchars($invoice_details['invoice_status']); ?></span></p>
            <p><strong>Sipariş ID:</strong> <a href="orders.php?action=view&id=<?php echo htmlspecialchars($invoice_details['order_id']); ?>"><?php echo htmlspecialchars($invoice_details['order_id']); ?></a></p>
            <p><strong>Bayi:</strong> <?php echo htmlspecialchars($invoice_details['dealer_name']); ?></p>
            <p><strong>Bayi Adresi:</strong> <?php echo nl2br(htmlspecialchars($invoice_details['dealer_address'])); ?></p>
            <p><strong>Bayi Telefon:</strong> <?php echo htmlspecialchars($invoice_details['dealer_phone']); ?></p>
            <p><strong>Bayi E-posta:</strong> <?php echo htmlspecialchars($invoice_details['dealer_email']); ?></p>
            <p><strong>Bayi Vergi No:</strong> <?php echo htmlspecialchars($invoice_details['dealer_tax_id']); ?></p>

            <hr> <!-- Ayırıcı çizgi -->
            <p><strong>KDV Hariç Tutar:</strong> <?php echo number_format($invoice_details['subtotal_amount'], 2); ?> TL</p>
            <p><strong>Uygulanan KDV Oranı:</strong> %<?php echo number_format($invoice_details['vat_rate'], 2); ?></p>
            <p><strong>KDV Tutarı:</strong> <?php echo number_format($invoice_details['vat_amount'], 2); ?> TL</p>
            <p><strong>Toplam Tutar (KDV Dahil):</strong> <?php echo number_format($invoice_details['total_amount'], 2); ?> TL</p>
            <p><strong>Ödenen Tutar:</strong> <?php echo number_format($invoice_details['paid_amount'], 2); ?> TL</p>
            <p><strong>Kalan Tutar:</strong> <?php echo number_format($invoice_details['total_amount'] - $invoice_details['paid_amount'], 2); ?> TL</p>
            <hr> <!-- Ayırıcı çizgi -->

            <p><strong>Oluşturan:</strong> <?php echo htmlspecialchars($invoice_details['created_by_name']); ?> (<?php echo htmlspecialchars($invoice_details['created_by_role']); ?>)</p>
            <?php if (!empty($invoice_details['last_updated_by_name'])) : ?>
                <p><strong>Son Güncelleyen:</strong> <?php echo htmlspecialchars($invoice_details['last_updated_by_name']); ?></p>
            <?php endif; ?>
            <?php if (!empty($invoice_details['notes'])) : ?>
                <p><strong>Notlar:</strong> <?php echo nl2br(htmlspecialchars($invoice_details['notes'])); ?></p>
            <?php endif; ?>
        </div>

        <h3>Faturalandırılan Ürünler</h3>
        <table class="order-items-table">
            <thead>
                <tr>
                    <th>Ürün Kodu</th>
                    <th>Ürün Adı</th>
                    <th>Adet</th>
                    <th>Birim Fiyat</th>
                    <th>Toplam Fiyat</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($invoice_details['order_items'])) : ?>
                    <?php foreach ($invoice_details['order_items'] as $item) : ?>
                        <tr>
                            <td><?php echo htmlspecialchars($item['sku']); ?></td>
                            <td><?php echo htmlspecialchars($item['product_name']); ?></td>
                            <td><?php echo htmlspecialchars($item['quantity']); ?></td>
                            <td><?php echo number_format($item['unit_price'], 2); ?> TL</td>
                            <td><?php echo number_format($item['total_price'], 2); ?> TL</td>
                        </tr>
                    <?php endforeach; ?>
                <?php else : ?>
                    <tr><td colspan='5'>Faturalandırılan ürün kalemi bulunamadı.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>

        <div style="margin-top: 30px;">
            <h4>Fatura İşlemleri</h4>
            <a href='invoicing.php?action=edit_payment&id=<?php echo htmlspecialchars($invoice_details['id']); ?>' class='btn btn-warning'>Ödeme Güncelle</a>
            <button type="button" class="btn btn-info" onclick="window.print()">Fatura Çıktısı Al</button>
        </div>

        <p style="margin-top: 30px;"><a href="invoicing.php" class="btn btn-secondary">Fatura Listesine Geri Dön</a></p>

    <?php elseif ($action == 'edit_payment' && $invoice_details) : ?>
        <h2>Fatura Ödeme Güncelle (Fatura No: <?php echo htmlspecialchars($invoice_details['invoice_number']); ?>)</h2>
        <form action="invoicing.php" method="post">
            <input type="hidden" name="invoice_id" value="<?php echo htmlspecialchars($invoice_details['id']); ?>">
            <div class="form-group">
                <label for="total_amount_display">Fatura Tutarı (KDV Dahil):</label>
                <input type="text" id="total_amount_display" value="<?php echo number_format($invoice_details['total_amount'], 2); ?> TL" disabled>
            </div>
            <div class="form-group">
                <label for="paid_amount">Ödenen Tutar:</label>
                <input type="number" id="paid_amount" name="paid_amount" step="0.01" min="0" value="<?php echo htmlspecialchars($invoice_details['paid_amount']); ?>" required>
            </div>
            <div class="form-group">
                <label for="notes">Notlar:</label>
                <textarea id="notes" name="notes"><?php echo htmlspecialchars($invoice_details['notes']); ?></textarea>
            </div>
            <div class="form-group">
                <button type="submit" name="update_payment" class="btn btn-primary">Ödemeyi Güncelle</button>
                <a href="invoicing.php?action=view&id=<?php echo htmlspecialchars($invoice_details['id']); ?>" class="btn btn-secondary">İptal</a>
            </div>
        </form>

    <?php else : ?>
        <div class="message error">Geçersiz işlem veya fatura bulunamadı.</div>
        <p><a href="invoicing.php" class="btn btn-secondary">Fatura Listesine Geri Dön</a></p>
    <?php endif; ?>
</div>

<?php
// Footer'ı dahil et
include 'includes/footer.php';
?>
