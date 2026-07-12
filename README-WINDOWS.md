# Установка «Школьные выборы PRO» на Windows через XAMPP

Инструкция предназначена для Windows 10 и Windows 11.

Проект использует PHP 8.1+, SQLite и Apache из XAMPP. MySQL запускать не требуется.

## 1. Установка XAMPP

Рекомендуемый путь установки:

```text
C:\xampp
```

Запустите:

```text
C:\xampp\xampp-control.exe
```

В XAMPP Control Panel включите **Apache**. MySQL можно не запускать.

Не рекомендуется устанавливать XAMPP в `Program Files`: Windows может ограничивать запись файлов.

## 2. Размещение проекта

Распакуйте проект сюда:

```text
C:\xampp\htdocs\school-election-pro
```

Проверьте наличие файла:

```text
C:\xampp\htdocs\school-election-pro\index.php
```

Не должно быть лишней вложенности:

```text
C:\xampp\htdocs\school-election-pro\school-election-pro\index.php
```

## 3. Проверка расширений PHP

Откройте Command Prompt или PowerShell:

```bat
C:\xampp\php\php.exe -m | findstr /I "pdo_sqlite sqlite3 mbstring zip dom fileinfo"
```

Обязательны:

```text
pdo_sqlite
sqlite3
mbstring
fileinfo
```

Для XLSX также нужны:

```text
zip
dom
```

Если расширение отсутствует, откройте:

```text
C:\xampp\php\php.ini
```

Проверьте, что нужные строки не начинаются с `;`:

```ini
extension=pdo_sqlite
extension=sqlite3
extension=mbstring
extension=zip
extension=fileinfo
```

После изменения `php.ini` полностью перезапустите Apache.

## 4. Создание рабочих папок

Откройте PowerShell:

```powershell
cd C:\xampp\htdocs\school-election-pro

New-Item -ItemType Directory -Force data
New-Item -ItemType Directory -Force uploads
New-Item -ItemType Directory -Force uploads\candidates
New-Item -ItemType Directory -Force backups
New-Item -ItemType Directory -Force exports
```

Если появляется ошибка:

```text
SQLSTATE[HY000] [14] unable to open database file
```

откройте Command Prompt от имени администратора:

```bat
cd /d C:\xampp\htdocs\school-election-pro

icacls data uploads backups exports /grant "%USERNAME%":(OI)(CI)M /T
```

Также проверьте:

- проект распакован, а не открыт внутри ZIP;
- папка не находится в `Program Files`;
- `data` не имеет атрибута «Только чтение»;
- Windows Defender не блокирует Apache;
- `election.sqlite` не открыт другой программой с блокировкой записи.

## 5. Первая установка

Запустите Apache и откройте:

```text
http://localhost/school-election-pro/setup.php
```

После установки:

```text
http://localhost/school-election-pro/admin/login.php
```

Вход учеников:

```text
http://localhost/school-election-pro/login.php
```

## 6. Первичная настройка

После входа в админ-панель:

1. Создайте кампанию в разделе **Выборы**.
2. Загрузите учеников.
3. Добавьте кандидатов.
4. Укажите дату и время голосования.
5. Настройте публикацию результатов.
6. Распечатайте карточки доступа.
7. Создайте резервную копию.
8. Откройте голосование.

## 7. Импорт учеников

Поддерживаются `.xlsx` и `.csv`.

Шаблоны находятся в:

```text
templates
```

Столбцы:

| Код ученика | ФИО | Класс | Пароль |
|---|---|---|---|
| S001 | Иван Иванов | 8 «А» | 4821 |

Код и пароль храните в Excel как текст, чтобы не исчезали начальные нули.

Большие таблицы импортируются пакетами. Во время импорта не закрывайте вкладку, не запускайте второй импорт и не выключайте Apache.

## 8. Резервная копия

База находится здесь:

```text
C:\xampp\htdocs\school-election-pro\data\election.sqlite
```

Command Prompt:

```bat
copy C:\xampp\htdocs\school-election-pro\data\election.sqlite "%USERPROFILE%\Desktop\election-backup.sqlite"
```

