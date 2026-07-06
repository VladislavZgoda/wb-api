В .env необходимо добавить следующие переменные:
API_BASE_URL=http://ip:port/api
API_SECRET_KEY

DB_CONNECTION=mysql
DB_HOST=FVH3.spaceweb.ru.
DB_PORT=3308
DB_DATABASE=zgodavgyan
DB_USERNAME=zgodavgyan
DB_PASSWORD=M7J4Q6mQUHV5R3

Для загрузки данных из api в дб использовать команду в консоли:
php artisan api:fetch --dateFrom=2000-01-01 --dateTo=now --truncate

Названия таблиц:
stocks
incomes
sales
orders
