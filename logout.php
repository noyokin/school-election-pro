<?php
declare(strict_types=1);
require_once __DIR__ . '/functions.php';
logout_student_session();
flash('success', 'Вы вышли из учётной записи.');
redirect('index.php');