PowerShell:

```powershell
Copy-Item `
  "C:\xampp\htdocs\school-election-pro\data\election.sqlite" `
  "$env:USERPROFILE\Desktop\election-backup.sqlite"
```

Перед каждым обновлением остановите Apache и создайте копию базы.

## 9. Установка патча

Предположим, патч находится в `Downloads`.

Остановите Apache, затем откройте PowerShell от имени администратора:

```powershell
Copy-Item `
  "C:\xampp\htdocs\school-election-pro\data\election.sqlite" `
  "$env:USERPROFILE\Desktop\election-before-update.sqlite"

Expand-Archive `
  -Path "$env:USERPROFILE\Downloads\school-election-pro-4.0.8-hotfix.zip" `
  -DestinationPath "C:\xampp\htdocs" `
  -Force
```

Запустите Apache и откройте:

```text
http://localhost/school-election-pro/diagnostics.php
```

Затем:

```text
http://localhost/school-election-pro/
```

Патч не должен содержать `data\election.sqlite`, иначе база может быть перезаписана.

## 10. Восстановление базы

Остановите Apache:

```powershell
Copy-Item `
  "$env:USERPROFILE\Desktop\election-backup.sqlite" `
  "C:\xampp\htdocs\school-election-pro\data\election.sqlite" `
  -Force
```

Запустите Apache и проверьте:

```text
http://localhost/school-election-pro/diagnostics.php
```

## 11. Доступ с телефонов и других компьютеров

Все устройства должны быть в одной локальной сети.

Узнайте IPv4-адрес:

```bat
ipconfig
```

Пример:

```text
192.168.1.50
```

Тогда адрес сайта:

```text
http://192.168.1.50/school-election-pro/
```

Этот адрес нужно указать как базовый URL для QR-кодов.

В Windows Firewall разрешите Apache доступ только для частных сетей.

Не используйте `localhost` в QR-кодах для телефонов: на телефоне `localhost` указывает на сам телефон.

## 12. Сохранение протокола в PDF

На странице протокола нажмите:

```text
Ctrl + P
```

Выберите:

```text
Microsoft Print to PDF
```

## 13. Типичные ошибки

### Apache не запускается

Проверьте, занят ли порт 80:

```bat
netstat -ano | findstr :80
```

Журнал:

```text
C:\xampp\apache\logs\error.log
```

### `unable to open database file`

```bat
cd /d C:\xampp\htdocs\school-election-pro
mkdir data
icacls data /grant "%USERNAME%":(OI)(CI)M /T
```

### `could not find driver`

```bat
C:\xampp\php\php.exe -m | findstr /I "pdo_sqlite sqlite3"
```

### `Call to undefined function mb_*`

Включите в `php.ini`:

```ini
extension=mbstring
```

### Не импортируется XLSX

```bat
C:\xampp\php\php.exe -m | findstr /I "zip dom"
```

При отсутствии расширений сохраните таблицу как CSV.

### Белая страница или HTTP 500

Проверьте:

```text
C:\xampp\apache\logs\error.log
```

И откройте:

```text
http://localhost/school-election-pro/diagnostics.php
```

### Медленно открывается список учеников

Используйте 25 или 50 записей на страницу. Не выводите всю базу сразу.

## 14. Безопасность

- Не публикуйте XAMPP напрямую в интернете.
- Используйте систему преимущественно в локальной школьной сети.
- Не передавайте `election.sqlite` посторонним.
- Используйте сложный пароль администратора.
- Создавайте резервную копию перед открытием голосования.
- После восстановления удалите `repair.php`.
- После диагностики удалите или переименуйте `diagnostics.php`, если сервер доступен извне.
- Для публичного размещения используйте HTTPS.

## 15. Удаление проекта

Сначала сохраните базу:

```bat
copy C:\xampp\htdocs\school-election-pro\data\election.sqlite "%USERPROFILE%\Desktop\election-final-backup.sqlite"
```

Остановите Apache и удалите:

```text
C:\xampp\htdocs\school-election-pro
```

Без резервной копии будут потеряны ученики, кампании и результаты.
