<?php
session_start(); // Oturumu başlat
// PHP hata raporlamasını açıyoruz. Geliştirme ortamında bu her zaman açık olmalı.
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// config.php dosyasını dahil ediyoruz.
// Bu dosyanın PDO bağlantısını (connect_db()) ve yetkilendirme fonksiyonunu (check_permission()) içerdiğinden emin olun.
require_once 'config.php';

// Yetki kontrolü: Sadece 'genel_mudur' veya 'genel_mudur_yardimcisi' rolleri bu sayfaya erişebilir.
check_permission(['genel_mudur', 'genel_mudur_yardimcisi']);

// Sayfa başlığını ayarla
$page_title = "Kullanıcı Yönetimi"; // Bu satır header.php tarafından kullanılacak

// Veritabanı bağlantısını al (PDO nesnesi)
$pdo = connect_db();

// Hata oluştuğunda PDO'nun exception fırlatmasını sağlıyoruz. Bu hata yakalamayı kolaylaştırır.
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$action = isset($_GET['action']) ? $_GET['action'] : 'list'; // 'list', 'add', 'edit', 'delete'

// Mesajlar (başarılı/başarısız işlemler için)
$message = '';
$message_type = ''; // 'success' or 'error'

// customer_role_id'yi burada sabit olarak tanımlayalım
$customer_role_id = 6; // 'customer' rolünün ID'si (kontrol edildi: 6)

