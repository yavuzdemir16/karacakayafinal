<?php
session_start();
require_once 'config.php'; // Veritabanı bağlantısı ve yardımcı fonksiyonlar

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Bu sayfaya erişebilecek roller: Genel Müdür (1), Genel Müdür Yardımcısı (2), Satış Müdürü (3), Muhasebe Müdürü (4)
check_permission(['genel_mudur', 'genel_mudur_yardimcisi', 'satis_muduru', 'muhasebe_muduru']);

$pdo = connect_db(); // PDO bağlantısını al

$action = isset($_GET['action']) ? $_GET['action'] : 'list_customers'; // Varsayılan aksiyon: müşteri listesi
$message = '';
$message_type = '';

// Şirket Ayarlarını Çek (Yazdırma görünümleri için)
$company_settings = [];
try {
    $stmt_company = $pdo->query("SELECT * FROM company_settings WHERE id = 1"); // Genellikle tek bir ayar satırı olur
    $company_settings = $stmt_company->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Şirket ayarları çekilirken hata: " . $e->getMessage());
    // Hata durumunda boş bırak, çıktıda "Bilgi Yok" yazsın
}


// KULLANICI ID'SİNİ DOĞRU ŞEKİLDE ALDIĞIMIZDAN EMİN OLALIM
$current_user_id = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : (isset($_SESSION['user_user_id']) ? $_SESSION['user_user_id'] : null);


// POST İsteklerinin İşlenmesi
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    if ($current_user_id === null) {
        $message = "Oturum bilgileri bulunamadı. Lütfen tekrar giriş yapın.";
        $message_type = 'error';
        // Hata durumunda action ve id'yi koruyarak sayfayı yenileyelim ki form verileri kaybolmasın.
        if (isset($_POST['customer_id'])) {
            $action = 'view_customer_account';
            $_GET['id'] = intval($_POST['customer_id']);
        } else {
            $action = 'list_customers';
        }
        goto end_post_processing; // Bu satır, POST işlemlerinin geri kalanını atlar.
    }

    if (isset($_POST['add_payment'])) {
        $customer_id = intval($_POST['customer_id']);
        $payment_date = $_POST['payment_date'];
        $amount = floatval($_POST['amount']);
        $payment_method = trim($_POST['payment_method']);
        $notes = trim($_POST['notes']);
        $payment_for_debt_type = $_POST['payment_for_debt_type']; // 'normal' veya 'fixed_asset'

        if (empty($customer_id) || $amount <= 0 || empty($payment_date)) {
            $message = "Tüm zorunlu alanları doldurun ve geçerli bir miktar girin.";
            $message_type = 'error';
            $action = 'view_customer_account'; // Aynı sayfada kal
            $_GET['id'] = $customer_id; // Müşteri ID'sini tekrar gönder
        } else {
            try {
                $pdo->beginTransaction();

                // 1. Ödemeyi customer_payments tablosuna kaydet
                // Hangi borç türüne ödendiğini de notlara ekle
                $payment_notes = "Ödeme Yöntemi: " . $payment_method . ". Not: " . $notes;
                if ($payment_for_debt_type == 'normal') {
                    $payment_notes = "Normal Ürün Borcu için " . $payment_notes;
                } elseif ($payment_for_debt_type == 'fixed_asset') {
                    $payment_notes = "Demirbaş Borcu için " . $payment_notes;
                }

                $stmt_payment = $pdo->prepare("INSERT INTO customer_payments (customer_id, payment_date, amount, payment_method, notes, created_by, debt_type_paid_for) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $stmt_payment->execute([$customer_id, $payment_date, $amount, $payment_method, $payment_notes, $current_user_id, $payment_for_debt_type]);

                // 2. Müşterinin ilgili current_debt'ini güncelle (Borç azaldığı için çıkarma işlemi)
                if ($payment_for_debt_type == 'normal') {
                    $stmt_customer_debt = $pdo->prepare("UPDATE customers SET current_debt = current_debt - ?, last_payment_date = ?, last_payment_amount = ? WHERE id = ?");
                } elseif ($payment_for_debt_type == 'fixed_asset') {
                    $stmt_customer_debt = $pdo->prepare("UPDATE customers SET fixed_asset_current_debt = fixed_asset_current_debt - ?, last_payment_date = ?, last_payment_amount = ? WHERE id = ?");
                } else {
                    throw new Exception("Geçersiz ödeme borç tipi.");
                }
                $stmt_customer_debt->execute([$amount, $payment_date, $amount, $customer_id]);

                $pdo->commit();
                $message = "Ödeme başarıyla kaydedildi ve müşteri bakiyesi güncellendi.";
                $message_type = 'success';
                $action = 'view_customer_account';
                $_GET['id'] = $customer_id;
            } catch (PDOException $e) {
                $pdo->rollBack();
                error_log("Ödeme kaydedilirken hata: " . $e->getMessage());
                $message = "Ödeme kaydedilirken bir hata oluştu: " . $e->getMessage();
                $message_type = 'error';
                $action = 'view_customer_account';
                $_GET['id'] = $customer_id;
            } catch (Exception $e) {
                $pdo->rollBack();
                error_log("Ödeme kaydedilirken mantıksal hata: " . $e->getMessage());
                $message = "Ödeme kaydedilirken bir hata oluştu: " . $e->getMessage();
                $message_type = 'error';
                $action = 'view_customer_account';
                $_GET['id'] = $customer_id;
            }
        }
    } elseif (isset($_POST['update_credit_limits'])) { // Tek bir formdan iki limiti de güncelle
        $customer_id = intval($_POST['customer_id']);
        $credit_limit_normal = floatval($_POST['credit_limit_normal']);
        $credit_limit_fixed_asset = floatval($_POST['credit_limit_fixed_asset']);

        if (empty($customer_id) || $credit_limit_normal < 0 || $credit_limit_fixed_asset < 0) {
            $message = "Geçerli bir müşteri ve pozitif kredi limitleri girin.";
            $message_type = 'error';
            $action = 'view_customer_account';
            $_GET['id'] = $customer_id;
        } else {
            try {
                $stmt = $pdo->prepare("UPDATE customers SET credit_limit = ?, fixed_asset_credit_limit = ?, last_updated_by = ?, updated_at = NOW() WHERE id = ?");
                $stmt->execute([$credit_limit_normal, $credit_limit_fixed_asset, $current_user_id, $customer_id]);
                $message = "Kredi limitleri başarıyla güncellendi.";
                $message_type = 'success';
                $action = 'view_customer_account';
                $_GET['id'] = $customer_id;
            } catch (PDOException $e) {
                error_log("Kredi limitleri güncellenirken hata: " . $e->getMessage());
                $message = "Kredi limitleri güncellenirken bir hata oluştu: " . $e->getMessage();
                $message_type = 'error';
                $action = 'view_customer_account';
                $_GET['id'] = $customer_id;
            }
        }
    }
}
end_post_processing:;


