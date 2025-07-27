<?php
session_start();
require_once 'config.php';

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Bu sayfaya erişebilecek roller: Genel Müdür, Genel Müdür Yardımcısı, Muhasebe Müdürü, Satış Müdürü
check_permission(['genel_mudur', 'genel_mudur_yardimcisi', 'muhasebe_muduru', 'satis_muduru']);

$message = '';
$message_type = '';

// Rapor türü seçimi
$report_type = isset($_GET['report_type']) ? $_GET['report_type'] : 'sales_summary';

// Filtreleme parametreleri
$start_date = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-01'); // Ayın başı
$end_date = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-d');     // Bugün
$dealer_id = isset($_GET['dealer_id']) ? intval($_GET['dealer_id']) : 0;
$product_id = isset($_GET['product_id']) ? intval($_GET['product_id']) : 0;
$order_status = isset($_GET['order_status']) ? $_GET['order_status'] : '';

// Tüm bayileri ve ürünleri filtreleme için çek (isteğe bağlı)
$all_dealers = [];
try {
    $stmt_dealers = $pdo->query("SELECT id, dealer_name FROM dealers WHERE is_active = TRUE ORDER BY dealer_name ASC");
    $all_dealers = $stmt_dealers->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Bayiler çekilirken hata: " . $e->getMessage());
}

$all_products = [];
try {
    $stmt_products = $pdo->query("SELECT id, product_name FROM products WHERE is_active = TRUE ORDER BY product_name ASC");
    $all_products = $stmt_products->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Ürünler çekilirken hata: " . $e->getMessage());
}


// Rapor verilerini depolamak için boş bir dizi
$report_data = [];
$report_title = '';

// Excel çıktısı isteği kontrolü
if (isset($_GET['export']) && $_GET['export'] == 'excel') {
    // Excel dışa aktarım başlıkları
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="' . $report_type . '_' . date('Ymd_His') . '.xls"');
    header('Cache-Control: max-age=0');
    // PHP'nin çıktı tamponlamasını temizle
    ob_end_clean();
}


