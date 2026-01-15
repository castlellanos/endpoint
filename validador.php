<?php
declare(strict_types=1);

/**
 * endpoint/validador.php
 * Valida que el proyecto tenga lo necesario para correr:
 * - PHP versión
 * - vendor/autoload.php
 * - PhpSpreadsheet disponible
 * - carpeta public/ y src/
 */

header('Content-Type: application/json; charset=utf-8');

$root = __DIR__;

$result = [
  'ok' => true,
  'checks' => [],
  'env' => [
    'php_version' => PHP_VERSION,
    'os' => PHP_OS_FAMILY,
    'project_root' => $root,
  ],
];

function addCheck(array &$result, string $name, bool $ok, string $message, array $extra = []): void {
  $result['checks'][] = array_merge([
    'name' => $name,
    'ok' => $ok,
    'message' => $message,
  ], $extra);

  if (!$ok) {
    $result['ok'] = false;
  }
}

addCheck(
  $result,
  'PHP version >= 8.1',
  version_compare(PHP_VERSION, '8.1.0', '>='),
  'Versión actual: ' . PHP_VERSION
);

$autoload = $root . '/vendor/autoload.php';
addCheck(
  $result,
  'vendor/autoload.php exists',
  file_exists($autoload),
  file_exists($autoload) ? 'Encontrado' : 'No existe. Ejecuta: composer install'
);

if (file_exists($autoload)) {
  require_once $autoload;

  $hasSpreadsheet = class_exists(\PhpOffice\PhpSpreadsheet\Spreadsheet::class);
  addCheck(
    $result,
    'PhpSpreadsheet installed',
    $hasSpreadsheet,
    $hasSpreadsheet ? 'OK' : 'No se encontró PhpSpreadsheet. Ejecuta: composer require phpoffice/phpspreadsheet'
  );
} else {
  addCheck(
    $result,
    'PhpSpreadsheet installed',
    false,
    'No puedo verificar porque falta vendor/autoload.php'
  );
}

$publicDir = $root . '/public';
$srcDir = $root . '/src';

addCheck($result, 'public/ folder', is_dir($publicDir), is_dir($publicDir) ? 'OK' : 'Falta la carpeta public/');
addCheck($result, 'src/ folder', is_dir($srcDir), is_dir($srcDir) ? 'OK' : 'Falta la carpeta src/');

// Permisos (opcional, útil si vas a guardar JSON luego)
addCheck(
  $result,
  'project root readable',
  is_readable($root),
  is_readable($root) ? 'OK' : 'No hay permisos de lectura en la raíz'
);

echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