// GET İsteklerinin ve Veri Çekme İşlemlerinin İşlenmesi
$customers = [];
$customer_detail = null;
$customer_transactions = []; // Ekstre için tüm hareketler

// Filtreleme ve sıralama parametrelerini al
$search_name = isset($_GET['search_name']) ? trim($_GET['search_name']) : '';
$debt_status_filter = isset($_GET['debt_status_filter']) ? $_GET['debt_status_filter'] : 'all'; // 'all', 'debtor', 'creditor' (borçlu, alacaklı)
$is_active_filter = isset($_GET['is_active']) ? $_GET['is_active'] : ''; // '1', '0', 'all'
$sort_by = isset($_GET['sort_by']) ? $_GET['sort_by'] : 'customer_name';
$sort_order = isset($_GET['sort_order']) ? $_GET['sort_order'] : 'ASC';

// Varsayılan sıralama sütunlarını güvenli hale getir
$allowed_sort_columns = ['customer_name', 'current_debt', 'fixed_asset_current_debt'];
if (!in_array($sort_by, $allowed_sort_columns)) {
    $sort_by = 'customer_name';
}
$allowed_sort_orders = ['ASC', 'DESC'];
if (!in_array(strtoupper($sort_order), $allowed_sort_orders)) {
    $sort_order = 'ASC';
}


// Müşteri listesi çekme fonksiyonu (Filtreleme ve sıralama için ortak)
function get_filtered_customers($pdo, $search_name, $debt_status_filter, $is_active_filter, $sort_by, $sort_order) {
    $sql_customers = "SELECT c.id, c.customer_name, c.phone, c.email, c.credit_limit, c.current_debt, c.fixed_asset_credit_limit, c.fixed_asset_current_debt, c.is_active,
                             c.last_payment_date, c.last_payment_amount
                      FROM customers c";
    $conditions = [];
    $params = [];

    if (!empty($search_name)) {
        $conditions[] = "c.customer_name LIKE ?";
        $params[] = '%' . $search_name . '%';
    }

    if ($debt_status_filter == 'debtor') { // Borçlu olanlar (normal ürün veya demirbaş)
        $conditions[] = "(c.current_debt > 0 OR c.fixed_asset_current_debt > 0)";
    } elseif ($debt_status_filter == 'creditor') { // Alacaklı olanlar (normal ürün veya demirbaş)
        $conditions[] = "(c.current_debt < 0 OR c.fixed_asset_current_debt < 0)";
    }
    // 'all' için ek koşul yok

    if ($is_active_filter !== '' && $is_active_filter !== 'all') {
        $conditions[] = "c.is_active = ?";
        $params[] = intval($is_active_filter);
    }

    if (!empty($conditions)) {
        $sql_customers .= " WHERE " . implode(" AND ", $conditions);
    }

    $sql_customers .= " ORDER BY {$sort_by} {$sort_order}";

    try {
        $stmt_customers = $pdo->prepare($sql_customers);
        $stmt_customers->execute($params);
        return $stmt_customers->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Müşteri listesi çekilirken hata: " . $e->getMessage());
        return [];
    }
}


if ($action == 'list_customers' || $action == 'print_customer_list') { // print_customer_list için de aynı liste çekilecek
    $customers = get_filtered_customers($pdo, $search_name, $debt_status_filter, $is_active_filter, $sort_by, $sort_order);

    // Toplamları hesapla (Normal Ürün Borçları)
    $total_current_debt_normal = 0;
    $total_credit_limit_normal = 0;
    $total_remaining_limit_normal = 0;

    // Toplamları hesapla (Demirbaş Borçları)
    $total_current_debt_fixed_asset = 0;
    $total_credit_limit_fixed_asset = 0;
    $total_remaining_limit_fixed_asset = 0;


    foreach ($customers as $customer) {
        $total_current_debt_normal += $customer['current_debt'];
        $total_credit_limit_normal += $customer['credit_limit'];
        $total_remaining_limit_normal += ($customer['credit_limit'] - $customer['current_debt']);

        $total_current_debt_fixed_asset += $customer['fixed_asset_current_debt'];
        $total_credit_limit_fixed_asset += $customer['fixed_asset_credit_limit'];
        $total_remaining_limit_fixed_asset += ($customer['fixed_asset_credit_limit'] - $customer['fixed_asset_current_debt']);
    }

} elseif ($action == 'view_customer_account' || $action == 'print_statement') { // Hem görüntüleme hem de yazdırma için aynı veri çekilecek
    $customer_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
    if ($customer_id > 0) {
        // Müşteri detayını çek - Şimdi tüm kolonları çekiyoruz
        $stmt_customer = $pdo->prepare("SELECT id, customer_name, credit_limit, current_debt, fixed_asset_credit_limit, fixed_asset_current_debt, address, phone, email, tax_id, tax_office FROM customers WHERE id = ?");
        $stmt_customer->execute([$customer_id]);
        $customer_detail = $stmt_customer->fetch(PDO::FETCH_ASSOC);

        if ($customer_detail) {
            // Müşterinin tüm finansal hareketlerini çek (Ekstre)
            // Ödemeler (Giriş - Alacak) - debt_type_paid_for kolonunu da çekiyoruz
            $sql_payments = "SELECT 'Ödeme' as type, payment_date as date, amount, 'TL' as currency, notes as notes,
                                    payment_method as transaction_detail, '' as document_number, '' as order_code, created_at, 'Alacak' as debt_credit_type,
                                    debt_type_paid_for as payment_debt_type
                             FROM customer_payments WHERE customer_id = ?";
            // Faturalar (Çıkış - Borç)
            // orders tablosundaki payment_method'a göre borç tipini belirleyeceğiz
            $sql_invoices = "SELECT 'Fatura' as type, inv.invoice_date as date, inv.total_amount as amount, 'TL' as currency, inv.notes as notes,
                                    '' as transaction_detail, inv.invoice_number as document_number, ord.order_code, inv.created_at, 'Borç' as debt_credit_type,
                                    CASE
                                        WHEN EXISTS (SELECT 1 FROM order_items oi JOIN products p ON oi.product_id = p.id WHERE oi.order_id = inv.order_id AND p.product_type = 'Demirbas') THEN 'fixed_asset'
                                        ELSE 'normal'
                                    END as invoice_debt_type
                             FROM invoices inv
                             JOIN orders ord ON inv.order_id = ord.id
                             WHERE ord.dealer_id = ? AND inv.invoice_status != 'İptal Edildi'";

            // Tüm hareketleri birleştir ve tarihe göre sırala
            // UNION ALL ile birleştirdikten sonra ORDER BY yapmak daha doğru.
            $sql_transactions = "
                SELECT * FROM (
                    ($sql_payments)
                    UNION ALL
                    ($sql_invoices)
                ) AS transactions
                ORDER BY date ASC, created_at ASC
            ";
            $stmt_transactions = $pdo->prepare($sql_transactions);
            // customer_id parametresini iki kez göndermemiz gerekiyor, her bir alt sorgu için birer tane
            $stmt_transactions->execute([$customer_id, $customer_id]);
            $customer_transactions = $stmt_transactions->fetchAll(PDO::FETCH_ASSOC);

            // Başlangıç bakiyelerini hesapla (normal ve demirbaş için ayrı ayrı)
            $current_system_debt_normal = $customer_detail['current_debt'];
            $current_system_debt_fixed_asset = $customer_detail['fixed_asset_current_debt'];

            $total_transactions_effect_normal = 0;
            $total_transactions_effect_fixed_asset = 0;

            foreach ($customer_transactions as $tr) {
                $transaction_debt_type = $tr['type'] == 'Fatura' ? ($tr['invoice_debt_type'] ?? 'normal') : ($tr['payment_debt_type'] ?? 'normal');

                if ($tr['debt_credit_type'] == 'Borç') { // Fatura
                    if ($transaction_debt_type == 'normal') {
                        $total_transactions_effect_normal += $tr['amount'];
                    } elseif ($transaction_debt_type == 'fixed_asset') {
                        $total_transactions_effect_fixed_asset += $tr['amount'];
                    }
                } else { // Ödeme
                    if ($transaction_debt_type == 'normal') {
                        $total_transactions_effect_normal -= $tr['amount'];
                    } elseif ($transaction_debt_type == 'fixed_asset') {
                        $total_transactions_effect_fixed_asset -= $tr['amount'];
                    }
                }
            }
            $initial_balance_normal = $current_system_debt_normal - $total_transactions_effect_normal;
            $initial_balance_fixed_asset = $current_system_debt_fixed_asset - $total_transactions_effect_fixed_asset;


        } else {
            $message = "Müşteri bulunamadı.";
            $message_type = 'error';
            $action = 'list_customers';
        }
    } else {
        $message = "Geçersiz müşteri ID'si.";
        $message_type = 'error';
        $action = 'list_customers';
    }
}