// Kullanıcı ekleme/düzenleme/silme işlemleri
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $current_logged_in_user_id = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : null;
    if ($current_logged_in_user_id === null) {
         $message = "Oturum kullanıcı ID'si bulunamadı. Lütfen tekrar giriş yapın.";
         $message_type = 'error';
         $action = 'list';
         goto end_post_processing_users;
    }

    if (isset($_POST['add_user'])) {
        $username = trim($_POST['username']);
        $password = trim($_POST['password']);
        $role_id = intval($_POST['role_id']);
        $full_name = trim($_POST['full_name']);

        // Gerekli alanların boş olup olmadığını kontrol et
        if (empty($username) || empty($password) || empty($full_name)) {
            $message = "Kullanıcı adı, parola ve tam ad boş bırakılamaz.";
            $message_type = 'error';
        } else {
            // Genel Müdür Yardımcısının Genel Müdür rolü atamasını engelle
            if (isset($_SESSION["user_role_id"]) && $_SESSION["user_role_id"] != 1 && $role_id == 1) {
                $message = "Genel Müdür Yardımcısı, Genel Müdür rolü atayamaz.";
                $message_type = 'error';
            } else {
                try {
                    // Transaction başlatma: Hem users hem de customers tablosuna kayıtlar başarılı olmalı
                    $pdo->beginTransaction();

                    $hashed_password = password_hash($password, PASSWORD_DEFAULT);

                    // Kullanıcı adının benzersizliğini kontrol et
                    $check_sql = "SELECT id FROM users WHERE username = ?";
                    $check_stmt = $pdo->prepare($check_sql);
                    $check_stmt->execute([$username]);
                    $existing_user = $check_stmt->fetch(PDO::FETCH_ASSOC);

                    if ($existing_user) {
                        $message = "Bu kullanıcı adı zaten mevcut.";
                        $message_type = 'error';
                        $pdo->rollBack(); // Hata olursa işlemi geri al
                    } else {
                        // users tablosuna ekle
                        $sql_user = "INSERT INTO users (username, password, role_id, full_name, created_at, updated_at) VALUES (?, ?, ?, ?, NOW(), NOW())";
                        $stmt_user = $pdo->prepare($sql_user);
                        $stmt_user->execute([$username, $hashed_password, $role_id, $full_name]);

                        // Yeni eklenen kullanıcının ID'sini al
                        $new_user_id = $pdo->lastInsertId();

                        // Eğer seçilen rol 'customer' ise, customer tablosuna da ekle
                        if ($role_id === $customer_role_id) {
                            // Formdan gelen müşteri bilgileri
                            $first_name = trim($_POST['first_name'] ?? '');
                            $last_name = trim($_POST['last_name'] ?? '');
                            $company_name = trim($_POST['company_name'] ?? '');
                            $tax_office = trim($_POST['tax_office'] ?? '');
                            $tax_id_customer = trim($_POST['tax_id_customer'] ?? '');
                            $address_line1 = trim($_POST['address_line1'] ?? '');
                            $address_line2 = trim($_POST['address_line2'] ?? '');
                            $city = trim($_POST['city'] ?? '');
                            $state = trim($_POST['state'] ?? '');
                            $zip_code = trim($_POST['zip_code'] ?? '');
                            $country = trim($_POST['country'] ?? '');
                            $phone_number = trim($_POST['phone_number'] ?? '');
                            $customer_email = trim($_POST['email'] ?? '');

                            // Müşteri bilgileri için zorunlu alan kontrolü (Kullanıcı Tarafında JS ile kontrol edilecek)
                            // PHP tarafında da basit bir kontrol
                            if (empty($company_name) || empty($first_name) || empty($last_name) || empty($phone_number) || empty($customer_email)) {
                                $message = "Müşteri rolü seçildiğinde Firma Adı, Ad, Soyad, Email ve Telefon Numarası boş bırakılamaz.";
                                $message_type = 'error';
                                $pdo->rollBack(); // Hata olursa işlemi geri al
                            } else {
                                $contact_person_for_db = $first_name . ' ' . $last_name;
                                $full_address_for_db = trim($address_line1 . ' ' . $address_line2); // Adres satırları birleştirilerek kaydedilecek

                                $sql_customer = "INSERT INTO customers (user_id, customer_name, first_name, last_name, tax_office, tax_id, contact_person, phone, email, address, address_line1, address_line2, city, state, zip_code, country, credit_limit, current_debt, created_by, last_updated_by, created_at, updated_at, is_active)
                                                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW(), TRUE)";
                                $stmt_customer = $pdo->prepare($sql_customer);
                                $stmt_customer->execute([
                                    $new_user_id,
                                    $company_name,
                                    $first_name,
                                    $last_name,
                                    $tax_office,
                                    $tax_id_customer,
                                    $contact_person_for_db,
                                    $phone_number,
                                    $customer_email,
                                    $full_address_for_db, // customers.address sütununa tam adres
                                    $address_line1,
                                    $address_line2,
                                    $city,
                                    $state,
                                    $zip_code,
                                    $country,
                                    0.00, // credit_limit (varsayılan)
                                    0.00, // current_debt (varsayılan)
                                    $current_logged_in_user_id, // created_by
                                    $current_logged_in_user_id // last_updated_by
                                ]);

                                // Commit transaction
                                $pdo->commit();
                                $message = "Müşteri kullanıcısı başarıyla eklendi.";
                                $message_type = 'success';
                            }
                        } else {
                            // Eğer rol 'customer' değilse, sadece users tablosuna eklendi
                            $pdo->commit();
                            $message = "Kullanıcı başarıyla eklendi.";
                            $message_type = 'success';
                        }
                    }
                } catch (PDOException $e) {
                    $pdo->rollBack(); // Hata olursa işlemi geri al
                    $message = "Kullanıcı eklenirken bir veritabanı hatası oluştu: " . $e->getMessage();
                    $message_type = 'error';
                    error_log("Add User Error: " . $e->getMessage()); // Hata loglama
                }
            }
        }
        $action = 'list';
    } elseif (isset($_POST['edit_user'])) {
        $user_id = intval($_POST['user_id']);
        $username = trim($_POST['username']);
        $role_id = intval($_POST['role_id']);
        $full_name = trim($_POST['full_name']);
        $password = trim($_POST['password']);

        try {
            // Hedef kullanıcının mevcut rolünü al
            $target_user_role_id = 0;
            $stmt_get_role = $pdo->prepare("SELECT role_id FROM users WHERE id = ?");
            $stmt_get_role->execute([$user_id]);
            $target_user_role_id = $stmt_get_role->fetchColumn();

            // Yetki kontrolleri
            if (isset($_SESSION["user_role_id"]) && $_SESSION["user_role_id"] != 1 && $role_id == 1) {
                $message = "Genel Müdür Yardımcısı, Genel Müdür rolü atayamaz.";
                $message_type = 'error';
            } elseif (isset($_SESSION["user_role_id"]) && $_SESSION["user_role_id"] == 2 && $target_user_role_id == 1) {
                $message = "Genel Müdür Yardımcısı, Genel Müdür kullanıcılarını düzenleyemez.";
                $message_type = 'error';
            } else {
                $pdo->beginTransaction(); // Transaction başlat

                // Kullanıcı adının benzersizliğini kontrol et (kendi hariç)
                $check_sql = "SELECT id FROM users WHERE username = ? AND id != ?";
                $check_stmt = $pdo->prepare($check_sql);
                $check_stmt->execute([$username, $user_id]);
                $existing_user = $check_stmt->fetch(PDO::FETCH_ASSOC);

                if ($existing_user) {
                    $message = "Bu kullanıcı adı zaten mevcut.";
                    $message_type = 'error';
                    $pdo->rollBack();
                } else {
                    $sql_fields = "username = ?, role_id = ?, full_name = ?, updated_at = NOW()";
                    $params_array = [$username, $role_id, $full_name];

                    if (!empty($password)) {
                        $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                        $sql_fields = "username = ?, password = ?, role_id = ?, full_name = ?, updated_at = NOW()";
                        array_splice($params_array, 1, 0, $hashed_password);
                    }

                    $sql = "UPDATE users SET " . $sql_fields . " WHERE id = ?";
                    $params_array[] = $user_id;

                    $stmt = $pdo->prepare($sql);
                    $stmt->execute($params_array); // UPDATE users tablosu

                    // Role değişikliği kontrolü
                    $current_customer_status = ($target_user_role_id === $customer_role_id);
                    $new_customer_status = ($role_id === $customer_role_id);

                    if ($new_customer_status && !$current_customer_status) {
                        // Rol 'customer' oldu, müşteriye özel detayları customers tablosuna ekle
                        $first_name = trim($_POST['first_name'] ?? '');
                        $last_name = trim($_POST['last_name'] ?? '');
                        $company_name = trim($_POST['company_name'] ?? '');
                        $tax_office = trim($_POST['tax_office'] ?? '');
                        $tax_id_customer = trim($_POST['tax_id_customer'] ?? '');
                        $address_line1 = trim($_POST['address_line1'] ?? '');
                        $address_line2 = trim($_POST['address_line2'] ?? '');
                        $city = trim($_POST['city'] ?? '');
                        $state = trim($_POST['state'] ?? '');
                        $zip_code = trim($_POST['zip_code'] ?? '');
                        $country = trim($_POST['country'] ?? '');
                        $phone_number = trim($_POST['phone_number'] ?? '');
                        $customer_email = trim($_POST['email'] ?? '');

                         if (empty($company_name) || empty($first_name) || empty($last_name) || empty($phone_number) || empty($customer_email)) {
                            $message = "Müşteri rolü seçildiğinde Firma Adı, Ad, Soyad, Email ve Telefon Numarası boş bırakılamaz.";
                            $message_type = 'error';
                            $pdo->rollBack();
                        } else {
                            $contact_person_for_db = $first_name . ' ' . $last_name;
                            $full_address_for_db = trim($address_line1 . ' ' . $address_line2);

                            $sql_customer_insert = "INSERT INTO customers (user_id, customer_name, first_name, last_name, tax_office, tax_id, contact_person, phone, email, address, address_line1, address_line2, city, state, zip_code, country, credit_limit, current_debt, created_by, last_updated_by, created_at, updated_at, is_active)
                                                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW(), TRUE)";
                            $stmt_customer_insert = $pdo->prepare($sql_customer_insert);
                            $stmt_customer_insert->execute([
                                $user_id,
                                $company_name,
                                $first_name,
                                $last_name,
                                $tax_office,
                                $tax_id_customer,
                                $contact_person_for_db,
                                $phone_number,
                                $customer_email,
                                $full_address_for_db,
                                $address_line1,
                                $address_line2,
                                $city,
                                $state,
                                $zip_code,
                                $country,
                                0.00,
                                0.00,
                                $current_logged_in_user_id,
                                $current_logged_in_user_id
                            ]);
                            $pdo->commit();
                            $message = "Kullanıcı ve müşteri bilgileri başarıyla güncellendi.";
                            $message_type = 'success';
                        }
                    } elseif (!$new_customer_status && $current_customer_status) {
                        // Rol 'customer' olmaktan çıktı, customers tablosundaki kaydı pasifize et
                        // Tamamen silmek yerine pasifize ediyoruz.
                        $sql_customer_deactivate = "UPDATE customers SET is_active = FALSE, last_updated_by = ?, updated_at = NOW() WHERE user_id = ?";
                        $stmt_customer_deactivate = $pdo->prepare($sql_customer_deactivate);
                        $stmt_customer_deactivate->execute([$current_logged_in_user_id, $user_id]);
                        $pdo->commit();
                        $message = "Kullanıcı rolü güncellendi, ilgili müşteri bilgisi pasifize edildi.";
                        $message_type = 'success';
                    } elseif ($new_customer_status && $current_customer_status) {
                        // Rol hala 'customer', müşteriye özel detayları customers tablosunda güncelle
                        $first_name = trim($_POST['first_name'] ?? '');
                        $last_name = trim($_POST['last_name'] ?? '');
                        $company_name = trim($_POST['company_name'] ?? '');
                        $tax_office = trim($_POST['tax_office'] ?? '');
                        $tax_id_customer = trim($_POST['tax_id_customer'] ?? '');
                        $address_line1 = trim($_POST['address_line1'] ?? '');
                        $address_line2 = trim($_POST['address_line2'] ?? '');
                        $city = trim($_POST['city'] ?? '');
                        $state = trim($_POST['state'] ?? '');
                        $zip_code = trim($_POST['zip_code'] ?? '');
                        $country = trim($_POST['country'] ?? '');
                        $phone_number = trim($_POST['phone_number'] ?? '');
                        $customer_email = trim($_POST['email'] ?? '');

                        if (empty($company_name) || empty($first_name) || empty($last_name) || empty($phone_number) || empty($customer_email)) {
                            $message = "Müşteri rolü seçildiğinde Firma Adı, Ad, Soyad, Email ve Telefon Numarası boş bırakılamaz.";
                            $message_type = 'error';
                            $pdo->rollBack();
                        } else {
                            $contact_person_for_db = $first_name . ' ' . $last_name;
                            $full_address_for_db = trim($address_line1 . ' ' . $address_line2);

                            $sql_customer_update = "UPDATE customers SET
                                                    customer_name = ?, first_name = ?, last_name = ?, tax_office = ?, tax_id = ?,
                                                    contact_person = ?, phone = ?, email = ?, address = ?, address_line1 = ?, address_line2 = ?, city = ?, state = ?, zip_code = ?, country = ?,
                                                    last_updated_by = ?, updated_at = NOW(), is_active = TRUE
                                                    WHERE user_id = ?"; // is_active'i tekrar TRUE yap
                            $stmt_customer_update = $pdo->prepare($sql_customer_update);
                            $stmt_customer_update->execute([
                                $company_name,
                                $first_name,
                                $last_name,
                                $tax_office,
                                $tax_id_customer,
                                $contact_person_for_db,
                                $phone_number,
                                $customer_email,
                                $full_address_for_db,
                                $address_line1,
                                $address_line2,
                                $city,
                                $state,
                                $zip_code,
                                $country,
                                $current_logged_in_user_id,
                                $user_id
                            ]);
                            $pdo->commit();
                            $message = "Kullanıcı ve müşteri bilgileri başarıyla güncellendi.";
                            $message_type = 'success';
                        }
                    } else {
                        // Rol aynı kaldı ve 'customer' değil
                        $pdo->commit();
                        $message = "Kullanıcı başarıyla güncellendi.";
                        $message_type = 'success';
                    }
                }
            }
        } catch (PDOException $e) {
            $pdo->rollBack();
            $message = "Kullanıcı güncellenirken bir veritabanı hatası oluştu: " . $e->getMessage();
            $message_type = 'error';
            error_log("Edit User Error: " . $e->getMessage()); // Hata loglama
        }
        $action = 'list';
    }
} elseif (isset($_GET['delete_id'])) { // GET metodu ile delete işlemi (aslında pasifize)
    $delete_id = intval($_GET['delete_id']); // Güvenlik için intval

    try {
        $pdo->beginTransaction(); // İşlem başlat

        // Hedef kullanıcının rolünü al
        $target_user_role_id = 0;
        $stmt_get_role = $pdo->prepare("SELECT role_id FROM users WHERE id = ?");
        $stmt_get_role->execute([$delete_id]);
        $target_user_role_id = $stmt_get_role->fetchColumn();

        // Yetki kontrolleri
        if (isset($_SESSION["user_role_id"]) && $_SESSION["user_role_id"] == 2 && $target_user_role_id == 1) {
            $message = "Genel Müdür Yardımcısı, Genel Müdür kullanıcılarını silemez.";
            $message_type = 'error';
            $pdo->rollBack();
        } else {
            // Kullanıcıyı users tablosundan sil
            $sql = "DELETE FROM users WHERE id = ?";
            $stmt = $pdo->prepare($sql);
            if ($stmt->execute([$delete_id])) {
                // Eğer silinen kullanıcı 'customer' rolündeyse, customers tablosundaki kaydını pasifize et
                if ($target_user_role_id === $customer_role_id) {
                    $sql_customer_deactivate = "UPDATE customers SET is_active = FALSE, last_updated_by = ?, updated_at = NOW() WHERE user_id = ?";
                    $stmt_customer_deactivate = $pdo->prepare($sql_customer_deactivate);
                    $stmt_customer_deactivate->execute([$current_logged_in_user_id, $delete_id]);
                }
                $pdo->commit();
                $message = "Kullanıcı başarıyla silindi (varsa ilgili müşteri pasifize edildi).";
                $message_type = 'success';
            } else {
                $message = "Kullanıcı silinirken bir hata oluştu.";
                $message_type = 'error';
                $pdo->rollBack();
            }
        }
    } catch (PDOException $e) {
        $pdo->rollBack();
        $message = "Kullanıcı silinirken bir veritabanı hatası oluştu: " . $e->getMessage();
        $message_type = 'error';
        error_log("Delete User Error: " . $e->getMessage()); // Hata loglama
    }
    $action = 'list';
}
end_post_processing_users:;


