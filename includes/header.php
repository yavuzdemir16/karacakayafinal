<?php
// header.php
// session_start() ve require_once 'config.php' zaten diğer dosyalarda (dashboard.php, settings.php vb.) yapıldığı için burada tekrar etmiyoruz.
// Ancak emin olmak için, eğer bu dosya doğrudan erişiliyorsa ve $pdo bağlantısı yoksa burada da tanımlanması gerekebilir.
// Genellikle bu tür include dosyaları, ana PHP dosyası (örneğin dashboard.php) tarafından çağrıldığı için,
// o ana dosyada yapılan veritabanı bağlantısı buraya da iletilir.

// Firma logosu ve adı için gerekli değişkenler
$company_logo_path = '';
$company_name_for_logo = 'Panel'; // Varsayılan uygulama adı - CRM LOGO yerine daha genel bir ifade

// company_settings tablosundan logo ve firma adını çek
// $pdo değişkeninin bu noktada tanımlı olduğunu varsayıyoruz.
// Eğer tanımlı değilse, bu dosyanın başında da config.php'yi include etmeniz gerekebilir.
try {
    // $pdo objesinin tanımlı ve geçerli bir PDO bağlantısı olduğundan emin olalım
    if (isset($pdo) && $pdo instanceof PDO) {
        $stmt_logo = $pdo->query("SELECT logo_path, company_name FROM company_settings WHERE id = 1 LIMIT 1");
        $logo_data = $stmt_logo->fetch(PDO::FETCH_ASSOC);
        if ($logo_data) {
            if (!empty($logo_data['logo_path']) && file_exists($logo_data['logo_path'])) {
                $company_logo_path = htmlspecialchars($logo_data['logo_path']);
            }
            if (!empty($logo_data['company_name'])) {
                $company_name_for_logo = htmlspecialchars($logo_data['company_name']);
            }
        }
    } else {
        error_log("header.php: PDO bağlantısı (\$pdo) tanımlı değil veya geçerli değil.");
        // Gerekirse burada bir hata mesajı gösterebilir veya varsayılan değerle devam edebilirsiniz.
    }
} catch (PDOException $e) {
    error_log("Header'da logo çekilirken veritabanı hatası: " . $e->getMessage());
    // Hata durumunda da varsayılan değerle devam edilebilir.
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo isset($page_title) ? htmlspecialchars($page_title) . ' | ' . $company_name_for_logo : $company_name_for_logo . ' Yönetim Paneli'; ?></title>
    <!-- Stil dosyanızı buraya bağlayın -->
    <link rel="stylesheet" href="style.css">
    <!-- İhtiyaç olursa Font Awesome gibi ikon kütüphaneleri de buraya eklenebilir -->
</head>
<body>
    <div id="wrapper">
        <aside id="sidebar">
            <div class="sidebar-header">
                <a href="dashboard.php" class="logo-link" title="<?php echo $company_name_for_logo; ?> Paneline Git">
                    <?php if (!empty($company_logo_path)) : ?>
                        <img src="<?php echo $company_logo_path; ?>" alt="<?php echo $company_name_for_logo; ?> Logo" class="app-logo">
                    <?php else : ?>
                        <h3 class="app-name"><?php echo $company_name_for_logo; ?></h3>
                    <?php endif; ?>
                </a>
            </div>
            <?php include 'menu.php'; // Menü içeriği buraya dahil edilecek ?>
        </aside>
        <main id="content">
            <!-- Sayfanın içeriği (örneğin shipments.php'den gelen kısım) buraya gelecek -->