// Rapor türüne göre veri çekme
switch ($report_type) {
    case 'sales_summary':
        $report_title = 'Dönemsel Satış Özeti Raporu';
        $sql = "SELECT DATE(o.order_date) as order_day, d.dealer_name, SUM(oi.total_price) as daily_total_sales, COUNT(DISTINCT o.id) as daily_order_count
                FROM orders o
                JOIN dealers d ON o.dealer_id = d.id
                JOIN order_items oi ON o.id = oi.order_id
                WHERE DATE(o.order_date) BETWEEN ? AND ?";
        $params = [$start_date, $end_date];

        if ($dealer_id > 0) {
            $sql .= " AND o.dealer_id = ?";
            $params[] = $dealer_id;
        }
        // Satış Müdürü kendi bayilerinin satışlarını görebilir
        if (isset($_SESSION['user_role_id']) && $_SESSION['user_role_id'] == 3) {
            $current_user_id = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : null;
            if ($current_user_id !== null) {
                $sql .= " AND o.dealer_id IN (SELECT smd.dealer_id FROM sales_managers_to_dealers smd WHERE smd.sales_manager_id = ?)";
                $params[] = $current_user_id;
            }
        }

        $sql .= " GROUP BY order_day, d.dealer_name ORDER BY order_day DESC, d.dealer_name ASC";

        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $report_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Satış özeti raporu çekilirken hata: " . $e->getMessage());
            $message = "Satış özeti raporu oluşturulurken bir hata oluştu.";
            $message_type = 'error';
        }
        break;

    case 'debt_status':
        $report_title = 'Bayi/Müşteri Borç Durumu Raporu';
        $sql = "SELECT c.customer_name, c.tax_office, c.tax_id, c.phone, c.email, c.credit_limit, c.current_debt
                FROM customers c
                WHERE c.is_active = TRUE";
        $params = [];

        if ($dealer_id > 0) { // Aslında customer_id olacak, burası şimdilik dealer_id ile eşleşsin
            $sql .= " AND c.id = ?";
            $params[] = $dealer_id;
        }

        // Sadece Muhasebe Müdürü ve GM'ler tüm borç durumunu görebilir
        if (isset($_SESSION['user_role_id']) && $_SESSION['user_role_id'] == 3) { // Satış Müdürü ise kendi bayilerini görsün
             $current_user_id = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : null;
            if ($current_user_id !== null) {
                $sql .= " AND c.id IN (SELECT smd.dealer_id FROM sales_managers_to_dealers smd WHERE smd.sales_manager_id = ?)";
                $params[] = $current_user_id;
            }
        } elseif (isset($_SESSION['user_role_id']) && !in_array($_SESSION['user_role_id'], [1, 2, 4])) { // GM, GMY, Muhasebe dışında kimse görmesin
             $sql .= " AND 1=0"; // Sonuç dönmemesi için
        }


        $sql .= " ORDER BY c.current_debt DESC";
        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $report_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Borç durumu raporu çekilirken hata: " . $e->getMessage());
            $message = "Borç durumu raporu oluşturulurken bir hata oluştu.";
            $message_type = 'error';
        }
        break;

    case 'product_sales_performance':
        $report_title = 'Ürün Satış Performansı Raporu';
        $sql = "SELECT p.product_name, p.sku, SUM(oi.quantity) as total_quantity_sold, SUM(oi.total_price) as total_sales_value
                FROM order_items oi
                JOIN products p ON oi.product_id = p.id
                JOIN orders o ON oi.order_id = o.id
                WHERE DATE(o.order_date) BETWEEN ? AND ?";
        $params = [$start_date, $end_date];

        if ($product_id > 0) {
            $sql .= " AND p.id = ?";
            $params[] = $product_id;
        }
         // Satış Müdürü kendi bayilerine ait ürün satışlarını görebilir
        if (isset($_SESSION['user_role_id']) && $_SESSION['user_role_id'] == 3) {
            $current_user_id = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : null;
            if ($current_user_id !== null) {
                $sql .= " AND o.dealer_id IN (SELECT smd.dealer_id FROM sales_managers_to_dealers smd WHERE smd.sales_manager_id = ?)";
                $params[] = $current_user_id;
            }
        }
        $sql .= " GROUP BY p.product_name, p.sku ORDER BY total_quantity_sold DESC";
        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $report_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Ürün satış performansı raporu çekilirken hata: " . $e->getMessage());
            $message = "Ürün satış performansı raporu oluşturulurken bir hata oluştu.";
            $message_type = 'error';
        }
        break;

    case 'order_status_distribution':
        $report_title = 'Sipariş Durumlarına Göre Dağılım';
        $sql = "SELECT order_status, COUNT(id) as count FROM orders WHERE DATE(order_date) BETWEEN ? AND ?";
        $params = [$start_date, $end_date];
         // Satış Müdürü kendi bayilerine ait sipariş durumlarını görebilir
        if (isset($_SESSION['user_role_id']) && $_SESSION['user_role_id'] == 3) {
            $current_user_id = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : null;
            if ($current_user_id !== null) {
                $sql .= " AND dealer_id IN (SELECT smd.dealer_id FROM sales_managers_to_dealers smd WHERE smd.sales_manager_id = ?)";
                $params[] = $current_user_id;
            }
        }
        $sql .= " GROUP BY order_status ORDER BY count DESC";
        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $report_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
            // Grafik için veri hazırlığı (PHP'de JSON olarak kodlanacak)
            $chart_labels = [];
            $chart_data = [];
            foreach ($report_data as $row) {
                $chart_labels[] = $row['order_status'];
                $chart_data[] = $row['count'];
            }
        } catch (PDOException $e) {
            error_log("Sipariş durum dağılımı raporu çekilirken hata: " . $e->getMessage());
            $message = "Sipariş durum dağılımı raporu oluşturulurken bir hata oluştu.";
            $message_type = 'error';
        }
        break;

    // Daha fazla rapor türü buraya eklenebilir (örn: tahsilat raporu, stok seviyeleri vb.)
}

