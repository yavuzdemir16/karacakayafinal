<?php
session_start(); // Oturumları başlat

// Eğer kullanıcı zaten giriş yapmışsa, dashboard'a yönlendir
if (isset($_SESSION["loggedin"]) && $_SESSION["loggedin"] === true) {
    header("location: dashboard.php");
    exit;
}

// Veritabanı bağlantı bilgileri
// NOT: Bu bilgileri kendi sunucunuzun bilgileriyle değiştirdiğinizden emin olun!
define('DB_SERVER', 'localhost');
define('DB_USERNAME', 'bursawe5_karacakaya');
define('DB_PASSWORD', 'Parola101!');
define('DB_NAME', 'bursawe5_karacakaya');

// Veritabanı bağlantısı (MySQLi yerine PDO kullanmak daha güvenli ve modern bir yaklaşımdır.
// Ancak mevcut yapıyı bozmamak adına şimdilik MySQLi ile devam ediyoruz.)
$conn = new mysqli(DB_SERVER, DB_USERNAME, DB_PASSWORD, DB_NAME);

// Bağlantı kontrolü
if ($conn->connect_error) {
    die("Veritabanı bağlantısı başarısız: " . $conn->connect_error);
}

$username = $password = "";
$username_err = $password_err = "";
$login_err = "";

// Form gönderildiğinde işleme al
if ($_SERVER["REQUEST_METHOD"] == "POST") {

    // Kullanıcı adı boş mu kontrol et
    if (empty(trim($_POST["username"]))) {
        $username_err = "Lütfen kullanıcı adınızı giriniz.";
    } else {
        $username = trim($_POST["username"]);
    }

    // Parola boş mu kontrol et
    if (empty(trim($_POST["password"]))) {
        $password_err = "Lütfen parolanızı giriniz.";
    } else {
        $password = trim($_POST["password"]);
    }

    // Giriş bilgilerini doğrula
    if (empty($username_err) && empty($password_err)) {
        // SQL sorgusunu hazırla (prepared statement)
        $sql = "SELECT id, username, password, role_id FROM users WHERE username = ?";

        if ($stmt = $conn->prepare($sql)) {
            // Parametreleri bağla
            $stmt->bind_param("s", $param_username);
            $param_username = $username;

            // Sorguyu çalıştır
            if ($stmt->execute()) {
                // Sonucu al
                $stmt->store_result();

                // Kullanıcı adı mevcutsa, parolayı doğrula
                if ($stmt->num_rows == 1) {
                    $stmt->bind_result($id, $username, $hashed_password, $role_id);
                    if ($stmt->fetch()) {
                        // password_verify ile parolayı doğrula (hashed parola ile karşılaştır)
                        if (password_verify($password, $hashed_password)) {
                            // Parola doğru, oturumu başlat ve ANAHTAR ADLARINI GÜNCELLE
                            $_SESSION["loggedin"] = true;
                            $_SESSION["user_id"] = $id;          // id -> user_id olarak değiştirildi
                            $_SESSION["username"] = $username;
                            $_SESSION["user_role_id"] = $role_id; // role_id -> user_role_id olarak değiştirildi

                            // Admin paneline yönlendir
                            header("location: dashboard.php"); // Yönlendirilecek sayfa
                            exit; // Yönlendirme sonrası betiği durdur
                        } else {
                            $login_err = "Geçersiz kullanıcı adı veya parola.";
                        }
                    }
                } else {
                    $login_err = "Geçersiz kullanıcı adı veya parola.";
                }
            } else {
                echo "Bir şeyler ters gitti. Lütfen daha sonra tekrar deneyin.";
            }
            $stmt->close();
        }
    }
    // Formun submit edilmediği durumda veya hata oluştuğunda bağlantıyı kapat
    $conn->close();
}
?>

<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Girişi</title>
    <style>
        /* CSS kodları öncekiyle aynı */
        body { font-family: Arial, sans-serif; background-color: #f4f4f4; display: flex; justify-content: center; align-items: center; height: 100vh; margin: 0; }
        .wrapper { width: 360px; padding: 20px; background-color: #fff; border-radius: 8px; box-shadow: 0 0 10px rgba(0,0,0,0.1); }
        h2 { text-align: center; margin-bottom: 20px; color: #333; }
        .form-group { margin-bottom: 15px; }
        label { display: block; margin-bottom: 5px; color: #555; }
        input[type="text"], input[type="password"] { width: calc(100% - 22px); padding: 10px; border: 1px solid #ddd; border-radius: 4px; }
        .help-block { color: red; font-size: 0.9em; margin-top: 5px; }
        .form-btn { width: 100%; padding: 10px; background-color: #007bff; color: white; border: none; border-radius: 4px; cursor: pointer; font-size: 1em; }
        .form-btn:hover { background-color: #0056b3; }
        .alert { padding: 10px; margin-bottom: 15px; border-radius: 4px; background-color: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
    </style>
</head>
<body>
    <div class="wrapper">
        <h2>Admin Girişi</h2>
        <?php
        if (!empty($login_err)) {
            echo '<div class="alert">' . $login_err . '</div>';
        }
        ?>
        <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="post">
            <div class="form-group">
                <label>Kullanıcı Adı</label>
                <input type="text" name="username" class="form-control" value="<?php echo $username; ?>">
                <span class="help-block"><?php echo $username_err; ?></span>
            </div>
            <div class="form-group">
                <label>Parola</label>
                <input type="password" name="password" class="form-control">
                <span class="help-block"><?php echo $password_err; ?></span>
            </div>
            <div class="form-group">
                <input type="submit" class="form-btn" value="Giriş Yap">
            </div>
        </form>
    </div>
</body>
</html>
