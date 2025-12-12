<?php
require_once 'includes/config.php';
checkAuth();

if (isAdmin()) {
    redirect('admin/dashboard.php');
} else {
    redirect('customer/dashboard.php');
}
?>