// Eğer Excel export isteği varsa, HTML'i oluştur ve çık
if (isset($_GET['export']) && $_GET['export'] == 'excel') {
    echo '<table>';
    echo '<thead><tr><th colspan="' . count($report_data[0]) . '">' . htmlspecialchars($report_title) . ' (' . htmlspecialchars($start_date) . ' - ' . htmlspecialchars($end_date) . ')</th></tr></thead>';
    echo '<thead><tr>';
    foreach (array_keys($report_data[0]) as $column_name) {
        echo '<th>' . htmlspecialchars(str_replace('_', ' ', ucfirst($column_name))) . '</th>';
    }
    echo '</tr></thead>';
    echo '<tbody>';
    foreach ($report_data as $row) {
        echo '<tr>';
        foreach ($row as $value) {
            echo '<td>' . htmlspecialchars($value) . '</td>';
        }
        echo '</tr>';
    }
    echo '</tbody>';
    echo '</table>';
    exit; // HTML çıktısını gönder ve scripti sonlandır
}


$page_title = "Raporlar";
include 'includes/header.php';
?>

<div class="container">
    <h1>Raporlar</h1>

    <?php if (!empty($message)) : ?>
        <div class="message <?php echo $message_type; ?>"><?php echo $message; ?></div>
    <?php endif; ?>

    <div class="report-filters" style="margin-bottom: 20px; padding: 15px; border: 1px solid #ddd; border-radius: 5px; background-color: #f9f9f9;">
        <h3>Rapor Filtreleri</h3>
        <form action="reports.php" method="get">
            <div class="form-group" style="display: flex; flex-wrap: wrap; gap: 15px;">
                <div style="flex: 1 1 200px;">
                    <label for="report_type">Rapor Tipi:</label>
                    <select id="report_type" name="report_type" onchange="this.form.submit()">
                        <option value="sales_summary" <?php echo ($report_type == 'sales_summary') ? 'selected' : ''; ?>>Dönemsel Satış Özeti</option>
                        <option value="debt_status" <?php echo ($report_type == 'debt_status') ? 'selected' : ''; ?>>Bayi/Müşteri Borç Durumu</option>
                        <option value="product_sales_performance" <?php echo ($report_type == 'product_sales_performance') ? 'selected' : ''; ?>>Ürün Satış Performansı</option>
                        <option value="order_status_distribution" <?php echo ($report_type == 'order_status_distribution') ? 'selected' : ''; ?>>Sipariş Durum Dağılımı</option>
                        <!-- Diğer rapor türleri buraya eklenecek -->
                    </select>
                </div>
                <div style="flex: 1 1 150px;">
                    <label for="start_date">Başlangıç Tarihi:</label>
                    <input type="date" id="start_date" name="start_date" value="<?php echo htmlspecialchars($start_date); ?>">
                </div>
                <div style="flex: 1 1 150px;">
                    <label for="end_date">Bitiş Tarihi:</label>
                    <input type="date" id="end_date" name="end_date" value="<?php echo htmlspecialchars($end_date); ?>">
                </div>
                <?php if ($report_type == 'sales_summary' || $report_type == 'debt_status' || $report_type == 'order_status_distribution') : ?>
                    <div style="flex: 1 1 200px;">
                        <label for="dealer_id">Bayi/Müşteri:</label>
                        <select id="dealer_id" name="dealer_id">
                            <option value="0">Tümü</option>
                            <?php foreach ($all_dealers as $dealer) : ?>
                                <option value="<?php echo htmlspecialchars($dealer['id']); ?>" <?php echo ($dealer['id'] == $dealer_id) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($dealer['dealer_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php endif; ?>
                <?php if ($report_type == 'product_sales_performance') : ?>
                    <div style="flex: 1 1 200px;">
                        <label for="product_id">Ürün:</label>
                        <select id="product_id" name="product_id">
                            <option value="0">Tümü</option>
                            <?php foreach ($all_products as $product) : ?>
                                <option value="<?php echo htmlspecialchars($product['id']); ?>" <?php echo ($product['id'] == $product_id) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($product['product_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php endif; ?>
                <div style="flex: 1 1 auto; align-self: flex-end;">
                     <button type="submit" class="btn btn-primary" style="padding: 10px 20px;">Raporu Getir</button>
                     <?php if (!empty($report_data)) : ?>
                        <a href="reports.php?<?php echo http_build_query(array_merge($_GET, ['export' => 'excel'])); ?>" class="btn btn-success" style="padding: 10px 20px;">Excel'e Aktar</a>
                     <?php endif; ?>
                </div>
            </div>
        </form>
    </div>

    <div class="report-content" style="margin-top: 30px;">
        <h2><?php echo htmlspecialchars($report_title); ?></h2>
        <?php if (!empty($report_data)) : ?>
            <?php if ($report_type == 'order_status_distribution') : ?>
                <div style="width: 80%; margin: auto; max-width: 600px;">
                    <canvas id="orderStatusChart"></canvas>
                </div>
                <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
                <script>
                    document.addEventListener('DOMContentLoaded', function() {
                        const ctx = document.getElementById('orderStatusChart').getContext('2d');
                        new Chart(ctx, {
                            type: 'pie', // Pasta grafik
                            data: {
                                labels: <?php echo json_encode($chart_labels); ?>,
                                datasets: [{
                                    data: <?php echo json_encode($chart_data); ?>,
                                    backgroundColor: [
                                        '#6c757d', // Beklemede - Gri
                                        '#ffc107', // Satış Onayı Bekliyor - Sarı
                                        '#007bff', // Muhasebe Onayı Bekliyor - Mavi
                                        '#28a745', // Onaylandı - Yeşil
                                        '#dc3545', // Reddedildi - Kırmızı
                                        '#17a2b8', // Faturalandı - Turkuaz
                                        '#6f42c1', // Sevkiyatta - Mor
                                        '#20c997', // Tamamlandı - Açık Yeşil
                                        '#343a40'  // İptal Edildi - Koyu Gri
                                    ],
                                    borderColor: '#fff',
                                    borderWidth: 2
                                }]
                            },
                            options: {
                                responsive: true,
                                plugins: {
                                    legend: {
                                        position: 'top',
                                    },
                                    tooltip: {
                                        callbacks: {
                                            label: function(context) {
                                                let label = context.label || '';
                                                if (label) {
                                                    label += ': ';
                                                }
                                                if (context.parsed !== null) {
                                                    label += context.parsed + ' adet';
                                                }
                                                return label;
                                            }
                                        }
                                    }
                                }
                            }
                        });
                    });
                </script>
            <?php endif; ?>

            <?php if (!empty($report_data)) : ?>
            <table class="report-table" style="width: 100%; border-collapse: collapse; margin-top: 20px;">
                <thead>
                    <tr>
                        <?php foreach (array_keys($report_data[0]) as $column_name) : ?>
                            <th style="padding: 10px; border: 1px solid #ccc; background-color: #eee; text-align: left;">
                                <?php echo htmlspecialchars(str_replace('_', ' ', ucfirst($column_name))); ?>
                            </th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($report_data as $row) : ?>
                        <tr>
                            <?php foreach ($row as $key => $value) : ?>
                                <td style="padding: 10px; border: 1px solid #eee;">
                                    <?php
                                        // Özel formatlama
                                        if (strpos($key, 'total_sales') !== false || strpos($key, 'total_sales_value') !== false || strpos($key, 'credit_limit') !== false || strpos($key, 'current_debt') !== false) {
                                            echo number_format($value, 2, ',', '.') . ' TL';
                                        } else {
                                            echo htmlspecialchars($value);
                                        }
                                    ?>
                                </td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>

        <?php else : ?>
            <p>Seçilen filtrelemeye göre rapor verisi bulunamadı.</p>
        <?php endif; ?>
    </div>
</div>

<?php include 'includes/footer.php'; ?>
