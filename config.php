<?php
// Hata raporlamayı aç (geliştirme aşamasında faydalıdır)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Oturumları başlat
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Veritabanı bağlantı bilgileri
define('DB_SERVER', 'localhost');
define('DB_USERNAME', 'bursawe5_karacakaya'); // Kendi veritabanı kullanıcı adınızı yazın
define('DB_PASSWORD', 'Parola101!');     // Kendi veritabanı parolanızı yazın
define('DB_NAME', 'bursawe5_karacakaya'); // Kendi veritabanı adınızı yazın

// PDO Veritabanı Bağlantısı
// Bu bağlantı, config.php dahil edildiğinde otomatik olarak kurulur
try {
    $dsn = "mysql:host=" . DB_SERVER . ";dbname=" . DB_NAME . ";charset=utf8mb4";
    $pdo = new PDO($dsn, DB_USERNAME, DB_PASSWORD);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("Veritabanı bağlantısı başarısız: " . $e->getMessage());
}

// Eski MySQLi connect_db() fonksiyonunun PDO versiyonunu sağlıyoruz
// Bu, diğer dosyalarda (örneğin header.php) hala bu fonksiyonu kullanıyorsa hata vermesini önleyecektir.
// İDEALDE, tüm projeyi PDO kullanacak şekilde revize etmelisiniz, bu sadece geçici bir köprüdür.
function connect_db() {
    global $pdo; // $pdo nesnesine erişim sağlıyoruz
    return $pdo; // Bağlantı nesnesini döndürüyoruz
}

// Kullanıcının yetkisini kontrol etme fonksiyonu
function check_permission($required_roles) {
    global $pdo; // PDO nesnesini fonksiyonda kullanabilmek için global keyword'ünü ekledik

    // Oturumda 'loggedin' durumu ve 'user_role_id' tanımlı mı kontrol et
    if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || !isset($_SESSION["user_role_id"])) {
        header("location: login.php");
        exit;
    }

    // Kullanıcının rol ID'sini al - ARTIK 'user_role_id' KULLANILIYOR
    $user_role_id = $_SESSION["user_role_id"];

    // Kullanıcının rol adını al
    $stmt = $pdo->prepare("SELECT role_name FROM roles WHERE id = ?");
    $stmt->execute([$user_role_id]);
    $user_role_name = $stmt->fetchColumn();

    // Eğer tek bir rol adı verilmişse diziye çevir
    if (!is_array($required_roles)) {
        $required_roles = [$required_roles];
    }

    // Kullanıcının rolü, izin verilen roller arasında mı kontrol et
    if (!in_array($user_role_name, $required_roles)) {
        // Yetkisiz erişim durumunda
        echo "<h1>Erişim Reddedildi!</h1>";
        echo "<p>Bu sayfaya erişim yetkiniz bulunmamaktadır.</p>";
        echo '<p><a href="dashboard.php">Ana Sayfaya Dön</a></p>';
        exit;
    }
}
?>
