# Dandash

Мобильное веб-приложение для пары на `Laravel + Vue + Tailwind + MariaDB + Reverb`.

## Что реализовано

- Авторизация:
- Login: `email + password`
- Register: `одноразовый registration code + email + ник + password`
- Главный экран:
- Вкладки снизу: `Продукты`, `Дела`, `Профиль`
- Свайпы:
- влево-направо: отметить сделанным/купленным
- вправо-налево: удалить
- Тап по элементу: редактирование текста
- Для todo: поддержка дедлайна (`datetime`)
- Профиль:
- изменение ника, email, пароля
- полноэкранная модалка share с отступами `10px`
- поиск пользователя и отправка приглашения
- Приглашения/синхронизация:
- модалка `Мои приглашения (count)` с вкладками `Приглашения` и `Списки`
- принять/отменить приглашение
- `Установить моим` / `Разорвать`
- выбор синхронизируемого списка через dropdown сверху
- Real-time:
- Reverb private channels
- плюс клиентский polling списка раз в `1 секунду`

## Локальный запуск (XAMPP)

1. Убедиться, что в PHP включены расширения:
   - `curl`, `pdo_mysql`, `mbstring`, `sockets`, `zip`, `openssl`
2. Запустить в XAMPP:
   - `Apache`
   - `MySQL`
3. Создать БД `dandash` (например через phpMyAdmin).
4. Установить зависимости:

```bash
composer install
npm install
```

5. Подготовить окружение:

```bash
copy .env.example .env
php artisan key:generate
```

6. Проверить `.env`:
   - `DB_CONNECTION=mysql`
   - `DB_HOST=127.0.0.1`
   - `DB_PORT=3306`
   - `DB_DATABASE=dandash`
   - `DB_USERNAME=root`
   - `DB_PASSWORD=`
   - `BROADCAST_CONNECTION=reverb`
7. Выполнить миграции:

```bash
php artisan migrate
```

8. Сгенерировать одноразовые коды регистрации:

```bash
php artisan registration-codes:generate 5 --expires-hours=72
```

9. Запустить приложение (3 процесса):

```bash
php artisan serve
php artisan reverb:start
npm run dev
```

10. Открыть:
    - `http://127.0.0.1:8000`

## Полезные команды

```bash
php artisan route:list
php artisan registration-codes:generate 1 --expires-hours=24
npm run build
```
