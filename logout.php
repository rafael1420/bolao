<?php
require_once __DIR__ . '/includes/auth.php';

// Sair deve ser feito por POST para evitar logout acidental por link externo.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    logout_user();
}

redirect('login.php');
