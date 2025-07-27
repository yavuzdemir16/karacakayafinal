<?php
// Hata raporlamayı aç
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Hata yakalama bloğu başlat
try {
    session_start();

    // config.php dosyasını dahil etmeye çalış
    if (!file_exists('config.php')) {
        throw new Exception("Hata: 'config.php' dosyası bulunamadı. Lütfen dosya yolunu kontrol edin.");
    }
    require_once 'config.php';

    // check_permission fonksiyonunun varlığını kontrol et
    if (!function_exists('check_permission')) {
        throw new Exception("Hata: 'check_permission' fonksiyonu bulunamadı. 'config.php' dosyasında veya ilgili kütüphanede tanımlı olduğundan emin olun.");
    }

    // Yetki kontrolü (bu sayfaya kimler erişebilir)
    check_permission(['genel_mudur', 'genel_mudur_yardimcisi']); // Sadece bu roller ürün ve kategori yönetimi yapabilir

    $message = '';
    $message_type = '';

    // $_GET['action'] boşsa varsayılan olarak 'list' ayarla
    $action = isset($_GET['action']) ? $_GET['action'] : 'list';

    // Mevcut ürün tiplerini çek (gerekirse başka bir yerden de çekilebilir)
    $product_types = ['PET', 'Damacana', 'Bardak', 'Demirbas', 'Koli/Kutu', 'Diğer']; // 'Koli/Kutu' ve 'Diğer' eklendi

    // company_settings'ten varsayılan KDV oranlarını çek
    $default_vat_rate_normal = 0.00;
    $default_vat_rate_fixed_asset = 0.00;
    try {
        $stmt_settings = $pdo->query("SELECT default_vat_rate_normal, default_vat_rate_fixed_asset FROM company_settings WHERE id = 1 LIMIT 1");
        $company_settings = $stmt_settings->fetch(PDO::FETCH_ASSOC);
        if ($company_settings) {
            $default_vat_rate_normal = $company_settings['default_vat_rate_normal'];
            $default_vat_rate_fixed_asset = $company_settings['default_vat_rate_fixed_asset'];
        }
    } catch (PDOException $e) {
        error_log("Firma ayarları (KDV oranları) çekilirken hata: " . $e->getMessage());
        // Hata durumunda varsayılan 0.00 kalır
    }


    // KATEGORİ YÖNETİMİ BAŞLANGICI
    // Kategori ekleme
    if (isset($_POST['add_category'])) {
        $category_name = trim($_POST['category_name']);
        if (!empty($category_name)) {
            try {
                $stmt = $pdo->prepare("INSERT INTO product_categories (category_name) VALUES (?)");
                $stmt->execute([$category_name]);
                $message = "Kategori başarıyla eklendi.";
                $message_type = 'success';
            } catch (PDOException $e) {
                if ($e->getCode() == 23000) { // Duplicate entry error
                    $message = "Bu kategori adı zaten mevcut.";
                } else {
                    $message = "Kategori eklenirken hata oluştu: " . $e->getMessage();
                }
                $message_type = 'error';
            }
        } else {
            $message = "Kategori adı boş olamaz.";
            $message_type = 'error';
        }
        $action = 'categories'; // Kategori listesine geri dön
    }

    // Kategori düzenleme
    if (isset($_POST['edit_category'])) {
        $category_id = $_POST['category_id'];
        $category_name = trim($_POST['category_name']);
        if (!empty($category_name) && !empty($category_id)) {
            try {
                $stmt = $pdo->prepare("UPDATE product_categories SET category_name = ? WHERE id = ?");
                $stmt->execute([$category_name, $category_id]);
                $message = "Kategori başarıyla güncellendi.";
                $message_type = 'success';
            } catch (PDOException $e) {
                if ($e->getCode() == 23000) {
                    $message = "Bu kategori adı zaten mevcut.";
                } else {
                    $message = "Kategori güncellenirken hata oluştu: " . $e->getMessage();
                }
                $message_type = 'error';
            }
        } else {
            $message = "Kategori adı veya ID boş olamaz.";
            $message_type = 'error';
        }
        $action = 'categories';
    }

    // Kategori silme
    if (isset($_GET['action']) && $_GET['action'] == 'delete_category' && isset($_GET['id'])) {
        $category_id = $_GET['id'];
        try {
            // Önce bu kategoriye bağlı ürün var mı kontrol et
            $stmt_check_products = $pdo->prepare("SELECT COUNT(*) FROM products WHERE category_id = ?");
            $stmt_check_products->execute([$category_id]);
            if ($stmt_check_products->fetchColumn() > 0) {
                $message = "Bu kategoriye bağlı ürünler olduğu için silinemez.";
                $message_type = 'error';
            } else {
                $stmt = $pdo->prepare("DELETE FROM product_categories WHERE id = ?");
                $stmt->execute([$category_id]);
                $message = "Kategori başarıyla silindi.";
                $message_type = 'success';
            }
        } catch (PDOException $e) {
            $message = "Kategori silinirken hata oluştu: " . $e->getMessage();
            $message_type = 'error';
        }
        $action = 'categories';
    }

    // Tüm kategorileri çek
    $categories = [];
    try {
        $stmt = $pdo->query("SELECT * FROM product_categories ORDER BY category_name ASC");
        $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Kategoriler çekilirken hata oluştu: " . $e->getMessage());
        $message = "Kategori bilgileri alınamadı.";
        $message_type = 'error';
    }
    // KATEGORİ YÖNETİMİ SONU


    // BAĞLI ÜRÜNLER YÖNETİMİ BAŞLANGICI
    // Bağlı ürün kuralı ekleme
    if (isset($_POST['add_dependency_group'])) { // Grup ekleme olarak değiştirildi
        $trigger_product_type = $_POST['trigger_product_type_group'];
        $dependent_products_data = json_decode($_POST['dependent_products_data'], true); // JSON'dan diziye dönüştür

        if (!empty($trigger_product_type) && !empty($dependent_products_data)) {
            $pdo->beginTransaction();
            try {
                // Önce bu tetikleyici ürün tipine ait mevcut kuralları sil
                $stmt_delete_existing = $pdo->prepare("DELETE FROM product_dependencies WHERE trigger_product_type = ?");
                $stmt_delete_existing->execute([$trigger_product_type]);

                // Yeni kuralları ekle
                $sql_insert = "INSERT INTO product_dependencies (trigger_product_type, dependent_product_id, quantity_per_unit, is_editable) VALUES (?, ?, ?, ?)";
                $stmt_insert = $pdo->prepare($sql_insert);

                foreach ($dependent_products_data as $dp_item) {
                    $dependent_product_id = $dp_item['product_id'];
                    $quantity_per_unit = $dp_item['quantity_per_unit'];
                    $is_editable = $dp_item['is_editable'];
                    $stmt_insert->execute([$trigger_product_type, $dependent_product_id, $quantity_per_unit, $is_editable]);
                }
                $pdo->commit();
                $message = "Bağlı ürün kural(lar)ı başarıyla eklendi/güncellendi.";
                $message_type = 'success';
            } catch (PDOException $e) {
                $pdo->rollBack();
                $message = "Bağlı ürün kural(lar)ı eklenirken hata oluştu: " . $e->getMessage();
                $message_type = 'error';
            }
        } else {
            $message = "Tetikleyici ürün tipi seçmeli ve en az bir bağımlı ürün eklemelisiniz.";
            $message_type = 'error';
        }
        $action = 'dependencies'; // Bağlı ürünler listesine geri dön
    }

    // Bağlı ürün kuralı silme (tekil silme)
    if (isset($_GET['action']) && $_GET['action'] == 'delete_dependency_item' && isset($_GET['id'])) {
        $dependency_item_id = $_GET['id'];
        try {
            $stmt = $pdo->prepare("DELETE FROM product_dependencies WHERE id = ?");
            $stmt->execute([$dependency_item_id]);
            $message = "Bağlı ürün kuralı başarıyla silindi.";
            $message_type = 'success';
        } catch (PDOException $e) {
            $message = "Bağlı ürün kuralı silinirken hata oluştu: " . $e->getMessage();
            $message_type = 'error';
        }
        $action = 'dependencies';
    }

    // Tüm bağlı ürün kurallarını çek
    // Şimdi her tetikleyici ürün tipi için birden fazla bağımlı ürün olabileceği için gruplayarak çekeceğiz.
    $dependencies_grouped = [];
    try {
        $stmt = $pdo->query("SELECT pd.*, p.product_name as dependent_product_name, p.product_type as dependent_product_type, p.image_url as dependent_product_image FROM product_dependencies pd JOIN products p ON pd.dependent_product_id = p.id ORDER BY pd.trigger_product_type, p.product_name ASC");
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $dependencies_grouped[$row['trigger_product_type']][] = $row;
        }
    } catch (PDOException $e) {
        error_log("Bağlı ürün kuralları çekilirken hata oluştu: " . $e->getMessage());
        $message = "Bağlı ürün kuralları alınamadı.";
        $message_type = 'error';
    }
    // BAĞLI ÜRÜNLER YÖNETİMİ SONU


    // ÜRÜN YÖNETİMİ BAŞLANGICI
    // Ürün ekleme
    if (isset($_POST['add_product'])) {
        $product_name = trim($_POST['product_name']);
        $sku = trim($_POST['sku']);
        $price = floatval($_POST['price']);
        $stock_quantity = intval($_POST['stock_quantity']);
        $product_type = $_POST['product_type'];
        $vat_rate = floatval($_POST['vat_rate']); // KDV oranı eklendi
        $description = trim($_POST['description']);
        $is_active = isset($_POST['is_active']) ? 1 : 0;
        $category_id = isset($_POST['category_id']) ? intval($_POST['category_id']) : null;

        $image_url = null;
        if (isset($_FILES['product_image']) && $_FILES['product_image']['error'] == UPLOAD_ERR_OK) {
            $upload_dir = 'uploads/products/';
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0755, true);
            }
            $file_extension = pathinfo($_FILES['product_image']['name'], PATHINFO_EXTENSION);
            $new_file_name = uniqid('product_') . '.' . $file_extension;
            $target_file = $upload_dir . $new_file_name;

            if (move_uploaded_file($_FILES['product_image']['tmp_name'], $target_file)) {
                $image_url = $target_file;
            } else {
                $message = "Resim yüklenirken hata oluştu.";
                $message_type = 'error';
            }
        }

        if (!empty($product_name) && !empty($sku) && $price >= 0 && $stock_quantity >= 0) {
            try {
                // vat_rate sütunu eklendi
                $stmt = $pdo->prepare("INSERT INTO products (product_name, sku, price, stock_quantity, product_type, vat_rate, image_url, created_by, description, is_active, category_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$product_name, $sku, $price, $stock_quantity, $product_type, $vat_rate, $image_url, $_SESSION['user_id'], $description, $is_active, $category_id]);
                $message = "Ürün başarıyla eklendi.";
                $message_type = 'success';
            } catch (PDOException $e) {
                if ($e->getCode() == 23000) { // Duplicate entry error (for SKU)
                    $message = "Bu SKU değeri zaten mevcut. Lütfen benzersiz bir SKU girin.";
                } else {
                    $message = "Ürün eklenirken hata oluştu: " . $e->getMessage();
                }
                $message_type = 'error';
            }
        } else {
            $message = "Tüm zorunlu alanları doldurun ve geçerli değerler girin.";
            $message_type = 'error';
        }
        $action = 'list';
    }

    // Ürün düzenleme
    if (isset($_POST['edit_product'])) {
        $product_id = $_POST['product_id'];
        $product_name = trim($_POST['product_name']);
        $sku = trim($_POST['sku']);
        $price = floatval($_POST['price']);
        $stock_quantity = intval($_POST['stock_quantity']);
        $product_type = $_POST['product_type'];
        $vat_rate = floatval($_POST['vat_rate']); // KDV oranı eklendi
        $description = trim($_POST['description']);
        $is_active = isset($_POST['is_active']) ? 1 : 0;
        $category_id = isset($_POST['category_id']) ? intval($_POST['category_id']) : null;


        $current_image_url = $_POST['current_image_url'] ?? null; // Mevcut resim URL'si

        if (isset($_FILES['product_image']) && $_FILES['product_image']['error'] == UPLOAD_ERR_OK) {
            $upload_dir = 'uploads/products/';
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0755, true);
            }
            $file_extension = pathinfo($_FILES['product_image']['name'], PATHINFO_EXTENSION);
            $new_file_name = uniqid('product_') . '.' . $file_extension;
            $target_file = $upload_dir . $new_file_name;

            if (move_uploaded_file($_FILES['product_image']['tmp_name'], $target_file)) {
                $current_image_url = $target_file; // Yeni resim yüklendi
            } else {
                $message = "Yeni resim yüklenirken hata oluştu.";
                $message_type = 'error';
            }
        }

        if (!empty($product_id) && !empty($product_name) && !empty($sku) && $price >= 0 && $stock_quantity >= 0) {
            try {
                // vat_rate sütunu eklendi
                $stmt = $pdo->prepare("UPDATE products SET product_name = ?, sku = ?, price = ?, stock_quantity = ?, product_type = ?, vat_rate = ?, image_url = ?, last_updated_by = ?, description = ?, is_active = ?, category_id = ?, updated_at = NOW() WHERE id = ?");
                $stmt->execute([$product_name, $sku, $price, $stock_quantity, $product_type, $vat_rate, $current_image_url, $_SESSION['user_id'], $description, $is_active, $category_id, $product_id]);
                $message = "Ürün başarıyla güncellendi.";
                $message_type = 'success';
            } catch (PDOException $e) {
                if ($e->getCode() == 23000) { // Duplicate entry error (for SKU)
                    $message = "Bu SKU değeri zaten mevcut. Lütfen benzersiz bir SKU girin.";
                } else {
                    $message = "Ürün güncellenirken hata oluştu: " . $e->getMessage();
                }
                $message_type = 'error';
            }
        } else {
            $message = "Tüm zorunlu alanları doldurun ve geçerli değerler girin.";
            $message_type = 'error';
        }
        $action = 'list';
    }

    // Ürün silme
    if (isset($_GET['action']) && $_GET['action'] == 'delete' && isset($_GET['id'])) {
        $product_id = $_GET['id'];
        try {
            // Ürün siparişlerde kullanıldıysa silinmemeli
            $stmt_check_orders = $pdo->prepare("SELECT COUNT(*) FROM order_items WHERE product_id = ?");
            $stmt_check_orders->execute([$product_id]);
            if ($stmt_check_orders->fetchColumn() > 0) {
                $message = "Bu ürün siparişlerde kullanıldığı için silinemez. Pasif duruma getirebilirsiniz.";
                $message_type = 'error';
            } else {
                // Ürünün resmini sil
                $stmt_get_image = $pdo->prepare("SELECT image_url FROM products WHERE id = ?");
                $stmt_get_image->execute([$product_id]);
                $image_to_delete = $stmt_get_image->fetchColumn();
                if ($image_to_delete && file_exists($image_to_delete)) {
                    unlink($image_to_delete);
                }

                // product_dependencies'den ilgili kayıtları sil
                // Eğer silinen ürün trigger_product_type olarak tanımlanmışsa, bu kuralı da silmeli
                $stmt_delete_deps = $pdo->prepare("DELETE FROM product_dependencies WHERE dependent_product_id = ? OR trigger_product_type IN (SELECT product_type FROM products WHERE id = ?)");
                $stmt_delete_deps->execute([$product_id, $product_id]);

                // customer_product_prices'dan ilgili kayıtları sil
                $stmt_delete_customer_prices = $pdo->prepare("DELETE FROM customer_product_prices WHERE product_id = ?");
                $stmt_delete_customer_prices->execute([$product_id]);


                $stmt = $pdo->prepare("DELETE FROM products WHERE id = ?");
                $stmt->execute([$product_id]);
                $message = "Ürün başarıyla silindi.";
                $message_type = 'success';
            }
        } catch (PDOException $e) {
            $message = "Ürün silinirken hata oluştu: " . $e->getMessage();
            $message_type = 'error';
        }
        $action = 'list';
    }

    // Tüm ürünleri çek (Bağlı ürünler ekranı için de kullanılacak)
    $all_products_for_selection = [];
    try {
        // vat_rate sütunu çekildi
        $stmt_all_products = $pdo->query("SELECT id, product_name, price, stock_quantity, sku, image_url, product_type, vat_rate FROM products WHERE is_active = TRUE ORDER BY product_name ASC");
        $all_products_for_selection = $stmt_all_products->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Tüm ürünler çekilirken hata oluştu (seçim için): " . $e->getMessage());
        $message = "Ürün bilgileri alınamadı.";
        $message_type = 'error';
    }

    // Ana ürün listeleme (normal ürünler sekmesi için)
    $products = [];
    $product_details = null;
    if ($action == 'list') {
        try {
            // vat_rate sütunu çekildi
            $stmt = $pdo->query("SELECT p.*, c.category_name FROM products p LEFT JOIN product_categories c ON p.category_id = c.id ORDER BY p.product_name ASC");
            $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Ürünler çekilirken hata oluştu: " . $e->getMessage());
            $message = "Ürün bilgileri alınamadı.";
            $message_type = 'error';
        }
    } elseif ($action == 'edit_product_form' && isset($_GET['id'])) {
        $product_id = $_GET['id'];
        try {
            // vat_rate sütunu çekildi
            $stmt = $pdo->prepare("SELECT p.*, c.category_name FROM products p LEFT JOIN product_categories c ON p.category_id = c.id WHERE p.id = ?");
            $stmt->execute([$product_id]);
            $product_details = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$product_details) {
                $message = "Ürün bulunamadı.";
                $message_type = 'error';
                $action = 'list';
            }
        } catch (PDOException $e) {
            error_log("Ürün detayları çekilirken hata oluştu: " . $e->getMessage());
            $message = "Ürün detayları alınırken bir hata oluştu.";
            $message_type = 'error';
            $action = 'list';
        }
    }
    // ÜRÜN YÖNETİMİ SONU


    $page_title = "Ürün Yönetimi";
    include 'includes/header.php';
    ?>

    <style>
        .nav-tabs {
            display: flex;
            border-bottom: 1px solid #dee2e6;
            margin-bottom: 20px;
        }
        .nav-tabs .nav-link {
            padding: 0.5rem 1rem;
            border: 1px solid transparent;
            border-top-left-radius: 0.25rem;
            border-top-right-radius: 0.25rem;
            margin-bottom: -1px;
            text-decoration: none;
            color: #495057;
        }
        .nav-tabs .nav-link.active {
            color: #495057;
            background-color: #fff;
            border-color: #dee2e6 #dee2e6 #fff;
        }
        .nav-tabs .nav-link:hover:not(.active) {
            border-color: #e9ecef #e9ecef #dee2e6;
        }
        .product-image-preview {
            max-width: 150px;
            height: auto;
            display: block;
            margin-top: 10px;
            border: 1px solid #ddd;
            padding: 5px;
        }
        .status-active { background-color: #28a745; color: white; padding: 3px 6px; border-radius: 4px; }
        .status-passive { background-color: #6c757d; color: white; padding: 3px 6px; border-radius: 4px; }

        /* Yeni Ürün Seçim Grid Stilleri (orders.php'den alınmıştır) */
        .product-selection-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); /* Responsive grid */
            gap: 15px;
            margin-top: 20px;
        }

        .product-card {
            border: 1px solid #ddd;
            border-radius: 8px;
            padding: 10px;
            text-align: center;
            background-color: #fff;
            box-shadow: 0 2px 5px rgba(0,0,0,0.05);
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            position: relative;
            cursor: pointer; /* Seçilebilir olduğunu belirtir */
            transition: all 0.2s ease-in-out;
        }

        .product-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 4px 10px rgba(0,0,0,0.1);
        }

        .product-card.selected {
            border: 2px solid #007bff; /* Seçildiğinde mavi çerçeve */
            box-shadow: 0 0 10px rgba(0, 123, 255, 0.5);
            background-color: #e6f7ff;
        }
        .product-card.selected::after {
            content: '✓';
            position: absolute;
            top: 5px;
            right: 5px;
            background-color: #28a745;
            color: white;
            border-radius: 50%;
            width: 24px;
            height: 24px;
            display: flex;
            justify-content: center;
            align-items: center;
            font-size: 1.2em;
        }


        .product-card img {
            max-width: 100%;
            height: 120px; /* Fixed height for consistency */
            object-fit: contain; /* Ensures the image fits within the bounds without cropping */
            border-bottom: 1px solid #eee;
            padding-bottom: 8px;
            margin-bottom: 8px;
        }

        .product-card h4 {
            font-size: 1.1em;
            margin: 5px 0;
            color: #333;
            height: 40px; /* fixed height for name */
            overflow: hidden; /* hide overflow text */
            text-overflow: ellipsis; /* show ellipsis for overflow text */
        }

        .product-card p {
            font-size: 0.9em;
            color: #666;
            margin: 3px 0;
        }

        /* Yeni: Seçilen Bağımlı Ürünler Listesi Stilleri */
        .selected-dependent-products-container {
            border: 1px solid #007bff;
            padding: 15px;
            margin-top: 20px;
            background-color: #e6f7ff;
            border-radius: 8px;
            min-height: 100px;
        }
        .dependent-product-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 8px;
            border-bottom: 1px dashed #a6d8ff;
            background-color: #fff;
            margin-bottom: 5px;
            border-radius: 4px;
        }
        .dependent-product-item:last-child {
            border-bottom: none;
        }
        .dependent-product-item .info {
            display: flex;
            align-items: center;
        }
        .dependent-product-item img {
            width: 50px;
            height: 50px;
            object-fit: cover;
            margin-right: 10px;
            border-radius: 4px;
        }
        .dependent-product-item .details h4 {
            margin: 0;
            font-size: 1em;
        }
        .dependent-product-item .details p {
            margin: 0;
            font-size: 0.8em;
            color: #555;
        }
        .dependent-product-item .controls {
            display: flex;
            align-items: center;
        }
        .dependent-product-item .controls input[type="number"] {
            width: 70px;
            text-align: center;
            margin: 0 10px;
            padding: 5px;
            border: 1px solid #ccc;
            border-radius: 4px;
        }
        .dependent-product-item .controls .remove-btn {
            background-color: #dc3545;
            color: white;
            border: none;
            padding: 5px 10px;
            border-radius: 4px;
            cursor: pointer;
        }

        .dependency-group-card {
            border: 1px solid #ccc;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 15px;
            background-color: #f9f9f9;
        }
        .dependency-group-header {
            font-weight: bold;
            margin-bottom: 10px;
            font-size: 1.1em;
            color: #333;
        }
        .dependency-group-item {
            display: flex;
            align-items: center;
            margin-bottom: 5px;
            padding: 5px;
            background-color: #fff;
            border-radius: 4px;
            border: 1px solid #eee;
        }
        .dependency-group-item img {
            width: 40px;
            height: 40px;
            object-fit: cover;
            margin-right: 10px;
            border-radius: 3px;
        }
        .dependency-group-item span {
            flex-grow: 1;
        }
        .dependency-group-actions {
            margin-top: 10px;
            text-align: right;
        }
    </style>

    <div class="container">
        <h1>Ürün Yönetimi</h1>

        <?php if (!empty($message)) : ?>
            <div class="message <?php echo $message_type; ?>"><?php echo $message; ?></div>
        <?php endif; ?>

        <div class="nav-tabs">
            <a class="nav-link <?php echo ($action == 'list' || $action == 'add' || $action == 'edit_product_form') ? 'active' : ''; ?>" href="products.php?action=list">Ürünler</a>
            <a class="nav-link <?php echo ($action == 'categories') ? 'active' : ''; ?>" href="products.php?action=categories">Kategoriler</a>
            <a class="nav-link <?php echo ($action == 'dependencies') ? 'active' : ''; ?>" href="products.php?action=dependencies">Bağlı Ürünler</a>
        </div>

        <?php if ($action == 'list') : ?>
            <p><a href="products.php?action=add" class="btn btn-success">Yeni Ürün Ekle</a></p>
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Görsel</th>
                        <th>Ürün Adı</th>
                        <th>SKU</th>
                        <th>Fiyat</th>
                        <th>Stok</th>
                        <th>Tip</th>
                        <th>KDV (%)</th>
                        <th>Kategori</th>
                        <th>Durum</th>
                        <th>İşlemler</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($products)) : ?>
                        <?php foreach ($products as $product) : ?>
                            <tr>
                                <td><?php echo htmlspecialchars($product['id']); ?></td>
                                <td>
                                    <?php if ($product['image_url']) : ?>
                                        <img src="<?php echo htmlspecialchars($product['image_url']); ?>" alt="<?php echo htmlspecialchars($product['product_name']); ?>" style="width: 50px; height: 50px; object-fit: cover;">
                                    <?php else : ?>
                                        <img src="placeholder.jpg" alt="No Image" style="width: 50px; height: 50px; object-fit: cover;">
                                    <?php endif; ?>
                                </td>
                                <td><?php echo htmlspecialchars($product['product_name']); ?></td>
                                <td><?php echo htmlspecialchars($product['sku']); ?></td>
                                <td><?php echo number_format($product['price'], 2, ',', '.'); ?> TL</td>
                                <td><?php echo htmlspecialchars($product['stock_quantity']); ?></td>
                                <td><?php echo htmlspecialchars($product['product_type']); ?></td>
                                <td><?php echo number_format($product['vat_rate'], 2, ',', '.'); ?></td> <!-- KDV oranı eklendi -->
                                <td><?php echo htmlspecialchars($product['category_name'] ?: 'Belirtilmemiş'); ?></td>
                                <td><span class="status-<?php echo $product['is_active'] ? 'active' : 'passive'; ?>"><?php echo $product['is_active'] ? 'Aktif' : 'Pasif'; ?></span></td>
                                <td>
                                    <a href="products.php?action=edit_product_form&id=<?php echo $product['id']; ?>" class="btn btn-warning">Düzenle</a>
                                    <a href="products.php?action=delete&id=<?php echo $product['id']; ?>" class="btn btn-danger" onclick="return confirm('Bu ürünü silmek istediğinizden emin misiniz? Bu işlem geri alınamaz.');">Sil</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else : ?>
                        <tr><td colspan="11">Henüz hiç ürün eklenmedi.</td></tr> <!-- Sütun sayısı güncellendi -->
                    <?php endif; ?>
                </tbody>
            </table>

        <?php elseif ($action == 'add') : ?>
            <h2>Yeni Ürün Ekle</h2>
            <form action="products.php" method="post" enctype="multipart/form-data">
                <div class="form-group">
                    <label for="product_name">Ürün Adı:</label>
                    <input type="text" id="product_name" name="product_name" required>
                </div>
                <div class="form-group">
                    <label for="sku">SKU (Stok Kodu):</label>
                    <input type="text" id="sku" name="sku" required>
                </div>
                <div class="form-group">
                    <label for="price">Fiyat:</label>
                    <input type="number" id="price" name="price" step="0.01" min="0" required>
                </div>
                <div class="form-group">
                    <label for="stock_quantity">Stok Miktarı:</label>
                    <input type="number" id="stock_quantity" name="stock_quantity" min="0" required>
                </div>
                <div class="form-group">
                    <label for="product_type">Ürün Tipi:</label>
                    <select id="product_type" name="product_type" required onchange="setDefaultVatRate()">
                        <?php foreach ($product_types as $type) : ?>
                            <option value="<?php echo htmlspecialchars($type); ?>"><?php echo htmlspecialchars($type); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="vat_rate">KDV Oranı (%):</label>
                    <input type="number" id="vat_rate" name="vat_rate" step="0.01" min="0" required value="<?php echo htmlspecialchars($default_vat_rate_normal); ?>">
                </div>
                <div class="form-group">
                    <label for="category_id">Kategori:</label>
                    <select id="category_id" name="category_id">
                        <option value="">Kategori Seçin</option>
                        <?php foreach ($categories as $category) : ?>
                            <option value="<?php echo htmlspecialchars($category['id']); ?>"><?php echo htmlspecialchars($category['category_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="product_image">Ürün Görseli:</label>
                    <input type="file" id="product_image" name="product_image" accept="image/*">
                </div>
                <div class="form-group">
                    <label for="description">Açıklama:</label>
                    <textarea id="description" name="description" rows="3"></textarea>
                </div>
                <div class="form-group">
                    <input type="checkbox" id="is_active" name="is_active" value="1" checked>
                    <label for="is_active">Aktif</label>
                </div>
                <div class="form-group">
                    <button type="submit" name="add_product" class="btn btn-primary">Ürün Ekle</button>
                    <a href="products.php" class="btn btn-secondary">İptal</a>
                </div>
            </form>
            <script>
                const defaultVatNormal = <?php echo json_encode($default_vat_rate_normal); ?>;
                const defaultVatFixedAsset = <?php echo json_encode($default_vat_rate_fixed_asset); ?>;

                function setDefaultVatRate() {
                    const productTypeSelect = document.getElementById('product_type');
                    const vatRateInput = document.getElementById('vat_rate');
                    if (productTypeSelect.value === 'Demirbas') {
                        vatRateInput.value = defaultVatFixedAsset;
                    } else {
                        vatRateInput.value = defaultVatNormal;
                    }
                }
                // Sayfa yüklendiğinde varsayılan KDV oranını ayarla
                document.addEventListener('DOMContentLoaded', setDefaultVatRate);
            </script>

        <?php elseif ($action == 'edit_product_form' && $product_details) : ?>
            <h2>Ürün Düzenle (ID: <?php echo htmlspecialchars($product_details['id']); ?>)</h2>
            <form action="products.php" method="post" enctype="multipart/form-data">
                <input type="hidden" name="product_id" value="<?php echo htmlspecialchars($product_details['id']); ?>">
                <input type="hidden" name="current_image_url" value="<?php echo htmlspecialchars($product_details['image_url']); ?>">
                <div class="form-group">
                    <label for="product_name">Ürün Adı:</label>
                    <input type="text" id="product_name" name="product_name" value="<?php echo htmlspecialchars($product_details['product_name']); ?>" required>
                </div>
                <div class="form-group">
                    <label for="sku">SKU (Stok Kodu):</label>
                    <input type="text" id="sku" name="sku" value="<?php echo htmlspecialchars($product_details['sku']); ?>" required>
                </div>
                <div class="form-group">
                    <label for="price">Fiyat:</label>
                    <input type="number" id="price" name="price" step="0.01" min="0" value="<?php echo htmlspecialchars($product_details['price']); ?>" required>
                </div>
                <div class="form-group">
                    <label for="stock_quantity">Stok Miktarı:</label>
                    <input type="number" id="stock_quantity" name="stock_quantity" min="0" value="<?php echo htmlspecialchars($product_details['stock_quantity']); ?>" required>
                </div>
                <div class="form-group">
                    <label for="product_type">Ürün Tipi:</label>
                    <select id="product_type" name="product_type" required onchange="setDefaultVatRateEdit()">
                        <?php foreach ($product_types as $type) : ?>
                            <option value="<?php echo htmlspecialchars($type); ?>" <?php echo ($product_details['product_type'] == $type) ? 'selected' : ''; ?>><?php echo htmlspecialchars($type); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="vat_rate">KDV Oranı (%):</label>
                    <input type="number" id="vat_rate" name="vat_rate" step="0.01" min="0" required value="<?php echo htmlspecialchars($product_details['vat_rate']); ?>">
                </div>
                <div class="form-group">
                    <label for="category_id">Kategori:</label>
                    <select id="category_id" name="category_id">
                        <option value="">Kategori Seçin</option>
                        <?php foreach ($categories as $category) : ?>
                            <option value="<?php echo htmlspecialchars($category['id']); ?>" <?php echo ($product_details['category_id'] == $category['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($category['category_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="product_image">Ürün Görseli:</label>
                    <input type="file" id="product_image" name="product_image" accept="image/*">
                    <?php if ($product_details['image_url']) : ?>
                        <img src="<?php echo htmlspecialchars($product_details['image_url']); ?>" alt="Mevcut Görsel" class="product-image-preview">
                        <small>Mevcut görsel.</small>
                    <?php else : ?>
                        <small>Henüz görsel yüklenmedi.</small>
                    <?php endif; ?>
                </div>
                <div class="form-group">
                    <label for="description">Açıklama:</label>
                    <textarea id="description" name="description" rows="3"><?php echo htmlspecialchars($product_details['description']); ?></textarea>
                </div>
                <div class="form-group">
                    <input type="checkbox" id="is_active" name="is_active" value="1" <?php echo $product_details['is_active'] ? 'checked' : ''; ?>>
                    <label for="is_active">Aktif</label>
                </div>
                <div class="form-group">
                    <button type="submit" name="edit_product" class="btn btn-primary">Ürünü Güncelle</button>
                    <a href="products.php" class="btn btn-secondary">İptal</a>
                </div>
            </form>
            <script>
                const defaultVatNormal = <?php echo json_encode($default_vat_rate_normal); ?>;
                const defaultVatFixedAsset = <?php echo json_encode($default_vat_rate_fixed_asset); ?>;

                function setDefaultVatRateEdit() {
                    const productTypeSelect = document.getElementById('product_type');
                    const vatRateInput = document.getElementById('vat_rate');
                    if (productTypeSelect.value === 'Demirbas') {
                        vatRateInput.value = defaultVatFixedAsset;
                    } else {
                        // Eğer ürün tipi değiştiyse ve Demirbaş değilse, mevcut KDV'yi koru veya varsayılana dön
                        // Basitlik için sadece Demirbaş durumunda değiştiriyoruz, diğer durumlarda kullanıcının girdiği kalır.
                        // vatRateInput.value = defaultVatNormal; // Bunu kullanırsanız Demirbaş'tan diğerine geçince varsayılana döner
                    }
                }
                // Sayfa yüklendiğinde ve product_details mevcutsa, zaten doğru KDV değeri yüklü olacaktır.
                // Sadece ürün tipi değiştirildiğinde KDV'yi güncellemek için event listener yeterli.
            </script>

        <?php elseif ($action == 'categories') : ?>
            <h2>Kategori Yönetimi</h2>

            <h3>Yeni Kategori Ekle</h3>
            <form action="products.php" method="post">
                <input type="hidden" name="action" value="categories">
                <div class="form-group">
                    <label for="category_name">Kategori Adı:</label>
                    <input type="text" id="category_name" name="category_name" required>
                </div>
                <div class="form-group">
                    <button type="submit" name="add_category" class="btn btn-success">Kategori Ekle</button>
                </div>
            </form>

            <h3 style="margin-top: 30px;">Mevcut Kategoriler</h3>
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Kategori Adı</th>
                        <th>İşlemler</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($categories)) : ?>
                        <?php foreach ($categories as $category) : ?>
                            <tr>
                                <td><?php echo htmlspecialchars($category['id']); ?></td>
                                <td>
                                    <span id="category_name_<?php echo $category['id']; ?>"><?php echo htmlspecialchars($category['category_name']); ?></span>
                                    <form action="products.php" method="post" style="display: none;" id="edit_category_form_<?php echo $category['id']; ?>">
                                        <input type="hidden" name="action" value="categories">
                                        <input type="hidden" name="category_id" value="<?php echo htmlspecialchars($category['id']); ?>">
                                        <input type="text" name="category_name" value="<?php echo htmlspecialchars($category['category_name']); ?>" required>
                                        <button type="submit" name="edit_category" class="btn btn-sm btn-primary">Kaydet</button>
                                        <button type="button" class="btn btn-sm btn-secondary" onclick="toggleEditForm(<?php echo $category['id']; ?>)">İptal</button>
                                    </form>
                                </td>
                                <td>
                                    <button type="button" class="btn btn-warning btn-sm" onclick="toggleEditForm(<?php echo $category['id']; ?>)">Düzenle</button>
                                    <a href="products.php?action=delete_category&id=<?php echo $category['id']; ?>" class="btn btn-danger btn-sm" onclick="return confirm('Bu kategoriyi silmek istediğinizden emin misiniz? Bu kategoriye bağlı ürünler varsa silinemez.');">Sil</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else : ?>
                        <tr><td colspan="3">Henüz hiç kategori eklenmedi.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
            <script>
                function toggleEditForm(categoryId) {
                    const nameSpan = document.getElementById('category_name_' + categoryId);
                    const editForm = document.getElementById('edit_category_form_' + categoryId);
                    if (nameSpan.style.display === 'none') {
                        nameSpan.style.display = 'inline';
                        editForm.style.display = 'none';
                    } else {
                        nameSpan.style.display = 'none';
                        editForm.style.display = 'inline-block';
                        editForm.querySelector('input[type="text"]').focus();
                    }
                }
            </script>

        <?php elseif ($action == 'dependencies') : ?>
            <h2>Bağlı Ürün Kuralları Yönetimi</h2>

            <h3>Yeni Bağlı Ürün Grubu Ekle/Güncelle</h3>
            <form action="products.php" method="post">
                <input type="hidden" name="action" value="dependencies">
                <input type="hidden" name="dependent_products_data" id="dependent_products_data">

                <div class="form-group">
                    <label for="trigger_product_type_group">Tetikleyici Ürün Tipi:</label>
                    <select id="trigger_product_type_group" name="trigger_product_type_group" required onchange="loadExistingDependencies(this.value);">
                        <option value="">Seçiniz</option>
                        <?php foreach ($product_types as $type) : ?>
                            <option value="<?php echo htmlspecialchars($type); ?>"><?php echo htmlspecialchars($type); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <small>Bu ürün tipinden bir ürün sipariş edildiğinde, aşağıdaki bağımlı ürünler otomatik eklenecektir.</small>
                </div>

                <hr>
                <h4>Bağımlı Ürünleri Seçin:</h4>
                <div class="selected-dependent-products-container">
                    <h5>Seçili Bağımlı Ürünler:</h5>
                    <div id="selected_dependent_products_list">
                        <!-- Seçilen bağımlı ürünler buraya eklenecek -->
                        <p id="no_dependent_products_yet" style="color: #666;">Henüz bağımlı ürün seçilmedi.</p>
                    </div>
                </div>

                <p style="margin-top: 20px;">Aşağıdan ürünleri seçerek bağımlı olarak ekleyin:</p>
                <div class="product-selection-grid">
                    <?php if (!empty($all_products_for_selection)) : ?>
                        <?php foreach ($all_products_for_selection as $product) : ?>
                            <div class="product-card"
                                data-product-id="<?php echo htmlspecialchars($product['id']); ?>"
                                data-product-name="<?php echo htmlspecialchars($product['product_name']); ?>"
                                data-product-price="<?php echo htmlspecialchars($product['price']); ?>"
                                data-product-stock="<?php echo htmlspecialchars($product['stock_quantity']); ?>"
                                data-product-image="<?php echo htmlspecialchars($product['image_url'] ?: 'placeholder.jpg'); ?>"
                                data-product-type="<?php echo htmlspecialchars($product['product_type']); ?>"
                                data-product-vat="<?php echo htmlspecialchars($product['vat_rate']); ?>"
                                onclick="toggleDependentProductSelection(this);">
                                <img src="<?php echo htmlspecialchars($product['image_url'] ?: 'placeholder.jpg'); ?>" alt="<?php echo htmlspecialchars($product['product_name']); ?>">
                                <h4><?php echo htmlspecialchars($product['product_name']); ?></h4>
                                <p>Stok: <?php echo htmlspecialchars($product['stock_quantity']); ?></p>
                                <p>Fiyat: <?php echo number_format($product['price'], 2, ',', '.'); ?> TL</p>
                                <p>KDV: %<?php echo number_format($product['vat_rate'], 2, ',', '.'); ?></p>
                            </div>
                        <?php endforeach; ?>
                    <?php else : ?>
                        <p>Henüz hiç ürün bulunmamaktadır.</p>
                    <?php endif; ?>
                </div>

                <div class="form-group" style="margin-top: 20px;">
                    <button type="submit" name="add_dependency_group" class="btn btn-success">Kural Grubunu Kaydet/Güncelle</button>
                </div>
            </form>

            <h3 style="margin-top: 30px;">Mevcut Bağlı Ürün Kuralları</h3>
            <?php if (!empty($dependencies_grouped)) : ?>
                <?php foreach ($dependencies_grouped as $trigger_type => $deps) : ?>
                    <div class="dependency-group-card">
                        <div class="dependency-group-header">
                            Tetikleyici Ürün Tipi: <strong><?php echo htmlspecialchars($trigger_type); ?></strong>
                        </div>
                        <?php foreach ($deps as $dep_item) : ?>
                            <div class="dependency-group-item">
                                <img src="<?php echo htmlspecialchars($dep_item['dependent_product_image'] ?: 'placeholder.jpg'); ?>" alt="<?php echo htmlspecialchars($dep_item['dependent_product_name']); ?>">
                                <span>
                                    <strong><?php echo htmlspecialchars($dep_item['dependent_product_name']); ?></strong> (ID: <?php echo htmlspecialchars($dep_item['dependent_product_id']); ?>) <br>
                                    Birim Başına Miktar: <?php echo htmlspecialchars($dep_item['quantity_per_unit']); ?> | Düzenlenebilir: <?php echo $dep_item['is_editable'] ? 'Evet' : 'Hayır'; ?>
                                </span>
                                <a href="products.php?action=delete_dependency_item&id=<?php echo $dep_item['id']; ?>" class="btn btn-danger btn-sm" onclick="return confirm('Bu bağımlılık kuralını silmek istediğinizden emin misiniz?');">Sil</a>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endforeach; ?>
            <?php else : ?>
                <p>Henüz hiç bağlı ürün kuralı eklenmedi.</p>
            <?php endif; ?>

            <script>
                const allProducts = <?php echo json_encode($all_products_for_selection); ?>;
                const productsMapById = {};
                allProducts.forEach(p => {
                    productsMapById[p.id] = p;
                });

                const selectedDependentProducts = new Map(); // productId -> {productData, quantity_per_unit, is_editable}
                const selectedDependentProductsList = document.getElementById('selected_dependent_products_list');
                const dependentProductsDataInput = document.getElementById('dependent_products_data');
                const noDependentProductsYet = document.getElementById('no_dependent_products_yet');

                // PHP'den mevcut bağımlılıkları JSON olarak al
                const existingDependenciesGrouped = <?php echo json_encode($dependencies_grouped); ?>;

                function renderSelectedDependentProducts() {
                    selectedDependentProductsList.innerHTML = '';
                    if (selectedDependentProducts.size === 0) {
                        noDependentProductsYet.style.display = 'block';
                    } else {
                        noDependentProductsYet.style.display = 'none';
                        selectedDependentProducts.forEach((item, productId) => {
                            const product = item.productData;
                            const div = document.createElement('div');
                            div.classList.add('dependent-product-item');
                            div.dataset.productId = productId;
                            div.innerHTML = `
                                <div class="info">
                                    <img src="${product.image_url || 'placeholder.jpg'}" alt="${product.product_name}">
                                    <div class="details">
                                        <h4>${product.product_name}</h4>
                                        <p>SKU: ${product.sku} | Stok: ${product.stock_quantity} | KDV: %${product.vat_rate}</p>
                                    </div>
                                </div>
                                <div class="controls">
                                    <label for="qpu_${productId}">Birim Başına Miktar:</label>
                                    <input type="number" id="qpu_${productId}" value="${item.quantity_per_unit}" step="0.01" min="0.01" onchange="updateDependentProductData(${productId}, 'quantity_per_unit', this.value);">
                                    <input type="checkbox" id="editable_${productId}" ${item.is_editable ? 'checked' : ''} onchange="updateDependentProductData(${productId}, 'is_editable', this.checked);">
                                    <label for="editable_${productId}">Düzenlenebilir</label>
                                    <button type="button" class="remove-btn" onclick="removeDependentProduct(${productId});">X</button>
                                </div>
                            `;
                            selectedDependentProductsList.appendChild(div);
                        });
                    }
                    updateHiddenInput();
                }

                function toggleDependentProductSelection(card) {
                    const productId = parseInt(card.dataset.productId);
                    if (selectedDependentProducts.has(productId)) {
                        // Kaldır
                        selectedDependentProducts.delete(productId);
                        card.classList.remove('selected');
                    } else {
                        // Ekle
                        const productData = productsMapById[productId];
                        if (productData) {
                            selectedDependentProducts.set(productId, {
                                productData: productData,
                                quantity_per_unit: 1.00, // Varsayılan değer
                                is_editable: 0 // Varsayılan olarak düzenlenemez (0 = hayır, 1 = evet)
                            });
                            card.classList.add('selected');
                        }
                    }
                    renderSelectedDependentProducts();
                }

                function updateDependentProductData(productId, key, value) {
                    if (selectedDependentProducts.has(productId)) {
                        const item = selectedDependentProducts.get(productId);
                        if (key === 'is_editable') {
                            item[key] = value ? 1 : 0;
                        } else if (key === 'quantity_per_unit') {
                            // parseFloat ile sayıya çevir ve 2 ondalık basamağa yuvarla
                            item[key] = parseFloat(parseFloat(value).toFixed(2));
                        }
                        selectedDependentProducts.set(productId, item); // Güncellenmiş öğeyi geri ata
                        updateHiddenInput();
                    }
                }

                function removeDependentProduct(productId) {
                    selectedDependentProducts.delete(productId);
                    // Ana seçim gridindeki kartın seçimini kaldır
                    const card = document.querySelector(`.product-card[data-product-id="${productId}"]`);
                    if (card) {
                        card.classList.remove('selected');
                    }
                    renderSelectedDependentProducts();
                }

                function loadExistingDependencies(triggerType) {
                    selectedDependentProducts.clear(); // Mevcut seçimleri temizle
                    // Tüm kartlardan 'selected' sınıfını kaldır
                    document.querySelectorAll('.product-card.selected').forEach(card => {
                        card.classList.remove('selected');
                    });

                    if (existingDependenciesGrouped[triggerType]) {
                        existingDependenciesGrouped[triggerType].forEach(depItem => {
                            const productId = parseInt(depItem.dependent_product_id);
                            const productData = productsMapById[productId];
                            if (productData) {
                                selectedDependentProducts.set(productId, {
                                    productData: productData,
                                    quantity_per_unit: parseFloat(depItem.quantity_per_unit),
                                    is_editable: parseInt(depItem.is_editable)
                                });
                                // Ana seçim gridindeki kartı seçili işaretle
                                const card = document.querySelector(`.product-card[data-product-id="${productId}"]`);
                                if (card) {
                                    card.classList.add('selected');
                                }
                            }
                        });
                    }
                    renderSelectedDependentProducts();
                }

                // Sayfa yüklendiğinde, eğer trigger_product_type_group seçili ise (POST sonrası durum),
                // o tipin bağımlılıklarını yükle.
                document.addEventListener('DOMContentLoaded', function() {
                    const initialTriggerType = document.getElementById('trigger_product_type_group').value;
                    if (initialTriggerType) {
                        loadExistingDependencies(initialTriggerType);
                    }
                });

            </script>

        <?php endif; ?>
    </div>

    <?php
    include 'includes/footer.php';
    ?>

<?php
} catch (Exception $e) {
    // Tüm hataları burada yakala ve kullanıcıya göster
    echo "<!DOCTYPE html>";
    echo "<html lang='tr'>";
    echo "<head>";
    echo "<meta charset='UTF-8'>";
    echo "<meta name='viewport' content='width=device-width, initial-scale=1.0'>";
    echo "<title>Hata Oluştu</title>";
    echo "<style>";
    echo "body { font-family: Arial, sans-serif; background-color: #f8f9fa; margin: 0; padding: 20px; display: flex; justify-content: center; align-items: center; min-height: 100vh; }";
    echo ".error-container { background-color: #ffffff; border: 1px solid #dc3545; border-radius: 5px; padding: 30px; box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1); text-align: center; max-width: 600px; width: 100%; }";
    echo ".error-container h1 { color: #dc3545; margin-bottom: 20px; }";
    echo ".error-container p { color: #343a40; font-size: 1.1em; line-height: 1.6; }";
    echo ".error-container .code { background-color: #e9ecef; border-left: 5px solid #dc3545; padding: 15px; margin-top: 20px; text-align: left; overflow-x: auto; }";
    echo "</style>";
    echo "</head>";
    echo "<body>";
    echo "<div class='error-container'>";
    echo "<h1>Bir Hata Oluştu!</h1>";
    echo "<p>Web sitesi şu anda bir sorunla karşılaştı. Lütfen daha sonra tekrar deneyin.</p>";
    echo "<p>Yöneticiyseniz, detaylar için aşağıdaki hata mesajını kontrol edin:</p>";
    echo "<div class='code'>";
    echo "<strong>Hata Mesajı:</strong> " . htmlspecialchars($e->getMessage()) . "<br>";
    echo "<strong>Hata Dosyası:</strong> " . htmlspecialchars($e->getFile()) . "<br>";
    echo "<strong>Hata Satırı:</strong> " . htmlspecialchars($e->getLine()) . "<br>";
    echo "</div>";
    echo "<p>Lütfen sunucu hata günlüklerini de kontrol etmeyi unutmayın.</p>";
    echo "</div>";
    echo "</body>";
    echo "</html>";
    error_log("Kritik Yakalanmış Hata (products.php): " . $e->getMessage() . " in " . $e->getFile() . " on line " . $e->getLine());
}
?>