// Roller listesini çek (add/edit formları için)
$roles = [];
try {
    $stmt_roles = $pdo->query("SELECT id, role_name, display_name FROM roles ORDER BY id ASC");
    $roles = $stmt_roles->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Roller çekilirken hata oluştu (users.php): " . $e->getMessage());
    $message = "Rol bilgileri alınamadı.";
    $message_type = 'error';
}

// header.php'yi dahil et
include 'includes/header.php';
?>

        <div class="container">
            <h1>Kullanıcı Yönetimi</h1>

            <?php if (!empty($message)) : ?>
                <div class="message <?php echo $message_type; ?>"><?php echo $message; ?></div>
            <?php endif; ?>

            <?php if ($action == 'list') : ?>
                <p><a href="users.php?action=add" class="btn btn-success">Yeni Kullanıcı Ekle</a></p>
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Kullanıcı Adı</th>
                            <th>Tam Adı</th>
                            <th>Rol</th>
                            <th>İşlemler</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        // Tüm kullanıcıları ve rollerini çek
                        try {
                            $users_sql = "SELECT u.id, u.username, u.full_name, r.role_name, r.display_name, r.id as role_id_actual
                                          FROM users u
                                          JOIN roles r ON u.role_id = r.id ORDER BY u.id DESC";
                            $stmt_users = $pdo->query($users_sql);
                            $users = $stmt_users->fetchAll(PDO::FETCH_ASSOC);

                            if (!empty($users)) {
                                foreach ($users as $user) {
                                    echo "<tr>";
                                    echo "<td>" . htmlspecialchars($user['id']) . "</td>";
                                    echo "<td>" . htmlspecialchars($user['username']) . "</td>";
                                    echo "<td>" . htmlspecialchars($user['full_name']) . "</td>";
                                    echo "<td>" . htmlspecialchars($user['display_name'] ?: $user['role_name']) . "</td>";
                                    echo "<td>";
                                    // Yetki kontrolü için $_SESSION["user_role_id"]'nin mevcut olduğundan emin ol
                                    $session_user_role_id = isset($_SESSION["user_role_id"]) ? $_SESSION["user_role_id"] : null;

                                    if ($session_user_role_id == 1 || ($session_user_role_id == 2 && $user['role_id_actual'] != 1)) {
                                        echo "<a href='users.php?action=edit&id=" . $user['id'] . "' class='btn btn-warning'>Düzenle</a>";
                                        echo "<a href='users.php?action=delete&delete_id=" . $user['id'] . "' class='btn btn-danger' onclick=\"return confirm('Bu kullanıcıyı silmek istediğinizden emin misiniz? (Eğer müşteri ise pasifize edilecektir)');\">Sil</a>";
                                    } else {
                                        echo "Yetkisiz";
                                    }
                                    echo "</td>";
                                    echo "</tr>";
                                }
                            } else {
                                echo "<tr><td colspan='5'>Henüz hiç kullanıcı yok.</td></tr>";
                            }
                        } catch (PDOException $e) {
                            error_log("Kullanıcı listesi çekilirken hata oluştu: " . $e->getMessage());
                            echo "<tr><td colspan='5'>Kullanıcı listesi alınırken bir hata oluştu.</td></tr>";
                        }
                        ?>
                    </tbody>
                </table>

            <?php elseif ($action == 'add') : ?>
                <h2>Yeni Kullanıcı Ekle</h2>
                <form action="users.php" method="post">
                    <div class="form-group">
                        <label for="username">Kullanıcı Adı:</label>
                        <input type="text" id="username" name="username" required>
                    </div>
                    <div class="form-group">
                        <label for="full_name">Tam Adı:</label>
                        <input type="text" id="full_name" name="full_name" required>
                    </div>
                    <div class="form-group">
                        <label for="password">Parola:</label>
                        <input type="password" id="password" name="password" required>
                    </div>
                    <div class="form-group">
                        <label for="role_id">Rol:</label>
                        <select id="role_id" name="role_id" required>
                            <?php foreach ($roles as $role) :
                                // Genel Müdür Yardımcısı ise, Genel Müdür rolünü gösterme
                                $session_user_role_id = isset($_SESSION["user_role_id"]) ? $_SESSION["user_role_id"] : null;
                                if ($session_user_role_id == 2 && $role['id'] == 1) continue;
                            ?>
                                <option value="<?php echo $role['id']; ?>"><?php echo htmlspecialchars($role['display_name'] ?: $role['role_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div id="customer_details_section" style="display: none;">
                        <h3>Müşteri Bilgileri</h3>
                        <div class="form-group">
                            <label for="first_name">Ad:</label>
                            <input type="text" id="first_name" name="first_name">
                        </div>
                        <div class="form-group">
                            <label for="last_name">Soyad:</label>
                            <input type="text" id="last_name" name="last_name">
                        </div>
                         <div class="form-group">
                            <label for="email">Email:</label>
                            <input type="email" id="email" name="email">
                        </div>
                        <div class="form-group">
                            <label for="company_name">Firma Adı:</label>
                            <input type="text" id="company_name" name="company_name">
                        </div>
                        <div class="form-group">
                            <label for="tax_office">Vergi Dairesi:</label>
                            <input type="text" id="tax_office" name="tax_office">
                        </div>
                        <div class="form-group">
                            <label for="tax_id_customer">Vergi Numarası / TC Kimlik No:</label>
                            <input type="text" id="tax_id_customer" name="tax_id_customer">
                        </div>
                        <div class="form-group">
                            <label for="phone_number">Telefon Numarası:</label>
                            <input type="text" id="phone_number" name="phone_number">
                        </div>
                        <div class="form-group">
                            <label for="address_line1">Adres Satırı 1:</label>
                            <input type="text" id="address_line1" name="address_line1">
                        </div>
                        <div class="form-group">
                            <label for="address_line2">Adres Satırı 2:</label>
                            <input type="text" id="address_line2" name="address_line2">
                        </div>
                        <div class="form-group">
                            <label for="city">Şehir:</label>
                            <input type="text" id="city" name="city">
                        </div>
                        <div class="form-group">
                            <label for="state">İlçe / Eyalet:</label>
                            <input type="text" id="state" name="state">
                        </div>
                        <div class="form-group">
                            <label for="zip_code">Posta Kodu:</label>
                            <input type="text" id="zip_code" name="zip_code">
                        </div>
                        <div class="form-group">
                            <label for="country">Ülke:</label>
                            <input type="text" id="country" name="country">
                        </div>
                    </div>

                    <div class="form-group">
                        <button type="submit" name="add_user" class="btn btn-primary">Kullanıcı Ekle</button>
                        <a href="users.php" class="btn btn-secondary">İptal</a>
                    </div>
                </form>

                <script>
                    document.addEventListener('DOMContentLoaded', function() {
                        const roleSelect = document.getElementById('role_id');
                        const customerDetailsSection = document.getElementById('customer_details_section');
                        const customerRoleID = <?php echo $customer_role_id; ?>; // 'customer' rolünün ID'si

                        // Müşteriye özel alanların listesi
                        const customerFields = [
                            'first_name', 'last_name', 'email', 'company_name', 'tax_office',
                            'tax_id_customer', 'phone_number', 'address_line1', 'address_line2',
                            'city', 'state', 'zip_code', 'country'
                        ];

                        function toggleCustomerDetails() {
                            if (parseInt(roleSelect.value) === customerRoleID) {
                                customerDetailsSection.style.display = 'block';
                                // Müşteri alanlarını zorunlu yap
                                ['first_name', 'last_name', 'email', 'company_name', 'phone_number'].forEach(fieldId => {
                                    const field = document.getElementById(fieldId);
                                    if (field) field.setAttribute('required', 'required');
                                });
                            } else {
                                customerDetailsSection.style.display = 'none';
                                // Müşteri alanlarını zorunluluktan çıkar
                                customerFields.forEach(fieldId => {
                                    const field = document.getElementById(fieldId);
                                    if (field) field.removeAttribute('required');
                                });
                            }
                        }

                        // Sayfa yüklendiğinde ve rol değiştiğinde kontrol et
                        roleSelect.addEventListener('change', toggleCustomerDetails);
                        toggleCustomerDetails(); // Sayfa yüklendiğinde başlangıç durumunu ayarla
                    });
                </script>

            <?php elseif ($action == 'edit' && isset($_GET['id'])) :
                $edit_user_id = intval($_GET['id']);
                $user_to_edit = null;
                $customer_details_for_edit = null; // Düzenlenecek müşterinin bilgileri

                try {
                    $stmt = $pdo->prepare("SELECT u.id, u.username, u.full_name, u.role_id, r.role_name as current_role_name, r.display_name as current_display_name FROM users u JOIN roles r ON u.role_id = r.id WHERE u.id = ?");
                    $stmt->execute([$edit_user_id]);
                    $user_to_edit = $stmt->fetch(PDO::FETCH_ASSOC);

                    if ($user_to_edit) {
                        // Eğer düzenlenecek kullanıcı 'customer' rolündeyse, müşteri detaylarını çek
                        if (intval($user_to_edit['role_id']) === $customer_role_id) {
                            $stmt_customer = $pdo->prepare("SELECT * FROM customers WHERE user_id = ? AND is_active = TRUE"); // Sadece aktif müşteriyi getir
                            $stmt_customer->execute([$user_to_edit['id']]);
                            $customer_details_for_edit = $stmt_customer->fetch(PDO::FETCH_ASSOC);
                        }

                        // Yetki kontrolü
                        $session_user_role_id = isset($_SESSION["user_role_id"]) ? $_SESSION["user_role_id"] : null;
                        if ($session_user_role_id == 2 && $user_to_edit['role_id'] == 1) {
                             echo "<div class='message error'>Genel Müdür Yardımcısı, Genel Müdür kullanıcılarını düzenleyemez.</div>";
                             echo "<p><a href='users.php' class='btn btn-primary'>Geri Dön</a></p>";
                             $user_to_edit = null; // Formu gösterme
                        }
                    }
                } catch (PDOException $e) {
                    error_log("Kullanıcı düzenleme bilgisi çekilirken hata oluştu: " . $e->getMessage());
                    echo "<div class='message error'>Kullanıcı bilgileri alınırken bir hata oluştu.</div>";
                    $user_to_edit = null;
                }

                if ($user_to_edit) : ?>
                    <h2>Kullanıcı Düzenle</h2>
                    <form action="users.php" method="post">
                        <input type="hidden" name="user_id" value="<?php echo htmlspecialchars($user_to_edit['id']); ?>">
                        <div class="form-group">
                            <label for="username">Kullanıcı Adı:</label>
                            <input type="text" id="username" name="username" value="<?php echo htmlspecialchars($user_to_edit['username']); ?>" required>
                        </div>
                        <div class="form-group">
                            <label for="full_name">Tam Adı:</label>
                            <input type="text" id="full_name" name="full_name" value="<?php echo htmlspecialchars($user_to_edit['full_name']); ?>" required>
                        </div>
                        <div class="form-group">
                            <label for="password">Yeni Parola (Değiştirmek istemiyorsanız boş bırakın):</label>
                            <input type="password" id="password" name="password">
                        </div>
                        <div class="form-group">
                            <label for="role_id">Rol:</label>
                            <select id="role_id" name="role_id" required>
                                <?php foreach ($roles as $role) :
                                    // Genel Müdür Yardımcısı ise, Genel Müdür rolünü gösterme (kendisine veya başkasına atamayı engelle)
                                    $session_user_role_id = isset($_SESSION["user_role_id"]) ? $_SESSION["user_role_id"] : null;
                                    if ($session_user_role_id == 2 && $role['id'] == 1) continue;
                                ?>
                                    <option value="<?php echo $role['id']; ?>" <?php echo (intval($user_to_edit['role_id']) === $role['id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($role['display_name'] ?: $role['role_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div id="customer_details_section" style="display: none;">
                            <h3>Müşteri Bilgileri</h3>
                            <div class="form-group">
                                <label for="first_name">Ad:</label>
                                <input type="text" id="first_name" name="first_name" value="<?php echo htmlspecialchars($customer_details_for_edit['first_name'] ?? ''); ?>">
                            </div>
                            <div class="form-group">
                                <label for="last_name">Soyad:</label>
                                <input type="text" id="last_name" name="last_name" value="<?php echo htmlspecialchars($customer_details_for_edit['last_name'] ?? ''); ?>">
                            </div>
                             <div class="form-group">
                                <label for="email">Email:</label>
                                <input type="email" id="email" name="email" value="<?php echo htmlspecialchars($customer_details_for_edit['email'] ?? ''); ?>">
                            </div>
                            <div class="form-group">
                                <label for="company_name">Firma Adı:</label>
                                <input type="text" id="company_name" name="company_name" value="<?php echo htmlspecialchars($customer_details_for_edit['customer_name'] ?? ''); ?>">
                            </div>
                             <div class="form-group">
                                <label for="tax_office">Vergi Dairesi:</label>
                                <input type="text" id="tax_office" name="tax_office" value="<?php echo htmlspecialchars($customer_details_for_edit['tax_office'] ?? ''); ?>">
                            </div>
                            <div class="form-group">
                                <label for="tax_id_customer">Vergi Numarası / TC Kimlik No:</label>
                                <input type="text" id="tax_id_customer" name="tax_id_customer" value="<?php echo htmlspecialchars($customer_details_for_edit['tax_id'] ?? ''); ?>">
                            </div>
                            <div class="form-group">
                                <label for="phone_number">Telefon Numarası:</label>
                                <input type="text" id="phone_number" name="phone_number" value="<?php echo htmlspecialchars($customer_details_for_edit['phone'] ?? ''); ?>">
                            </div>
                            <div class="form-group">
                                <label for="address_line1">Adres Satırı 1:</label>
                                <input type="text" id="address_line1" name="address_line1" value="<?php echo htmlspecialchars($customer_details_for_edit['address_line1'] ?? ''); ?>">
                            </div>
                            <div class="form-group">
                                <label for="address_line2">Adres Satırı 2:</label>
                                <input type="text" id="address_line2" name="address_line2" value="<?php echo htmlspecialchars($customer_details_for_edit['address_line2'] ?? ''); ?>">
                            </div>
                            <div class="form-group">
                                <label for="city">Şehir:</label>
                                <input type="text" id="city" name="city" value="<?php echo htmlspecialchars($customer_details_for_edit['city'] ?? ''); ?>">
                            </div>
                            <div class="form-group">
                                <label for="state">İlçe / Eyalet:</label>
                                <input type="text" id="state" name="state" value="<?php echo htmlspecialchars($customer_details_for_edit['state'] ?? ''); ?>">
                            </div>
                            <div class="form-group">
                                <label for="zip_code">Posta Kodu:</label>
                                <input type="text" id="zip_code" name="zip_code" value="<?php echo htmlspecialchars($customer_details_for_edit['zip_code'] ?? ''); ?>">
                            </div>
                            <div class="form-group">
                                <label for="country">Ülke:</label>
                                <input type="text" id="country" name="country" value="<?php echo htmlspecialchars($customer_details_for_edit['country'] ?? ''); ?>">
                            </div>
                        </div>

                        <div class="form-group">
                            <button type="submit" name="edit_user" class="btn btn-primary">Kullanıcıyı Güncelle</button>
                            <a href="users.php" class="btn btn-secondary">İptal</a>
                        </div>
                    </form>

                    <script>
                        document.addEventListener('DOMContentLoaded', function() {
                            const roleSelect = document.getElementById('role_id');
                            const customerDetailsSection = document.getElementById('customer_details_section');
                            const customerRoleID = <?php echo $customer_role_id; ?>; // 'customer' rolünün ID'si

                            // Müşteriye özel alanların listesi
                            const customerFields = [
                                'first_name', 'last_name', 'email', 'company_name', 'tax_office',
                                'tax_id_customer', 'phone_number', 'address_line1', 'address_line2',
                                'city', 'state', 'zip_code', 'country'
                            ];

                            function toggleCustomerDetails() {
                                if (parseInt(roleSelect.value) === customerRoleID) {
                                    customerDetailsSection.style.display = 'block';
                                    // Müşteri alanlarını zorunlu yap
                                    ['first_name', 'last_name', 'email', 'company_name', 'phone_number'].forEach(fieldId => {
                                        const field = document.getElementById(fieldId);
                                        if (field) field.setAttribute('required', 'required');
                                    });
                                } else {
                                    customerDetailsSection.style.display = 'none';
                                    // Müşteri alanlarını zorunluluktan çıkar
                                    customerFields.forEach(fieldId => {
                                        const field = document.getElementById(fieldId);
                                        if (field) field.removeAttribute('required');
                                    });
                                });
                            }

                            // Sayfa yüklendiğinde ve rol değiştiğinde kontrol et
                            roleSelect.addEventListener('change', toggleCustomerDetails);
                            toggleCustomerDetails(); // Sayfa yüklendiğinde başlangıç durumunu ayarla
                        });
                    </script>
                <?php else : ?>
                    <div class="message error">Kullanıcı bulunamadı veya düzenleme yetkiniz yok.</div>
                    <p><a href="users.php" class="btn btn-primary">Geri Dön</a></p>
                <?php endif; ?>
            <?php endif; ?>
        </div>
<?php
// footer.php'yi dahil et
include 'includes/footer.php';
?>
