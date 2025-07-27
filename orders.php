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

    // Yetki kontrolü (tüm yetkili roller buraya eklendi)
    check_permission(['genel_mudur', 'genel_mudur_yardimcisi', 'satis_muduru', 'muhasebe_muduru', 'sevkiyat_sorumlusu']);

    $message = '';
    $message_type = '';

    // $_GET['action'] boşsa varsayılan olarak 'list' ayarla
    $action = isset($_GET['action']) ? $_GET['action'] : 'list';

    // Yardımcı fonksiyon: Kullanıcının rol adını getir (Eğer zaten config.php veya başka bir helperda yoksa)
    if (!function_exists('get_role_name_from_db')) {
        function get_role_name_from_db($pdo, $role_id) {
            $role_name = "Bilinmiyor";
            try {
                $stmt = $pdo->prepare("SELECT role_name FROM roles WHERE id = ?");
                $stmt->execute([$role_id]);
                $fetched_role_name = $stmt->fetchColumn();
                if ($fetched_role_name) {
                    $role_name = $fetched_role_name;
                }
            } catch (PDOException $e) {
                error_log("Rol adı çekilirken PDO hatası: " . $e->getMessage());
                $role_name = "Hata!";
            }
            return $role_name;
        }
    }

    // Yardımcı fonksiyon: Ürünün müşteriye özel fiyatını getir veya varsayılanı kullan
    function get_product_price($pdo, $product_id, $customer_id = null) {
        if ($customer_id) {
            try {
                // customer_product_prices tablosunda 'price' kolonu kullanılıyor
                $stmt = $pdo->prepare("SELECT price FROM customer_product_prices WHERE product_id = ? AND customer_id = ?");
                $stmt->execute([$product_id, $customer_id]);
                $special_price = $stmt->fetchColumn();
                if ($special_price !== false) {
                    return (float)$special_price;
                }
            } catch (PDOException $e) {
                error_log("Müşteriye özel fiyat çekilirken hata: " . $e->getMessage());
                // Hata durumunda varsayılan fiyata düş
            }
        }
        // Müşteriye özel fiyat yoksa veya hata oluştuysa standart fiyatı getir
        $stmt = $pdo->prepare("SELECT price FROM products WHERE id = ?");
        $stmt->execute([$product_id]);
        return (float)$stmt->fetchColumn();
    }

    $pdo = connect_db(); // PDO bağlantısını burada kur


    // Form gönderildiğinde işleme al
    if ($_SERVER["REQUEST_METHOD"] == "POST") {
        // Tüm post işlemlerinde user_id ve role_id kontrolü
        $current_logged_in_user_id = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : null;
        $current_logged_in_user_role_id = isset($_SESSION['user_role_id']) ? $_SESSION['user_role_id'] : null;

        if ($current_logged_in_user_id === null || $current_logged_in_user_role_id === null) {
             $message = "Oturum bilgileri bulunamadı. Lütfen tekrar giriş yapın.";
             $message_type = 'error';
             $action = 'list'; // Listeye geri dön
             goto end_post_processing_orders; // Hata durumunda işlemi sonlandır
        }

        if (isset($_POST['add_order'])) {
            // Sadece Genel Müdür, Genel Müdür Yardımcısı, Satış Müdürü sipariş oluşturabilir.
            if (!in_array($current_logged_in_user_role_id, [1, 2, 3])) {
                $message = "Sipariş oluşturmaya yetkiniz yok.";
                $message_type = 'error';
            } else {
                $dealer_id = $_POST['dealer_id']; // Artık müşteri id'si
                $notes = trim($_POST['notes']);
                $payment_method = $_POST['payment_method'];

                // JSON'dan ürün verilerini al
                $selected_products_data_json = isset($_POST['selected_products_data']) ? $_POST['selected_products_data'] : '[]';
                $selected_products_data = json_decode($selected_products_data_json, true);

                if (empty($dealer_id) || empty($selected_products_data) || empty($payment_method)) {
                    $message = "Tüm alanları doldurun ve en az bir ürün ekleyin.";
                    $message_type = 'error';
                    $action = 'add'; // Formu tekrar göster
                    goto end_post_processing_orders;
                }

                $pdo->beginTransaction();
                try {
                    $order_status = 'Beklemede';
                    $current_approver_role_id = null; // Varsayılan: Kimsenin onayı beklenmiyor

                    if ($current_logged_in_user_role_id == 3) { // Satış Müdürü sipariş oluşturursa 'Satış Onayı Bekliyor' olsun
                        $order_status = 'Satış Onayı Bekliyor';
                        $current_approver_role_id = 3; // Satış Müdürü onayı bekliyor
                    } else { // GM veya GMY oluşturursa direkt muhasebe onayına gitsin veya onaylansın
                        $order_status = 'Muhasebe Onayı Bekliyor';
                        $current_approver_role_id = 4; // Muhasebe Müdürü onayı bekliyor
                    }

                    // Cari limit kontrolü
                    $stmt_customer = $pdo->prepare("SELECT credit_limit, current_debt, fixed_asset_credit_limit, fixed_asset_current_debt FROM customers WHERE id = ?");
                    $stmt_customer->execute([$dealer_id]);
                    $customer = $stmt_customer->fetch(PDO::FETCH_ASSOC);

                    if (!$customer) {
                        throw new Exception("Müşteri bulunamadı.");
                    }

                    $potential_total_amount_product = 0; // Normal ürünler için
                    $potential_total_amount_fixed_asset = 0; // Demirbaş ürünler için (Bağımlı ürünler buraya dahil)

                    // Tüm ürünlerin tiplerini ve KDV oranlarını çek (JavaScript'teki productsData'ya paralel)
                    $products_info_map = [];
                    $stmt_all_products_info = $pdo->query("SELECT id, product_type, vat_rate FROM products");
                    while ($row = $stmt_all_products_info->fetch(PDO::FETCH_ASSOC)) {
                        $products_info_map[$row['id']] = $row;
                    }

                    // Hangi ürünlerin bağımlı olduğunu hızlıca kontrol etmek için bir liste oluştur
                    $dependent_product_ids = [];
                    $stmt_dep_ids = $pdo->query("SELECT DISTINCT dependent_product_id FROM product_dependencies");
                    while ($row = $stmt_dep_ids->fetch(PDO::FETCH_ASSOC)) {
                        $dependent_product_ids[] = $row['dependent_product_id'];
                    }

                    foreach ($selected_products_data as $item_data) {
                        $product_id = $item_data['product_id'];
                        $quantity = $item_data['quantity'];
                        if ($quantity <= 0) continue;

                        $unit_price = get_product_price($pdo, $product_id, $dealer_id); // KDV dahil birim fiyat
                        $item_total = $quantity * $unit_price;

                        // Eğer ürün bağımlı ürün ise, her zaman demirbaş cari hesabına işlenir.
                        // Aksi takdirde, ana üründür ve ödeme yöntemine göre işlenir.
                        if (in_array($product_id, $dependent_product_ids)) {
                            $potential_total_amount_fixed_asset += $item_total;
                        } else {
                            $potential_total_amount_product += $item_total;
                        }
                    }

                    // Cari limit kontrolü
                    // Ana ürünler için kontrol, sadece ödeme yöntemi 'Cari Hesap' ise yapılır
                    if ($payment_method == 'Cari Hesap') {
                        if (($customer['current_debt'] + $potential_total_amount_product) > $customer['credit_limit']) {
                            throw new Exception("Müşterinin ÜRÜN cari hesabı limiti aşıyor. Mevcut borç: " . number_format($customer['current_debt'], 2) . ", Limit: " . number_format($customer['credit_limit'], 2) . ", Bu sipariş (ürün): " . number_format($potential_total_amount_product, 2));
                        }
                    }
                    // Demirbaş ürünler için kontrol, ödeme yöntemi ne olursa olsun her zaman yapılır
                    if (($customer['fixed_asset_current_debt'] + $potential_total_amount_fixed_asset) > $customer['fixed_asset_credit_limit']) {
                        throw new Exception("Müşterinin DEMİRBAŞ cari hesabı limiti aşıyor. Mevcut borç: " . number_format($customer['fixed_asset_current_debt'], 2) . ", Limit: " . number_format($customer['fixed_asset_credit_limit'], 2) . ", Bu sipariş (demirbaş): " . number_format($potential_total_amount_fixed_asset, 2));
                    }


                    $sql_order = "INSERT INTO orders (dealer_id, created_by, last_updated_by, notes, order_status, current_approver_role_id, payment_method) VALUES (?, ?, ?, ?, ?, ?, ?)";
                    $stmt_order = $pdo->prepare($sql_order);
                    $stmt_order->execute([$dealer_id, $current_logged_in_user_id, $current_logged_in_user_id, $notes, $order_status, $current_approver_role_id, $payment_method]);
                    $order_id = $pdo->lastInsertId();

                    $total_amount = 0;
                    // KDV bilgileri de eklendi
                    $sql_item = "INSERT INTO order_items (order_id, product_id, quantity, unit_price, total_price, vat_rate, unit_price_excluding_vat, vat_amount_per_unit) VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
                    $stmt_item = $pdo->prepare($sql_item);

                    foreach ($selected_products_data as $item_data) {
                        $product_id = $item_data['product_id'];
                        $quantity = $item_data['quantity'];
                        if ($quantity <= 0) continue;

                        $unit_price_inc_vat = get_product_price($pdo, $product_id, $dealer_id); // KDV Dahil Birim Fiyat

                        // Ürünün KDV oranını al
                        $product_vat_rate = $products_info_map[$product_id]['vat_rate'];

                        // KDV hariç fiyat ve KDV tutarını hesapla
                        $vat_factor = 1 + ($product_vat_rate / 100);
                        $unit_price_ex_vat = $unit_price_inc_vat / $vat_factor;
                        $vat_amount_per_unit = $unit_price_inc_vat - $unit_price_ex_vat;

                        $item_total_inc_vat = $quantity * $unit_price_inc_vat;
                        $total_amount += $item_total_inc_vat;

                        $stmt_item->execute([
                            $order_id,
                            $product_id,
                            $quantity,
                            $unit_price_inc_vat,
                            $item_total_inc_vat,
                            $product_vat_rate,
                            $unit_price_ex_vat,
                            $vat_amount_per_unit
                        ]);
                    }

                    $sql_update_total = "UPDATE orders SET total_amount = ? WHERE id = ?";
                    $stmt_update_total = $pdo->prepare($sql_update_total);
                    $stmt_update_total->execute([$total_amount, $order_id]);

                    // Cari hesap borçlarını güncelle
                    // Ana ürünler için güncelleme, sadece ödeme yöntemi 'Cari Hesap' ise yapılır
                    if ($payment_method == 'Cari Hesap') {
                        $sql_update_debt_product = "UPDATE customers SET current_debt = current_debt + ? WHERE id = ?";
                        $stmt_update_debt_product = $pdo->prepare($sql_update_debt_product);
                        $stmt_update_debt_product->execute([$potential_total_amount_product, $dealer_id]);
                    }
                    // Demirbaş ürünler için güncelleme, ödeme yöntemi ne olursa olsun her zaman yapılır
                    $sql_update_debt_fixed_asset = "UPDATE customers SET fixed_asset_current_debt = fixed_asset_current_debt + ? WHERE id = ?";
                    $stmt_update_debt_fixed_asset = $pdo->prepare($sql_update_debt_fixed_asset);
                    $stmt_update_debt_fixed_asset->execute([$potential_total_amount_fixed_asset, $dealer_id]);


                    // Çek görselleri yükleme
                    if ($payment_method == 'Çek' && isset($_FILES['check_images'])) {
                        $upload_dir = 'uploads/check_images/'; // Yükleme dizini
                        if (!is_dir($upload_dir)) {
                            mkdir($upload_dir, 0755, true);
                        }

                        foreach ($_FILES['check_images']['tmp_name'] as $key => $tmp_name) {
                            if ($_FILES['check_images']['error'][$key] == UPLOAD_ERR_OK) {
                                $file_name = uniqid('check_') . '_' . basename($_FILES['check_images']['name'][$key]);
                                $target_file = $upload_dir . $file_name;
                                if (move_uploaded_file($tmp_name, $target_file)) {
                                    $sql_attach = "INSERT INTO payment_attachments (order_id, attachment_type, file_path) VALUES (?, ?, ?)";
                                    $stmt_attach = $pdo->prepare($sql_attach);
                                    // attachment_type için basit bir mantık: ilk dosya ön, ikinci arka olsun.
                                    $attachment_type = ($key == 0) ? 'çek_on_yüz' : 'çek_arka_yüz';
                                    $stmt_attach->execute([$order_id, $attachment_type, $target_file]);
                                } else {
                                    error_log("Çek resmi yüklenemedi: " . $file_name);
                                }
                            }
                        }
                    }

                    $pdo->commit();
                    $message = "Sipariş başarıyla oluşturuldu. Sipariş durumu: " . $order_status;
                    $message_type = 'success';

                    if ($payment_method == 'Kredi Kartı') {
                        $message .= " Kredi kartı ödeme sayfasına yönlendiriliyorsunuz...";
                        // Gerçek bir uygulamada burada ödeme ağ geçidine yönlendirme yapılır.
                        // header("Location: /odeme-sayfasi?order_id=" . $order_id);
                        // exit();
                    }

                } catch (Exception $e) {
                    $pdo->rollBack();
                    error_log("Sipariş oluşturulurken hata oluştu: " . $e->getMessage());
                    $message = "Sipariş oluşturulurken bir hata oluştu: " . $e->getMessage();
                    $message_type = 'error';
                }
            }
            $action = 'list';
        } elseif (isset($_POST['edit_order'])) {
            $order_id = $_POST['order_id'];
            $dealer_id = $_POST['dealer_id'];
            $notes = trim($_POST['notes']);
            $payment_method = $_POST['payment_method']; // Ödeme yöntemi inputunda hidden olarak gelecek

            $selected_products_data_json = isset($_POST['selected_products_data']) ? $_POST['selected_products_data'] : '[]';
            $selected_products_data = json_decode($selected_products_data_json, true);

            // Sadece Genel Müdür, Genel Müdür Yardımcısı ve Satış Müdürü sipariş düzenleyebilir.
            if (!in_array($current_logged_in_user_role_id, [1, 2, 3])) {
                $message = "Sipariş düzenlemeye yetkiniz yok.";
                $message_type = 'error';
            } else {
                 // Satış müdürü ise müşterinin kendine bağlı olup olmadığını kontrol et
                 if ($current_logged_in_user_role_id == 3) {
                     $check_dealer_owner_sql = "SELECT COUNT(*) FROM sales_managers_to_dealers smd WHERE smd.sales_manager_id = ? AND smd.dealer_id = ?";
                     $check_dealer_owner_stmt = $pdo->prepare($check_dealer_owner_sql);
                     $check_dealer_owner_stmt->execute([$current_logged_in_user_id, $dealer_id]);
                     $count_owner = $check_dealer_owner_stmt->fetchColumn();
                     if ($count_owner == 0) {
                         $message = "Bu müşteriye ait siparişi düzenlemeye yetkiniz yok.";
                         $message_type = 'error';
                         $action = 'list';
                         goto end_post_processing_orders;
                     }
                 }

                $pdo->beginTransaction();
                try {
                    // Mevcut siparişin ödeme yöntemini ve eski total_amount'ı çek
                    $stmt_old_order_info = $pdo->prepare("SELECT total_amount, payment_method FROM orders WHERE id = ?");
                    $stmt_old_order_info->execute([$order_id]);
                    $old_order_info = $stmt_old_order_info->fetch(PDO::FETCH_ASSOC);

                    // Tüm ürünlerin tiplerini ve KDV oranlarını çek
                    $products_info_map = [];
                    $stmt_all_products_info = $pdo->query("SELECT id, product_type, vat_rate FROM products");
                    while ($row = $stmt_all_products_info->fetch(PDO::FETCH_ASSOC)) {
                        $products_info_map[$row['id']] = $row;
                    }
                    // Hangi ürünlerin bağımlı olduğunu hızlıca kontrol etmek için bir liste oluştur
                    $dependent_product_ids = [];
                    $stmt_dep_ids = $pdo->query("SELECT DISTINCT dependent_product_id FROM product_dependencies");
                    while ($row = $stmt_dep_ids->fetch(PDO::FETCH_ASSOC)) {
                        $dependent_product_ids[] = $row['dependent_product_id'];
                    }


                    // Eski sipariş kalemlerini çek ve borçları geri al
                    $old_items_total_product = 0;
                    $old_items_total_fixed_asset = 0;
                    $stmt_old_items = $pdo->prepare("SELECT product_id, quantity, unit_price FROM order_items WHERE order_id = ?");
                    $stmt_old_items->execute([$order_id]);
                    while ($old_item = $stmt_old_items->fetch(PDO::FETCH_ASSOC)) {
                        $old_item_total = $old_item['quantity'] * $old_item['unit_price'];

                        if (in_array($old_item['product_id'], $dependent_product_ids)) {
                            $old_items_total_fixed_asset += $old_item_total;
                        } else {
                            $old_items_total_product += $old_item_total;
                        }
                    }

                    // Önceki cari hesap borçlarını geri al (ödeme yöntemi 'Cari Hesap' ise normal ürün borcunu, her durumda demirbaş borcunu)
                    if ($old_order_info && $old_order_info['payment_method'] == 'Cari Hesap') {
                         $sql_revert_debt_product = "UPDATE customers SET current_debt = current_debt - ? WHERE id = ?";
                         $stmt_revert_debt_product = $pdo->prepare($sql_revert_debt_product);
                         $stmt_revert_debt_product->execute([$old_items_total_product, $dealer_id]);
                    }
                    $sql_revert_debt_fixed_asset = "UPDATE customers SET fixed_asset_current_debt = fixed_asset_current_debt - ? WHERE id = ?";
                    $stmt_revert_debt_fixed_asset = $pdo->prepare($sql_revert_debt_fixed_asset);
                    $stmt_revert_debt_fixed_asset->execute([$old_items_total_fixed_asset, $dealer_id]);


                    // Yeni sipariş toplamlarını hesapla ve limit kontrolü yap
                    $potential_total_amount_product = 0;
                    $potential_total_amount_fixed_asset = 0;
                    foreach ($selected_products_data as $item_data) {
                        $product_id = $item_data['product_id'];
                        $quantity = $item_data['quantity'];
                        if ($quantity <= 0) continue;

                        $unit_price = get_product_price($pdo, $product_id, $dealer_id);
                        $item_total = $quantity * $unit_price;

                        if (in_array($product_id, $dependent_product_ids)) {
                            $potential_total_amount_fixed_asset += $item_total;
                        } else {
                            $potential_total_amount_product += $item_total;
                        }
                    }

                    $stmt_customer = $pdo->prepare("SELECT credit_limit, current_debt, fixed_asset_credit_limit, fixed_asset_current_debt FROM customers WHERE id = ?");
                    $stmt_customer->execute([$dealer_id]);
                    $customer = $stmt_customer->fetch(PDO::FETCH_ASSOC);

                    if (!$customer) {
                        throw new Exception("Müşteri bulunamadı.");
                    }

                    // Cari limit kontrolü
                    if ($payment_method == 'Cari Hesap') {
                        if (($customer['current_debt'] + $potential_total_amount_product) > $customer['credit_limit']) {
                            throw new Exception("Müşterinin ÜRÜN cari hesabı limiti aşıyor. Mevcut borç: " . number_format($customer['current_debt'], 2) . ", Limit: " . number_format($customer['credit_limit'], 2) . ", Bu sipariş (ürün): " . number_format($potential_total_amount_product, 2));
                        }
                    }
                    if (($customer['fixed_asset_current_debt'] + $potential_total_amount_fixed_asset) > $customer['fixed_asset_credit_limit']) {
                        throw new Exception("Müşterinin DEMİRBAŞ cari hesabı limiti aşıyor. Mevcut borç: " . number_format($customer['fixed_asset_current_debt'], 2) . ", Limit: " . number_format($customer['fixed_asset_credit_limit'], 2) . ", Bu sipariş (demirbaş): " . number_format($potential_total_amount_fixed_asset, 2));
                    }

                    $sql_order = "UPDATE orders SET dealer_id = ?, last_updated_by = ?, notes = ? WHERE id = ?";
                    $stmt_order = $pdo->prepare($sql_order);
                    $stmt_order->execute([$dealer_id, $current_logged_in_user_id, $notes, $order_id]);

                    // Eski ürünleri sil
                    $sql_delete_items = "DELETE FROM order_items WHERE order_id = ?";
                    $stmt_delete_items = $pdo->prepare($sql_delete_items);
                    $stmt_delete_items->execute([$order_id]);

                    $total_amount = 0;
                    // KDV bilgileri de eklendi
                    $sql_item = "INSERT INTO order_items (order_id, product_id, quantity, unit_price, total_price, vat_rate, unit_price_excluding_vat, vat_amount_per_unit) VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
                    $stmt_item = $pdo->prepare($sql_item);

                    foreach ($selected_products_data as $item_data) {
                        $product_id = $item_data['product_id'];
                        $quantity = $item_data['quantity'];
                        if ($quantity <= 0) continue;

                        $unit_price_inc_vat = get_product_price($pdo, $product_id, $dealer_id); // KDV Dahil Birim Fiyat
                        // Ürünün KDV oranını al
                        $product_vat_rate = $products_info_map[$product_id]['vat_rate'];

                        // KDV hariç fiyat ve KDV tutarını hesapla
                        $vat_factor = 1 + ($product_vat_rate / 100);
                        $unit_price_ex_vat = $unit_price_inc_vat / $vat_factor;
                        $vat_amount_per_unit = $unit_price_inc_vat - $unit_price_ex_vat;

                        $item_total_inc_vat = $quantity * $unit_price_inc_vat;
                        $total_amount += $item_total_inc_vat;

                        $stmt_item->execute([
                            $order_id,
                            $product_id,
                            $quantity,
                            $unit_price_inc_vat,
                            $item_total_inc_vat,
                            $product_vat_rate,
                            $unit_price_ex_vat,
                            $vat_amount_per_unit
                        ]);
                    }

                    $sql_update_total = "UPDATE orders SET total_amount = ? WHERE id = ?";
                    $stmt_update_total = $pdo->prepare($sql_update_total);
                    $stmt_update_total->execute([$total_amount, $order_id]);

                    // Yeni cari hesap borcunu güncelle
                    if ($payment_method == 'Cari Hesap') {
                        $sql_update_debt_product = "UPDATE customers SET current_debt = current_debt + ? WHERE id = ?";
                        $stmt_update_debt_product = $pdo->prepare($sql_update_debt_product);
                        $stmt_update_debt_product->execute([$potential_total_amount_product, $dealer_id]);
                    }
                    $sql_update_debt_fixed_asset = "UPDATE customers SET fixed_asset_current_debt = fixed_asset_current_debt + ? WHERE id = ?";
                    $stmt_update_debt_fixed_asset = $pdo->prepare($sql_update_debt_fixed_asset);
                    $stmt_update_debt_fixed_asset->execute([$potential_total_amount_fixed_asset, $dealer_id]);


                    $pdo->commit();
                    $message = "Sipariş başarıyla güncellendi.";
                    $message_type = 'success';
                } catch (Exception $e) {
                    $pdo->rollBack();
                    error_log("Sipariş güncellenirken hata oluştu: " . $e->getMessage());
                    $message = "Sipariş güncellenirken bir hata oluştu: " . $e->getMessage();
                    $message_type = 'error';
                }
            }
            $action = 'view';
            $_GET['id'] = $order_id;
        } elseif (isset($_POST['approve_sales_order'])) {
            $order_id = $_POST['order_id'];

            // Sadece Genel Müdür, Genel Müdür Yardımcısı veya Satış Müdürü onaylayabilir.
            if (!in_array($current_logged_in_user_role_id, [1, 2, 3])) {
                $message = "Sipariş onaylamaya yetkiniz yok.";
                $message_type = 'error';
            } else {
                 // Satış müdürü ise sadece kendi müşterilerine ait siparişleri onaylayabilir.
                if ($current_logged_in_user_role_id == 3) {
                    $check_owner_sql = "SELECT COUNT(o.id) FROM orders o JOIN sales_managers_to_dealers smd ON o.dealer_id = smd.dealer_id WHERE o.id = ? AND smd.sales_manager_id = ?";
                    $check_owner_stmt = $pdo->prepare($check_owner_sql);
                    $check_owner_stmt->execute([$order_id, $current_logged_in_user_id]);
                    $count_owner = $check_owner_stmt->fetchColumn();
                    if ($count_owner == 0) {
                        $message = "Bu siparişi onaylamaya yetkiniz yok.";
                        $message_type = 'error';
                        $action = 'view';
                        $_GET['id'] = $order_id;
                        goto end_post_processing_orders;
                    }
                }

                // Siparişin mevcut durumunu kontrol et
                $stmt_status = $pdo->prepare("SELECT order_status FROM orders WHERE id = ?");
                $stmt_status->execute([$order_id]);
                $current_status = $stmt_status->fetchColumn();

                if ($current_status != 'Satış Onayı Bekliyor') {
                    $message = "Sipariş satış onayı için uygun durumda değil.";
                    $message_type = 'error';
                } else {
                    $new_status = 'Muhasebe Onayı Bekliyor';
                    $new_approver_role_id = 4; // Muhasebe Müdürü
                    $sql = "UPDATE orders SET order_status = ?, sales_manager_approved_by = ?, sales_manager_approved_date = NOW(), last_updated_by = ?, current_approver_role_id = ? WHERE id = ?";
                    $stmt = $pdo->prepare($sql);
                    if ($stmt->execute([$new_status, $current_logged_in_user_id, $current_logged_in_user_id, $new_approver_role_id, $order_id])) {
                        $message = "Sipariş satış müdürü tarafından başarıyla onaylandı. Muhasebe onayı bekleniyor.";
                        $message_type = 'success';
                    } else {
                        $errorInfo = $stmt->errorInfo();
                        error_log("Sipariş satış müdürü tarafından onaylanırken hata oluştu: " . $errorInfo[2]);
                        $message = "Sipariş onaylanırken bir hata oluştu: " . (isset($errorInfo[2]) ? $errorInfo[2] : 'Bilinmeyen Hata');
                        $message_type = 'error';
                    }
                }
            }
            $action = 'view';
            $_GET['id'] = $order_id; // Detay sayfasına geri dön
        } elseif (isset($_POST['approve_accounting_order'])) {
            $order_id = $_POST['order_id'];

            // Sadece Genel Müdür, Genel Müdür Yardımcısı veya Muhasebe Müdürü onaylayabilir.
            if (!in_array($current_logged_in_user_role_id, [1, 2, 4])) { // 4: Muhasebe Müdürü
                $message = "Sipariş onaylamaya yetkiniz yok.";
                $message_type = 'error';
            } else {
                 // Siparişin mevcut durumunu kontrol et
                $stmt_status = $pdo->prepare("SELECT order_status FROM orders WHERE id = ?");
                $stmt_status->execute([$order_id]);
                $current_status = $stmt_status->fetchColumn();

                if ($current_status != 'Muhasebe Onayı Bekliyor') {
                    $message = "Sipariş muhasebe onayı için uygun durumda değil.";
                    $message_type = 'error';
                } else {
                    $new_status = 'Onaylandı';
                    $new_approver_role_id = null; // Onay süreci tamamlandı
                    $sql = "UPDATE orders SET order_status = ?, accounting_approved_by = ?, accounting_approved_date = NOW(), last_updated_by = ?, current_approver_role_id = ?, invoice_status = 'Beklemede' WHERE id = ?"; // Faturalandırma için işaretle
                    $stmt = $pdo->prepare($sql);
                    if ($stmt->execute([$new_status, $current_logged_in_user_id, $current_logged_in_user_id, $new_approver_role_id, $order_id])) {
                        $message = "Sipariş muhasebe tarafından başarıyla onaylandı. Sipariş 'Onaylandı' olarak işaretlendi ve faturalandırılmayı bekliyor.";
                        $message_type = 'success';
                    } else {
                        $errorInfo = $stmt->errorInfo();
                        error_log("Sipariş muhasebe tarafından onaylanırken hata oluştu: " . $errorInfo[2]);
                        $message = "Sipariş onaylanırken bir hata oluştu: " . (isset($errorInfo[2]) ? $errorInfo[2] : 'Bilinmeyen Hata');
                        $message_type = 'error';
                    }
                }
            }
            $action = 'view';
            $_GET['id'] = $order_id;
        } elseif (isset($_POST['reject_order'])) {
            $order_id = $_POST['order_id'];
            $reject_notes = trim($_POST['reject_notes']);

            // Sadece Genel Müdür, Genel Müdür Yardımcısı veya Satış Müdürü reddedebilir.
            if (!in_array($current_logged_in_user_role_id, [1, 2, 3, 4])) { // Satış ve Muhasebe de reddedebilir
                $message = "Sipariş reddetmeye yetkiniz yok.";
                $message_type = 'error';
            } else {
                 // Satış müdürü ise sadece kendi müşterilerine ait siparişleri reddedebilir.
                if ($current_logged_in_user_role_id == 3) {
                    $check_owner_sql = "SELECT COUNT(o.id) FROM orders o JOIN sales_managers_to_dealers smd ON o.dealer_id = smd.dealer_id WHERE o.id = ? AND smd.sales_manager_id = ?";
                    $check_owner_stmt = $pdo->prepare($check_owner_sql);
                    $check_owner_stmt->execute([$order_id, $current_logged_in_user_id]);
                    $count_owner = $check_owner_stmt->fetchColumn();
                    if ($count_owner == 0) {
                        $message = "Bu siparişi reddetmeye yetkiniz yok.";
                        $message_type = 'error';
                        $action = 'view';
                        $_GET['id'] = $order_id;
                        goto end_post_processing_orders;
                    }
                }

                // Siparişin mevcut durumunu kontrol et
                $stmt_status = $pdo->prepare("SELECT order_status, payment_method, total_amount, dealer_id FROM orders WHERE id = ?");
                $stmt_status->execute([$order_id]);
                $order_info = $stmt_status->fetch(PDO::FETCH_ASSOC);

                if (!$order_info || in_array($order_info['order_status'], ['Onaylandı', 'Faturalandı', 'Sevkiyatta', 'Tamamlandı', 'İptal Edildi', 'Reddedildi'])) {
                     $message = "Bu siparişin durumu reddedilmeye uygun değil.";
                     $message_type = 'error';
                } else {
                    $new_status = 'Reddedildi';
                    $new_approver_role_id = null; // Reddedildi, onay süreci bitti
                    $sql = "UPDATE orders SET order_status = ?, notes = CONCAT(COALESCE(notes, ''), '\nReddetme Notu: ', ?), last_updated_by = ?, current_approver_role_id = ? WHERE id = ?"; // notes'u COALESCE ile kontrol et
                    $stmt = $pdo->prepare($sql);
                    if ($stmt->execute([$new_status, $reject_notes, $current_logged_in_user_id, $new_approver_role_id, $order_id])) {
                        $message = "Sipariş başarıyla reddedildi.";
                        $message_type = 'success';

                        // Cari hesap siparişi reddedildiğinde borcu geri al (hem normal hem demirbaş)
                        $old_items_total_product = 0;
                        $old_items_total_fixed_asset = 0;

                        // Hangi ürünlerin bağımlı olduğunu hızlıca kontrol etmek için bir liste oluştur
                        $dependent_product_ids = [];
                        $stmt_dep_ids = $pdo->query("SELECT DISTINCT dependent_product_id FROM product_dependencies");
                        while ($row = $stmt_dep_ids->fetch(PDO::FETCH_ASSOC)) {
                            $dependent_product_ids[] = $row['dependent_product_id'];
                        }


                        $stmt_old_items = $pdo->prepare("SELECT product_id, quantity, unit_price FROM order_items WHERE order_id = ?");
                        $stmt_old_items->execute([$order_id]);
                        while ($old_item = $stmt_old_items->fetch(PDO::FETCH_ASSOC)) {
                            $old_item_total = $old_item['quantity'] * $old_item['unit_price'];

                            if (in_array($old_item['product_id'], $dependent_product_ids)) {
                                $old_items_total_fixed_asset += $old_item_total;
                            } else {
                                $old_items_total_product += $old_item_total;
                            }
                        }

                        // Normal ürün borcunu geri al, sadece ödeme yöntemi 'Cari Hesap' ise
                        if ($order_info['payment_method'] == 'Cari Hesap') {
                            $sql_revert_debt_product = "UPDATE customers SET current_debt = current_debt - ? WHERE id = ?";
                            $stmt_revert_debt_product = $pdo->prepare($sql_revert_debt_product);
                            $stmt_revert_debt_product->execute([$old_items_total_product, $order_info['dealer_id']]);
                        }
                        // Demirbaş borcunu geri al, ödeme yöntemi ne olursa olsun
                        $sql_revert_debt_fixed_asset = "UPDATE customers SET fixed_asset_current_debt = fixed_asset_current_debt - ? WHERE id = ?";
                        $stmt_revert_debt_fixed_asset = $pdo->prepare($sql_revert_debt_fixed_asset);
                        $stmt_revert_debt_fixed_asset->execute([$old_items_total_fixed_asset, $order_info['dealer_id']]);

                    } else {
                        $errorInfo = $stmt->errorInfo();
                        error_log("Sipariş reddedilirken hata oluştu: " . $errorInfo[2]);
                        $message = "Sipariş reddedilirken bir hata oluştu: " . (isset($errorInfo[2]) ? $errorInfo[2] : 'Bilinmeyen Hata');
                        $message_type = 'error';
                    }
                }
            }
            $action = 'view';
            $_GET['id'] = $order_id;
        } elseif (isset($_POST['update_shipment'])) {
            $order_id = $_POST['order_id'];
            $shipment_date = $_POST['shipment_date'];
            $delivery_plate_number = trim($_POST['delivery_plate_number']);

            // Sadece Genel Müdür, Genel Müdür Yardımcısı veya Sevkiyat Sorumlusu güncelleyebilir.
            if (!in_array($current_logged_in_user_role_id, [1, 2, 5])) { // 5: Sevkiyat Sorumlusu
                $message = "Sevkiyat bilgilerini güncellemeye yetkiniz yok.";
                $message_type = 'error';
            } else {
                 // Siparişin mevcut durumunu kontrol et
                $stmt_status = $pdo->prepare("SELECT order_status FROM orders WHERE id = ?");
                $stmt_status->execute([$order_id]);
                $current_status = $stmt_status->fetchColumn();

                if (!in_array($current_status, ['Onaylandı', 'Faturalandı', 'Sevkiyatta'])) {
                    $message = "Siparişin sevkiyat bilgilerini güncelleyebilmek için 'Onaylandı' veya 'Faturalandı' durumunda olması gerekir.";
                    $message_type = 'error';
                } else {
                    $new_status = 'Sevkiyatta';
                    $new_approver_role_id = null; // Sevkiyat tamamlandı
                    $sql = "UPDATE orders SET shipment_date = ?, delivery_plate_number = ?, order_status = ?, shipment_approved_by = ?, last_updated_by = ?, current_approver_role_id = ? WHERE id = ?";
                    $stmt = $pdo->prepare($sql);
                    if ($stmt->execute([$shipment_date, $delivery_plate_number, $new_status, $current_logged_in_user_id, $current_logged_in_user_id, $new_approver_role_id, $order_id])) {
                        $message = "Sevkiyat bilgileri başarıyla güncellendi ve sipariş 'Sevkiyatta' olarak işaretlendi.";
                        $message_type = 'success';
                    } else {
                        $errorInfo = $stmt->errorInfo();
                        error_log("Sevkiyat bilgileri güncellenirken hata oluştu: " . $errorInfo[2]);
                        $message = "Sevkiyat bilgileri güncellenirken bir hata oluştu: " . (isset($errorInfo[2]) ? $errorInfo[2] : 'Bilinmeyen Hata');
                        $message_type = 'error';
                    }
                }
            }
            $action = 'view';
            $_GET['id'] = $order_id;
        }
    }
    end_post_processing_orders:;


    // Tüm ürünleri çek (sipariş oluşturma/düzenleme formları için) - Şimdi image_url ve vat_rate'i de çekiyoruz
    // Sadece "ana" ürünleri çek (yani product_dependencies tablosunda dependent_product_id olarak geçmeyenler)
    $products = [];
    try {
        $stmt_products = $pdo->query("SELECT id, product_name, price, stock_quantity, sku, image_url, product_type, vat_rate FROM products WHERE is_active = TRUE AND id NOT IN (SELECT DISTINCT dependent_product_id FROM product_dependencies) ORDER BY product_name ASC");
        $products = $stmt_products->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Ürünler çekilirken hata oluştu: " . $e->getMessage());
        $message = "Ürün bilgileri alınamadı.";
        $message_type = 'error';
    }

    // Ürün ID'sine göre ürün detaylarını hızlıca bulmak için bir harita oluştur (JS için)
    // Bu haritada hem ana hem de bağlı tüm ürünler bulunmalı, çünkü JS her ikisine de ihtiyaç duyacak.
    $products_map_for_js = [];
    try {
        // vat_rate kolonunu da çekiyoruz
        $stmt_all_products = $pdo->query("SELECT id, product_name, price, stock_quantity, sku, image_url, product_type, vat_rate FROM products WHERE is_active = TRUE");
        while ($row = $stmt_all_products->fetch(PDO::FETCH_ASSOC)) {
            $products_map_for_js[$row['id']] = $row;
        }
    } catch (PDOException $e) {
        error_log("Tüm ürünler (JS için) çekilirken hata oluştu: " . $e->getMessage());
        $message = "Tüm ürün bilgileri alınamadı.";
        $message_type = 'error';
    }


    // !!! YENİ EKLENECEK: Ürün bağımlılıklarını çek !!!
    // Bu yapı artık PHP'de gruplandırılmış olarak çekilip JS'e aktarılacak
    $product_dependencies_for_js = [];
    try {
        $stmt_deps = $pdo->query("SELECT trigger_product_type, dependent_product_id, quantity_per_unit, is_editable FROM product_dependencies");
        // Bağımlılıkları JavaScript için daha kullanışlı bir yapıya dönüştürelim
        while ($row = $stmt_deps->fetch(PDO::FETCH_ASSOC)) {
            $product_dependencies_for_js[$row['trigger_product_type']][] = [
                'dependent_product_id' => (int)$row['dependent_product_id'],
                'quantity_per_unit' => (float)$row['quantity_per_unit'],
                'is_editable' => (bool)$row['is_editable']
            ];
        }
    } catch (PDOException $e) {
        error_log("Ürün bağımlılıkları çekilirken hata oluştu: " . $e->getMessage());
        $message = "Ürün bağımlılık bilgileri alınamadı.";
        $message_type = 'error';
    }


    // Tüm müşterileri çek (sipariş oluşturma/düzenleme formları için)
    // Yeni eklenen demirbaş limitleri de çekiliyor
    $customers = []; // dealer_id yerine customer_id kullanıyoruz
    $sql_customers_for_dealers = "SELECT id, customer_name, credit_limit, current_debt, fixed_asset_credit_limit, fixed_asset_current_debt FROM customers WHERE is_active = 1";
    $customer_params = [];

    // Satış Müdürü ise sadece kendine bağlı müşterileri görsün
    if (isset($_SESSION['user_role_id']) && $_SESSION['user_role_id'] == 3) {
        $current_user_id = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : null;
        if ($current_user_id !== null) {
            $sql_customers_for_dealers .= " AND customers.id IN (SELECT smd.dealer_id FROM sales_managers_to_dealers smd WHERE smd.sales_manager_id = ?)";
            $customer_params[] = $current_user_id;
        } else {
            $sql_customers_for_dealers .= " AND 1=0"; // Kullanıcı ID'si yoksa hiç sonuç gösterme
            error_log("Satış müdürü için user_id session'da yok, müşteri listesi filtrelenemedi.");
        }
    }

    try {
        $stmt_customers_form = $pdo->prepare($sql_customers_for_dealers);
        $stmt_customers_form->execute($customer_params);
        $customers = $stmt_customers_form->fetchAll(PDO::FETCH_ASSOC);

    } catch (PDOException $e) {
        error_log("Müşteriler çekilirken hata oluştu: " . $e->getMessage());
        $message = "Müşteri bilgileri (müşteri listesi) alınamadı.";
        $message_type = 'error';
    }


    // Siparişleri listeleme veya tek sipariş detayını çekme
    $orders = [];
    $order_details = null;

    if ($action == 'list') {
        $sql_orders = "SELECT o.*, c.customer_name as dealer_name, c.current_debt, c.credit_limit, c.fixed_asset_current_debt, c.fixed_asset_credit_limit,
                       u_created.username as created_by_username, COALESCE(u_last_updated.username, 'N/A') as last_updated_by_username,
                       r_approver.role_name as current_approver_role_name
                       FROM orders o
                       JOIN customers c ON o.dealer_id = c.id
                       JOIN users u_created ON o.created_by = u_created.id
                       LEFT JOIN users u_last_updated ON o.last_updated_by = u_last_updated.id
                       LEFT JOIN roles r_approver ON o.current_approver_role_id = r_approver.id";
        $conditions = [];
        $params = [];

        // Yetkilere göre filtreleme
        if (isset($_SESSION['user_role_id'])) {
            $current_user_id = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : null;

            if ($_SESSION['user_role_id'] == 3) { // Satış Müdürü ise sadece kendi müşterilerinin siparişlerini görsün
                if ($current_user_id !== null) {
                    $conditions[] = "o.dealer_id IN (SELECT smd.dealer_id FROM sales_managers_to_dealers smd WHERE smd.sales_manager_id = ?)";
                    $params[] = $current_user_id;
                } else {
                    $conditions[] = "1=0";
                    error_log("Satış müdürü için user_id session'da yok, sipariş listesi filtrelenemedi.");
                }
            } elseif ($_SESSION['user_role_id'] == 4) { // Muhasebe Müdürü ise sadece muhasebe onayı bekleyenleri görsün
                // Muhasebe, muhasebe onayı bekleyenleri, onaylanmışları ve faturalanacakları görmeli
                $conditions[] = "o.order_status IN ('Muhasebe Onayı Bekliyor', 'Onaylandı', 'Faturalandı', 'Sevkiyatta', 'Tamamlandı') OR o.invoice_status = 'Beklemede'";
            } elseif ($_SESSION['user_role_id'] == 5) { // Sevkiyat Sorumlusu ise sadece onaylanmış ve sevkiyatta olmayanları görsün
                $conditions[] = "o.order_status IN ('Onaylandı', 'Faturalandı', 'Sevkiyatta')";
            }
        }


        if (!empty($conditions)) {
            $sql_orders .= " WHERE " . implode(" AND ", $conditions);
        }
        $sql_orders .= " ORDER BY o.order_date DESC";

        try {
            $stmt_orders = $pdo->prepare($sql_orders);
            $stmt_orders->execute($params);
            $orders = $stmt_orders->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Sipariş listesi çekilirken hata oluştu: " . $e->getMessage());
            $message = "Sipariş listesi alınırken bir hata oluştu.";
            $message_type = 'error';
        }

    } elseif ($action == 'view' || $action == 'edit') {
        if (isset($_GET['id'])) {
            $order_id = $_GET['id'];
            $sql_order_details = "SELECT o.*, c.customer_name as dealer_name, c.address as dealer_address, c.phone as dealer_phone, c.email as dealer_email, c.tax_id as dealer_tax_id, c.credit_limit, c.current_debt, c.fixed_asset_credit_limit, c.fixed_asset_current_debt,
                                        u_created.username as created_by_name, r_created.role_name as created_by_role,
                                        COALESCE(u_last_updated.username, 'N/A') as last_updated_by_name, COALESCE(r_last_updated.role_name, 'N/A') as last_updated_by_role,
                                        COALESCE(u_sales_approved.username, 'N/A') as sales_approved_by_name,
                                        COALESCE(u_accounting_approved.username, 'N/A') as accounting_approved_by_name,
                                        COALESCE(u_shipment_approved.username, 'N/A') as shipment_approved_by_name,
                                        r_approver_detail.role_name as current_approver_role_name_detail
                                  FROM orders o
                                  JOIN customers c ON o.dealer_id = c.id
                                  JOIN users u_created ON o.created_by = u_created.id
                                  JOIN roles r_created ON u_created.role_id = r_created.id
                                  LEFT JOIN users u_last_updated ON o.last_updated_by = u_last_updated.id
                                  LEFT JOIN roles r_last_updated ON u_last_updated.role_id = r_last_updated.id
                                  LEFT JOIN users u_sales_approved ON o.sales_manager_approved_by = u_sales_approved.id
                                  LEFT JOIN users u_accounting_approved ON o.accounting_approved_by = u_accounting_approved.id
                                  LEFT JOIN users u_shipment_approved ON o.shipment_approved_by = u_shipment_approved.id
                                  LEFT JOIN roles r_approver_detail ON o.current_approver_role_id = r_approver_detail.id
                                  WHERE o.id = ?";

            $params = [$order_id];

            // Yetkilere göre siparişin kendisine ait olup olmadığını kontrol et
            if (isset($_SESSION['user_role_id']) && $_SESSION['user_role_id'] == 3) { // Satış Müdürü ise sadece kendi müşterilerinin siparişlerini görsün
                $current_user_id = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : null;
                if ($current_user_id !== null) {
                    $sql_order_details .= " AND o.dealer_id IN (SELECT smd.dealer_id FROM sales_managers_to_dealers smd WHERE smd.sales_manager_id = ?)";
                    $params[] = $current_user_id;
                } else {
                    $message = "Sipariş detayları alınamadı, oturum kullanıcı ID'si yok.";
                    $message_type = 'error';
                    $action = 'list';
                    goto end_post_processing_orders;
                }
            }

            try {
                $stmt_order_details = $pdo->prepare($sql_order_details);
                $stmt_order_details->execute($params);
                $order_details = $stmt_order_details->fetch(PDO::FETCH_ASSOC);

                if ($order_details) {
                    // Sipariş ürünlerini çek (KDV bilgileri de eklendi)
                    $order_details['items'] = [];
                    $sql_order_items = "SELECT oi.*, p.product_name, p.sku, p.image_url, p.stock_quantity, p.product_type FROM order_items oi JOIN products p ON oi.product_id = p.id WHERE oi.order_id = ?";
                    $stmt_order_items = $pdo->prepare($sql_order_items);
                    $stmt_order_items->execute([$order_id]);
                    $order_details['items'] = $stmt_order_items->fetchAll(PDO::FETCH_ASSOC);

                    // Çek görsellerini çek
                    if ($order_details['payment_method'] == 'Çek') {
                        $sql_attachments = "SELECT * FROM payment_attachments WHERE order_id = ?";
                        $stmt_attachments = $pdo->prepare($sql_attachments);
                        $stmt_attachments->execute([$order_id]);
                        $order_details['check_images'] = $stmt_attachments->fetchAll(PDO::FETCH_ASSOC);
                    } else {
                        $order_details['check_images'] = [];
                    }

                } else {
                    $message = "Sipariş bulunamadı veya görüntülemeye yetkiniz yok.";
                    $message_type = 'error';
                    $action = 'list';
                }
            } catch (PDOException $e) {
                error_log("Sipariş detayları çekilirken hata oluştu: " . $e->getMessage());
                $message = "Sipariş detayları alınırken bir hata oluştu.";
                $message_type = 'error';
            }
        }
    }

    // Sayfa başlığını ayarla
    $page_title = "Sipariş Yönetimi";
    // Genel HTML yapısını ve style.css'i include et
    if (!file_exists('includes/header.php')) {
        throw new Exception("Hata: 'includes/header.php' dosyası bulunamadı. Lütfen dosya yolunu kontrol edin.");
    }
    include 'includes/header.php';
    ?>

    <!-- Yeni eklenen CSS stilleri -->
    <style>
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
            position: relative; /* Remove button positioning */
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

        .quantity-control {
            display: flex;
            justify-content: center;
            align-items: center;
            margin-top: 10px;
        }

        .quantity-control button {
            background-color: #007bff;
            color: white;
            border: none;
            padding: 5px 10px;
            font-size: 1.2em;
            cursor: pointer;
            border-radius: 4px;
            transition: background-color 0.2s;
            min-width: 35px;
            text-align: center;
        }

        .quantity-control button:hover {
            background-color: #0056b3;
        }

        .quantity-control input[type="number"] {
            width: 60px;
            text-align: center;
            margin: 0 8px;
            border: 1px solid #ddd;
            border-radius: 4px;
            padding: 5px;
            -moz-appearance: textfield; /* Firefox hide arrows */
        }

        .quantity-control input[type="number"]::-webkit-outer-spin-button,
        .quantity-control input[type="number"]::-webkit-inner-spin-button {
            -webkit-appearance: none; /* Chrome, Safari hide arrows */
            margin: 0;
        }

        .product-remove-btn {
            background-color: #dc3545;
            color: white;
            border: none;
            padding: 4px 8px;
            font-size: 0.8em;
            cursor: pointer;
            border-radius: 4px;
            position: absolute;
            top: 5px;
            right: 5px;
            display: none; /* Hidden by default, shown when quantity > 0 */
            transition: background-color 0.2s;
        }
        .product-remove-btn:hover {
            background-color: #c82333;
        }

        /* Styles for selected/added products */
        .selected-products-container {
            border: 1px solid #007bff;
            padding: 15px;
            margin-top: 20px;
            background-color: #e6f7ff;
            border-radius: 8px;
            min-height: 100px;
        }
        .selected-product-card {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 8px;
            border-bottom: 1px dashed #a6d8ff;
        }
        .selected-product-card:last-child {
            border-bottom: none;
        }
        .selected-product-card .info {
            display: flex;
            align-items: center;
        }
        .selected-product-card img {
            width: 50px;
            height: 50px;
            object-fit: cover;
            margin-right: 10px;
            border-radius: 4px;
        }
        .selected-product-card .details h4 {
            margin: 0;
            font-size: 1em;
        }
        .selected-product-card .details p {
            margin: 0;
            font-size: 0.8em;
            color: #555;
        }
        .selected-product-card .quantity-controls {
            display: flex;
            align-items: center;
        }
        .selected-product-card .quantity-controls input {
            width: 40px;
            text-align: center;
            margin: 0 5px;
            border: 1px solid #ccc;
            border-radius: 4px;
        }
        .selected-product-card .quantity-controls button {
            background-color: #28a745;
            color: white;
            border: none;
            padding: 3px 8px;
            font-size: 0.9em;
            cursor: pointer;
            border-radius: 4px;
        }
        .selected-product-card .quantity-controls button.remove {
            background-color: #dc3545;
        }

        /* Yeni: Otomatik eklenen ürünler için stiller */
        .selected-product-card[data-auto-added="true"] {
            background-color: #e6f7ff; /* Açık mavi arka plan */
            border-left: 5px solid #007bff; /* Mavi sol kenarlık */
            opacity: 0.8;
        }

        .selected-product-card[data-auto-added="true"] .quantity-controls input {
            background-color: #f0f0f0;
            cursor: not-allowed;
        }

        .selected-product-card[data-auto-added="true"] .quantity-controls button {
            display: none; /* Otomatik eklenenlerin butonlarını gizle */
        }

        .badge-info {
            background-color: #17a2b8;
            color: white;
            padding: 3px 6px;
            border-radius: 4px;
            font-size: 0.75em;
            margin-left: 5px;
        }
    </style>

    <!-- HTML içeriği buradan başlıyor -->
    <div class="container">
        <h1>Sipariş Yönetimi</h1>

        <?php if (!empty($message)) : ?>
            <div class="message <?php echo $message_type; ?>"><?php echo $message; ?></div>
        <?php endif; ?>

        <?php if ($action == 'list') : ?>
            <?php
            // Sipariş oluşturma yetkisi olan roller: Genel Müdür, Genel Müdür Yardımcısı, Satış Müdürü
            $can_create_order = false;
            if (isset($_SESSION['user_role_id'])) {
                $can_create_order = in_array($_SESSION['user_role_id'], [1, 2, 3]);
            }

            if ($can_create_order) :
            ?>
                <p><a href="orders.php?action=add" class="btn btn-success">Yeni Sipariş Oluştur</a></p>
            <?php endif; ?>

            <table>
                <thead>
                    <tr>
                        <th>Sipariş ID</th>
                        <th>Müşteri Adı</th>
                        <th>Toplam Tutar</th>
                        <th>Oluşturma Tarihi</th>
                        <th>Durum</th>
                        <th>Kimin Onayında</th>
                        <th>Oluşturan</th>
                        <th>Son Güncelleyen</th>
                        <th>İşlemler</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($orders)) : ?>
                        <?php foreach ($orders as $order) : ?>
                            <tr>
                                <td><?php echo htmlspecialchars($order['id']); ?></td>
                                <td><?php echo htmlspecialchars($order['dealer_name']); ?></td>
                                <td><?php echo number_format($order['total_amount'], 2, ',', '.') . ' TL'; ?></td>
                                <td><?php echo htmlspecialchars($order['order_date']); ?></td>
                                <td><span class="status-badge status-<?php echo str_replace([' ', '.'], ['_', ''], htmlspecialchars($order['order_status'])); ?>"><?php echo htmlspecialchars($order['order_status']); ?></span></td>
                                <td><?php echo $order['current_approver_role_name'] ? htmlspecialchars($order['current_approver_role_name']) : 'Onay Süreci Tamamlandı'; ?></td>
                                <td><?php echo htmlspecialchars($order['created_by_username']); ?></td>
                                <td><?php echo htmlspecialchars($order['last_updated_by_username']); ?></td>
                                <td>
                                    <a href="orders.php?action=view&id=<?php echo $order['id']; ?>" class="btn btn-primary">Görüntele</a>
                                    <?php
                                        // Düzenleme yetkisi olan roller: Genel Müdür, Genel Müdür Yardımcısı, Satış Müdürü
                                        // Satış Müdürü sadece kendi müşterisine ait siparişleri düzenleyebilir
                                        $can_edit = false;
                                        if (isset($_SESSION['user_role_id']) && isset($_SESSION['user_id'])) {
                                            if (in_array($_SESSION['user_role_id'], [1, 2])) { // GM, GMY
                                                $can_edit = true;
                                            } elseif ($_SESSION['user_role_id'] == 3) { // Satış Müdürü
                                                try {
                                                    $stmt_check_owner = $pdo->prepare("SELECT COUNT(*) FROM sales_managers_to_dealers WHERE sales_manager_id = ? AND dealer_id = ?");
                                                    $stmt_check_owner->execute([$_SESSION['user_id'], $order['dealer_id']]);
                                                    if ($stmt_check_owner->fetchColumn() > 0) {
                                                        $can_edit = true;
                                                    }
                                                } catch (PDOException $e) {
                                                    error_log("Sipariş düzenleme yetkisi kontrolü hatası: " . $e->getMessage());
                                                }
                                            }
                                        }

                                        // Sadece "Beklemede", "Satış Onayı Bekliyor", "Muhasebe Onayı Bekliyor" durumundaki siparişler düzenlenebilir
                                        if ($can_edit && in_array($order['order_status'], ['Beklemede', 'Satış Onayı Bekliyor', 'Muhasebe Onayı Bekliyor'])) :
                                    ?>
                                            <a href="orders.php?action=edit&id=<?php echo $order['id']; ?>" class="btn btn-warning">Düzenle</a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else : ?>
                        <tr><td colspan="9">Henüz hiç sipariş yok.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>

        <?php elseif ($action == 'add') : ?>
            <h2>Yeni Sipariş Oluştur</h2>
            <form action="orders.php" method="post" enctype="multipart/form-data">
                <input type="hidden" name="selected_products_data" id="selected_products_data">

                <div class="form-group">
                    <label for="dealer_id">Müşteri Seçin:</label>
                    <select id="dealer_id" name="dealer_id" required>
                        <option value="">Müşteri Seçin</option>
                        <?php foreach ($customers as $customer) : ?>
                            <option
                                value="<?php echo htmlspecialchars($customer['id']); ?>"
                                data-credit-limit="<?php echo htmlspecialchars($customer['credit_limit']); ?>"
                                data-current-debt="<?php echo htmlspecialchars($customer['current_debt']); ?>"
                                data-fixed-asset-credit-limit="<?php echo htmlspecialchars($customer['fixed_asset_credit_limit']); ?>"
                                data-fixed-asset-current-debt="<?php echo htmlspecialchars($customer['fixed_asset_current_debt']); ?>">
                                <?php echo htmlspecialchars($customer['customer_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div id="cari_limit_info" style="margin-top: 10px; padding: 10px; border: 1px solid #ccc; background-color: #f9f9f9; display: none;">
                    <strong>ÜRÜN Cari Limit:</strong> <span id="display_credit_limit"></span> TL <br>
                    <strong>ÜRÜN Mevcut Borç:</strong> <span id="display_current_debt"></span> TL <br>
                    <strong>ÜRÜN Kalan Limit:</strong> <span id="display_remaining_limit"></span> TL
                    <br><br>
                    <strong>DEMİRBAŞ Cari Limit:</strong> <span id="display_fixed_asset_credit_limit"></span> TL <br>
                    <strong>DEMİRBAŞ Mevcut Borç:</strong> <span id="display_fixed_asset_current_debt"></span> TL <br>
                    <strong>DEMİRBAŞ Kalan Limit:</strong> <span id="display_fixed_asset_remaining_limit"></span> TL
                </div>

                <div class="form-group">
                    <label for="payment_method">Ödeme Yöntemi:</label>
                    <select id="payment_method" name="payment_method" required>
                        <option value="">Seçiniz</option>
                        <option value="Nakit">Nakit</option>
                        <option value="Kredi Kartı">Kredi Kartı</option>
                        <option value="Çek">Çek</option>
                        <option value="Cari Hesap">Cari Hesap</option>
                    </select>
                </div>

                <div id="check_upload_section" class="form-group" style="display: none;">
                    <label for="check_images">Çek Görselleri (Ön ve Arka Yüz):</label>
                    <input type="file" id="check_images" name="check_images[]" accept="image/*" multiple>
                    <small>Lütfen çekin ön ve arka yüzünün resimlerini yükleyin.</small>
                </div>

                <h3>Ürün Seçimi</h3>
                <div class="selected-products-container">
                    <h4>Seçili Ürünler:</h4>
                    <div id="selected_products_list">
                        <!-- Seçilen ürünler buraya eklenecek -->
                        <p id="no_products_selected" style="color: #666;">Henüz ürün seçilmedi.</p>
                    </div>
                </div>

                <p style="margin-top: 20px;">Aşağıdan ürünleri seçerek siparişinize ekleyin (Bağımlı ürünler otomatik eklenecektir):</p>
                <div class="product-selection-grid">
                    <?php if (!empty($products)) : ?>
                        <?php foreach ($products as $product) : ?>
                            <div class="product-card"
                                data-product-id="<?php echo htmlspecialchars($product['id']); ?>"
                                data-product-name="<?php echo htmlspecialchars($product['product_name']); ?>"
                                data-product-price="<?php echo htmlspecialchars($product['price']); ?>"
                                data-product-stock="<?php echo htmlspecialchars($product['stock_quantity']); ?>"
                                data-product-image="<?php echo htmlspecialchars($product['image_url'] ?: 'placeholder.jpg'); ?>"
                                data-product-type="<?php echo htmlspecialchars($product['product_type']); ?>"
                                data-product-vat-rate="<?php echo htmlspecialchars($product['vat_rate']); ?>">
                                <img src="<?php echo htmlspecialchars($product['image_url'] ?: 'placeholder.jpg'); ?>" alt="<?php echo htmlspecialchars($product['product_name']); ?>">
                                <h4><?php echo htmlspecialchars($product['product_name']); ?></h4>
                                <p>Stok: <?php echo htmlspecialchars($product['stock_quantity']); ?></p>
                                <p>Fiyat: <?php echo number_format($product['price'], 2, ',', '.'); ?> TL (KDV: <?php echo number_format($product['vat_rate'], 0); ?>%)</p>
                                <div class="quantity-control">
                                    <button type="button" class="decrease-btn">-</button>
                                    <input type="number" class="product-qty-input" value="0" min="0" max="<?php echo htmlspecialchars($product['stock_quantity']); ?>" readonly>
                                    <button type="button" class="increase-btn">+</button>
                                </div>
                                <button type="button" class="product-remove-btn">X</button>
                            </div>
                        <?php endforeach; ?>
                    <?php else : ?>
                        <p>Henüz hiç ürün bulunmamaktadır.</p>
                    <?php endif; ?>
                </div>

                <div class="form-group" style="margin-top: 20px;">
                    <label for="notes">Notlar:</label>
                    <textarea id="notes" name="notes" rows="3"><?php echo isset($_POST['notes']) ? htmlspecialchars($_POST['notes']) : ''; ?></textarea>
                </div>

                <div class="form-group">
                    <button type="submit" name="add_order" class="btn btn-primary">Sipariş Oluştur</button>
                    <a href="orders.php" class="btn btn-secondary">İptal</a>
                </div>
            </form>

        <?php elseif ($action == 'edit' && $order_details) : ?>
            <h2>Sipariş Düzenle (ID: <?php echo htmlspecialchars($order_details['id']); ?>)</h2>
            <form action="orders.php" method="post">
                <input type="hidden" name="order_id" value="<?php echo htmlspecialchars($order_details['id']); ?>">
                <input type="hidden" name="selected_products_data" id="selected_products_data">

                <div class="form-group">
                    <label for="dealer_id">Müşteri Seçin:</label>
                    <select id="dealer_id" name="dealer_id" required>
                        <option value="">Müşteri Seçin</option>
                        <?php foreach ($customers as $customer) : ?>
                            <option
                                value="<?php echo htmlspecialchars($customer['id']); ?>"
                                data-credit-limit="<?php echo htmlspecialchars($customer['credit_limit']); ?>"
                                data-current-debt="<?php echo htmlspecialchars($customer['current_debt']); ?>"
                                data-fixed-asset-credit-limit="<?php echo htmlspecialchars($customer['fixed_asset_credit_limit']); ?>"
                                data-fixed-asset-current-debt="<?php echo htmlspecialchars($customer['fixed_asset_current_debt']); ?>"
                                <?php echo ($customer['id'] == $order_details['dealer_id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($customer['customer_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div id="cari_limit_info" style="margin-top: 10px; padding: 10px; border: 1px solid #ccc; background-color: #f9f9f9; display: none;">
                    <strong>ÜRÜN Cari Limit:</strong> <span id="display_credit_limit"></span> TL <br>
                    <strong>ÜRÜN Mevcut Borç:</strong> <span id="display_current_debt"></span> TL <br>
                    <strong>ÜRÜN Kalan Limit:</strong> <span id="display_remaining_limit"></span> TL
                    <br><br>
                    <strong>DEMİRBAŞ Cari Limit:</strong> <span id="display_fixed_asset_credit_limit"></span> TL <br>
                    <strong>DEMİRBAŞ Mevcut Borç:</strong> <span id="display_fixed_asset_current_debt"></span> TL <br>
                    <strong>DEMİRBAŞ Kalan Limit:</strong> <span id="display_fixed_asset_remaining_limit"></span> TL
                </div>

                <!-- Ödeme Yöntemi: Düzenleme ekranında ödeme yöntemi değişmemeli, sadece bilgi olarak gösterilmeli. -->
                <div class="form-group">
                    <label for="payment_method_display">Ödeme Yöntemi:</label>
                    <input type="text" id="payment_method_display" value="<?php echo htmlspecialchars($order_details['payment_method']); ?>" disabled>
                    <input type="hidden" name="payment_method" value="<?php echo htmlspecialchars($order_details['payment_method']); ?>">
                </div>

                <?php if ($order_details['payment_method'] == 'Çek' && !empty($order_details['check_images'])) : ?>
                    <div class="form-group">
                        <label>Mevcut Çek Görselleri:</label>
                        <div class="check-images-gallery">
                            <?php foreach ($order_details['check_images'] as $image) : ?>
                                <a href="<?php echo htmlspecialchars($image['file_path']); ?>" target="_blank">
                                    <img src="<?php echo htmlspecialchars($image['file_path']); ?>" alt="<?php echo htmlspecialchars($image['attachment_type']); ?>" style="max-width: 100px; height: auto; margin: 5px; border: 1px solid #ddd;">
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>


                <h3>Ürünler</h3>
                <div class="selected-products-container">
                    <h4>Seçili Ürünler:</h4>
                    <div id="selected_products_list">
                        <p id="no_products_selected" style="color: #666;">Henüz ürün seçilmedi.</p>
                        <!-- Seçilen ürünler buraya eklenecek -->
                    </div>
                </div>

                <p style="margin-top: 20px;">Aşağıdan ürünleri seçerek siparişinize ekleyin (Bağımlı ürünler otomatik eklenecektir):</p>
                <div class="product-selection-grid">
                    <?php if (!empty($products)) : ?>
                        <?php foreach ($products as $product) : ?>
                            <div class="product-card"
                                data-product-id="<?php echo htmlspecialchars($product['id']); ?>"
                                data-product-name="<?php echo htmlspecialchars($product['product_name']); ?>"
                                data-product-price="<?php echo htmlspecialchars($product['price']); ?>"
                                data-product-stock="<?php echo htmlspecialchars($product['stock_quantity']); ?>"
                                data-product-image="<?php echo htmlspecialchars($product['image_url'] ?: 'placeholder.jpg'); ?>"
                                data-product-type="<?php echo htmlspecialchars($product['product_type']); ?>"
                                data-product-vat-rate="<?php echo htmlspecialchars($product['vat_rate']); ?>">
                                <img src="<?php echo htmlspecialchars($product['image_url'] ?: 'placeholder.jpg'); ?>" alt="<?php echo htmlspecialchars($product['product_name']); ?>">
                                <h4><?php echo htmlspecialchars($product['product_name']); ?></h4>
                                <p>Stok: <?php echo htmlspecialchars($product['stock_quantity']); ?></p>
                                <p>Fiyat: <?php echo number_format($product['price'], 2, ',', '.'); ?> TL (KDV: <?php echo number_format($product['vat_rate'], 0); ?>%)</p>
                                <div class="quantity-control">
                                    <button type="button" class="decrease-btn">-</button>
                                    <input type="number" class="product-qty-input" value="0" min="0" max="<?php echo htmlspecialchars($product['stock_quantity']); ?>" readonly>
                                    <button type="button" class="increase-btn">+</button>
                                </div>
                                <button type="button" class="product-remove-btn">X</button>
                            </div>
                        <?php endforeach; ?>
                    <?php else : ?>
                        <p>Henüz hiç ürün bulunmamaktadır.</p>
                    <?php endif; ?>
                </div>

                <div class="form-group" style="margin-top: 20px;">
                    <label for="notes">Notlar:</label>
                    <textarea id="notes" name="notes" rows="3"><?php echo htmlspecialchars($order_details['notes']); ?></textarea>
                </div>

                <div class="form-group">
                    <button type="submit" name="edit_order" class="btn btn-primary">Siparişi Güncelle</button>
                    <a href="orders.php?action=view&id=<?php echo htmlspecialchars($order_details['id']); ?>" class="btn btn-secondary">İptal</a>
                </div>
            </form>

        <?php elseif ($action == 'view' && $order_details) : ?>
            <h2>Sipariş Detayları (ID: <?php echo htmlspecialchars($order_details['id']); ?>)</h2>
            <div class="order-summary">
                <p><strong>Müşteri Adı:</strong> <?php echo htmlspecialchars($order_details['dealer_name']); ?></p>
                <p><strong>Müşteri Adresi:</strong> <?php echo htmlspecialchars($order_details['dealer_address']); ?></p>
                <p><strong>Müşteri Telefon:</strong> <?php echo htmlspecialchars($order_details['dealer_phone']); ?></p>
                <p><strong>Müşteri Email:</strong> <?php echo htmlspecialchars($order_details['dealer_email']); ?></p>
                <p><strong>Müşteri Vergi No:</strong> <?php echo htmlspecialchars($order_details['dealer_tax_id']); ?></p>
                <p><strong>ÜRÜN Cari Limit:</strong> <?php echo number_format($order_details['credit_limit'], 2, ',', '.') . ' TL'; ?></p>
                <p><strong>ÜRÜN Mevcut Borç:</strong> <?php echo number_format($order_details['current_debt'], 2, ',', '.') . ' TL'; ?></p>
                <p><strong>DEMİRBAŞ Cari Limit:</strong> <?php echo number_format($order_details['fixed_asset_credit_limit'], 2, ',', '.') . ' TL'; ?></p>
                <p><strong>DEMİRBAŞ Mevcut Borç:</strong> <?php echo number_format($order_details['fixed_asset_current_debt'], 2, ',', '.') . ' TL'; ?></p>
                <p><strong>Sipariş Tarihi:</strong> <?php echo htmlspecialchars($order_details['order_date']); ?></p>
                <p><strong>Toplam Tutar:</strong> <?php echo number_format($order_details['total_amount'], 2, ',', '.') . ' TL'; ?></p>
                <p><strong>Durum:</strong> <span class="status-badge status-<?php echo str_replace([' ', '.'], ['_', ''], htmlspecialchars($order_details['order_status'])); ?>"><?php echo htmlspecialchars($order_details['order_status']); ?></span></p>
                <p><strong>Ödeme Yöntemi:</strong> <?php echo htmlspecialchars($order_details['payment_method']); ?></p>
                <p><strong>Ödeme Durumu:</strong> <?php echo htmlspecialchars($order_details['payment_status']); ?></p>
                <p><strong>Faturalandırma Durumu:</strong> <?php echo htmlspecialchars($order_details['invoice_status']); ?></p>

                <?php if ($order_details['current_approver_role_name_detail']) : ?>
                    <p><strong>Şu Anki Onayda:</strong> <?php echo htmlspecialchars($order_details['current_approver_role_name_detail']); ?></p>
                <?php else: ?>
                    <p><strong>Şu Anki Onayda:</strong> Onay Süreci Tamamlandı</p>
                <?php endif; ?>

                <p><strong>Oluşturan:</strong> <?php echo htmlspecialchars($order_details['created_by_name']); ?> (<?php echo htmlspecialchars($order_details['created_by_role']); ?>)</p>
                <p><strong>Son Güncelleyen:</strong> <?php echo htmlspecialchars($order_details['last_updated_by_name']); ?> (<?php echo htmlspecialchars($order_details['last_updated_by_role']); ?>)</p>

                <?php if ($order_details['sales_manager_approved_by']) : ?>
                    <p><strong>Satış Onayı:</strong> <?php echo htmlspecialchars($order_details['sales_approved_by_name']); ?> (<?php echo htmlspecialchars($order_details['sales_manager_approved_date']); ?>)</p>
                <?php endif; ?>
                <?php if ($order_details['accounting_approved_by']) : ?>
                    <p><strong>Muhasebe Onayı:</strong> <?php echo htmlspecialchars($order_details['accounting_approved_by_name']); ?> (<?php echo htmlspecialchars($order_details['accounting_approved_date']); ?>)</p>
                <?php endif; ?>
                <?php if ($order_details['shipment_date']) : ?>
                    <p><strong>Sevkiyat Tarihi:</strong> <?php echo htmlspecialchars($order_details['shipment_date']); ?></p>
                    <p><strong>Teslimat Plaka No:</strong> <?php echo htmlspecialchars($order_details['delivery_plate_number']); ?></p>
                    <p><strong>Sevkiyatı Yapan:</strong> <?php echo htmlspecialchars($order_details['shipment_approved_by_name']); ?></p>
                <?php endif; ?>
                <p><strong>Notlar:</strong> <?php echo nl2br(htmlspecialchars($order_details['notes'])); ?></p>

                <?php if ($order_details['payment_method'] == 'Çek' && !empty($order_details['check_images'])) : ?>
                    <h4>Çek Görselleri:</h4>
                    <div class="check-images-gallery">
                        <?php foreach ($order_details['check_images'] as $image) : ?>
                            <a href="<?php echo htmlspecialchars($image['file_path']); ?>" target="_blank">
                                <img src="<?php echo htmlspecialchars($image['file_path']); ?>" alt="<?php echo htmlspecialchars($image['attachment_type']); ?>" style="max-width: 150px; height: auto; margin: 5px; border: 1px solid #ddd;">
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <h3>Sipariş Ürünleri</h3>
            <table class="order-items-table">
                <thead>
                    <tr>
                        <th>Ürün Kodu</th>
                        <th>Ürün Adı</th>
                        <th>Miktar</th>
                        <th>KDV Oranı</th>
                        <th>KDV Dahil Birim Fiyat</th>
                        <th>KDV Hariç Birim Fiyat</th>
                        <th>Birim KDV Tutarı</th>
                        <th>Toplam Fiyat (KDV Dahil)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($order_details['items'])) : ?>
                        <?php foreach ($order_details['items'] as $item) : ?>
                            <tr>
                                <td><?php echo htmlspecialchars($item['sku']); ?></td>
                                <td><?php echo htmlspecialchars($item['product_name']); ?></td>
                                <td><?php echo htmlspecialchars($item['quantity']); ?></td>
                                <td><?php echo number_format($item['vat_rate'], 0) . '%'; ?></td>
                                <td><?php echo number_format($item['unit_price'], 2, ',', '.') . ' TL'; ?></td>
                                <td><?php echo number_format($item['unit_price_excluding_vat'], 2, ',', '.') . ' TL'; ?></td>
                                <td><?php echo number_format($item['vat_amount_per_unit'], 2, ',', '.') . ' TL'; ?></td>
                                <td><?php echo number_format($item['total_price'], 2, ',', '.') . ' TL'; ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else : ?>
                        <tr><td colspan="8">Bu sipariş için ürün bulunmamaktadır.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>

            <div style="margin-top: 20px;">
                <a href="orders.php" class="btn btn-secondary">Listeye Dön</a>

                <?php
                    $user_role_id = isset($_SESSION['user_role_id']) ? $_SESSION['user_role_id'] : null;
                    $order_status = $order_details['order_status'];

                    // Satış Onayı Formu (Satış Müdürü, Genel Müdür, GMY)
                    if ($user_role_id !== null && in_array($user_role_id, [1, 2, 3]) && $order_status == 'Satış Onayı Bekliyor') :
                        $show_sales_approve = true;
                        if ($user_role_id == 3) {
                            if (isset($_SESSION['user_id'])) {
                                $stmt_check_owner = $pdo->prepare("SELECT COUNT(*) FROM sales_managers_to_dealers WHERE sales_manager_id = ? AND dealer_id = ?");
                                $stmt_check_owner->execute([$_SESSION['user_id'], $order_details['dealer_id']]);
                                if ($stmt_check_owner->fetchColumn() == 0) {
                                    $show_sales_approve = false;
                                }
                            } else {
                                $show_sales_approve = false;
                            }
                        }
                        if ($show_sales_approve) :
                ?>
                            <form action="orders.php" method="post" style="display: inline-block;">
                                <input type="hidden" name="order_id" value="<?php echo htmlspecialchars($order_details['id']); ?>">
                                <button type="submit" name="approve_sales_order" class="btn btn-success">Satış Onayı Ver</button>
                            </form>
                <?php
                        endif;
                    endif;

                    // Muhasebe Onayı Formu (Muhasebe Müdürü, Genel Müdür, GMY)
                    if ($user_role_id !== null && in_array($user_role_id, [1, 2, 4]) && $order_status == 'Muhasebe Onayı Bekliyor') :
                ?>
                        <form action="orders.php" method="post" style="display: inline-block;">
                            <input type="hidden" name="order_id" value="<?php echo htmlspecialchars($order_details['id']); ?>">
                            <button type="submit" name="approve_accounting_order" class="btn btn-success">Muhasebe Onayı Ver</button>
                        </form>
                <?php
                    endif;

                    // Reddetme Butonu (Tüm onaycılar reddedebilir, ancak onayla butonu yerine)
                    if ($user_role_id !== null && in_array($user_role_id, [1, 2, 3, 4]) && in_array($order_status, ['Beklemede', 'Satış Onayı Bekliyor', 'Muhasebe Onayı Bekliyor'])) :
                        $show_reject = true;
                        if ($user_role_id == 3) { // Satış Müdürü
                            if (isset($_SESSION['user_id'])) {
                                $stmt_check_owner = $pdo->prepare("SELECT COUNT(*) FROM sales_managers_to_dealers WHERE sales_manager_id = ? AND dealer_id = ?");
                                $stmt_check_owner->execute([$_SESSION['user_id'], $order_details['dealer_id']]);
                                if ($stmt_check_owner->fetchColumn() == 0) {
                                    $show_reject = false;
                                }
                            } else {
                                $show_reject = false;
                            }
                        }
                        // Muhasebe sadece kendi onayına geleni reddedebilir
                        if ($user_role_id == 4 && $order_status != 'Muhasebe Onayı Bekliyor') {
                            $show_reject = false;
                        }
                        
                        if ($show_reject) :
                ?>
                            <button type="button" class="btn btn-danger" onclick="document.getElementById('rejectForm').style.display='block'">Reddet</button>
                <?php
                        endif;
                    endif;

                    // Sevkiyat Güncelleme Formu (Sevkiyat Sorumlusu, Genel Müdür, GMY)
                    if ($user_role_id !== null && in_array($user_role_id, [1, 2, 5]) && in_array($order_status, ['Onaylandı', 'Faturalandı', 'Sevkiyatta'])) :
                ?>
                        <button type="button" class="btn btn-info" onclick="document.getElementById('shipmentForm').style.display='block'">Sevkiyat Bilgilerini Güncelle</button>
                <?php
                    endif;

                    // Fatura Yazdırma Butonu (Muhasebe Müdürü, Genel Müdür, GMY)
                    if ($user_role_id !== null && in_array($user_role_id, [1, 2, 4]) && ($order_status == 'Onaylandı' || $order_status == 'Faturalandı' || $order_status == 'Sevkiyatta' || $order_status == 'Tamamlandı')) :
                ?>
                    <button type="button" class="btn btn-secondary" onclick="window.print()">Fatura Yazdır</button>
                <?php endif; ?>

            </div>

            <!-- Reddetme Formu -->
            <div id="rejectForm" style="display: none; margin-top: 20px;">
                <h3>Siparişi Reddet</h3>
                <form action="orders.php" method="post">
                    <input type="hidden" name="order_id" value="<?php echo htmlspecialchars($order_details['id']); ?>">
                    <div class="form-group">
                        <label for="reject_notes">Reddetme Nedeni:</label>
                        <textarea id="reject_notes" name="reject_notes" rows="3" required></textarea>
                    </div>
                    <button type="submit" name="reject_order" class="btn btn-danger">Siparişi Reddet</button>
                    <button type="button" class="btn btn-secondary" onclick="document.getElementById('rejectForm').style.display='none'">İptal</button>
                </form>
            </div>

            <!-- Sevkiyat Bilgilerini Güncelleme Formu -->
            <div id="shipmentForm" style="display: none; margin-top: 20px;">
                <h3>Sevkiyat Bilgilerini Güncelle</h3>
                <form action="orders.php" method="post">
                    <input type="hidden" name="order_id" value="<?php echo htmlspecialchars($order_details['id']); ?>">
                    <div class="form-group">
                        <label for="shipment_date">Sevkiyat Tarihi:</label>
                        <input type="date" id="shipment_date" name="shipment_date" value="<?php echo htmlspecialchars($order_details['shipment_date'] ?: date('Y-m-d')); ?>" required>
                    </div>
                    <div class="form-group">
                        <label for="delivery_plate_number">Teslimat Plaka No:</label>
                        <input type="text" id="delivery_plate_number" name="delivery_plate_number" value="<?php echo htmlspecialchars($order_details['delivery_plate_number']); ?>">
                    </div>
                    <button type="submit" name="update_shipment" class="btn btn-info">Sevkiyatı Güncelle</button>
                    <button type="button" class="btn btn-secondary" onclick="document.getElementById('shipmentForm').style.display='none'">İptal</button>
                </form>
            </div>

        <?php else : ?>
            <div class="message error">Sipariş bulunamadı veya geçersiz işlem.</div>
            <p><a href="orders.php" class="btn btn-primary">Geri Dön</a></p>
        <?php endif; ?>
    </div>

    <!-- HTML içeriği buraya kadar -->

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const dealerSelect = document.getElementById('dealer_id');
            const paymentMethodSelect = document.getElementById('payment_method');
            const checkUploadSection = document.getElementById('check_upload_section');
            const cariLimitInfo = document.getElementById('cari_limit_info');
            
            // ÜRÜN Cari Limit Bilgileri
            const displayCreditLimit = document.getElementById('display_credit_limit');
            const displayCurrentDebt = document.getElementById('display_current_debt');
            const displayRemainingLimit = document.getElementById('display_remaining_limit');

            // DEMİRBAŞ Cari Limit Bilgileri
            const displayFixedAssetCreditLimit = document.getElementById('display_fixed_asset_credit_limit');
            const displayFixedAssetCurrentDebt = document.getElementById('display_fixed_asset_current_debt');
            const displayFixedAssetRemainingLimit = document.getElementById('display_fixed_asset_remaining_limit');


            const productSelectionGrid = document.querySelector('.product-selection-grid');
            const selectedProductsList = document.getElementById('selected_products_list');
            const noProductsSelected = document.getElementById('no_products_selected'); // "Henüz ürün seçilmedi" yazısı

            // !!! PHP'den ürün ve bağımlılık verilerini al !!!
            const productsData = <?php echo json_encode($products_map_for_js); ?>; // Tüm ürünlerin detayları (id'ye göre map)
            const productDependencies = <?php echo json_encode($product_dependencies_for_js); ?>; // Bağımlılık kuralları

            // Seçilen ürünleri ve miktarlarını tutan Map
            const selectedOrderProducts = new Map(); // productId -> {productData, quantity, autoAdded, isEditable}
            const selectedProductsDataInput = document.getElementById('selected_products_data');


            // Fonksiyon: Gizli input alanını güncelle
            function updateHiddenInput() {
                const data = [];
                selectedOrderProducts.forEach(item => {
                    data.push({
                        product_id: item.productData.id,
                        quantity: item.quantity
                    });
                });
                selectedProductsDataInput.value = JSON.stringify(data);
            }

            // Fonksiyon: Seçili ürünleri listesini render et
            function renderSelectedProducts() {
                selectedProductsList.innerHTML = ''; // Önce listeyi temizle
                if (selectedOrderProducts.size === 0) {
                    noProductsSelected.style.display = 'block';
                } else {
                    noProductsSelected.style.display = 'none';
                    selectedOrderProducts.forEach(item => {
                        const product = item.productData;
                        const div = document.createElement('div');
                        div.classList.add('selected-product-card');
                        div.dataset.productId = product.id;
                        if (item.autoAdded) {
                            div.dataset.autoAdded = 'true';
                        }
                        div.innerHTML = `
                            <div class="info">
                                <img src="${product.image_url || 'placeholder.jpg'}" alt="${product.product_name}">
                                <div class="details">
                                    <h4>${product.product_name} ${item.autoAdded ? '<span class="badge-info">(Otomatik)</span>' : ''}</h4>
                                    <p>Stok: ${product.stock_quantity} | Fiyat: ${parseFloat(product.price).toFixed(2).replace('.', ',')} TL (KDV: ${parseFloat(product.vat_rate).toFixed(0)}%)</p>
                                </div>
                            </div>
                            <div class="quantity-controls">
                                <button type="button" class="decrease-qty-btn" ${item.autoAdded && !item.isEditable ? 'disabled' : ''}>-</button>
                                <input type="number" value="${item.quantity}" min="1" max="${product.stock_quantity}" data-product-id="${product.id}" class="product-quantity-input" ${item.autoAdded && !item.isEditable ? 'readonly' : ''}>
                                <button type="button" class="increase-qty-btn" ${item.autoAdded && !item.isEditable ? 'disabled' : ''}>+</button>
                                <button type="button" class="remove-selected-product-btn remove" ${item.autoAdded && !item.isEditable ? 'disabled' : ''}>X</button>
                            </div>
                        `;
                        selectedProductsList.appendChild(div);
                    });
                }
                updateHiddenInput(); // Hidden input'u da her render sonrası güncelle
            }

            // Fonksiyon: Ana ürünlerin miktarlarına göre bağımlı ürünleri hesaplar ve günceller
            function updateDependentProducts() {
                // Sadece manuel eklenen ürünleri al
                const mainProductsData = new Map(); // productId -> quantity
                selectedOrderProducts.forEach((item, productId) => {
                    if (!item.autoAdded) {
                        mainProductsData.set(productId, item.quantity);
                    }
                });

                // Önceki otomatik eklenmiş ürünleri temizle
                // selectedOrderProducts'ı tersine dönerek veya yeni bir harita oluşturarak silme işlemi yapılır
                const newSelectedOrderProducts = new Map();
                selectedOrderProducts.forEach((item, productId) => {
                    if (!item.autoAdded) { // Otomatik olmayanları koru
                        newSelectedOrderProducts.set(productId, item);
                    }
                });
                selectedOrderProducts.clear(); // Eski haritayı temizle
                newSelectedOrderProducts.forEach((item, productId) => { // Yeni ürünleri ekle
                    selectedOrderProducts.set(productId, item);
                });


                // Bağımlılıkları işle
                mainProductsData.forEach((mainQty, mainProductId) => {
                    const mainProduct = productsData[mainProductId];
                    if (!mainProduct) return; // Ürün datası yoksa geç

                    const mainProductType = mainProduct.product_type;
                    if (productDependencies[mainProductType]) {
                        productDependencies[mainProductType].forEach(dep => {
                            const dependentProductId = dep.dependent_product_id;
                            const dependentQtyPerUnit = dep.quantity_per_unit;
                            const isEditable = dep.is_editable;

                            const totalDependentQty = Math.ceil(mainQty * dependentQtyPerUnit); // Miktarı yukarı yuvarla

                            if (totalDependentQty > 0) {
                                const dependentProduct = productsData[dependentProductId];
                                if (dependentProduct) {
                                    // Eğer bağımlı ürün zaten manuel olarak eklenmişse, otomatik ekleme yapma
                                    if (mainProductsData.has(dependentProductId)) {
                                        // Bu durumda bağımlı ürün, aynı zamanda manuel eklenmiş bir ana üründür.
                                        // Otomatik olarak eklenmemeli, zaten manuel olarak yönetiliyor demektir.
                                        // console.log(`Bağımlı ürün ID ${dependentProductId} aynı zamanda ana ürün olarak seçildi. Otomatik eklenmeyecek.`);
                                    } else {
                                        // Otomatik eklenmişse miktarını güncelle veya yeni ekle
                                        if (selectedOrderProducts.has(dependentProductId) && selectedOrderProducts.get(dependentProductId).autoAdded) {
                                            const currentDep = selectedOrderProducts.get(dependentProductId);
                                            currentDep.quantity += totalDependentQty; // Aynı üründen birden fazla bağımlılık gelirse topla
                                            selectedOrderProducts.set(dependentProductId, currentDep);
                                        } else {
                                            selectedOrderProducts.set(dependentProductId, {
                                                productData: dependentProduct,
                                                quantity: totalDependentQty,
                                                autoAdded: true,
                                                isEditable: isEditable
                                            });
                                        }
                                    }
                                }
                            }
                        });
                    }
                });
                renderSelectedProducts();
            }

            // Fonksiyon: Ürün kartındaki miktar değiştiğinde veya X'e basıldığında tetiklenir
            function updateProductQuantity(productId, newQuantity) {
                if (newQuantity <= 0) {
                    selectedOrderProducts.delete(productId);
                    // Ana griddeki ürün kartının miktarını sıfırla ve X butonunu gizle
                    const mainCard = productSelectionGrid.querySelector(`.product-card[data-product-id="${productId}"]`);
                    if (mainCard) {
                        mainCard.querySelector('.product-qty-input').value = 0;
                        mainCard.querySelector('.product-remove-btn').style.display = 'none';
                    }
                } else {
                    const product = productsData[productId];
                    if (product) {
                        selectedOrderProducts.set(productId, {
                            productData: product,
                            quantity: newQuantity,
                            autoAdded: false // Bu manuel olarak değiştirildiği için autoAdded değil
                        });
                    }
                }
                updateDependentProducts(); // Ana ürünler değişince bağımlıları güncelle
            }


            // Ürün kartlarındaki + / - / X butonları
            if (productSelectionGrid) {
                productSelectionGrid.addEventListener('click', function(e) {
                    const targetBtn = e.target;
                    const productCard = targetBtn.closest('.product-card');
                    if (!productCard) return;

                    const qtyInput = productCard.querySelector('.product-qty-input');
                    const removeBtn = productCard.querySelector('.product-remove-btn');
                    let currentQty = parseInt(qtyInput.value);
                    const maxQty = parseInt(productCard.dataset.productStock);
                    const productId = parseInt(productCard.dataset.productId);

                    if (targetBtn.classList.contains('increase-btn')) {
                        if (currentQty < maxQty) {
                            qtyInput.value = currentQty + 1;
                            removeBtn.style.display = 'block'; // Miktar 0'dan büyükse X butonu görünür
                            updateProductQuantity(productId, currentQty + 1);
                        }
                    } else if (targetBtn.classList.contains('decrease-btn')) {
                        if (currentQty > 0) {
                            qtyInput.value = currentQty - 1;
                            if (qtyInput.value == 0) {
                                removeBtn.style.display = 'none'; // Miktar 0 olursa X butonu gizlenir
                            }
                            updateProductQuantity(productId, currentQty - 1);
                        }
                    } else if (targetBtn.classList.contains('product-remove-btn')) {
                        qtyInput.value = 0;
                        removeBtn.style.display = 'none';
                        updateProductQuantity(productId, 0);
                    }
                });
            }

            // Seçili ürünler listesindeki + / - / X butonları
            if (selectedProductsList) {
                selectedProductsList.addEventListener('click', function(e) {
                    const targetBtn = e.target;
                    const selectedProductCard = targetBtn.closest('.selected-product-card');
                    if (!selectedProductCard) return;

                    // Otomatik eklenmiş ve düzenlenemez olan ürünler üzerinde işlem yapma
                    const isAutoAdded = selectedProductCard.dataset.autoAdded === 'true';
                    const qtyInput = selectedProductCard.querySelector('.product-quantity-input');
                    const isEditable = !qtyInput.readOnly; // readonly kontrolü
                    
                    if (isAutoAdded && !isEditable) {
                        return; // Otomatik eklenmiş ve düzenlenemez ise hiçbir şey yapma
                    }

                    const productId = parseInt(selectedProductCard.dataset.productId);
                    let currentQty = parseInt(qtyInput.value);
                    const productStock = parseInt(productsData[productId].stock_quantity);

                    // Ana griddeki ürün kartının miktar inputunu da güncellemek için
                    const mainProductCard = productSelectionGrid.querySelector(`.product-card[data-product-id="${productId}"]`);
                    const mainQtyInput = mainProductCard ? mainProductCard.querySelector('.product-qty-input') : null;
                    const mainRemoveBtn = mainProductCard ? mainProductCard.querySelector('.product-remove-btn') : null;


                    if (targetBtn.classList.contains('increase-qty-btn')) {
                        if (currentQty < productStock) {
                            qtyInput.value = currentQty + 1;
                            if (mainQtyInput && !isAutoAdded) mainQtyInput.value = qtyInput.value; // Sadece manuelse main'i güncelle
                            
                            // Map'teki ürünü güncelle (otomatik eklenmiş olsa bile miktarını güncelle)
                            const currentItem = selectedOrderProducts.get(productId);
                            if (currentItem) {
                                currentItem.quantity = currentQty + 1;
                                selectedOrderProducts.set(productId, currentItem);
                            }
                            renderSelectedProducts(); // Listeyi yeniden çiz
                        }
                    } else if (targetBtn.classList.contains('decrease-qty-btn')) {
                        if (currentQty > 1) {
                            qtyInput.value = currentQty - 1;
                            if (mainQtyInput && !isAutoAdded) mainQtyInput.value = qtyInput.value;
                            
                            // Map'teki ürünü güncelle
                            const currentItem = selectedOrderProducts.get(productId);
                            if (currentItem) {
                                currentItem.quantity = currentQty - 1;
                                selectedOrderProducts.set(productId, currentItem);
                            }
                            renderSelectedProducts(); // Listeyi yeniden çiz

                        } else if (currentQty === 1 && !isAutoAdded) { // Miktar 1 ise ve azaltılırsa, ürünü listeden kaldır (sadece manuel ürünler için)
                            selectedOrderProducts.delete(productId);
                            if (mainQtyInput) mainQtyInput.value = 0;
                            if (mainRemoveBtn) mainRemoveBtn.style.display = 'none';
                            updateDependentProducts(); // Listeden kalktığı için tekrar hesapla
                        }
                    } else if (targetBtn.classList.contains('remove-selected-product-btn')) {
                        if (!isAutoAdded) { // Sadece manuel ürünleri bu butondan kaldır
                            selectedOrderProducts.delete(productId);
                            if (mainQtyInput) mainQtyInput.value = 0;
                            if (mainRemoveBtn) mainRemoveBtn.style.display = 'none';
                            updateDependentProducts(); // Listeden kalktığı için tekrar hesapla
                        }
                    }
                });
            }


            // Ödeme Yöntemi seçimi değiştiğinde çek yükleme alanını göster/gizle
            if (paymentMethodSelect && checkUploadSection) {
                paymentMethodSelect.addEventListener('change', function() {
                    if (this.value === 'Çek') {
                        checkUploadSection.style.display = 'block';
                    } else {
                        checkUploadSection.style.display = 'none';
                    }
                });
                // Sayfa yüklendiğinde mevcut değeri kontrol et (Sipariş Ekle formu için)
                // Düzenleme formunda paymentMethodSelect yok, onun yerine disabled input var.
                if (paymentMethodSelect && paymentMethodSelect.value === 'Çek') {
                    checkUploadSection.style.display = 'block';
                }
            }
            
            // Müşteri seçimi değiştiğinde cari limit bilgilerini göster/gizle
            if (dealerSelect && cariLimitInfo) {
                dealerSelect.addEventListener('change', function() {
                    const selectedOption = this.options[this.selectedIndex];
                    if (selectedOption && selectedOption.value) {
                        const creditLimit = parseFloat(selectedOption.dataset.creditLimit || 0);
                        const currentDebt = parseFloat(selectedOption.dataset.currentDebt || 0);
                        const fixedAssetCreditLimit = parseFloat(selectedOption.dataset.fixedAssetCreditLimit || 0);
                        const fixedAssetCurrentDebt = parseFloat(selectedOption.dataset.fixedAssetCurrentDebt || 0);

                        displayCreditLimit.textContent = creditLimit.toFixed(2).replace('.', ',');
                        displayCurrentDebt.textContent = currentDebt.toFixed(2).replace('.', ',');
                        displayRemainingLimit.textContent = (creditLimit - currentDebt).toFixed(2).replace('.', ',');

                        displayFixedAssetCreditLimit.textContent = fixedAssetCreditLimit.toFixed(2).replace('.', ',');
                        displayFixedAssetCurrentDebt.textContent = fixedAssetCurrentDebt.toFixed(2).replace('.', ',');
                        displayFixedAssetRemainingLimit.textContent = (fixedAssetCreditLimit - fixedAssetCurrentDebt).toFixed(2).replace('.', ',');

                        cariLimitInfo.style.display = 'block';
                    } else {
                        cariLimitInfo.style.display = 'none';
                    }
                });
                // Sayfa yüklendiğinde mevcut müşteri seçili ise bilgileri göster
                if (dealerSelect.value) {
                    dealerSelect.dispatchEvent(new Event('change'));
                }
            }

            // Sayfa yüklendiğinde mevcut seçili ürünleri selected_products_list'e ekle (düzenleme modu için)
            <?php if ($action == 'edit' && $order_details) : ?>
                <?php foreach ($order_details['items'] as $item) : ?>
                    // Ürünü selectedOrderProducts map'ine ekle
                    const prodId = <?php echo htmlspecialchars($item['product_id']); ?>;
                    const prodQty = <?php echo htmlspecialchars($item['quantity']); ?>;
                    const prodData = productsData[prodId]; // productsData objesini PHP'den alıyoruz

                    // Bağımlı ürün mü değil mi kontrolü yap
                    const isDependent = <?php
                        $is_dependent_check = false;
                        foreach ($product_dependencies_for_js as $trigger_type => $deps_array) {
                            foreach ($deps_array as $dep) {
                                if ($dep['dependent_product_id'] == $item['product_id']) {
                                    $is_dependent_check = true;
                                    break 2; // İç ve dış döngüden çık
                                }
                            }
                        }
                        echo $is_dependent_check ? 'true' : 'false';
                    ?>;

                    // Bağımlı ürünün editable durumunu bul
                    let isEditableDependent = true; // Varsayılan olarak düzenlenebilir kabul et
                    if (isDependent) {
                        for (const type in productDependencies) {
                            if (productDependencies.hasOwnProperty(type)) {
                                const deps = productDependencies[type];
                                const foundDep = deps.find(dep => dep.dependent_product_id === prodId);
                                if (foundDep) {
                                    isEditableDependent = foundDep.is_editable;
                                    break;
                                }
                            }
                        }
                    }

                    if (prodData) {
                        selectedOrderProducts.set(prodId, {
                            productData: prodData,
                            quantity: prodQty,
                            autoAdded: isDependent, // Veritabanından gelen bağımlı ürünü autoAdded olarak işaretle
                            isEditable: isEditableDependent // Dependent ise onun editable durumunu kullan
                        });
                        // Eğer ürün ana bir ürünse, ana griddeki kartını da güncelle
                        if (!isDependent) {
                            const mainCard = document.querySelector(`.product-card[data-product-id="${prodId}"]`);
                            if (mainCard) {
                                mainCard.querySelector('.product-qty-input').value = prodQty;
                                mainCard.querySelector('.product-remove-btn').style.display = 'block';
                            }
                        }
                    }
                <?php endforeach; ?>
                // Tüm manuel ürünler eklendikten sonra bağımlı ürünleri bir kez hesapla ve render et
                // Düzenleme ekranında veritabanından gelen autoAdded ürünler zaten mevcut olduğu için,
                // updateDependentProducts() fonksiyonunun sadece manuel ürünlerden tekrar hesaplama yapması gerekebilir.
                // Ancak burada zaten tümü çekildiği için sadece render yeterli olabilir.
                renderSelectedProducts(); 

            <?php elseif ($action == 'add' && isset($_POST['selected_products_data'])) : ?>
                // Eğer add formunda bir hata olduysa ve form tekrar yüklendiyse, daha önce seçili ürünleri göstermek için
                const postedProductsData = <?php echo json_encode($_POST['selected_products_data']); ?>;
                // PHP'den gelen products_map_for_js objesini de JS'e aktar.
                // Bu ürünler autoAdded olarak işaretlenmediği için, updateDependentProducts() bunları manuel ürün gibi görüp
                // bağımlılıkları hesaplayacaktır.
                postedProductsData.forEach(item => {
                    const prodId = item.product_id;
                    const prodQty = item.quantity;
                    const prodData = productsData[prodId]; // productsData global olarak tanımlı

                    // Sadece manuel eklenmiş ürünleri geri yükle. Otomatik eklenenler updateDependentProducts() ile tekrar hesaplanacak.
                    // Ürün ID'si bağımlı ürünler listesinde geçiyorsa bu manuel eklenmiş bir ürün değildir.
                    // Veya PHP'den gelen data'da `autoAdded` bilgisi yok, bu yüzden `dependent_product_ids` listesini kullanmalıyız.
                    const isDependentProduct = Object.values(productDependencies).some(deps => 
                        deps.some(dep => dep.dependent_product_id === prodId)
                    );

                    if (prodData && !isDependentProduct) { // Sadece ana (manuel) ürünleri geri yükle
                        selectedOrderProducts.set(prodId, {
                            productData: prodData,
                            quantity: prodQty,
                            autoAdded: false
                        });
                        const mainCard = document.querySelector(`.product-card[data-product-id="${prodId}"]`);
                        if (mainCard) {
                            mainCard.querySelector('.product-qty-input').value = prodQty;
                            mainCard.querySelector('.product-remove-btn').style.display = 'block';
                        }
                    }
                });
                updateDependentProducts(); // Geri yüklenen manuel ürünlere göre bağımlılıkları tekrar hesapla
            <?php else: ?>
                // Eğer hiçbir ürün seçili değilse, başlangıçta boş listeyi render et
                renderSelectedProducts();
            <?php endif; ?>
        });
    </script>

    <?php
    // footer.php dosyasının varlığını kontrol et
    if (!file_exists('includes/footer.php')) {
        throw new Exception("Hata: 'includes/footer.php' dosyası bulunamadı. Lütfen dosya yolunu kontrol edin.");
    }
    include 'includes/footer.php';
    ?>
</body>
</html>
<?php
} catch (Exception $e) {
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
    error_log("Kritik Yakalanmış Hata (orders.php): " . $e->getMessage() . " in " . $e->getFile() . " on line " . $e->getLine());
}
?>