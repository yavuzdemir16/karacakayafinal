<?php
require_once 'config.php';

// Yetki kontrolü: Genel Müdür, Genel Müdür Yardımcısı, Satış Müdürü erişebilir.
// check_permission fonksiyonu config.php'de tanımlıdır ve PDO kullanır.
check_permission(['genel_mudur', 'genel_mudur_yardimcisi', 'satis_muduru']);

// Sayfa başlığını ayarla
$page_title = "Bayi Yönetimi"; // Bu satır header.php tarafından kullanılacak

// connect_db() fonksiyonu artık PDO nesnesi döndürüyor.
$pdo = connect_db(); // Bağlantı nesnesini $pdo olarak aldık

$action = isset($_GET['action']) ? $_GET['action'] : 'list'; // 'list', 'add', 'edit', 'delete'

$message = '';
$message_type = '';

// Satış Müdürleri listesini çek (bayi ataması için)
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
    if (isset($_POST['add_dealer'])) {
        $dealer_name = trim($_POST['dealer_name']);
        $contact_person = trim($_POST['contact_person']);
        $phone = trim($_POST['phone']);
        $email = trim($_POST['email']);
        $address = trim($_POST['address']);
        $city = trim($_POST['city']);
        $country = trim($_POST['country']);
        $tax_id = trim($_POST['tax_id']);
        $assigned_sales_manager_ids = isset($_POST['assigned_sales_managers']) ? $_POST['assigned_sales_managers'] : [];

        if (empty($dealer_name)) {
            $message = "Bayi adı boş bırakılamaz.";
            $message_type = 'error';
        } else {
            // Sadece Genel Müdür ve Genel Müdür Yardımcısı yeni bayi ekleyebilir.
            // Satış müdürü sadece kendi bayilerini ekleyebilir, bu kuralı ekledim
            // Not: Session'daki role_id'nin veritabanındaki role ID'si ile eşleştiğinden emin olun.
            // Örneğin: 1=genel_mudur, 2=genel_mudur_yardimcisi, 3=satis_muduru
            // $_SESSION['user_role_id'] kullanıyoruz
            if ($_SESSION['user_role_id'] == 3) {
                 $message = "Satış müdürleri, bayi ekleyemez.";
                 $message_type = 'error';
            } else {
                try {
                    $sql = "INSERT INTO dealers (dealer_name, contact_person, phone, email, address, city, country, tax_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute([$dealer_name, $contact_person, $phone, $email, $address, $city, $country, $tax_id]);

                    $dealer_id = $pdo->lastInsertId();

                    // Satış müdürlerini ata
                    if (!empty($assigned_sales_manager_ids)) {
                        $insert_sm_sql = "INSERT INTO sales_managers_to_dealers (sales_manager_id, dealer_id) VALUES (?, ?)";
                        $stmt_sm = $pdo->prepare($insert_sm_sql);
                        foreach ($assigned_sales_manager_ids as $sm_id) {
                            $stmt_sm->execute([$sm_id, $dealer_id]);
                        }
                    }
                    $message = "Bayi başarıyla eklendi.";
                    $message_type = 'success';
                } catch (PDOException $e) {
                    $message = "Bayi eklenirken bir hata oluştu: " . $e->getMessage();
                    $message_type = 'error';
                }
            }
        }
        $action = 'list'; // İşlem sonrası listeye dön
    } elseif (isset($_POST['edit_dealer'])) {
        $dealer_id = $_POST['dealer_id'];
        $dealer_name = trim($_POST['dealer_name']);
        $contact_person = trim($_POST['contact_person']);
        $phone = trim($_POST['phone']);
        $email = trim($_POST['email']);
        $address = trim($_POST['address']);
        $city = trim($_POST['city']);
        $country = trim($_POST['country']);
        $tax_id = trim($_POST['tax_id']);
        $assigned_sales_manager_ids = isset($_POST['assigned_sales_managers']) ? $_POST['assigned_sales_managers'] : [];
        $is_active = isset($_POST['is_active']) ? 1 : 0;

        if (empty($dealer_name)) {
            $message = "Bayi adı boş bırakılamaz.";
            $message_type = 'error';
        } else {
            try {
                // Satış müdürünün sadece kendi bayilerini düzenlemesine izin ver
                if ($_SESSION['user_role_id'] == 3) { // 3: Satış Müdürü ID'si
                     // Bayinin ilgili satış müdürüne ait olup olmadığını kontrol et
                     $check_owner_sql = "SELECT COUNT(*) FROM sales_managers_to_dealers WHERE sales_manager_id = ? AND dealer_id = ?";
                     $check_owner_stmt = $pdo->prepare($check_owner_sql);
                     $check_owner_stmt->execute([$_SESSION['user_id'], $dealer_id]);
                     $count_owner = $check_owner_stmt->fetchColumn();

                     if ($count_owner == 0) {
                         $message = "Bu bayiyi düzenlemeye yetkiniz yok.";
                         $message_type = 'error';
                         $action = 'list';
                         goto end_post_processing; // İşlemi sonlandır
                     }
                     // Satış müdürü is_active'i düzenleyemez
                     $sql = "UPDATE dealers SET dealer_name = ?, contact_person = ?, phone = ?, email = ?, address = ?, city = ?, country = ?, tax_id = ? WHERE id = ?";
                     $stmt = $pdo->prepare($sql);
                     $stmt->execute([$dealer_name, $contact_person, $phone, $email, $address, $city, $country, $tax_id, $dealer_id]);
                } else { // Genel Müdür veya Genel Müdür Yardımcısı tüm alanları düzenleyebilir
                    $sql = "UPDATE dealers SET dealer_name = ?, contact_person = ?, phone = ?, email = ?, address = ?, city = ?, country = ?, tax_id = ?, is_active = ? WHERE id = ?";
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute([$dealer_name, $contact_person, $phone, $email, $address, $city, $country, $tax_id, $is_active, $dealer_id]);

                    // Eski satış müdürü atamalarını sil
                    $delete_sm_sql = "DELETE FROM sales_managers_to_dealers WHERE dealer_id = ?";
                    $stmt_delete_sm = $pdo->prepare($delete_sm_sql);
                    $stmt_delete_sm->execute([$dealer_id]);

                    // Yeni satış müdürlerini ata
                    if (!empty($assigned_sales_manager_ids)) {
                        $insert_sm_sql = "INSERT INTO sales_managers_to_dealers (sales_manager_id, dealer_id) VALUES (?, ?)";
                        $stmt_insert_sm = $pdo->prepare($insert_sm_sql);
                        foreach ($assigned_sales_manager_ids as $sm_id) {
                            $stmt_insert_sm->execute([$sm_id, $dealer_id]);
                        }
                    }
                }

                $message = "Bayi başarıyla güncellendi.";
                $message_type = 'success';
            } catch (PDOException $e) {
                $message = "Bayi güncellenirken bir hata oluştu: " . $e->getMessage();
                $message_type = 'error';
            }
        }
        $action = 'list'; // İşlem sonrası listeye dön
    } elseif (isset($_GET['delete_id'])) {
        $delete_id = $_GET['delete_id'];

        // Sadece Genel Müdür ve Genel Müdür Yardımcısı silme yetkisine sahip.
        if ($_SESSION['user_role_id'] == 1 || $_SESSION['user_role_id'] == 2) { // 1: Genel Müdür, 2: Genel Müdür Yardımcısı ID'si
            try {
                // Bağlı satış müdürlerini sil
                $delete_sm_sql = "DELETE FROM sales_managers_to_dealers WHERE dealer_id = ?";
                $stmt_delete_sm = $pdo->prepare($delete_sm_sql);
                $stmt_delete_sm->execute([$delete_id]);

                $sql = "DELETE FROM dealers WHERE id = ?";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$delete_id]);

                $message = "Bayi başarıyla silindi.";
                $message_type = 'success';
            } catch (PDOException $e) {
                $message = "Bayi silinirken bir hata oluştu: " . $e->getMessage();
                $message_type = 'error';
            }
        } else {
            $message = "Bu bayiyi silmeye yetkiniz yok.";
            $message_type = 'error';
        }
        $action = 'list'; // İşlem sonrası listeye dön
    }
}
end_post_processing:;


