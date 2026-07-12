<?php
declare(strict_types=1);
require_once __DIR__.'/config.php';
header('Content-Type: text/html; charset=UTF-8');

$checks=[];
function diagnostic_add(array &$checks,string $name,bool $ok,string $details):void{$checks[]=[$name,$ok,$details];}
function diagnostic_directory(array &$checks,string $label,string $path):void{
    diagnostic_add($checks,$label.' существует',is_dir($path),$path);
    $ok=is_dir($path)&&is_writable($path);$details=$ok?'доступна для записи':'нет прав на запись';
    if(is_dir($path)){$test=$path.'/.write-test-'.bin2hex(random_bytes(3));if(@file_put_contents($test,'ok')!==false){$ok=true;$details='тестовая запись выполнена';@unlink($test);}else{$error=error_get_last();$details=$error['message']??$details;}}
    diagnostic_add($checks,$label.' доступна PHP для записи',$ok,$details);
}

diagnostic_add($checks,'PHP 8.1+',version_compare(PHP_VERSION,'8.1.0','>='),PHP_VERSION);
foreach(['pdo_sqlite'=>'SQLite','mbstring'=>'Unicode/ФИО','fileinfo'=>'проверка загрузок'] as $extension=>$purpose)diagnostic_add($checks,'Расширение '.$extension,extension_loaded($extension),extension_loaded($extension)?'подключено':'нужно для: '.$purpose);
diagnostic_add($checks,'ZipArchive для XLSX',class_exists('ZipArchive'),class_exists('ZipArchive')?'доступно':'CSV продолжит работать, XLSX — нет');
diagnostic_add($checks,'DOM/XML для XLSX',class_exists('DOMDocument'),class_exists('DOMDocument')?'доступно':'CSV продолжит работать, XLSX — нет');
diagnostic_directory($checks,'Папка data',dirname(DB_PATH));
diagnostic_directory($checks,'Папка uploads',UPLOAD_ROOT);
diagnostic_directory($checks,'Папка backups',BACKUP_ROOT);

$sqliteOk=false;$sqliteMessage='не проверено';$foreignKeyOk=false;$foreignKeyMessage='база не открыта';
if(extension_loaded('pdo_sqlite')&&is_dir(dirname(DB_PATH))&&is_writable(dirname(DB_PATH))){try{$pdo=new PDO('sqlite:'.DB_PATH);$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$pdo->exec('PRAGMA foreign_keys=ON');$pdo->query('SELECT 1');$sqliteOk=true;$sqliteMessage=DB_PATH;$violations=$pdo->query('PRAGMA foreign_key_check')->fetchAll(PDO::FETCH_ASSOC);$foreignKeyOk=$violations===[];$foreignKeyMessage=$foreignKeyOk?'нарушений не найдено':json_encode($violations,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);}catch(Throwable $e){$sqliteMessage=$e->getMessage();$foreignKeyMessage=$e->getMessage();}}
diagnostic_add($checks,'Открытие SQLite-базы',$sqliteOk,$sqliteMessage);
diagnostic_add($checks,'Внешние ключи SQLite',$foreignKeyOk,$foreignKeyMessage);

$baseUrl=(isset($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off'?'https':'http').'://'.($_SERVER['HTTP_HOST']??'localhost').rtrim(dirname($_SERVER['SCRIPT_NAME']??'/'),'/');
?>
<!DOCTYPE html><html lang="ru"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Диагностика</title><style>body{margin:0;padding:32px 16px;background:#f4f7fb;color:#172033;font-family:system-ui,sans-serif}main{max-width:980px;margin:auto;padding:28px;background:#fff;border:1px solid #dfe6f0;border-radius:22px}table{width:100%;border-collapse:collapse}th,td{padding:13px;border-bottom:1px solid #dfe6f0;text-align:left;vertical-align:top}.ok{color:#087455;font-weight:800}.bad{color:#b32f42;font-weight:800}code{overflow-wrap:anywhere}.note{margin-top:22px;padding:15px;border-radius:13px;background:#fff7df}</style></head><body><main><h1>Диагностика XAMPP</h1><p>Версия проекта: <strong><?=htmlspecialchars(APP_VERSION)?></strong></p><p>Предполагаемый адрес сайта: <code><?=htmlspecialchars($baseUrl)?></code></p><p>Путь базы: <code><?=htmlspecialchars(DB_PATH)?></code></p><table><thead><tr><th>Проверка</th><th>Статус</th><th>Детали</th></tr></thead><tbody><?php foreach($checks as[$name,$ok,$details]):?><tr><td><?=htmlspecialchars($name)?></td><td class="<?=$ok?'ok':'bad'?>"><?=$ok?'OK':'ОШИБКА'?></td><td><code><?=htmlspecialchars($details)?></code></td></tr><?php endforeach;?></tbody></table><div class="note"><strong>macOS XAMPP:</strong><pre>cd /Applications/XAMPP/xamppfiles/htdocs/school-election-pro
sudo chown -R daemon:daemon data uploads backups
sudo chmod -R 775 data uploads backups</pre>После проверки удалите или переименуйте <code>diagnostics.php</code> на публичном сервере.</div></main></body></html>
