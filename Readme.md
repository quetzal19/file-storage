## Quetzal19FileStorageBundle
Бандл, реализующий хранение файлов

## Установка
1. Выполнить установку бандла
```shell script
composer require quetzal19/file-storage
```

2. Сгенерировать миграции
```shell script
php bin/console make:migration
```

3. Выполнить миграции
```shell script
php bin/console doctrine:migrations:migrate
```