// Liste için bayileri çek
$dealers = [];
$sql_dealers = "SELECT d.* FROM dealers d";
$params = [];
// Satış müdürü ise sadece kendi bayilerini görsün
if (isset($_SESSION['user_role_id']) && $_SESSION['user_role_id'] == 3) { // 3: Satış Müdürü ID'si
    $sql_dealers .= " JOIN sales_managers_to_dealers smd ON d.id = smd.dealer_id WHERE smd.sales_manager_id = ?";
    $params[] = $_SESSION['user_id'];
}

try {
    $stmt_dealers = $pdo->prepare($sql_dealers);
    $stmt_dealers->execute($params);
    $dealers_raw = $stmt_dealers->fetchAll(PDO::FETCH_ASSOC);

    if (!empty($dealers_raw)) {
        foreach ($dealers_raw as $row) {
            // Her bayi için atanmış satış müdürlerini çek
            $assigned_sm_names = [];
            $stmt_assigned_sm = $pdo->prepare("SELECT u.full_name FROM users u JOIN sales_managers_to_dealers smd ON u.id = smd.sales_manager_id WHERE smd.dealer_id = ?");
            $stmt_assigned_sm->execute([$row['id']]);
            $sm_rows = $stmt_assigned_sm->fetchAll(PDO::FETCH_ASSOC);
            foreach($sm_rows as $sm_row){
                $assigned_sm_names[] = $sm_row['full_name'];
            }
            $row['assigned_sales_managers'] = implode(', ', $assigned_sm_names);
            $dealers[] = $row;
        }
    }
} catch (PDOException $e) {
    error_log("Bayi listesi çekilirken hata oluştu: " . $e->getMessage());
    $message = "Bayi listesi alınırken bir hata oluştu.";
    $message_type = 'error';
}

