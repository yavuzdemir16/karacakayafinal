<?php
session_start();

// Tüm oturum değişkenlerini temizle
$_SESSION = array();

// Oturumu yok et
session_destroy();

// Giriş sayfasına yönlendir
header("location: login.php");
exit;
?>