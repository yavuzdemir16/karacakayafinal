<?php
session_start();
require_once 'config.php'; // Veritabanı bağlantısı ve check_permission fonksiyonu burada olmalı

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Yetki kontrolü (Genel Müdür ve Genel Müdür Yardımcısı erişebilir)
$allowed_role_ids = [1, 2];
if (!isset($_SESSION['user_role_id']) || !in_array($_SESSION['user_role_id'], $allowed_role_ids)) {
    $_SESSION['message'] = "Bu alana giriş yapmaya yetkiniz yoktur. Lütfen yöneticinizle iletişime geçiniz.";
    $_SESSION['message_type'] = "error";
    header("Location: dashboard.php");
    exit();
}

$message = '';
$message_type = '';

$user_id = $_SESSION['user_id'];
$user_settings = []; // Kullanıcının kişisel ayarlarını tutacak

// $pdo bağlantısını config.php'den alıyoruz, bu yüzden burada tekrar tanımlamamıza gerek yok.
// Eğer config.php'de $pdo tanımı yoksa, burada tanımlanmalıdır:
// try {
//     $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET, DB_USER, DB_PASS);
//     $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
//     $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
// } catch (PDOException $e) {
//     die("Veritabanı bağlantı hatası: " . $e->getMessage());
// }

// Kullanıcının tema tercihini çek
try {
    $stmt = $pdo->prepare("SELECT theme_preference FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($result) {
        $user_settings['theme_preference'] = $result['theme_preference'];
    } else {
        $user_settings['theme_preference'] = 'light'; // Varsayılan tema
    }
} catch (PDOException $e) {
    error_log("Kullanıcı ayarları çekilirken hata: " . $e->getMessage());
    $message = "Kişisel ayarlar yüklenirken bir hata oluştu.";
    $message_type = 'error';
}

// Firma bilgilerini çek
$company_settings = [
    'company_name' => '',
    'address' => '',
    'phone' => '',
    'tax_office' => '',
    'tax_number' => '',
    'email' => '',
    'logo_path' => '',
    'default_vat_rate_normal' => 0.00, // Yeni alan
    'default_vat_rate_fixed_asset' => 0.00 // Yeni alan
];

try {
    $stmt = $pdo->query("SELECT * FROM company_settings WHERE id = 1 LIMIT 1"); // Tek bir ayar satırı olduğunu varsayıyoruz
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($result) {
        $company_settings = array_merge($company_settings, $result);
    }
} catch (PDOException $e) {
    error_log("Firma ayarları çekilirken hata: " . $e->getMessage());
    $message = "Firma bilgileri yüklenirken bir hata oluştu. Veritabanında 'company_settings' tablosunun varlığını kontrol edin.";
    $message_type = 'error';
}


