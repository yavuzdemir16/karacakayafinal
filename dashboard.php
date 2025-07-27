<?php
session_start();
require_once 'config.php'; // Veritabanı bağlantısı ve check_permission fonksiyonu burada olmalı

// Kullanıcı girişi kontrolü ve yetkilendirme
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

// Veritabanı bağlantısı fonksiyonunuzu çağırın
try {
    $pdo = connect_db();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); // Hata ayıklama için
} catch (PDOException $e) {
    die("Veritabanı bağlantı hatası: " . $e->getMessage());
}

$user_id = $_SESSION['user_id'];
$user_role_id = $_SESSION['user_role_id'];

// Kullanıcının rolünü al (yetkilendirme için)
$user_role_name = 'Bilinmiyor';
try {
    $stmt = $pdo->prepare("SELECT role_name FROM roles WHERE id = ?");
    $stmt->execute([$user_role_id]);
    $role_result = $stmt->fetchColumn();
    if ($role_result) {
        $user_role_name = $role_result;
    }
} catch (PDOException $e) {
    error_log("Kullanıcı rolü çekilirken hata: " . $e->getMessage());
}

// Sayfa başlığını ayarla
$page_title = "Yönetim Paneli (Dashboard)";
include 'includes/header.php'; // header.php dosyanızın var olduğunu varsayıyoruz

// Veritabanı sorguları için değişkenler (başlangıç değerleri)
$total_orders = 0;
$pending_orders = 0;
$approved_orders = 0;
$in_shipment_orders = 0;
$delivered_orders = 0;

$total_invoiced_amount = 0;
$pending_invoices_count = 0;
$invoiced_orders_count = 0;

$total_receivables = 0;
$receivables_to_sales_ratio = 0;

$average_order_value = 0;
$last_30_days_sales = 0;
$top_customer_name = 'Yok';
$top_customer_amount = 0;
$total_customers = 0; // Yeni metrik
$avg_payment_days = 0; // Yeni metrik

