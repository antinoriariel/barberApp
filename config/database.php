<?php
/**
 * config/database.php — Conexión a la base de datos (se lee con Config::get('database.*')).
 * Los valores salen del entorno / .env: nunca escribir credenciales acá.
 *
 * driver: 'files' (lee data/*.php, nada se guarda) o 'db' (MySQL/MariaDB por PDO).
 * Es el interruptor de seguridad del plan: volver a 'files' revierte la app a la maqueta.
 */
return [
    'driver'   => getenv('DB_DRIVER') ?: 'files',
    'host'     => getenv('DB_HOST') ?: '127.0.0.1',
    'port'     => (int) (getenv('DB_PORT') ?: 3306),
    'name'     => getenv('DB_NAME') ?: 'navaja',
    'charset'  => 'utf8mb4',
    // Zona del local en la conexión (Argentina no usa horario de verano, así que un offset fijo alcanza).
    'timezone' => '-03:00',
    // Usuario de la app: solo SELECT, INSERT, UPDATE y DELETE.
    'user'     => getenv('DB_USER') ?: 'navaja_app',
    'password' => getenv('DB_PASSWORD') ?: '',
    // Usuario de migraciones (bin/migrate.php): puede crear y alterar tablas.
    'migrate_user'     => getenv('DB_MIGRATE_USER') ?: 'navaja_migrator',
    'migrate_password' => getenv('DB_MIGRATE_PASSWORD') ?: '',
];