// Form gönderimi işleme
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    if (isset($_POST['save_personal_settings'])) {
        $new_theme_preference = trim($_POST['theme_preference']);

        try {
            $stmt = $pdo->prepare("UPDATE users SET theme_preference = ? WHERE id = ?");
            $stmt->execute([$new_theme_preference, $user_id]);
            $message = "Kişisel ayarlar başarıyla kaydedildi.";
            $message_type = 'success';
            $_SESSION['theme_preference'] = $new_theme_preference;
            $user_settings['theme_preference'] = $new_theme_preference;
        } catch (PDOException $e) {
            error_log("Kişisel ayarlar güncellenirken hata: " . $e->getMessage());
            $message = "Kişisel ayarlar kaydedilirken bir hata oluştu.";
            $message_type = 'error';
        }
    } elseif (isset($_POST['save_company_settings'])) {
        // Sadece Genel Müdür (role_id = 1) firma bilgilerini güncelleyebilir
        if ($_SESSION['user_role_id'] != 1) {
            $_SESSION['message'] = "Firma bilgilerini düzenlemeye yetkiniz yoktur.";
            $_SESSION['message_type'] = "error";
            header("Location: settings.php");
            exit();
        }

        $company_name = trim($_POST['company_name']);
        $address = trim($_POST['address']);
        $phone = trim($_POST['phone']);
        $tax_office = trim($_POST['tax_office']);
        $tax_number = trim($_POST['tax_number']);
        $email = trim($_POST['email']);
        // Yeni KDV alanları
        $default_vat_rate_normal = floatval(str_replace(',', '.', trim($_POST['default_vat_rate_normal'])));
        $default_vat_rate_fixed_asset = floatval(str_replace(',', '.', trim($_POST['default_vat_rate_fixed_asset'])));

        $logo_path = $company_settings['logo_path']; // Mevcut logo yolunu koru

        // Logo yükleme işlemi
        if (isset($_FILES['company_logo']) && $_FILES['company_logo']['error'] === UPLOAD_ERR_OK) {
            $upload_dir = 'uploads/logos/'; // Logoların yükleneceği klasör
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0777, true);
            }

            $file_tmp = $_FILES['company_logo']['tmp_name'];
            $file_ext = strtolower(pathinfo($_FILES['company_logo']['name'], PATHINFO_EXTENSION));
            $allowed_ext = ['jpg', 'jpeg', 'png', 'gif'];

            if (in_array($file_ext, $allowed_ext)) {
                $new_file_name = 'company_logo_' . time() . '.' . $file_ext;
                $new_file_path = $upload_dir . $new_file_name;

                if (move_uploaded_file($file_tmp, $new_file_path)) {
                    // Eski logoyu sil (eğer varsa ve farklı bir dosya ise)
                    if (!empty($company_settings['logo_path']) && file_exists($company_settings['logo_path']) && $company_settings['logo_path'] != $new_file_path) {
                        unlink($company_settings['logo_path']);
                    }
                    $logo_path = $new_file_path;
                } else {
                    $message = "Logo yüklenirken bir sorun oluştu.";
                    $message_type = 'error';
                }
            } else {
                $message = "Sadece JPG, JPEG, PNG ve GIF formatında logo yükleyebilirsiniz.";
                $message_type = 'error';
            }
        }

        try {
            $stmt = $pdo->prepare("
                INSERT INTO company_settings (id, company_name, address, phone, tax_office, tax_number, email, logo_path, default_vat_rate_normal, default_vat_rate_fixed_asset)
                VALUES (1, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    company_name = VALUES(company_name),
                    address = VALUES(address),
                    phone = VALUES(phone),
                    tax_office = VALUES(tax_office),
                    tax_number = VALUES(tax_number),
                    email = VALUES(email),
                    logo_path = VALUES(logo_path),
                    default_vat_rate_normal = VALUES(default_vat_rate_normal),
                    default_vat_rate_fixed_asset = VALUES(default_vat_rate_fixed_asset)
            ");
            $stmt->execute([
                $company_name, $address, $phone, $tax_office, $tax_number, $email, $logo_path,
                $default_vat_rate_normal, $default_vat_rate_fixed_asset // Yeni alanlar
            ]);

            // Güncel bilgileri tekrar çek
            $stmt = $pdo->query("SELECT * FROM company_settings WHERE id = 1 LIMIT 1");
            $company_settings = array_merge($company_settings, $stmt->fetch(PDO::FETCH_ASSOC));

            $message = "Firma bilgileri başarıyla kaydedildi.";
            $message_type = 'success';
        } catch (PDOException $e) {
            error_log("Firma bilgileri güncellenirken hata: " . $e->getMessage());
            $message = "Firma bilgileri kaydedilirken bir hata oluştu: " . $e->getMessage();
            $message_type = 'error';
        }
    }
}

$page_title = "Ayarlar";
include 'includes/header.php'; // Header'da başlık ve CSS

// Flash mesajı varsa göster ve sil
if (isset($_SESSION['message'])) {
    $message = $_SESSION['message'];
    $message_type = $_SESSION['message_type'];
    unset($_SESSION['message']);
    unset($_SESSION['message_type']);
}
?>

