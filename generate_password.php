<?php
// Oluşturmak istediğin parolayı buraya yaz
$password_to_hash = "hashed_genel_mudur_password"; // Örneğin: "genelmudursifresi"

// Parolayı güvenli bir şekilde hashle
$hashed_password = password_hash($password_to_hash, PASSWORD_DEFAULT);

echo "Hashlenmiş Parolanız: " . $hashed_password;
?>