// header.php'yi dahil et
include 'includes/header.php';
?>

        <div class="container">
            <h1>Bayi Yönetimi</h1>

            <?php if (!empty($message)) : ?>
                <div class="message <?php echo $message_type; ?>"><?php echo $message; ?></div>
            <?php endif; ?>

            <?php if ($action == 'list') : ?>
                <?php if (isset($_SESSION['user_role_id']) && ($_SESSION['user_role_id'] == 1 || $_SESSION['user_role_id'] == 2)) : // Genel Müdür ve Genel Müdür Yardımcısı ekleyebilir ?>
                    <p><a href="dealers.php?action=add" class="btn btn-success">Yeni Bayi Ekle</a></p>
                <?php endif; ?>

                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Bayi Adı</th>
                            <th>Yetkili</th>
                            <th>Telefon</th>
                            <th>Email</th>
                            <th>Şehir</th>
                            <th>Atanan Satış Müdürleri</th>
                            <th>Aktif Mi?</th>
                            <th>İşlemler</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($dealers)) : ?>
                            <?php foreach ($dealers as $dealer) : ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($dealer['id']); ?></td>
                                    <td><?php echo htmlspecialchars($dealer['dealer_name']); ?></td>
                                    <td><?php echo htmlspecialchars($dealer['contact_person']); ?></td>
                                    <td><?php echo htmlspecialchars($dealer['phone']); ?></td>
                                    <td><?php echo htmlspecialchars($dealer['email']); ?></td>
                                    <td><?php echo htmlspecialchars($dealer['city']); ?></td>
                                    <td><?php echo htmlspecialchars($dealer['assigned_sales_managers'] ?: 'Yok'); ?></td>
                                    <td><?php echo $dealer['is_active'] ? 'Evet' : 'Hayır'; ?></td>
                                    <td>
                                        <?php
                                        // user_role_id ve user_id kullanarak kontrol
                                        $can_edit = false;
                                        if (isset($_SESSION['user_role_id'])) {
                                            if ($_SESSION['user_role_id'] == 1 || $_SESSION['user_role_id'] == 2) { // GM veya GMY
                                                $can_edit = true;
                                            } elseif ($_SESSION['user_role_id'] == 3 && isset($_SESSION['user_id'])) { // Satış Müdürü
                                                // Eğer satış müdürü ise, atandığı bayiyi düzenleyebilir mi kontrolü
                                                $stmt_check_assignment = $pdo->prepare("SELECT COUNT(*) FROM sales_managers_to_dealers WHERE sales_manager_id = ? AND dealer_id = ?");
                                                $stmt_check_assignment->execute([$_SESSION['user_id'], $dealer['id']]);
                                                if ($stmt_check_assignment->fetchColumn() > 0) {
                                                    $can_edit = true;
                                                }
                                            }
                                        }
                                        ?>
                                        <?php if ($can_edit) : ?>
                                            <a href='dealers.php?action=edit&id=<?php echo $dealer['id']; ?>' class='btn btn-warning'>Düzenle</a>
                                        <?php endif; ?>

                                        <?php if (isset($_SESSION['user_role_id']) && ($_SESSION['user_role_id'] == 1 || $_SESSION['user_role_id'] == 2)) : // Sadece Genel Müdür ve Yardımcısı silebilir ?>
                                            <a href='dealers.php?action=delete&delete_id=<?php echo $dealer['id']; ?>' class='btn btn-danger' onclick="return confirm('Bu bayiyi silmek istediğinizden emin misiniz?');">Sil</a>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else : ?>
                            <tr><td colspan='9'>Henüz hiç bayi yok.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>

            <?php elseif ($action == 'add' && isset($_SESSION['user_role_id']) && ($_SESSION['user_role_id'] == 1 || $_SESSION['user_role_id'] == 2)) : // Sadece GM ve GMY ekleyebilir ?>
                <h2>Yeni Bayi Ekle</h2>
                <form action="dealers.php" method="post">
                    <div class="form-group">
                        <label for="dealer_name">Bayi Adı:</label>
                        <input type="text" id="dealer_name" name="dealer_name" required>
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
                        <button type="submit" name="add_dealer" class="btn btn-primary">Bayi Ekle</button>
                        <a href="dealers.php" class="btn btn-secondary">İptal</a>
                    </div>
                </form>

            <?php elseif ($action == 'edit' && isset($_GET['id'])) :
                $edit_dealer_id = $_GET['id'];
                $dealer_to_edit = null;

                try {
                    // Yetki kontrolü: Sadece Genel Müdür, Genel Müdür Yardımcısı veya atanmış Satış Müdürü düzenleyebilir.
                    // Satış müdürü sadece kendi bayisini düzenleyebilir.
                    if (isset($_SESSION['user_role_id']) && $_SESSION['user_role_id'] == 3) {
                         $stmt = $pdo->prepare("SELECT d.* FROM dealers d JOIN sales_managers_to_dealers smd ON d.id = smd.dealer_id WHERE d.id = ? AND smd.sales_manager_id = ?");
                         $stmt->execute([$edit_dealer_id, $_SESSION['user_id']]);
                    } else {
                         $stmt = $pdo->prepare("SELECT d.* FROM dealers d WHERE d.id = ?");
                         $stmt->execute([$edit_dealer_id]);
                    }

                    $dealer_to_edit = $stmt->fetch(PDO::FETCH_ASSOC);

                    if ($dealer_to_edit) {
                        // Atanmış satış müdürlerini çek (sadece edit formunda gerekli)
                        $current_assigned_sm_ids = [];
                        if (isset($_SESSION['user_role_id']) && $_SESSION['user_role_id'] != 3) { // Satış müdürünün bu kısmı görmesine gerek yok
                            $stmt_current_sm = $pdo->prepare("SELECT sales_manager_id FROM sales_managers_to_dealers WHERE dealer_id = ?");
                            $stmt_current_sm->execute([$edit_dealer_id]);
                            $sm_rows = $stmt_current_sm->fetchAll(PDO::FETCH_COLUMN);
                            $current_assigned_sm_ids = $sm_rows;
                        }
                    }
                } catch (PDOException $e) {
                    error_log("Bayi düzenleme bilgisi çekilirken hata oluştu: " . $e->getMessage());
                    $message = "Bayi bilgileri alınırken bir hata oluştu.";
                    $message_type = 'error';
                }

                if ($dealer_to_edit) : ?>
                    <h2>Bayi Düzenle</h2>
                    <form action="dealers.php" method="post">
                        <input type="hidden" name="dealer_id" value="<?php echo htmlspecialchars($dealer_to_edit['id']); ?>">
                        <div class="form-group">
                            <label for="dealer_name">Bayi Adı:</label>
                            <input type="text" id="dealer_name" name="dealer_name" value="<?php echo htmlspecialchars($dealer_to_edit['dealer_name']); ?>" required>
                        </div>
                        <div class="form-group">
                            <label for="contact_person">İlgili Kişi:</label>
                            <input type="text" id="contact_person" name="contact_person" value="<?php echo htmlspecialchars($dealer_to_edit['contact_person']); ?>">
                        </div>
                        <div class="form-group">
                            <label for="phone">Telefon:</label>
                            <input type="tel" id="phone" name="phone" value="<?php echo htmlspecialchars($dealer_to_edit['phone']); ?>">
                        </div>
                        <div class="form-group">
                            <label for="email">E-posta:</label>
                            <input type="email" id="email" name="email" value="<?php echo htmlspecialchars($dealer_to_edit['email']); ?>">
                        </div>
                        <div class="form-group">
                            <label for="address">Adres:</label>
                            <textarea id="address" name="address"><?php echo htmlspecialchars($dealer_to_edit['address']); ?></textarea>
                        </div>
                        <div class="form-group">
                            <label for="city">Şehir:</label>
                            <input type="text" id="city" name="city" value="<?php echo htmlspecialchars($dealer_to_edit['city']); ?>">
                        </div>
                        <div class="form-group">
                            <label for="country">Ülke:</label>
                            <input type="text" id="country" name="country" value="<?php echo htmlspecialchars($dealer_to_edit['country']); ?>">
                        </div>
                        <div class="form-group">
                            <label for="tax_id">Vergi Numarası:</label>
                            <input type="text" id="tax_id" name="tax_id" value="<?php echo htmlspecialchars($dealer_to_edit['tax_id']); ?>">
                        </div>

                        <?php if (isset($_SESSION['user_role_id']) && ($_SESSION['user_role_id'] == 1 || $_SESSION['user_role_id'] == 2)) : // Sadece GM ve GMY görebilir/düzenleyebilir ?>
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
                            <input type="checkbox" id="is_active" name="is_active" value="1" <?php echo $dealer_to_edit['is_active'] ? 'checked' : ''; ?>>
                        </div>
                        <?php else: ?>
                            <input type="hidden" name="is_active" value="<?php echo $dealer_to_edit['is_active']; ?>">
                            <?php // Satış müdürü sadece kendi bayilerini düzenleyebildiğinden, is_active ve atanmış satış müdürlerini göremesin ?>
                        <?php endif; ?>

                        <div class="form-group">
                            <button type="submit" name="edit_dealer" class="btn btn-primary">Bayiyi Güncelle</button>
                            <a href="dealers.php" class="btn btn-secondary">İptal</a>
                        </div>
                    </form>
                <?php else : ?>
                    <div class="message error">Bayi bulunamadı veya düzenleme yetkiniz yok.</div>
                    <p><a href="dealers.php" class="btn btn-primary">Geri Dön</a></p>
                <?php endif; ?>
            <?php endif; ?>
        </div>

<?php
// footer.php'yi dahil et
include 'includes/footer.php';
?>
