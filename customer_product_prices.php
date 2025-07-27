<?php
session_start();
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once 'config.php';

// Yetki kontrolü: Sadece 'genel_mudur' veya 'genel_mudur_yardimcisi' rolleri bu sayfaya erişebilir.
check_permission(['genel_mudur', 'genel_mudur_yardimcisi']);

$page_title = "Müşteri Ürün Fiyatları Yönetimi";
$pdo = connect_db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$message = '';
$message_type = '';
$selected_customer_id = isset($_GET['customer_id']) ? intval($_GET['customer_id']) : 0;
$current_logged_in_user_id = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : null;

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $selected_customer_id = intval($_POST['customer_id']);

    if (isset($_POST['set_prices'])) {
        try {
            $pdo->beginTransaction();

            // Önce mevcut fiyatları sil (veya pasifize et), sonra yeniden ekle/güncelle
            // Basitlik adına mevcut yaklaşımda direkt silip yeniden ekliyoruz.
            // Daha karmaşık bir senaryoda UPDATE/INSERT on duplicate key update kullanılabilir.
            $stmt_delete = $pdo->prepare("DELETE FROM customer_product_prices WHERE customer_id = ?");
            $stmt_delete->execute([$selected_customer_id]);

            if (isset($_POST['product_prices']) && is_array($_POST['product_prices'])) {
                foreach ($_POST['product_prices'] as $product_id => $price) {
                    $product_id = intval($product_id);
                    $price = floatval(str_replace(',', '.', $price)); // Virgülü noktaya çevir

                    // Sadece geçerli fiyatları (0'dan büyük veya eşit) ve geçerli ürünleri kaydet
                    if ($product_id > 0 && $price >= 0) {
                        $stmt_insert = $pdo->prepare("INSERT INTO customer_product_prices (customer_id, product_id, price, created_by, created_at, updated_at) VALUES (?, ?, ?, ?, NOW(), NOW())");
                        $stmt_insert->execute([$selected_customer_id, $product_id, $price, $current_logged_in_user_id]);
                    }
                }
            }

            $pdo->commit();
            $message = "Müşteri ürün fiyatları başarıyla güncellendi.";
            $message_type = 'success';
        } catch (PDOException $e) {
            $pdo->rollBack();
            $message = "Fiyatlar güncellenirken bir hata oluştu: " . $e->getMessage();
            $message_type = 'error';
            error_log("Müşteri ürün fiyatı güncelleme hatası: " . $e->getMessage());
        }
    }
}

// Müşteri listesini çek
$customers = [];
try {
    $stmt_customers = $pdo->query("SELECT id, customer_name FROM customers WHERE is_active = TRUE ORDER BY customer_name ASC");
    $customers = $stmt_customers->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Müşteri listesi çekilirken hata oluştu: " . $e->getMessage());
    $message = "Müşteri listesi alınamadı.";
    $message_type = 'error';
}

// Tüm ürünleri çek
$all_products = [];
try {
    $stmt_products = $pdo->query("SELECT id, product_name, price FROM products WHERE is_active = TRUE ORDER BY product_name ASC");
    $all_products = $stmt_products->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Ürün listesi çekilirken hata oluştu: " . $e->getMessage());
    $message = "Ürün listesi alınamadı.";
    $message_type = 'error';
}

// Seçilen müşterinin mevcut ürün fiyatlarını çek
$customer_product_prices = [];
if ($selected_customer_id > 0) {
    try {
        $stmt_current_prices = $pdo->prepare("SELECT product_id, price FROM customer_product_prices WHERE customer_id = ?");
        $stmt_current_prices->execute([$selected_customer_id]);
        foreach ($stmt_current_prices->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $customer_product_prices[$row['product_id']] = $row['price'];
        }
    } catch (PDOException $e) {
        error_log("Müşteri ürün fiyatları çekilirken hata oluştu: " . $e->getMessage());
        $message = "Müşterinin mevcut fiyatları alınamadı.";
        $message_type = 'error';
    }
}

include 'includes/header.php';
?>

<div class="container">
    <h1><?php echo htmlspecialchars($page_title); ?></h1>

    <?php if (!empty($message)) : ?>
        <div class="message <?php echo $message_type; ?>"><?php echo $message; ?></div>
    <?php endif; ?>

    <form action="customer_product_prices.php" method="GET" class="form-inline mb-4">
        <div class="form-group mr-2">
            <label for="customer_select" class="mr-2">Müşteri Seç:</label>
            <select id="customer_select" name="customer_id" class="form-control" onchange="this.form.submit()">
                <option value="">-- Müşteri Seçiniz --</option>
                <?php foreach ($customers as $customer) : ?>
                    <option value="<?php echo htmlspecialchars($customer['id']); ?>" <?php echo ($selected_customer_id == $customer['id']) ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($customer['customer_name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
    </form>

    <?php if ($selected_customer_id > 0 && !empty($all_products)) : ?>
        <form action="customer_product_prices.php" method="POST">
            <input type="hidden" name="customer_id" value="<?php echo htmlspecialchars($selected_customer_id); ?>">

            <h3>Ürün Fiyatlarını Belirle</h3>
            <table class="table table-bordered table-striped">
                <thead>
                    <tr>
                        <th>Ürün Adı</th>
                        <th>Varsayılan Fiyat (TL)</th>
                        <th>Müşteriye Özel Fiyat (TL)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($all_products as $product) : ?>
                        <tr>
                            <td><?php echo htmlspecialchars($product['product_name']); ?></td>
                            <td><?php echo number_format($product['price'], 2, ',', '.') . ' TL'; ?></td>
                            <td>
                                <input type="number"
                                       name="product_prices[<?php echo htmlspecialchars($product['id']); ?>]"
                                       step="0.01"
                                       min="0"
                                       class="form-control"
                                       value="<?php echo htmlspecialchars(number_format($customer_product_prices[$product['id']] ?? $product['price'], 2, '.', '')); ?>"
                                       placeholder="Müşteriye özel fiyat">
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <button type="submit" name="set_prices" class="btn btn-primary">Fiyatları Kaydet</button>
        </form>
    <?php elseif ($selected_customer_id > 0 && empty($all_products)) : ?>
        <div class="alert alert-warning">Sistemde aktif ürün bulunmamaktadır. Lütfen öncelikle ürün ekleyiniz.</div>
    <?php elseif ($selected_customer_id == 0 && !empty($customers)) : ?>
        <div class="alert alert-info">Yukarıdan bir müşteri seçerek ürün fiyatlarını yönetmeye başlayabilirsiniz.</div>
    <?php elseif (empty($customers)) : ?>
         <div class="alert alert-warning">Sistemde aktif müşteri bulunmamaktadır. Lütfen öncelikle müşteri ekleyiniz.</div>
    <?php endif; ?>

</div>

<?php include 'includes/footer.php'; ?>