$page_title = "Cari Hesap Yönetimi";

// Eğer action 'print_statement' veya 'print_customer_list' ise, header ve footer'ı dahil etme
if ($action != 'print_statement' && $action != 'print_customer_list') {
    include 'includes/header.php'; // Header dosyasını dahil et
}
?>

<?php if ($action == 'print_statement' && $customer_detail) : ?>
    <!DOCTYPE html>
    <html lang="tr">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title><?php echo htmlspecialchars($customer_detail['customer_name']); ?> - Cari Ekstre</title>
        <style>
            body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; margin: 0; padding: 20px; font-size: 12px; }
            .container { width: 210mm; min-height: 297mm; margin: 0 auto; padding: 15mm; border: 1px solid #eee; box-shadow: 0 0 10px rgba(0,0,0,0.05); }
            .header, .footer { text-align: center; margin-bottom: 20px; }
            .header h1 { margin: 0; font-size: 24px; color: #333; }
            .header p { margin: 2px 0; font-size: 10px; color: #666; }
            .customer-info, .company-info { border: 1px solid #ddd; padding: 10px; margin-bottom: 20px; display: flex; justify-content: space-between; align-items: flex-start; }
            .customer-info div, .company-info div { flex: 1; padding: 0 10px; }
            .customer-info strong, .company-info strong { display: block; margin-bottom: 5px; color: #555; }
            .statement-details table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
            .statement-details th, .statement-details td { border: 1px solid #ddd; padding: 8px; text-align: left; }
            .statement-details th { background-color: #f2f2f2; font-weight: bold; }
            .statement-details td.text-right { text-align: right; }
            .summary-table { width: 50%; float: right; margin-top: 10px; }
            .summary-table th, .summary-table td { border: none; padding: 5px 8px; text-align: right; }
            .summary-table th { font-weight: bold; }
            .signature-area { margin-top: 50px; display: flex; justify-content: space-around; text-align: center; }
            .signature-area div { width: 45%; }
            .signature-area p { border-top: 1px solid #aaa; padding-top: 5px; margin-top: 30px; }

            /* Print Specific Styles */
            @media print {
                body { margin: 0; padding: 0; font-size: 10px; }
                .container { width: 100%; min-height: auto; border: none; box-shadow: none; margin: 0; padding: 0; }
                .header, .footer { page-break-after: avoid; }
                .statement-details table { page-break-inside: auto; }
                .statement-details tr { page-break-inside: avoid; page-break-after: auto; }
                .no-print { display: none !important; }
            }
            .clear-float::after { content: ""; clear: both; display: table; }
            .balance-row { background-color: #e6f7ff; font-weight: bold; }
            .debt { color: red; }
            .credit { color: green; }
            .debt-type-badge {
                display: inline-block;
                padding: 2px 5px;
                background-color: #007bff;
                color: white;
                border-radius: 3px;
                font-size: 0.8em;
                margin-left: 5px;
            }
            .fixed-asset-badge {
                background-color: #28a745;
            }
        </style>
    </head>
    <body onload="window.print();">
        <div class="container">
            <div class="header">
                <h1><?php echo htmlspecialchars($company_settings['company_name'] ?? 'Şirket Adı'); ?></h1>
                <p>Adres: <?php echo htmlspecialchars($company_settings['address'] ?? 'Bilgi Yok'); ?></p>
                <p>Telefon: <?php echo htmlspecialchars($company_settings['phone'] ?? 'Bilgi Yok'); ?> | E-posta: <?php echo htmlspecialchars($company_settings['email'] ?? 'Bilgi Yok'); ?></p>
                <p>Vergi Dairesi: <?php echo htmlspecialchars($company_settings['tax_office'] ?? 'Bilgi Yok'); ?> | Vergi No: <?php echo htmlspecialchars($company_settings['tax_number'] ?? 'Bilgi Yok'); ?></p>
                <h3>Cari Hesap Ekstresi</h3>
                <p>Ekstre Tarihi: <?php echo date('d.m.Y'); ?></p>
            </div>

            <div class="company-info">
                <div>
                    <strong>Şirket Bilgileri</strong>
                    <p>Adı: <?php echo htmlspecialchars($company_settings['company_name'] ?? 'Şirket Adı'); ?></p>
                    <p>Adres: <?php echo htmlspecialchars($company_settings['address'] ?? 'Bilgi Yok'); ?></p>
                    <p>Telefon: <?php echo htmlspecialchars($company_settings['phone'] ?? 'Bilgi Yok'); ?></p>
                    <p>Vergi No: <?php echo htmlspecialchars($company_settings['tax_number'] ?? 'Bilgi Yok'); ?></p>
                </div>
                <div>
                    <strong>Müşteri Bilgileri</strong>
                    <p>Adı: <?php echo htmlspecialchars($customer_detail['customer_name']); ?></p>
                    <p>Adres: <?php echo htmlspecialchars($customer_detail['address'] ?: 'Bilgi Yok'); ?></p>
                    <p>Telefon: <?php echo htmlspecialchars($customer_detail['phone'] ?: 'Bilgi Yok'); ?></p>
                    <p>E-posta: <?php echo htmlspecialchars($customer_detail['email'] ?: 'Bilgi Yok'); ?></p>
                    <p>Vergi Dairesi: <?php echo htmlspecialchars($customer_detail['tax_office'] ?: 'Bilgi Yok'); ?></p>
                    <p>Vergi No: <?php echo htmlspecialchars($customer_detail['tax_id'] ?: 'Bilgi Yok'); ?></p>
                </div>
            </div>

            <div class="statement-details">
                <table>
                    <thead>
                        <tr>
                            <th>Tarih</th>
                            <th>Açıklama</th>
                            <th>Borç Tipi</th>
                            <th>Belge No</th>
                            <th>Borç (TL)</th>
                            <th>Alacak (TL)</th>
                            <th>Ürün Bakiye (TL)</th>
                            <th>Demirbaş Bakiye (TL)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr class="balance-row">
                            <td></td>
                            <td>Önceki Dönem Bakiyesi</td>
                            <td></td>
                            <td></td>
                            <td></td>
                            <td></td>
                            <td class="text-right"><?php echo number_format($initial_balance_normal, 2); ?></td>
                            <td class="text-right"><?php echo number_format($initial_balance_fixed_asset, 2); ?></td>
                        </tr>
                        <?php
                        $running_balance_normal = $initial_balance_normal;
                        $running_balance_fixed_asset = $initial_balance_fixed_asset;

                        foreach ($customer_transactions as $transaction) :
                            $date = date('d.m.Y', strtotime($transaction['date']));
                            $description = '';
                            $debt_amount = '';
                            $credit_amount = '';
                            $debt_type_text = '';

                            $transaction_debt_type = $transaction['type'] == 'Fatura' ? ($transaction['invoice_debt_type'] ?? 'normal') : ($transaction['payment_debt_type'] ?? 'normal');

                            if ($transaction_debt_type == 'normal') {
                                $debt_type_text = 'Ürün';
                            } elseif ($transaction_debt_type == 'fixed_asset') {
                                $debt_type_text = 'Demirbaş';
                            }

                            if ($transaction['debt_credit_type'] == 'Borç') { // Fatura
                                $description = 'Fatura - ' . htmlspecialchars($transaction['notes'] ?: 'Sipariş Faturası');
                                $debt_amount = number_format($transaction['amount'], 2);
                                if ($transaction_debt_type == 'normal') {
                                    $running_balance_normal += $transaction['amount'];
                                } elseif ($transaction_debt_type == 'fixed_asset') {
                                    $running_balance_fixed_asset += $transaction['amount'];
                                }
                            } else { // Ödeme
                                $description = 'Ödeme - ' . htmlspecialchars($transaction['transaction_detail'] ?: 'Nakit/Banka');
                                $credit_amount = number_format($transaction['amount'], 2);
                                if ($transaction_debt_type == 'normal') {
                                    $running_balance_normal -= $transaction['amount'];
                                } elseif ($transaction_debt_type == 'fixed_asset') {
                                    $running_balance_fixed_asset -= $transaction['amount'];
                                }
                            }
                        ?>
                            <tr>
                                <td><?php echo $date; ?></td>
                                <td><?php echo htmlspecialchars($description); ?></td>
                                <td><span class="debt-type-badge <?php echo ($transaction_debt_type == 'fixed_asset' ? 'fixed-asset-badge' : ''); ?>"><?php echo $debt_type_text; ?></span></td>
                                <td><?php echo htmlspecialchars($transaction['document_number'] ?: $transaction['order_code'] ?: ''); ?></td>
                                <td class="text-right <?php echo $debt_amount ? 'debt' : ''; ?>"><?php echo $debt_amount; ?></td>
                                <td class="text-right <?php echo $credit_amount ? 'credit' : ''; ?>"><?php echo $credit_amount; ?></td>
                                <td class="text-right"><?php echo number_format($running_balance_normal, 2); ?></td>
                                <td class="text-right"><?php echo number_format($running_balance_fixed_asset, 2); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <tr class="balance-row">
                            <td colspan="6" class="text-right"><strong>Güncel Bakiye</strong></td>
                            <td class="text-right"><?php echo number_format($running_balance_normal, 2); ?></td>
                            <td class="text-right"><?php echo number_format($running_balance_fixed_asset, 2); ?></td>
                        </tr>
                        <!-- Veritabanındaki güncel borcu da ekleyelim teyit için -->
                        <tr class="balance-row no-print">
                            <td colspan="6" class="text-right"><strong>Sistemdeki Güncel Borç (Teyit)</strong></td>
                            <td class="text-right"><?php echo number_format($customer_detail['current_debt'], 2); ?></td>
                            <td class="text-right"><?php echo number_format($customer_detail['fixed_asset_current_debt'], 2); ?></td>
                        </tr>
                    </tbody>
                </table>
                <div class="clear-float"></div>
                <div class="summary-table">
                    <table>
                        <tr>
                            <th colspan="2">Ürün Borcu Özeti</th>
                        </tr>
                        <tr>
                            <th>Önceki Bakiye:</th>
                            <td><?php echo number_format($initial_balance_normal, 2); ?> TL</td>
                        </tr>
                        <tr>
                            <th>Toplam Borç Hareketi:</th>
                            <td><?php
                                $total_debt_movements_normal = 0;
                                foreach ($customer_transactions as $tr) {
                                    if ($tr['debt_credit_type'] == 'Borç' && ($tr['invoice_debt_type'] ?? 'normal') == 'normal') {
                                        $total_debt_movements_normal += $tr['amount'];
                                    }
                                }
                                echo number_format($total_debt_movements_normal, 2); ?> TL
                            </td>
                        </tr>
                        <tr>
                            <th>Toplam Alacak Hareketi:</th>
                            <td><?php
                                $total_credit_movements_normal = 0;
                                foreach ($customer_transactions as $tr) {
                                    if ($tr['debt_credit_type'] == 'Alacak' && ($tr['payment_debt_type'] ?? 'normal') == 'normal') {
                                        $total_credit_movements_normal += $tr['amount'];
                                    }
                                }
                                echo number_format($total_credit_movements_normal, 2); ?> TL
                            </td>
                        </tr>
                        <tr>
                            <th>Son Bakiye:</th>
                            <td><?php echo number_format($running_balance_normal, 2); ?> TL</td>
                        </tr>
                    </table>

                    <table style="margin-top: 20px;">
                        <tr>
                            <th colspan="2">Demirbaş Borcu Özeti</th>
                        </tr>
                        <tr>
                            <th>Önceki Bakiye:</th>
                            <td><?php echo number_format($initial_balance_fixed_asset, 2); ?> TL</td>
                        </tr>
                        <tr>
                            <th>Toplam Borç Hareketi:</th>
                            <td><?php
                                $total_debt_movements_fixed_asset = 0;
                                foreach ($customer_transactions as $tr) {
                                    if ($tr['debt_credit_type'] == 'Borç' && ($tr['invoice_debt_type'] ?? 'normal') == 'fixed_asset') {
                                        $total_debt_movements_fixed_asset += $tr['amount'];
                                    }
                                }
                                echo number_format($total_debt_movements_fixed_asset, 2); ?> TL
                            </td>
                        </tr>
                        <tr>
                            <th>Toplam Alacak Hareketi:</th>
                            <td><?php
                                $total_credit_movements_fixed_asset = 0;
                                foreach ($customer_transactions as $tr) {
                                    if ($tr['debt_credit_type'] == 'Alacak' && ($tr['payment_debt_type'] ?? 'normal') == 'fixed_asset') {
                                        $total_credit_movements_fixed_asset += $tr['amount'];
                                    }
                                }
                                echo number_format($total_credit_movements_fixed_asset, 2); ?> TL
                            </td>
                        </tr>
                        <tr>
                            <th>Son Bakiye:</th>
                            <td><?php echo number_format($running_balance_fixed_asset, 2); ?> TL</td>
                        </tr>
                    </table>
                </div>
                <div class="clear-float"></div>
            </div>

            <div class="signature-area">
                <div>
                    <p>Müşteri Adı / Kaşe & İmza</p>
                </div>
                <div>
                    <p>Şirket Yetkilisi Adı / Kaşe & İmza</p>
                </div>
            </div>

            <div class="footer no-print" style="margin-top: 50px;">
                <p>Bu ekstre <?php echo date('d.m.Y H:i:s'); ?> tarihinde oluşturulmuştur.</p>
                <p>Saygılarımızla,</p>
                <p><?php echo htmlspecialchars($company_settings['company_name'] ?? 'Şirket Adı'); ?></p>
            </div>
        </div>
    </body>
    </html>
<?php elseif ($action == 'print_customer_list') : // Tüm müşteri listesi için yazdırma görünümü ?>
    <!DOCTYPE html>
    <html lang="tr">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Cari Hesap Listesi (Yazdır)</title>
        <style>
            body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; margin: 0; padding: 20px; font-size: 10px; }
            .print-container { width: 100%; max-width: 210mm; margin: 0 auto; padding: 10mm; }
            .header { text-align: center; margin-bottom: 20px; }
            .header h1 { margin: 0; font-size: 20px; color: #333; }
            .header p { margin: 2px 0; font-size: 10px; color: #666; }
            table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
            th, td { border: 1px solid #ddd; padding: 6px; text-align: left; }
            th { background-color: #f2f2f2; font-weight: bold; }
            .text-right { text-align: right; }
            .summary-box {
                background-color: #e9f5ff;
                border: 1px solid #cceeff;
                padding: 10px;
                margin-top: 15px;
                border-radius: 5px;
                display: flex;
                flex-wrap: wrap;
                justify-content: space-around;
                text-align: center;
                font-size: 0.9em;
            }
            .summary-item {
                flex: 1;
                min-width: 150px;
                padding: 5px;
            }
            .summary-item strong {
                display: block;
                font-size: 1.0em;
                color: #333;
            }
            .summary-item span {
                font-size: 1.2em;
                font-weight: bold;
                color: #0056b3;
            }
            @media print {
                body { margin: 0; padding: 0; }
                .print-container { width: 100%; margin: 0; padding: 0; }
            }
        </style>
    </head>
    <body onload="window.print();">
        <div class="print-container">
            <div class="header">
                <h1>Cari Hesap Listesi Raporu</h1>
                <p>Oluşturma Tarihi: <?php echo date('d.m.Y H:i:s'); ?></p>
                <p>Filtreler:
                    <?php
                        $filter_summary = [];
                        if (!empty($search_name)) $filter_summary[] = "Ad: " . htmlspecialchars($search_name);
                        if ($debt_status_filter == 'debtor') $filter_summary[] = "Borç Durumu: Borçlu";
                        if ($debt_status_filter == 'creditor') $filter_summary[] = "Borç Durumu: Alacaklı";
                        if ($is_active_filter == '1') $filter_summary[] = "Durum: Aktif";
                        if ($is_active_filter == '0') $filter_summary[] = "Durum: Pasif";
                        echo empty($filter_summary) ? "Yok" : implode(", ", $filter_summary);
                    ?>
                </p>
                <p>Sıralama: <?php echo htmlspecialchars($sort_by) . " " . htmlspecialchars($sort_order); ?></p>
            </div>

            <table>
                <thead>
                    <tr>
                        <th>Müşteri Adı</th>
                        <th>Telefon</th>
                        <th>E-posta</th>
                        <th class="text-right">Ürün Kredi Limiti</th>
                        <th class="text-right">Ürün Güncel Borç</th>
                        <th class="text-right">Ürün Kalan Limit</th>
                        <th class="text-right">Demirbaş Kredi Limiti</th>
                        <th class="text-right">Demirbaş Güncel Borç</th>
                        <th class="text-right">Demirbaş Kalan Limit</th>
                        <th>Son Ödeme Tarihi</th>
                        <th class="text-right">Son Ödeme Miktarı</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($customers)) : ?>
                        <?php foreach ($customers as $customer) : ?>
                            <tr>
                                <td><?php echo htmlspecialchars($customer['customer_name']); ?></td>
                                <td><?php echo htmlspecialchars($customer['phone']); ?></td>
                                <td><?php echo htmlspecialchars($customer['email']); ?></td>
                                <td class="text-right"><?php echo number_format($customer['credit_limit'], 2); ?> TL</td>
                                <td class="text-right"><?php echo number_format($customer['current_debt'], 2); ?> TL</td>
                                <td class="text-right"><?php echo number_format($customer['credit_limit'] - $customer['current_debt'], 2); ?> TL</td>
                                <td class="text-right"><?php echo number_format($customer['fixed_asset_credit_limit'], 2); ?> TL</td>
                                <td class="text-right"><?php echo number_format($customer['fixed_asset_current_debt'], 2); ?> TL</td>
                                <td class="text-right"><?php echo number_format($customer['fixed_asset_credit_limit'] - $customer['fixed_asset_current_debt'], 2); ?> TL</td>
                                <td><?php echo $customer['last_payment_date'] ? date('d.m.Y', strtotime($customer['last_payment_date'])) : 'Yok'; ?></td>
                                <td class="text-right"><?php echo $customer['last_payment_amount'] ? number_format($customer['last_payment_amount'], 2) . ' TL' : 'Yok'; ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else : ?>
                        <tr><td colspan="11">Filtreleme kriterlerine uygun müşteri bulunmamaktadır.</td></tr>
                    <?php endif; ?>
                </tbody>
                <tfoot>
                    <tr>
                        <td colspan="3" style="text-align: right; font-weight: bold;">Genel Toplamlar:</td>
                        <td class="text-right" style="font-weight: bold;"><?php echo number_format($total_credit_limit_normal, 2); ?> TL</td>
                        <td class="text-right" style="font-weight: bold;"><?php echo number_format($total_current_debt_normal, 2); ?> TL</td>
                        <td class="text-right" style="font-weight: bold;"><?php echo number_format($total_remaining_limit_normal, 2); ?> TL</td>
                        <td class="text-right" style="font-weight: bold;"><?php echo number_format($total_credit_limit_fixed_asset, 2); ?> TL</td>
                        <td class="text-right" style="font-weight: bold;"><?php echo number_format($total_current_debt_fixed_asset, 2); ?> TL</td>
                        <td class="text-right" style="font-weight: bold;"><?php echo number_format($total_remaining_limit_fixed_asset, 2); ?> TL</td>
                        <td colspan="2"></td>
                    </tr>
                </tfoot>
            </table>

            <div class="summary-box">
                <div class="summary-item">
                    <strong>Toplam Ürün Güncel Borç:</strong>
                    <span><?php echo number_format($total_current_debt_normal, 2); ?> TL</span>
                </div>
                <div class="summary-item">
                    <strong>Toplam Ürün Kredi Limiti:</strong>
                    <span><?php echo number_format($total_credit_limit_normal, 2); ?> TL</span>
                </div>
                <div class="summary-item">
                    <strong>Toplam Ürün Kalan Limit:</strong>
                    <span><?php echo number_format($total_remaining_limit_normal, 2); ?> TL</span>
                </div>
                <div class="summary-item">
                    <strong>Toplam Demirbaş Güncel Borç:</strong>
                    <span><?php echo number_format($total_current_debt_fixed_asset, 2); ?> TL</span>
                </div>
                <div class="summary-item">
                    <strong>Toplam Demirbaş Kredi Limiti:</strong>
                    <span><?php echo number_format($total_credit_limit_fixed_asset, 2); ?> TL</span>
                </div>
                <div class="summary-item">
                    <strong>Toplam Demirbaş Kalan Limit:</strong>
                    <span><?php echo number_format($total_remaining_limit_fixed_asset, 2); ?> TL</span>
                </div>
            </div>

        </div>
    </body>
    </html>

<?php else : // Normal accounting.php görünümü ?>

    <div class="container">
        <h1>Cari Hesap Yönetimi</h1>

        <?php if (!empty($message)) : ?>
            <div class="message <?php echo $message_type; ?>"><?php echo $message; ?></div>
        <?php endif; ?>

        <?php if ($action == 'list_customers') : ?>
            <h2>Müşteri Cari Listesi</h2>

            <style>
                .filter-form {
                    background-color: #f8f8f8;
                    border: 1px solid #ddd;
                    padding: 15px;
                    margin-bottom: 20px;
                    border-radius: 5px;
                    display: flex;
                    flex-wrap: wrap;
                    gap: 15px;
                    align-items: flex-end;
                }
                .filter-form .form-group {
                    margin-bottom: 0; /* Override default form-group margin */
                    flex: 1; /* Allow items to grow */
                    min-width: 150px; /* Minimum width for form fields */
                }
                .filter-form label {
                    display: block;
                    margin-bottom: 5px;
                    font-weight: bold;
                    color: #555;
                }
                .filter-form input[type="text"],
                .filter-form input[type="number"],
                .filter-form select {
                    width: 100%;
                    padding: 8px;
                    border: 1px solid #ccc;
                    border-radius: 4px;
                }
                .filter-form button {
                    padding: 8px 15px;
                    border-radius: 4px;
                    cursor: pointer;
                    white-space: nowrap; /* Keep button text on one line */
                }
                .summary-box {
                    background-color: #e9f5ff;
                    border: 1px solid #cceeff;
                    padding: 15px;
                    margin-bottom: 20px;
                    border-radius: 5px;
                    display: flex;
                    flex-wrap: wrap;
                    justify-content: space-around;
                    text-align: center;
                }
                .summary-item {
                    flex: 1;
                    min-width: 200px;
                    padding: 10px;
                }
                .summary-item strong {
                    display: block;
                    font-size: 1.1em;
                    color: #333;
                }
                .summary-item span {
                    font-size: 1.5em;
                    font-weight: bold;
                    color: #0056b3;
                }
                .print-buttons {
                    margin-bottom: 20px;
                    display: flex;
                    gap: 10px;
                    justify-content: flex-end;
                }
                .debt-normal { color: #d9534f; font-weight: bold; } /* Kırmızımsı */
                .debt-fixed-asset { color: #f0ad4e; font-weight: bold; } /* Turuncumsu */
                .credit-limit { color: #5cb85c; font-weight: bold; } /* Yeşilsi */
                .remaining-limit { color: #337ab7; font-weight: bold; } /* Mavimsi */
            </style>

            <form action="accounting.php" method="get" class="filter-form">
                <input type="hidden" name="action" value="list_customers">
                <div class="form-group">
                    <label for="search_name">Müşteri Adı:</label>
                    <input type="text" id="search_name" name="search_name" value="<?php echo htmlspecialchars($search_name); ?>" placeholder="Müşteri Adı">
                </div>
                <div class="form-group">
                    <label for="debt_status_filter">Borç Durumu:</label>
                    <select id="debt_status_filter" name="debt_status_filter">
                        <option value="all" <?php echo ($debt_status_filter == 'all' ? 'selected' : ''); ?>>Tümü</option>
                        <option value="debtor" <?php echo ($debt_status_filter == 'debtor' ? 'selected' : ''); ?>>Sadece Borçlular</option>
                        <option value="creditor" <?php echo ($debt_status_filter == 'creditor' ? 'selected' : ''); ?>>Sadece Alacaklılar</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="is_active">Durum:</label>
                    <select id="is_active" name="is_active">
                        <option value="all" <?php echo ($is_active_filter == 'all' ? 'selected' : ''); ?>>Tümü</option>
                        <option value="1" <?php echo ($is_active_filter == '1' ? 'selected' : ''); ?>>Aktif</option>
                        <option value="0" <?php echo ($is_active_filter == '0' ? 'selected' : ''); ?>>Pasif</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="sort_by">Sırala:</label>
                    <select id="sort_by" name="sort_by">
                        <option value="customer_name" <?php echo ($sort_by == 'customer_name' ? 'selected' : ''); ?>>Müşteri Adı</option>
                        <option value="current_debt" <?php echo ($sort_by == 'current_debt' ? 'selected' : ''); ?>>Ürün Güncel Borç</option>
                        <option value="fixed_asset_current_debt" <?php echo ($sort_by == 'fixed_asset_current_debt' ? 'selected' : ''); ?>>Demirbaş Güncel Borç</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="sort_order">Sıralama Yönü:</label>
                    <select id="sort_order" name="sort_order">
                        <option value="ASC" <?php echo (strtoupper($sort_order) == 'ASC' ? 'selected' : ''); ?>>Artan</option>
                        <option value="DESC" <?php echo (strtoupper($sort_order) == 'DESC' ? 'selected' : ''); ?>>Azalan</option>
                    </select>
                </div>
                <div class="form-group">
                    <button type="submit" class="btn btn-primary">Filtrele</button>
                    <a href="accounting.php?action=list_customers" class="btn btn-secondary">Temizle</a>
                </div>
            </form>

            <div class="summary-box">
                <div class="summary-item">
                    <strong>Toplam Ürün Güncel Borç:</strong>
                    <span><?php echo number_format($total_current_debt_normal, 2); ?> TL</span>
                </div>
                <div class="summary-item">
                    <strong>Toplam Ürün Kredi Limiti:</strong>
                    <span><?php echo number_format($total_credit_limit_normal, 2); ?> TL</span>
                </div>
                <div class="summary-item">
                    <strong>Toplam Ürün Kalan Limit:</strong>
                    <span><?php echo number_format($total_remaining_limit_normal, 2); ?> TL</span>
                </div>
                <div class="summary-item">
                    <strong>Toplam Demirbaş Güncel Borç:</strong>
                    <span><?php echo number_format($total_current_debt_fixed_asset, 2); ?> TL</span>
                </div>
                <div class="summary-item">
                    <strong>Toplam Demirbaş Kredi Limiti:</strong>
                    <span><?php echo number_format($total_credit_limit_fixed_asset, 2); ?> TL</span>
                </div>
                <div class="summary-item">
                    <strong>Toplam Demirbaş Kalan Limit:</strong>
                    <span><?php echo number_format($total_remaining_limit_fixed_asset, 2); ?> TL</span>
                </div>
            </div>

            <div class="print-buttons">
                <?php
                // Mevcut tüm GET parametrelerini koru
                $current_get_params = $_GET;
                $current_get_params['action'] = 'print_customer_list'; // Aksiyonu değiştir
                $print_url = 'accounting.php?' . http_build_query($current_get_params);
                ?>
                <a href="<?php echo htmlspecialchars($print_url); ?>" target="_blank" class="btn btn-info">Tüm Listeyi Yazdır</a>
            </div>

            <table>
                <thead>
                    <tr>
                        <th>Müşteri Adı</th>
                        <th>Telefon</th>
                        <th>E-posta</th>
                        <th>Ürün Kredi Limiti</th>
                        <th>Ürün Güncel Borç</th>
                        <th>Ürün Kalan Limit</th>
                        <th>Demirbaş Kredi Limiti</th>
                        <th>Demirbaş Güncel Borç</th>
                        <th>Demirbaş Kalan Limit</th>
                        <th>Aktif Mi?</th>
                        <th>Son Ödeme Tarihi</th>
                        <th>Son Ödeme Miktarı</th>
                        <th>İşlemler</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($customers)) : ?>
                        <?php foreach ($customers as $customer) : ?>
                            <tr>
                                <td><?php echo htmlspecialchars($customer['customer_name']); ?></td>
                                <td><?php echo htmlspecialchars($customer['phone']); ?></td>
                                <td><?php echo htmlspecialchars($customer['email']); ?></td>
                                <td class="text-right credit-limit"><?php echo number_format($customer['credit_limit'], 2); ?> TL</td>
                                <td class="text-right debt-normal"><?php echo number_format($customer['current_debt'], 2); ?> TL</td>
                                <td class="text-right remaining-limit"><?php echo number_format($customer['credit_limit'] - $customer['current_debt'], 2); ?> TL</td>
                                <td class="text-right credit-limit"><?php echo number_format($customer['fixed_asset_credit_limit'], 2); ?> TL</td>
                                <td class="text-right debt-fixed-asset"><?php echo number_format($customer['fixed_asset_current_debt'], 2); ?> TL</td>
                                <td class="text-right remaining-limit"><?php echo number_format($customer['fixed_asset_credit_limit'] - $customer['fixed_asset_current_debt'], 2); ?> TL</td>
                                <td><?php echo $customer['is_active'] ? 'Evet' : 'Hayır'; ?></td>
                                <td><?php echo $customer['last_payment_date'] ? date('d.m.Y', strtotime($customer['last_payment_date'])) : 'Yok'; ?></td>
                                <td class="text-right"><?php echo $customer['last_payment_amount'] ? number_format($customer['last_payment_amount'], 2) . ' TL' : 'Yok'; ?></td>
                                <td>
                                    <a href="accounting.php?action=view_customer_account&id=<?php echo htmlspecialchars($customer['id']); ?>" class="btn btn-primary btn-sm">Detay/Ekstre</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else : ?>
                        <tr><td colspan="13">Filtreleme kriterlerine uygun müşteri bulunmamaktadır.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>

        <?php elseif ($action == 'view_customer_account' && $customer_detail) : ?>
            <h2><?php echo htmlspecialchars($customer_detail['customer_name']); ?> - Cari Hesap Ekstresi</h2>

            <div class="customer-summary">
                <p><strong>Ürün Kredi Limiti:</strong> <span class="credit-limit"><?php echo number_format($customer_detail['credit_limit'], 2); ?> TL</span></p>
                <p><strong>Ürün Güncel Borç:</strong> <span class="debt-normal"><?php echo number_format($customer_detail['current_debt'], 2); ?> TL</span></p>
                <p><strong>Ürün Kalan Limit:</strong> <span class="remaining-limit"><?php echo number_format($customer_detail['credit_limit'] - $customer_detail['current_debt'], 2); ?> TL</span></p>

                <p><strong>Demirbaş Kredi Limiti:</strong> <span class="credit-limit"><?php echo number_format($customer_detail['fixed_asset_credit_limit'], 2); ?> TL</span></p>
                <p><strong>Demirbaş Güncel Borç:</strong> <span class="debt-fixed-asset"><?php echo number_format($customer_detail['fixed_asset_current_debt'], 2); ?> TL</span></p>
                <p><strong>Demirbaş Kalan Limit:</strong> <span class="remaining-limit"><?php echo number_format($customer_detail['fixed_asset_credit_limit'] - $customer_detail['fixed_asset_current_debt'], 2); ?> TL</span></p>
            </div>

            <h3>Ödeme Girişi</h3>
            <form action="accounting.php" method="post">
                <input type="hidden" name="customer_id" value="<?php echo htmlspecialchars($customer_detail['id']); ?>">
                <div class="form-group">
                    <label for="payment_date">Ödeme Tarihi:</label>
                    <input type="date" id="payment_date" name="payment_date" value="<?php echo date('Y-m-d'); ?>" required>
                </div>
                <div class="form-group">
                    <label for="amount">Miktar (TL):</label>
                    <input type="number" id="amount" name="amount" step="0.01" min="0.01" required>
                </div>
                <div class="form-group">
                    <label for="payment_method">Ödeme Yöntemi:</label>
                    <select id="payment_method" name="payment_method">
                        <option value="Nakit">Nakit</option>
                        <option value="Banka Havalesi">Banka Havalesi</option>
                        <option value="Kredi Kartı">Kredi Kartı</option>
                        <option value="Diğer">Diğer</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="payment_for_debt_type">Ödeme Hangi Borç Tipi İçin?</label>
                    <select id="payment_for_debt_type" name="payment_for_debt_type" required>
                        <option value="normal">Ürün Borcu</option>
                        <option value="fixed_asset">Demirbaş Borcu</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="notes">Notlar:</label>
                    <textarea id="notes" name="notes"></textarea>
                </div>
                <button type="submit" name="add_payment" class="btn btn-success">Ödeme Kaydet</button>
            </form>

            <h3 style="margin-top: 30px;">Kredi Limitlerini Güncelle</h3>
            <form action="accounting.php" method="post">
                <input type="hidden" name="customer_id" value="<?php echo htmlspecialchars($customer_detail['id']); ?>">
                <div class="form-group">
                    <label for="credit_limit_normal">Ürün İçin Yeni Kredi Limiti (TL):</label>
                    <input type="number" id="credit_limit_normal" name="credit_limit_normal" step="0.01" min="0" value="<?php echo htmlspecialchars($customer_detail['credit_limit']); ?>" required>
                </div>
                <div class="form-group">
                    <label for="credit_limit_fixed_asset">Demirbaş İçin Yeni Kredi Limiti (TL):</label>
                    <input type="number" id="credit_limit_fixed_asset" name="credit_limit_fixed_asset" step="0.01" min="0" value="<?php echo htmlspecialchars($customer_detail['fixed_asset_credit_limit']); ?>" required>
                </div>
                <button type="submit" name="update_credit_limits" class="btn btn-warning">Limitleri Güncelle</button>
            </form>

            <h3 style="margin-top: 30px;">Ekstre Detayları</h3>
            <p><a href="accounting.php?action=print_statement&id=<?php echo htmlspecialchars($customer_detail['id']); ?>" target="_blank" class="btn btn-info">Ekstreyi Yazdırmaya Hazırla</a></p>
            <table>
                <thead>
                    <tr>
                        <th>Tarih</th>
                        <th>İşlem Tipi</th>
                        <th>Borç Tipi</th>
                        <th>Açıklama</th>
                        <th>Belge No / Sipariş Kodu</th>
                        <th>Borç (TL)</th>
                        <th>Alacak (TL)</th>
                        <th>Ürün Bakiye (TL)</th>
                        <th>Demirbaş Bakiye (TL)</th>
                    </tr>
                </thead>
                <tbody>
                    <tr class="balance-row">
                        <td></td>
                        <td>Önceki Dönem Bakiyesi</td>
                        <td></td>
                        <td></td>
                        <td></td>
                        <td></td>
                        <td></td>
                        <td class="text-right"><?php echo number_format($initial_balance_normal, 2); ?></td>
                        <td class="text-right"><?php echo number_format($initial_balance_fixed_asset, 2); ?></td>
                    </tr>
                    <?php
                    $running_balance_normal = $initial_balance_normal;
                    $running_balance_fixed_asset = $initial_balance_fixed_asset;

                    if (!empty($customer_transactions)) : ?>
                        <?php foreach ($customer_transactions as $transaction) :
                            $description_cell = '';
                            $debt_amount_display = '';
                            $credit_amount_display = '';
                            $transaction_debt_type_text = '';

                            $transaction_debt_type = $transaction['type'] == 'Fatura' ? ($transaction['invoice_debt_type'] ?? 'normal') : ($transaction['payment_debt_type'] ?? 'normal');

                            if ($transaction_debt_type == 'normal') {
                                $transaction_debt_type_text = 'Ürün';
                            } elseif ($transaction_debt_type == 'fixed_asset') {
                                $transaction_debt_type_text = 'Demirbaş';
                            }

                            if ($transaction['debt_credit_type'] == 'Borç') { // Fatura
                                $description_cell = htmlspecialchars($transaction['notes'] ?: 'Sipariş Faturası');
                                $debt_amount_display = number_format($transaction['amount'], 2);
                                if ($transaction_debt_type == 'normal') {
                                    $running_balance_normal += $transaction['amount'];
                                } elseif ($transaction_debt_type == 'fixed_asset') {
                                    $running_balance_fixed_asset += $transaction['amount'];
                                }
                            } else { // Ödeme
                                $description_cell = htmlspecialchars($transaction['notes'] ?: $transaction['transaction_detail']);
                                $credit_amount_display = number_format($transaction['amount'], 2);
                                if ($transaction_debt_type == 'normal') {
                                    $running_balance_normal -= $transaction['amount'];
                                } elseif ($transaction_debt_type == 'fixed_asset') {
                                    $running_balance_fixed_asset -= $transaction['amount'];
                                }
                            }
                        ?>
                            <tr>
                                <td><?php echo date('d.m.Y', strtotime($transaction['date'])); ?></td>
                                <td><?php echo htmlspecialchars($transaction['type']); ?></td>
                                <td><span class="debt-type-badge <?php echo ($transaction_debt_type == 'fixed_asset' ? 'fixed-asset-badge' : ''); ?>"><?php echo $transaction_debt_type_text; ?></span></td>
                                <td><?php echo htmlspecialchars($transaction['document_number'] ?: $transaction['order_code']); ?></td>
                                <td class="text-right <?php echo $debt_amount_display ? 'debt' : ''; ?>"><?php echo $debt_amount_display; ?></td>
                                <td class="text-right <?php echo $credit_amount_display ? 'credit' : ''; ?>"><?php echo $credit_amount_display; ?></td>
                                <td class="text-right"><?php echo number_format($running_balance_normal, 2); ?></td>
                                <td class="text-right"><?php echo number_format($running_balance_fixed_asset, 2); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <tr class="balance-row">
                            <td colspan="7" class="text-right"><strong>Güncel Hesap Bakiyesi</strong></td>
                            <td class="text-right"><strong><?php echo number_format($running_balance_normal, 2); ?> TL</strong></td>
                            <td class="text-right"><strong><?php echo number_format($running_balance_fixed_asset, 2); ?> TL</strong></td>
                        </tr>
                        <tr class="balance-row">
                            <td colspan="7" class="text-right"><strong>Sistemdeki Güncel Borç (Teyit)</strong></td>
                            <td class="text-right"><strong><?php echo number_format($customer_detail['current_debt'], 2); ?> TL</strong></td>
                            <td class="text-right"><strong><?php echo number_format($customer_detail['fixed_asset_current_debt'], 2); ?> TL</strong></td>
                        </tr>
                    <?php else : ?>
                        <tr><td colspan="9">Bu müşteri için henüz bir hareket bulunmamaktadır.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
            <p style="margin-top: 20px;"><a href="accounting.php" class="btn btn-secondary">Müşteri Listesine Geri Dön</a></p>

        <?php else : ?>
            <div class="message error">Geçersiz işlem veya müşteri bulunamadı.</div>
            <p><a href="accounting.php" class="btn btn-secondary">Müşteri Listesine Geri Dön</a></p>
        <?php endif; ?>
    </div>

    <?php include 'includes/footer.php'; ?>
<?php endif; ?>