<div class="container">
    <h1>Ayarlar</h1>

    <?php if (!empty($message)) : ?>
        <div class="message <?php echo $message_type; ?>"><?php echo $message; ?></div>
    <?php endif; ?>

    <!-- Kişisel Ayarlar -->
    <div class="settings-section">
        <h2>Panel Özelleştirme Ayarları</h2>
        <form action="settings.php" method="post">
            <div class="form-group">
                <label for="theme_preference">Tema Seçimi:</label>
                <select id="theme_preference" name="theme_preference" class="form-control">
                    <option value="light" <?php echo ($user_settings['theme_preference'] == 'light') ? 'selected' : ''; ?>>Açık Tema</option>
                    <option value="dark" <?php echo ($user_settings['theme_preference'] == 'dark') ? 'selected' : ''; ?>>Koyu Tema</option>
                </select>
            </div>
            <div class="form-group form-actions">
                <button type="submit" name="save_personal_settings" class="btn btn-primary">Ayarları Kaydet</button>
            </div>
        </form>
    </div>

    <!-- Firma Bilgileri (Sadece Genel Müdür için) -->
    <?php if ($_SESSION['user_role_id'] == 1) : // Sadece Genel Müdür görebilir ?>
        <div class="settings-section company-settings-section">
            <h2>Firma Bilgileri <small>(Sadece Genel Müdür)</small></h2>
            <form action="settings.php" method="post" enctype="multipart/form-data">
                <div class="form-group">
                    <label for="company_name">Firma Adı:</label>
                    <input type="text" id="company_name" name="company_name" class="form-control" value="<?php echo htmlspecialchars($company_settings['company_name']); ?>" required>
                </div>
                <div class="form-group">
                    <label for="address">Adres:</label>
                    <textarea id="address" name="address" class="form-control" rows="3" required><?php echo htmlspecialchars($company_settings['address']); ?></textarea>
                </div>
                <div class="form-group">
                    <label for="phone">Telefon:</label>
                    <input type="text" id="phone" name="phone" class="form-control" value="<?php echo htmlspecialchars($company_settings['phone']); ?>" required>
                </div>
                <div class="form-group">
                    <label for="tax_office">Vergi Dairesi:</label>
                    <input type="text" id="tax_office" name="tax_office" class="form-control" value="<?php echo htmlspecialchars($company_settings['tax_office']); ?>" required>
                </div>
                <div class="form-group">
                    <label for="tax_number">Vergi Numarası:</label>
                    <input type="text" id="tax_number" name="tax_number" class="form-control" value="<?php echo htmlspecialchars($company_settings['tax_number']); ?>" required>
                </div>
                <div class="form-group">
                    <label for="email">E-posta:</label>
                    <input type="email" id="email" name="email" class="form-control" value="<?php echo htmlspecialchars($company_settings['email']); ?>" required>
                </div>
                <div class="form-group">
                    <label for="default_vat_rate_normal">Normal Ürünler İçin Varsayılan KDV Oranı (%):</label>
                    <input type="number" step="0.01" id="default_vat_rate_normal" name="default_vat_rate_normal" class="form-control" value="<?php echo htmlspecialchars($company_settings['default_vat_rate_normal']); ?>" required>
                </div>
                <div class="form-group">
                    <label for="default_vat_rate_fixed_asset">Demirbaşlar İçin Varsayılan KDV Oranı (%):</label>
                    <input type="number" step="0.01" id="default_vat_rate_fixed_asset" name="default_vat_rate_fixed_asset" class="form-control" value="<?php echo htmlspecialchars($company_settings['default_vat_rate_fixed_asset']); ?>" required>
                </div>
                <div class="form-group">
                    <label for="company_logo">Logo Yükle:</label>
                    <input type="file" id="company_logo" name="company_logo" class="form-control-file">
                    <?php if (!empty($company_settings['logo_path']) && file_exists($company_settings['logo_path'])) : ?>
                        <p class="current-logo-text">Mevcut Logo:</p>
                        <img src="<?php echo htmlspecialchars($company_settings['logo_path']); ?>" alt="Company Logo" class="current-logo-preview">
                    <?php endif; ?>
                    <small class="form-text text-muted">Sadece JPG, JPEG, PNG, GIF formatında ve en fazla 2MB boyutunda dosya yükleyebilirsiniz.</small>
                </div>

                <div class="form-group form-actions">
                    <button type="submit" name="save_company_settings" class="btn btn-primary">Firma Bilgilerini Kaydet</button>
                </div>
            </form>
        </div>
    <?php endif; ?>

</div>

<?php include 'includes/footer.php'; ?>

<style>
    .settings-section {
        background-color: #fff;
        padding: 25px;
        border-radius: 8px;
        box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        margin-bottom: 30px;
        border: 1px solid #e0e0e0;
    }

    .settings-section h2 {
        color: #333;
        margin-top: 0;
        margin-bottom: 20px;
        padding-bottom: 10px;
        border-bottom: 1px solid #eee;
        font-size: 1.6em;
    }

    .settings-section h2 small {
        font-size: 0.6em;
        color: #777;
        font-weight: normal;
    }

    .form-group {
        margin-bottom: 15px;
    }

    .form-group label {
        display: block;
        margin-bottom: 5px;
        font-weight: 600;
        color: #555;
    }

    .form-control, .form-control-file {
        width: 100%;
        padding: 10px 12px;
        border: 1px solid #ccc;
        border-radius: 5px;
        font-size: 1em;
        box-sizing: border-box; /* padding'in width'i etkilememesi için */
    }

    .form-control:focus, .form-control-file:focus {
        border-color: #007bff;
        outline: none;
        box-shadow: 0 0 0 0.2rem rgba(0, 123, 255, 0.25);
    }

    textarea.form-control {
        resize: vertical;
        min-height: 80px;
    }

    .form-text {
        font-size: 0.85em;
        color: #888;
        margin-top: 5px;
        display: block;
    }

    .form-actions {
        margin-top: 25px;
        text-align: right;
    }

    .btn {
        padding: 10px 20px;
        border: none;
        border-radius: 5px;
        cursor: pointer;
        font-size: 1em;
        transition: background-color 0.2s ease;
    }

    .btn-primary {
        background-color: #007bff;
        color: white;
    }

    .btn-primary:hover {
        background-color: #0056b3;
    }

    .message {
        padding: 15px;
        margin-bottom: 20px;
        border-radius: 5px;
        font-weight: 500;
    }

    .message.success {
        background-color: #d4edda;
        color: #155724;
        border-color: #c3e6cb;
    }

    .message.error {
        background-color: #f8d7da;
        color: #721c24;
        border-color: #f5c6cb;
    }

    .current-logo-text {
        margin-top: 15px;
        margin-bottom: 8px;
        font-weight: 600;
        color: #555;
    }

    .current-logo-preview {
        max-width: 150px;
        height: auto;
        border: 1px solid #eee;
        padding: 5px;
        border-radius: 5px;
        background-color: #fdfdfd;
    }
</style>