try {
    // Sipariş Durumları (orders tablosundan)
    $stmt = $pdo->query("SELECT COUNT(*) FROM orders");
    $total_orders = $stmt->fetchColumn() ?: 0;

    $stmt = $pdo->query("SELECT COUNT(*) FROM orders WHERE order_status = 'Onay Bekliyor'");
    $pending_orders = $stmt->fetchColumn() ?: 0;

    $stmt = $pdo->query("SELECT COUNT(*) FROM orders WHERE order_status = 'Onaylandı'");
    $approved_orders = $stmt->fetchColumn() ?: 0;

    $stmt = $pdo->query("SELECT COUNT(*) FROM orders WHERE order_status IN ('Sevkiyatta', 'Yola Çıktı')");
    $in_shipment_orders = $stmt->fetchColumn() ?: 0;

    $stmt = $pdo->query("SELECT COUNT(*) FROM orders WHERE order_status = 'Teslim Edildi'");
    $delivered_orders = $stmt->fetchColumn() ?: 0;

    // Finansal Özetler (invoices ve orders tablolarından)

    // Toplam Faturalandırılan Tutar (Ödendi veya Kısmen Ödendi faturaların toplamı)
    $stmt = $pdo->query("SELECT SUM(total_amount) FROM invoices WHERE invoice_status IN ('Ödendi', 'Kısmen Ödendi')");
    $total_invoiced_amount = $stmt->fetchColumn() ?: 0.00;

    // Bekleyen Faturalar (Sayı) (invoice_status 'Ödenmedi' olanlar)
    $stmt = $pdo->query("SELECT COUNT(*) FROM invoices WHERE invoice_status = 'Ödenmedi'");
    $pending_invoices_count = $stmt->fetchColumn() ?: 0;

    // Faturalandırılmış Siparişler (Sayı) (invoices tablosundaki toplam kayıt sayısı)
    $stmt = $pdo->query("SELECT COUNT(*) FROM invoices");
    $invoiced_orders_count = $stmt->fetchColumn() ?: 0;

    // Toplam Açık Hesap (Alacak) (customers.current_debt sütunundan)
    // Sadece pozitif borçları (bizim alacaklarımızı) topluyoruz.
    $stmt_positive_debt = $pdo->query("SELECT SUM(current_debt) FROM customers WHERE current_debt > 0 AND is_active = 1");
    $total_receivables = $stmt_positive_debt->fetchColumn() ?: 0.00;

    // Cari Bakiye / Satış Oranı (Son 1 Yıl)
    $one_year_ago = date('Y-m-d H:i:s', strtotime('-1 year'));
    $stmt_total_sales_last_year = $pdo->prepare("SELECT SUM(total_amount) FROM orders WHERE order_date >= ?");
    $stmt_total_sales_last_year->execute([$one_year_ago]);
    $total_sales_last_year = $stmt_total_sales_last_year->fetchColumn() ?: 0.00;

    if ($total_sales_last_year > 0) {
        $receivables_to_sales_ratio = ($total_receivables / $total_sales_last_year) * 100;
    }

    // Ortalama Sipariş Değeri
    if ($total_orders > 0) {
        $stmt_total_all_orders_amount = $pdo->query("SELECT SUM(total_amount) FROM orders");
        $total_all_orders_amount = $stmt_total_all_orders_amount->fetchColumn() ?: 0.00;
        $average_order_value = $total_all_orders_amount / $total_orders;
    }

    // Son 30 Günlük Satış Hacmi (Faturalanan siparişlerin toplamı)
    $thirty_days_ago = date('Y-m-d H:i:s', strtotime('-30 days'));
    $stmt_30_days_sales = $pdo->prepare("SELECT SUM(o.total_amount)
                                            FROM orders o
                                            JOIN invoices i ON o.id = i.order_id
                                            WHERE o.order_date >= ? AND i.invoice_status IN ('Ödendi', 'Kısmen Ödendi')");
    $stmt_30_days_sales->execute([$thirty_days_ago]);
    $last_30_days_sales = $stmt_30_days_sales->fetchColumn() ?: 0.00;


    // En Çok Sipariş Veren Müşteri (Tutar Bazında)
    $stmt_top_customer = $pdo->query("
        SELECT c.customer_name, SUM(o.total_amount) as total_spent_amount
        FROM orders o
        JOIN customers c ON o.dealer_id = c.id
        GROUP BY c.customer_name
        ORDER BY total_spent_amount DESC
        LIMIT 1
    ");
    $top_customer_data = $stmt_top_customer->fetch(PDO::FETCH_ASSOC);
    if ($top_customer_data) {
        $top_customer_name = htmlspecialchars($top_customer_data['customer_name']);
        $top_customer_amount = $top_customer_data['total_spent_amount'];
    }

    // Yeni Metrik: Toplam Müşteri Sayısı
    $stmt = $pdo->query("SELECT COUNT(*) FROM customers WHERE is_active = 1");
    $total_customers = $stmt->fetchColumn() ?: 0;

    // Yeni Metrik: Ortalama Ödeme Günü (Basit Yaklaşım - Her Müşterinin Son Ödeme Tarihi ile Son Fatura Tarihi Arasındaki Farkın Ortalaması)
    // Bu metrik karmaşık olabilir ve mevcut şemada tam doğru olmayabilir.
    // Eğer faturaları ödemelerle eşleştiren bir mekanizmanız yoksa bu bilgi yanıltıcı olabilir.
    // Şu an için basit bir ortalama alıyoruz.
    $stmt_avg_payment_days = $pdo->query("
        SELECT AVG(DATEDIFF(cp.payment_date, i.invoice_date))
        FROM customer_payments cp
        JOIN invoices i ON cp.customer_id = (SELECT o.dealer_id FROM orders o WHERE o.id = i.order_id LIMIT 1)
        WHERE cp.payment_date IS NOT NULL AND i.invoice_date IS NOT NULL
        AND DATEDIFF(cp.payment_date, i.invoice_date) >= 0
    ");
    $avg_payment_days_raw = $stmt_avg_payment_days->fetchColumn();
    $avg_payment_days = round($avg_payment_days_raw ?: 0);


} catch (PDOException $e) {
    error_log("Dashboard veri çekme hatası: " . $e->getMessage());
    echo "<div class='message error'>Veriler yüklenirken bir hata oluştu: " . $e->getMessage() . "</div>";
}

?>

<div class="main-dashboard-container">
    <h1 class="dashboard-title">Genel Durum Paneli</h1>

    <!-- Metric Cards Grid -->
    <div class="metrics-grid">
        <div class="metric-card">
            <span class="metric-label">Toplam Siparişler</span>
            <span class="metric-value green"><?php echo htmlspecialchars($total_orders); ?></span>
        </div>
        <div class="metric-card">
            <span class="metric-label">Onay Bekleyen Siparişler</span>
            <span class="metric-value yellow"><?php echo htmlspecialchars($pending_orders); ?></span>
        </div>
        <div class="metric-card">
            <span class="metric-label">Onaylanmış Siparişler</span>
            <span class="metric-value blue"><?php echo htmlspecialchars($approved_orders); ?></span>
        </div>
        <div class="metric-card">
            <span class="metric-label">Sevkiyattaki Siparişler</span>
            <span class="metric-value purple"><?php echo htmlspecialchars($in_shipment_orders); ?></span>
        </div>
        <div class="metric-card">
            <span class="metric-label">Teslim Edilen Siparişler</span>
            <span class="metric-value red"><?php echo htmlspecialchars($delivered_orders); ?></span>
        </div>

        <div class="metric-card">
            <span class="metric-label">Toplam Faturalandırılan Tutar</span>
            <span class="metric-value dark-purple"><?php echo number_format($total_invoiced_amount, 2) . " TL"; ?></span>
        </div>
        <div class="metric-card">
            <span class="metric-label">Bekleyen Faturalar (Adet)</span>
            <span class="metric-value orange"><?php echo htmlspecialchars($pending_invoices_count); ?></span>
        </div>
        <div class="metric-card">
            <span class="metric-label">Faturalanmış Siparişler (Adet)</span>
            <span class="metric-value teal"><?php echo htmlspecialchars($invoiced_orders_count); ?></span>
        </div>
        <div class="metric-card">
            <span class="metric-label">Ortalama Sipariş Değeri</span>
            <span class="metric-value light-blue"><?php echo number_format($average_order_value, 2) . " TL"; ?></span>
        </div>
        <div class="metric-card">
            <span class="metric-label">Son 30 Günlük Satış Hacmi</span>
            <span class="metric-value dark-green"><?php echo number_format($last_30_days_sales, 2) . " TL"; ?></span>
        </div>

        <div class="metric-card">
            <span class="metric-label">Toplam Açık Hesap (Alacak)</span>
            <span class="metric-value pink"><?php echo number_format($total_receivables, 2) . " TL"; ?></span>
        </div>
        <div class="metric-card">
            <span class="metric-label">Cari Bakiye / Satış Oranı (Son 1 Yıl)</span>
            <span class="metric-value dark-purple"><?php echo number_format($receivables_to_sales_ratio, 2) . " %"; ?></span>
        </div>
        <div class="metric-card">
            <span class="metric-label">Toplam Müşteri Sayısı</span>
            <span class="metric-value blue"><?php echo htmlspecialchars($total_customers); ?></span>
        </div>
        <div class="metric-card">
            <span class="metric-label">Ortalama Ödeme Günü</span>
            <span class="metric-value green"><?php echo htmlspecialchars($avg_payment_days); ?> Gün</span>
        </div>
        <div class="metric-card featured-card">
            <span class="metric-label">En Çok Sipariş Veren Müşteri</span>
            <div class="customer-info">
                <span class="customer-name"><?php echo $top_customer_name; ?></span>
                <span class="customer-amount"><?php echo number_format($top_customer_amount, 2) . " TL"; ?></span>
            </div>
        </div>
    </div>

    <!-- Lists Section: Son 5 Sipariş and Kritik Cari Bakiyeler -->
    <div class="lists-container">
        <div class="list-panel">
            <h2>Son 5 Sipariş</h2>
            <?php
            $latest_orders_query = "
                SELECT
                    o.id AS order_id,
                    o.order_date,
                    c.customer_name,
                    o.total_amount,
                    o.order_status
                FROM
                    orders o
                JOIN
                    customers c ON o.dealer_id = c.id
                ORDER BY
                    o.order_date DESC
                LIMIT 5
            ";

            try {
                 $latest_orders_stmt = $pdo->query($latest_orders_query);
                 $latest_orders = $latest_orders_stmt->fetchAll(PDO::FETCH_ASSOC);

                if (!empty($latest_orders)) {
                    echo "<table class='data-table'>";
                    echo "<thead><tr><th>Sipariş ID</th><th>Tarih</th><th>Müşteri</th><th>Tutar</th><th>Durum</th></tr></thead>";
                    echo "<tbody>";
                    foreach ($latest_orders as $order) {
                        echo "<tr>";
                        echo "<td>" . htmlspecialchars($order['order_id']) . "</td>";
                        echo "<td>" . htmlspecialchars(date('d.m.Y', strtotime($order['order_date']))) . "</td>";
                        echo "<td>" . htmlspecialchars($order['customer_name']) . "</td>";
                        echo "<td>" . number_format($order['total_amount'], 2) . " TL</td>";
                        echo "<td>" . htmlspecialchars($order['order_status']) . "</td>";
                        echo "</tr>";
                    }
                    echo "</tbody>";
                    echo "</table>";
                } else {
                    echo "<p class='no-data-message'>Henüz sipariş bulunmamaktadır.</p>";
                }
            } catch (PDOException $e) {
                echo "<div class='message error'>Son 5 Sipariş yüklenirken bir hata oluştu: " . $e->getMessage() . "</div>";
                error_log("Dashboard Latest Orders Error: " . $e->getMessage());
            }
            ?>
        </div>

        <div class="list-panel">
            <h2>Kritik Cari Bakiyeler (En Yüksek Borçlu İlk 5)</h2>
            <?php
            $critical_receivables_query = "
                SELECT
                    customer_name,
                    current_debt,
                    credit_limit
                FROM
                    customers
                WHERE
                    current_debt > 0 AND is_active = 1
                ORDER BY
                    current_debt DESC
                LIMIT 5
            ";

            try {
                $critical_receivables_stmt = $pdo->query($critical_receivables_query);
                $critical_receivables = $critical_receivables_stmt->fetchAll(PDO::FETCH_ASSOC);

                if (!empty($critical_receivables)) {
                    echo "<table class='data-table'>";
                    echo "<thead><tr><th>Müşteri Adı</th><th>Bakiye (TL)</th><th>Limit (TL)</th></tr></thead>";
                    echo "<tbody>";
                    foreach ($critical_receivables as $customer) {
                        echo "<tr>";
                        echo "<td>" . htmlspecialchars($customer['customer_name']) . "</td>";
                        echo "<td>" . number_format($customer['current_debt'], 2) . "</td>";
                        echo "<td>" . number_format($customer['credit_limit'], 2) . "</td>";
                        echo "</tr>";
                    }
                    echo "</tbody>";
                    echo "</table>";
                } else {
                    echo "<p class='no-data-message'>Kritik cari bakiye bulunmamaktadır.</p>";
                }
            } catch (PDOException $e) {
                echo "<div class='message error'>Kritik cari bakiyeler yüklenirken bir hata oluştu: " . $e->getMessage() . "</div>";
                error_log("Dashboard Critical Receivables Error: " . $e->getMessage());
            }
            ?>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>

<!-- Dashboard'a özel CSS Eklemesi -->
<style>
    /* Global Reset & Base Styles */
    body {
        font-family: 'Inter', 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        background-color: #f0f2f5;
        color: #333;
        margin: 0;
        line-height: 1.6;
    }

    .main-dashboard-container {
        max-width: 1400px; /* Daha geniş bir konteyner */
        margin: 20px auto;
        padding: 0 25px; /* Yanlardan daha fazla boşluk */
    }

    .dashboard-title {
        font-size: 2.5em;
        color: #2c3e50;
        margin-bottom: 40px;
        text-align: center;
        font-weight: 700;
        letter-spacing: 0.8px;
        position: relative;
        padding-bottom: 15px;
    }
    .dashboard-title::after {
        content: '';
        position: absolute;
        bottom: 0;
        left: 50%;
        transform: translateX(-50%);
        width: 80px;
        height: 4px;
        background-color: #007bff;
        border-radius: 2px;
    }

    /* Metrics Grid - Tüm Kartlar Tek Düzende */
    .metrics-grid {
        display: grid;
        grid-template-columns: repeat(5, 1fr); /* Tam 5 sütunlu sabit düzen */
        gap: 25px; /* Kartlar arası boşluk */
        margin-bottom: 40px;
    }

    .metric-card {
        background-color: #fff;
        padding: 25px;
        border-radius: 12px; /* Daha belirgin yuvarlak köşeler */
        box-shadow: 0 6px 20px rgba(0,0,0,0.08); /* Daha yumuşak gölge */
        display: flex;
        flex-direction: column;
        justify-content: space-between;
        align-items: flex-start; /* Metrikler sola hizalı */
        border: 1px solid #e9ecef; /* Hafif kenarlık */
        transition: transform 0.3s ease, box-shadow 0.3s ease;
        min-height: 100px; /* Minimum kart yüksekliği */
    }

    .metric-card:hover {
        transform: translateY(-5px);
        box-shadow: 0 10px 30px rgba(0,0,0,0.12);
    }

    .metric-label {
        font-size: 0.95em;
        color: #6c757d;
        margin-bottom: 10px;
        font-weight: 500;
        letter-spacing: 0.2px;
    }

    .metric-value {
        font-size: 2.2em;
        font-weight: 700;
        line-height: 1;
        letter-spacing: -0.8px;
        color: #343a40; /* Varsayılan değer rengi */
    }

    /* Metric Value Colors */
    .metric-value.green { color: #28a745; }
    .metric-value.yellow { color: #ffc107; }
    .metric-value.blue { color: #007bff; }
    .metric-value.purple { color: #6f42c1; }
    .metric-value.red { color: #dc3545; }
    .metric-value.dark-purple { color: #6610f2; }
    .metric-value.orange { color: #fd7e14; }
    .metric-value.teal { color: #20c997; }
    .metric-value.light-blue { color: #17a2b8; }
    .metric-value.dark-green { color: #218838; }
    .metric-value.pink { color: #e83e8c; }

    .featured-card {
        grid-column: span 1; /* Tek bir sütunu kaplasın */
        display: flex;
        flex-direction: column;
        justify-content: center;
        align-items: center;
        text-align: center;
        background-color: #007bff; /* Öne çıkan kart için arka plan rengi */
        color: #fff;
    }
    .featured-card .metric-label {
        color: rgba(255,255,255,0.8);
        font-size: 1.05em;
    }
    .featured-card .customer-info {
        margin-top: 10px;
    }
    .featured-card .customer-name {
        font-size: 1.5em;
        font-weight: 600;
        display: block;
        margin-bottom: 5px;
        color: #fff;
    }
    .featured-card .customer-amount {
        font-size: 2.5em;
        font-weight: 700;
        display: block;
        color: #fff;
    }

    /* Lists Container - Yan Yana Listeler */
    .lists-container {
        display: grid;
        grid-template-columns: 1fr; /* Mobil için tek sütun */
        gap: 30px;
    }

    @media (min-width: 992px) { /* Geniş ekranlar için iki sütun */
        .lists-container {
            grid-template-columns: 1fr 1fr;
        }
    }

    .list-panel {
        background-color: #fff;
        padding: 30px;
        border-radius: 12px;
        box-shadow: 0 6px 20px rgba(0,0,0,0.08);
        border: 1px solid #e9ecef;
    }

    .list-panel h2 {
        font-size: 1.8em;
        color: #34495e;
        margin-bottom: 25px;
        text-align: center;
        font-weight: 600;
        position: relative;
        padding-bottom: 10px;
    }
    .list-panel h2::after {
        content: '';
        position: absolute;
        bottom: 0;
        left: 50%;
        transform: translateX(-50%);
        width: 50px;
        height: 3px;
        background-color: #17a2b8;
        border-radius: 2px;
    }

    .data-table {
        width: 100%;
        border-collapse: collapse;
        margin-top: 15px;
    }

    .data-table th, .data-table td {
        padding: 12px 15px;
        text-align: left;
        border-bottom: 1px solid #eee;
    }

    .data-table th {
        background-color: #f8f9fa;
        color: #555;
        font-weight: 600;
        text-transform: uppercase;
        font-size: 0.85em;
    }

    .data-table tbody tr:hover {
        background-color: #f1f1f1;
    }

    .no-data-message {
        text-align: center;
        color: #777;
        font-style: italic;
        padding: 20px;
        background-color: #f9f9f9;
        border-radius: 8px;
        margin-top: 20px;
    }

    /* Responsive Adjustments for grid columns */
    @media (max-width: 1200px) {
        .metrics-grid {
            grid-template-columns: repeat(4, 1fr); /* 4 sütuna düşür */
        }
    }

    @media (max-width: 992px) {
        .metrics-grid {
            grid-template-columns: repeat(3, 1fr); /* 3 sütuna düşür */
        }
    }

    @media (max-width: 768px) {
        .metrics-grid {
            grid-template-columns: repeat(2, 1fr); /* 2 sütuna düşür */
        }
        .metric-value {
            font-size: 1.8em;
        }
        .metric-label {
            font-size: 0.85em;
        }
        .featured-card .customer-name {
            font-size: 1.2em;
        }
        .featured-card .customer-amount {
            font-size: 2em;
        }
        .list-panel h2 {
            font-size: 1.6em;
        }
        .data-table th, .data-table td {
            padding: 10px;
            font-size: 0.9em;
        }
    }

    @media (max-width: 576px) {
        .metrics-grid {
            grid-template-columns: 1fr; /* Küçük ekranlarda tek sütun */
        }
        .dashboard-title {
            font-size: 2em;
        }
        .main-dashboard-container {
            padding: 0 15px;
        }
        .list-panel {
            padding: 20px;
        }
    }
</style>
