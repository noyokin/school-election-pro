# Обновление существующего проекта на XAMPP macOS

## 1. Остановите Apache
Откройте XAMPP Manager и остановите Apache.

## 2. Сохраните базу

```bash
cp /Applications/XAMPP/xamppfiles/htdocs/school-election/data/election.sqlite \
~/Desktop/election-backup-before-pro.sqlite
```

Замените `school-election` на фактическое имя старой папки.

## 3. Распакуйте новую версию
Рекомендуемый путь:

```text
/Applications/XAMPP/xamppfiles/htdocs/school-election-pro
```

Скопируйте старый файл `election.sqlite` в новую папку `data`:

```bash
sudo cp ~/Desktop/election-backup-before-pro.sqlite \
/Applications/XAMPP/xamppfiles/htdocs/school-election-pro/data/election.sqlite
```

## 4. Выдайте права Apache XAMPP

```bash
cd /Applications/XAMPP/xamppfiles/htdocs/school-election-pro
sudo chown -R daemon:daemon data uploads backups exports
sudo chmod -R 775 data uploads backups exports
sudo chmod 664 data/election.sqlite
```

## 5. Запустите Apache и проверьте

```text
http://localhost/school-election-pro/diagnostics.php
```

При первом открытии сайта старая база автоматически расширится до новой схемы. Старые голоса останутся анонимными и будут помещены в первую кампанию.

## 6. Настройте адрес QR-кодов
В админ-панели откройте **Настройки** и укажите:

```text
http://localhost/school-election-pro
```

Для голосования с телефонов используйте сетевой адрес Mac, например:

```text
http://192.168.1.25/school-election-pro
```

Телефон и Mac должны находиться в одной сети, а Apache должен быть доступен через брандмауэр.
