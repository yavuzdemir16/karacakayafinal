<?php
// Hata raporlamayı aç
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

try {
    session_start();

    require_once 'config.php';

    // check_permission fonksiyonunun varlığını kontrol et
    if (!function_exists('check_permission')) {
        throw new Exception("Hata: 'check_permission' fonksiyonu bulunamadı. 'config.php' dosyasında veya ilgili kütüphanede tanımlı olduğundan emin olun.");
    }

    // Yetki kontrolü: Genel Müdür, Genel Müdür Yardımcısı, Satış Müdürü erişebilir.
    check_permission(['genel_mudur', 'genel_mudur_yardimcisi', 'satis_muduru']);

    // connect_db() fonksiyonu artık PDO nesnesi döndürüyor.
    $pdo = connect_db(); // Bağlantı nesnesini $pdo olarak aldık

    $action = isset($_GET['action']) ? $_GET['action'] : 'list'; // 'list', 'add', 'edit', 'delete'

    $message = '';
    $message_type = '';

    // Satış Müdürleri listesini çek (müşteri ataması için)
    $sales_managers = [];
    try {
        $stmt_sales_managers = $pdo->prepare("SELECT u.id, u.full_name FROM users u JOIN roles r ON u.role_id = r.id WHERE r.role_name = 'satis_muduru'");
        $stmt_sales_managers->execute();
        $sales_managers = $stmt_sales_managers->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Satış müdürleri çekilirken hata oluştu: " . $e->getMessage());
        $message = "Satış müdürleri bilgileri alınamadı.";
        $message_type = 'error';
    }


    // Form gönderildiğinde işleme al
    if ($_SERVER["REQUEST_METHOD"] == "POST") {
        if (isset($_POST['add_customer'])) {
            $customer_name = trim($_POST['customer_name']);
            $contact_person = trim($_POST['contact_person']);
            $phone = trim($_POST['phone']);
            $email = trim($_POST['email']);
            $address = trim($_POST['address']);
            $city = trim($_POST['city']);
            $country = trim($_POST['country']);
            $tax_id = trim($_POST['tax_id']);
            $credit_limit = isset($_POST['credit_limit']) ? floatval($_POST['credit_limit']) : 0.0; // Yeni
            $fixed_asset_credit_limit = isset($_POST['fixed_asset_credit_limit']) ? floatval($_POST['fixed_asset_credit_limit']) : 0.0; // Yeni
            $assigned_sales_manager_ids = isset($_POST['assigned_sales_managers']) ? $_POST['assigned_sales_managers'] : [];

            if (empty($customer_name)) {
                $message = "Müşteri adı boş bırakılamaz.";
                $message_type = 'error';
            } else {
                // Sadece Genel Müdür ve Genel Müdür Yardımcısı yeni müşteri ekleyebilir.
                if ($_SESSION['user_role_id'] == 3) {
                     $message = "Satış müdürleri, müşteri ekleyemez.";
                     $message_type = 'error';
                } else {
                    try {
                        $pdo->beginTransaction();

                        $sql = "INSERT INTO customers (customer_name, contact_person, phone, email, address, city, country, tax_id, credit_limit, fixed_asset_credit_limit) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                        $stmt = $pdo->prepare($sql);
                        $stmt->execute([$customer_name, $contact_person, $phone, $email, $address, $city, $country, $tax_id, $credit_limit, $fixed_asset_credit_limit]);

                        $customer_id = $pdo->lastInsertId();

                        // Satış müdürlerini ata
                        if (!empty($assigned_sales_manager_ids)) {
                            $insert_sm_sql = "INSERT INTO sales_managers_to_dealers (sales_manager_id, dealer_id) VALUES (?, ?)"; // dealer_id yerine customer_id
                            $stmt_sm = $pdo->prepare($insert_sm_sql);
                            foreach ($assigned_sales_manager_ids as $sm_id) {
                                $stmt_sm->execute([$sm_id, $customer_id]);
                            }
                        }
                        $pdo->commit();
                        $message = "Müşteri başarıyla eklendi.";
                        $message_type = 'success';
                    } catch (PDOException $e) {
                        $pdo->rollBack();
                        $message = "Müşteri eklenirken bir hata oluştu: " . $e->getMessage();
                        $message_type = 'error';
                    }
                }
            }
            $action = 'list'; // İşlem sonrası listeye dön
        } elseif (isset($_POST['edit_customer'])) {
            $customer_id = $_POST['customer_id'];
            $customer_name = trim($_POST['customer_name']);
            $contact_person = trim($_POST['contact_person']);
            $phone = trim($_POST['phone']);
            $email = trim($_POST['email']);
            $address = trim($_POST['address']);
            $city = trim($_POST['city']);
            $country = trim($_POST['country']);
            $tax_id = trim($_POST['tax_id']);
            $is_active = isset($_POST['is_active']) ? 1 : 0;
            // Yeni credit limit alanları, sadece Genel Müdür ve GMY için POST'ta gelecek
            $credit_limit = isset($_POST['credit_limit']) ? floatval($_POST['credit_limit']) : null;
            $fixed_asset_credit_limit = isset($_POST['fixed_asset_credit_limit']) ? floatval($_POST['fixed_asset_credit_limit']) : null;


            if (empty($customer_name)) {
                $message = "Müşteri adı boş bırakılamaz.";
                $message_type = 'error';
            } else {
                try {
                    $pdo->beginTransaction();
                    // Satış müdürünün sadece kendi müşterilerini düzenlemesine izin ver
                    if ($_SESSION['user_role_id'] == 3) { // 3: Satış Müdürü ID'si
                         // Müşterinin ilgili satış müdürüne ait olup olmadığını kontrol et
                         $check_owner_sql = "SELECT COUNT(*) FROM sales_managers_to_dealers WHERE sales_manager_id = ? AND dealer_id = ?";
                         $check_owner_stmt = $pdo->prepare($check_owner_sql);
                         $check_owner_stmt->execute([$_SESSION['user_id'], $customer_id]);
                         $count_owner = $check_owner_stmt->fetchColumn();

                         if ($count_owner == 0) {
                             $message = "Bu müşteriyi düzenlemeye yetkiniz yok.";
                             $message_type = 'error';
                             $action = 'list';
                             goto end_post_processing; // İşlemi sonlandır
                         }
                         // Satış müdürü credit_limit, fixed_asset_credit_limit ve is_active'i düzenleyemez
                         $sql = "UPDATE customers SET customer_name = ?, contact_person = ?, phone = ?, email = ?, address = ?, city = ?, country = ?, tax_id = ? WHERE id = ?";
                         $stmt = $pdo->prepare($sql);
                         $stmt->execute([$customer_name, $contact_person, $phone, $email, $address, $city, $country, $tax_id, $customer_id]);
                    } else { // Genel Müdür veya Genel Müdür Yardımcısı tüm alanları düzenleyebilir
                        $sql = "UPDATE customers SET customer_name = ?, contact_person = ?, phone = ?, email = ?, address = ?, city = ?, country = ?, tax_id = ?, is_active = ?, credit_limit = ?, fixed_asset_credit_limit = ? WHERE id = ?";
                        $stmt = $pdo->prepare($sql);
                        $stmt->execute([$customer_name, $contact_person, $phone, $email, $address, $city, $country, $tax_id, $is_active, $credit_limit, $fixed_asset_credit_limit, $customer_id]);

                        // Eski satış müdürü atamalarını sil
                        $delete_sm_sql = "DELETE FROM sales_managers_to_dealers WHERE dealer_id = ?"; // dealer_id yerine customer_id
                        $stmt_delete_sm = $pdo->prepare($delete_sm_sql);
                        $stmt_delete_sm->execute([$customer_id]);

                        // Yeni satış müdürlerini ata
                        if (!empty($assigned_sales_manager_ids)) {
                            $insert_sm_sql = "INSERT INTO sales_managers_to_dealers (sales_manager_id, dealer_id) VALUES (?, ?)";
                            $stmt_insert_sm = $pdo->prepare($insert_sm_sql);
                            foreach ($assigned_sales_manager_ids as $sm_id) {
                                $stmt_insert_sm->execute([$sm_id, $customer_id]);
                            }
                        }
                    }

                    $pdo->commit();
                    $message = "Müşteri başarıyla güncellendi.";
                    $message_type = 'success';
                } catch (PDOException $e) {
                    $pdo->rollBack();
                    $message = "Müşteri güncellenirken bir hata oluştu: " . $e->getMessage();
                    $message_type = 'error';
                }
            }
            $action = 'list'; // İşlem sonrası listeye dön
        } elseif (isset($_GET['delete_id'])) {
            $delete_id = $_GET['delete_id'];

            // Sadece Genel Müdür ve Genel Müdür Yardımcısı silme yetkisine sahip.
            if ($_SESSION['user_role_id'] == 1 || $_SESSION['user_role_id'] == 2) { // 1: Genel Müdür, 2: Genel Müdür Yardımcısı ID'si
                try {
                    $pdo->beginTransaction();
                    // Bağlı satış müdürlerini sil
                    $delete_sm_sql = "DELETE FROM sales_managers_to_dealers WHERE dealer_id = ?"; // dealer_id yerine customer_id
                    $stmt_delete_sm = $pdo->prepare($delete_sm_sql);
                    $stmt_delete_sm->execute([$delete_id]);

                    $sql = "DELETE FROM customers WHERE id = ?";
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute([$delete_id]);

                    $pdo->commit();
                    $message = "Müşteri başarıyla silindi.";
                    $message_type = 'success';
                } catch (PDOException $e) {
                    $pdo->rollBack();
                    $message = "Müşteri silinirken bir hata oluştu: " . $e->getMessage();
                    $message_type = 'error';
                }
            } else {
                $message = "Bu müşteriyi silmeye yetkiniz yok.";
                $message_type = 'error';
            }
            $action = 'list'; // İşlem sonrası listeye dön
        }
    }
    end_post_processing:;


    // Liste için müşterileri çek
    $customers = [];
    $sql_customers = "SELECT c.* FROM customers c";
    $params = [];
    // Satış müdürü ise sadece kendi müşterilerini görsün
    if (isset($_SESSION['user_role_id']) && $_SESSION['user_role_id'] == 3) { // 3: Satış Müdürü ID'si
        $sql_customers .= " JOIN sales_managers_to_dealers smd ON c.id = smd.dealer_id WHERE smd.sales_manager_id = ?"; // dealer_id yerine customer_id
        $params[] = $_SESSION['user_id'];
    }

    try {
        $stmt_customers = $pdo->prepare($sql_customers);
        $stmt_customers->execute($params);
        $customers_raw = $stmt_customers->fetchAll(PDO::FETCH_ASSOC);

        if (!empty($customers_raw)) {
            foreach ($customers_raw as $row) {
                // Her müşteri için atanmış satış müdürlerini çek
                $assigned_sm_names = [];
                $stmt_assigned_sm = $pdo->prepare("SELECT u.full_name FROM users u JOIN sales_managers_to_dealers smd ON u.id = smd.sales_manager_id WHERE smd.dealer_id = ?"); // dealer_id yerine customer_id
                $stmt_assigned_sm->execute([$row['id']]);
                $sm_rows = $stmt_assigned_sm->fetchAll(PDO::FETCH_ASSOC);
                foreach($sm_rows as $sm_row){
                    $assigned_sm_names[] = $sm_row['full_name'];
                }
                $row['assigned_sales_managers'] = implode(', ', $assigned_sm_names);
                $customers[] = $row;
            }
        }
    } catch (PDOException $e) {
        error_log("Müşteri listesi çekilirken hata oluştu: " . $e->getMessage());
        $message = "Müşteri listesi alınırken bir hata oluştu.";
        $message_type = 'error';
    }

    // Sayfa başlığını ayarla
    $page_title = "Müşteri Yönetimi"; // Bu satır header.php tarafından kullanılacak
    // header.php'yi dahil et
    include 'includes/header.php';
    ?>

        <div class="container">
            <h1>Müşteri Yönetimi</h1>

            <?php if (!empty($message)) : ?>
                <div class="message <?php echo $message_type; ?>"><?php echo $message; ?></div>
            <?php endif; ?>

            <?php if ($action == 'list') : ?>
                <?php if (isset($_SESSION['user_role_id']) && ($_SESSION['user_role_id'] == 1 || $_SESSION['user_role_id'] == 2)) : // Genel Müdür ve Genel Müdür Yardımcısı ekleyebilir ?>
                    <p><a href="customers.php?action=add" class="btn btn-success">Yeni Müşteri Ekle</a></p>
                <?php endif; ?>

                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Müşteri Adı</th>
                            <th>Yetkili</th>
                            <th>Telefon</th>
                            <th>Email</th>
                            <th>Şehir</th>
                            <th>Ürün Cari Limit</th>
                            <th>Demirbaş Cari Limit</th>
                            <th>Atanan Satış Müdürleri</th>
                            <th>Aktif Mi?</th>
                            <th>İşlemler</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($customers)) : ?>
                            <?php foreach ($customers as $customer) : ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($customer['id']); ?></td>
                                    <td><?php echo htmlspecialchars($customer['customer_name']); ?></td>
                                    <td><?php echo htmlspecialchars($customer['contact_person']); ?></td>
                                    <td><?php echo htmlspecialchars($customer['phone']); ?></td>
                                    <td><?php echo htmlspecialchars($customer['email']); ?></td>
                                    <td><?php echo htmlspecialchars($customer['city']); ?></td>
                                    <td><?php echo number_format($customer['credit_limit'], 2, ',', '.') . ' TL'; ?></td>
                                    <td><?php echo number_format($customer['fixed_asset_credit_limit'], 2, ',', '.') . ' TL'; ?></td>
                                    <td><?php echo htmlspecialchars($customer['assigned_sales_managers'] ?: 'Yok'); ?></td>
                                    <td><?php echo $customer['is_active'] ? 'Evet' : 'Hayır'; ?></td>
                                    <td>
                                        <?php
                                        // user_role_id ve user_id kullanarak kontrol
                                        $can_edit = false;
                                        if (isset($_SESSION['user_role_id'])) {
                                            if ($_SESSION['user_role_id'] == 1 || $_SESSION['user_role_id'] == 2) { // GM veya GMY
                                                $can_edit = true;
                                            } elseif ($_SESSION['user_role_id'] == 3 && isset($_SESSION['user_id'])) { // Satış Müdürü
                                                // Eğer satış müdürü ise, atandığı müşteriyi düzenleyebilir mi kontrolü
                                                $stmt_check_assignment = $pdo->prepare("SELECT COUNT(*) FROM sales_managers_to_dealers WHERE sales_manager_id = ? AND dealer_id = ?"); // dealer_id yerine customer_id
                                                $stmt_check_assignment->execute([$_SESSION['user_id'], $customer['id']]);
                                                if ($stmt_check_assignment->fetchColumn() > 0) {
                                                    $can_edit = true;
                                                }
                                            }
                                        }
                                        ?>
                                        <?php if ($can_edit) : ?>
                                            <a href='customers.php?action=edit&id=<?php echo $customer['id']; ?>' class='btn btn-warning'>Düzenle</a>
                                        <?php endif; ?>

                                        <?php if (isset($_SESSION['user_role_id']) && ($_SESSION['user_role_id'] == 1 || $_SESSION['user_role_id'] == 2)) : // Sadece Genel Müdür ve Yardımcısı silebilir ?>
                                            <a href='customers.php?action=delete&delete_id=<?php echo $customer['id']; ?>' class='btn btn-danger' onclick="return confirm('Bu müşteriyi silmek istediğinizden emin misiniz?');">Sil</a>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else : ?>
                            <tr><td colspan='11'>Henüz hiç müşteri yok.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>

            <?php elseif ($action == 'add' && isset($_SESSION['user_role_id']) && ($_SESSION['user_role_id'] == 1 || $_SESSION['user_role_id'] == 2)) : // Sadece GM ve GMY ekleyebilir ?>
                <h2>Yeni Müşteri Ekle</h2>
                <form action="customers.php" method="post">
                    <div class="form-group">
                        <label for="customer_name">Müşteri Adı:</label>
                        <input type="text" id="customer_name" name="customer_name" required>
                    </div>
                    <div class="form-group">
                        <label for="contact_person">İlgili Kişi:</label>
                        <input type="text" id="contact_person" name="contact_person">
                    </div>
                    <div class="form-group">
                        <label for="phone">Telefon:</label>
                        <input type="tel" id="phone" name="phone">
                    </div>
                    <div class="form-group">
                        <label for="email">E-posta:</label>
                        <input type="email" id="email" name="email">
                    </div>
                    <div class="form-group">
                        <label for="address">Adres:</label>
                        <textarea id="address" name="address"></textarea>
                    </div>
                    <div class="form-group">
                        <label for="city">Şehir:</label>
                        <input type="text" id="city" name="city">
                    </div>
                    <div class="form-group">
                        <label for="country">Ülke:</label>
                        <input type="text" id="country" name="country">
                    </div>
                    <div class="form-group">
                        <label for="tax_id">Vergi Numarası:</label>
                        <input type="text" id="tax_id" name="tax_id">
                    </div>

                    <!-- Yeni Cari Limit Alanları -->
                    <div class="form-group">
                        <label for="credit_limit">Ürün Cari Limit (TL):</label>
                        <input type="number" id="credit_limit" name="credit_limit" step="0.01" min="0" value="0.00">
                    </div>
                    <div class="form-group">
                        <label for="fixed_asset_credit_limit">Demirbaş Cari Limit (TL):</label>
                        <input type="number" id="fixed_asset_credit_limit" name="fixed_asset_credit_limit" step="0.01" min="0" value="0.00">
                    </div>
                    <!-- Yeni Cari Limit Alanları Son -->

                    <div class="form-group">
                        <label>Atanacak Satış Müdürleri:</label>
                        <div class="checkbox-group">
                            <?php if (!empty($sales_managers)) : ?>
                                <?php foreach ($sales_managers as $sm) : ?>
                                    <label>
                                        <input type="checkbox" name="assigned_sales_managers[]" value="<?php echo $sm['id']; ?>">
                                        <?php echo htmlspecialchars($sm['full_name']); ?>
                                    </label>
                                <?php endforeach; ?>
                            <?php else : ?>
                                <p>Henüz kayıtlı satış müdürü bulunmamaktadır.</p>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="form-group">
                        <button type="submit" name="add_customer" class="btn btn-primary">Müşteri Ekle</button>
                        <a href="customers.php" class="btn btn-secondary">İptal</a>
                    </div>
                </form>

            <?php elseif ($action == 'edit' && isset($_GET['id'])) :
                $edit_customer_id = $_GET['id'];
                $customer_to_edit = null;

                try {
                    // Yetki kontrolü: Sadece Genel Müdür, Genel Müdür Yardımcısı veya atanmış Satış Müdürü düzenleyebilir.
                    // Satış müdürü sadece kendi müşterisini düzenleyebilir.
                    if (isset($_SESSION['user_role_id']) && $_SESSION['user_role_id'] == 3) {
                         $stmt = $pdo->prepare("SELECT c.* FROM customers c JOIN sales_managers_to_dealers smd ON c.id = smd.dealer_id WHERE c.id = ? AND smd.sales_manager_id = ?");
                         $stmt->execute([$edit_customer_id, $_SESSION['user_id']]);
                    } else {
                         $stmt = $pdo->prepare("SELECT c.* FROM customers c WHERE c.id = ?");
                         $stmt->execute([$edit_customer_id]);
                    }

                    $customer_to_edit = $stmt->fetch(PDO::FETCH_ASSOC);

                    if ($customer_to_edit) {
                        // Atanmış satış müdürlerini çek (sadece edit formunda gerekli)
                        $current_assigned_sm_ids = [];
                        if (isset($_SESSION['user_role_id']) && $_SESSION['user_role_id'] != 3) { // Satış müdürünün bu kısmı görmesine gerek yok
                            $stmt_current_sm = $pdo->prepare("SELECT sales_manager_id FROM sales_managers_to_dealers WHERE dealer_id = ?");
                            $stmt_current_sm->execute([$edit_customer_id]);
                            $sm_rows = $stmt_current_sm->fetchAll(PDO::FETCH_COLUMN);
                            $current_assigned_sm_ids = $sm_rows;
                        }
                    }
                } catch (PDOException $e) {
                    error_log("Müşteri düzenleme bilgisi çekilirken hata oluştu: " . $e->getMessage());
                    $message = "Müşteri bilgileri alınırken bir hata oluştu.";
                    $message_type = 'error';
                }

                if ($customer_to_edit) : ?>
                    <h2>Müşteri Düzenle</h2>
                    <form action="customers.php" method="post">
                        <input type="hidden" name="customer_id" value="<?php echo htmlspecialchars($customer_to_edit['id']); ?>">
                        <div class="form-group">
                            <label for="customer_name">Müşteri Adı:</label>
                            <input type="text" id="customer_name" name="customer_name" value="<?php echo htmlspecialchars($customer_to_edit['customer_name']); ?>" required>
                        </div>
                        <div class="form-group">
                            <label for="contact_person">İlgili Kişi:</label>
                            <input type="text" id="contact_person" name="contact_person" value="<?php echo htmlspecialchars($customer_to_edit['contact_person']); ?>">
                        </div>
                        <div class="form-group">
                            <label for="phone">Telefon:</label>
                            <input type="tel" id="phone" name="phone" value="<?php echo htmlspecialchars($customer_to_edit['phone']); ?>">
                        </div>
                        <div class="form-group">
                            <label for="email">E-posta:</label>
                            <input type="email" id="email" name="email" value="<?php echo htmlspecialchars($customer_to_edit['email']); ?>">
                        </div>
                        <div class="form-group">
                            <label for="address">Adres:</label>
                            <textarea id="address" name="address"><?php echo htmlspecialchars($customer_to_edit['address']); ?></textarea>
                        </div>
                        <div class="form-group">
                            <label for="city">Şehir:</label>
                            <input type="text" id="city" name="city" value="<?php echo htmlspecialchars($customer_to_edit['city']); ?>">
                        </div>
                        <div class="form-group">
                            <label for="country">Ülke:</label>
                            <input type="text" id="country" name="country" value="<?php echo htmlspecialchars($customer_to_edit['country']); ?>">
                        </div>
                        <div class="form-group">
                            <label for="tax_id">Vergi Numarası:</label>
                            <input type="text" id="tax_id" name="tax_id" value="<?php echo htmlspecialchars($customer_to_edit['tax_id']); ?>">
                        </div>

                        <?php if (isset($_SESSION['user_role_id']) && ($_SESSION['user_role_id'] == 1 || $_SESSION['user_role_id'] == 2)) : // Sadece GM ve GMY görebilir/düzenleyebilir ?>
                        <!-- Yeni Cari Limit Alanları -->
                        <div class="form-group">
                            <label for="credit_limit">Ürün Cari Limit (TL):</label>
                            <input type="number" id="credit_limit" name="credit_limit" step="0.01" min="0" value="<?php echo htmlspecialchars($customer_to_edit['credit_limit']); ?>">
                        </div>
                        <div class="form-group">
                            <label for="fixed_asset_credit_limit">Demirbaş Cari Limit (TL):</label>
                            <input type="number" id="fixed_asset_credit_limit" name="fixed_asset_credit_limit" step="0.01" min="0" value="<?php echo htmlspecialchars($customer_to_edit['fixed_asset_credit_limit']); ?>">
                        </div>
                        <!-- Yeni Cari Limit Alanları Son -->

                        <div class="form-group">
                            <label>Atanacak Satış Müdürleri:</label>
                            <div class="checkbox-group">
                                <?php if (!empty($sales_managers)) : ?>
                                    <?php foreach ($sales_managers as $sm) : ?>
                                        <label>
                                            <input type="checkbox" name="assigned_sales_managers[]" value="<?php echo $sm['id']; ?>" <?php echo in_array($sm['id'], $current_assigned_sm_ids) ? 'checked' : ''; ?>>
                                            <?php echo htmlspecialchars($sm['full_name']); ?>
                                        </label>
                                    <?php endforeach; ?>
                                <?php else : ?>
                                    <p>Henüz kayıtlı satış müdürü bulunmamaktadır.</p>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="form-group">
                            <label for="is_active">Aktif Mi?</label>
                            <input type="checkbox" id="is_active" name="is_active" value="1" <?php echo $customer_to_edit['is_active'] ? 'checked' : ''; ?>>
                        </div>
                        <?php else: ?>
                            <input type="hidden" name="is_active" value="<?php echo $customer_to_edit['is_active']; ?>">
                            <?php // Satış müdürü sadece kendi müşterilerini düzenleyebildiğinden, is_active ve atanmış satış müdürlerini göremesin ?>
                            <?php // Credit limit ve fixed asset credit limit de burada hidden olarak tutulmalı ki POST'a gitsin ?>
                            <input type="hidden" name="credit_limit" value="<?php echo htmlspecialchars($customer_to_edit['credit_limit']); ?>">
                            <input type="hidden" name="fixed_asset_credit_limit" value="<?php echo htmlspecialchars($customer_to_edit['fixed_asset_credit_limit']); ?>">
                        <?php endif; ?>

                        <div class="form-group">
                            <button type="submit" name="edit_customer" class="btn btn-primary">Müşteriyi Güncelle</button>
                            <a href="customers.php" class="btn btn-secondary">İptal</a>
                        </div>
                    </form>
                <?php else : ?>
                    <div class="message error">Müşteri bulunamadı veya düzenleme yetkiniz yok.</div>
                    <p><a href="customers.php" class="btn btn-primary">Geri Dön</a></p>
                <?php endif; ?>
            <?php endif; ?>
        </div>

    <?php
    // footer.php'yi dahil et
    include 'includes/footer.php';
    ?>

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
    error_log("Kritik Yakalanmış Hata (customers.php): " . $e->getMessage() . " in " . $e->getFile() . " on line " . $e->getLine());
}
?>
