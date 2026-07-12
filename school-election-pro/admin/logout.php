<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/functions.php';
if(current_admin()) audit_log('admin_logout','admin',(int)$_SESSION['admin_id']);
unset($_SESSION['admin_id'],$_SESSION['admin_last_activity']);session_regenerate_id(true);flash('success','Вы вышли из административной панели.');redirect('login.php');
