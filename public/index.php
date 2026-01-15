<?php
declare(strict_types=1);

// Ajusta rutas
$ROOT = realpath(__DIR__ . '/..') ?: (__DIR__ . '/..');

require_once $ROOT . '/importer.php';

$UPLOAD_DIR = $ROOT . '/storage/uploads';
$JSON_DIR   = $ROOT . '/storage/json';

// Crear carpetas si no existen
@mkdir($UPLOAD_DIR, 0777, true);
@mkdir($JSON_DIR, 0777, true);

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

function jsonResponse(mixed $payload, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

function safeExt(string $name): string {
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    return in_array($ext, ['csv', 'xlsx'], true) ? $ext : '';
}

function listFiles(string $uploadDir, string $jsonDir): array {
    $items = [];
    $files = glob($uploadDir . '/*.{csv,xlsx}', GLOB_BRACE) ?: [];

    // Ordena más reciente primero
    usort($files, fn($a, $b) => filemtime($b) <=> filemtime($a));

    foreach ($files as $path) {
        $base = basename($path);
        $id = pathinfo($base, PATHINFO_FILENAME); // nombre sin extensión (fecha_hora)
        $jsonPath = $jsonDir . '/' . $id . '.json';

        $items[] = [
            'id' => $id,
            'file' => $base,
            'ext' => strtolower(pathinfo($base, PATHINFO_EXTENSION)),
            'uploaded_at' => date('Y-m-d H:i:s', filemtime($path)),
            'size' => filesize($path) ?: 0,
            'has_json' => file_exists($jsonPath),
        ];
    }
    return $items;
}

// Endpoint AJAX para traer JSON al modal: ?action=view&id=YYYY-MM-DD_HH-MM-SS
if (($_GET['action'] ?? '') === 'view') {
    $id = preg_replace('/[^0-9\-\_]/', '', (string)($_GET['id'] ?? ''));
    if ($id === '') jsonResponse(['error' => 'ID inválido'], 400);

    $jsonPath = $JSON_DIR . '/' . $id . '.json';
    if (!file_exists($jsonPath)) jsonResponse(['error' => 'JSON no encontrado'], 404);

    header('Content-Type: application/json; charset=utf-8');
    readfile($jsonPath);
    exit;
}

// Upload
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!isset($_FILES['file'])) {
        jsonResponse(['error' => 'No se recibió archivo'], 400);
    }

    $f = $_FILES['file'];
    if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        jsonResponse(['error' => 'Error al subir archivo', 'code' => $f['error'] ?? null], 400);
    }

    $ext = safeExt((string)$f['name']);
    if ($ext === '') {
        jsonResponse(['error' => 'Formato no permitido. Usa .csv o .xlsx'], 400);
    }

    // Nombre de referencia: fecha_hora
    $id = date('Y-m-d_H-i-s');
    $storedName = $id . '.' . $ext;

    $destPath = $UPLOAD_DIR . '/' . $storedName;
    if (!move_uploaded_file((string)$f['tmp_name'], $destPath)) {
        jsonResponse(['error' => 'No se pudo guardar el archivo'], 500);
    }

    // Convertir a JSON y guardarlo
    try {
        $importer = new Importer();
        $data = $importer->import($destPath, $storedName);

        $jsonPath = $JSON_DIR . '/' . $id . '.json';
        file_put_contents($jsonPath, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        // redirigir para ver tabla actualizada
        header('Location: ./index.php');
        exit;
    } catch (Throwable $e) {
        jsonResponse(['error' => $e->getMessage()], 500);
    }
}

$items = listFiles($UPLOAD_DIR, $JSON_DIR);
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>endpoint - Upload a JSON</title>
  <style>
    body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial; margin:32px;}
    .wrap{max-width:1100px;}
    .card{background:#fff;border:1px solid #e7e7e7;border-radius:14px;padding:18px;box-shadow:0 1px 10px rgba(0,0,0,.04)}
    .row{display:flex;gap:12px;align-items:center;flex-wrap:wrap}
    input,button{font-size:16px;padding:10px 12px}
    button{cursor:pointer}
    table{width:100%;border-collapse:collapse;margin-top:14px}
    th,td{border-bottom:1px solid #eee;padding:10px;text-align:left;font-size:14px}
    th{background:#fafafa}
    .pill{display:inline-block;padding:4px 10px;border-radius:999px;background:#f2f2f2;font-size:12px}
    .btn{border:1px solid #ddd;background:#fff;border-radius:10px;padding:8px 10px}
    .btn:hover{background:#f7f7f7}
    .muted{color:#666;font-size:13px}
    /* Modal */
    .modal-backdrop{position:fixed;inset:0;background:rgba(0,0,0,.55);display:none;align-items:center;justify-content:center;padding:18px}
    .modal{width:min(980px,100%);max-height:86vh;background:#0b1020;color:#e6edf3;border-radius:14px;overflow:hidden;display:flex;flex-direction:column}
    .modal-head{display:flex;justify-content:space-between;align-items:center;padding:12px 14px;background:#0f1733;border-bottom:1px solid rgba(255,255,255,.08)}
    .modal-body{padding:14px;overflow:auto}
    pre{white-space:pre-wrap;word-break:break-word;margin:0;font-size:13px;line-height:1.35}
    .x{background:transparent;border:1px solid rgba(255,255,255,.25);color:#fff;border-radius:10px;padding:6px 10px}
    .x:hover{background:rgba(255,255,255,.08)}
  </style>
</head>
<body>
<div class="wrap">
  <div class="card">
    <h2 style="margin:0 0 8px">endpoint</h2>
    <div class="muted">Sube archivos CSV/XLSX. Se guardan con nombre fecha/hora y se convierten a JSON.</div>

    <form method="POST" enctype="multipart/form-data" style="margin-top:14px">
      <div class="row">
        <input type="file" name="file" accept=".csv,.xlsx" required />
        <button type="submit">Subir y convertir</button>
      </div>
    </form>

    <h3 style="margin:18px 0 8px">Archivos cargados</h3>

    <table>
      <thead>
        <tr>
          <th>Referencia (ID)</th>
          <th>Archivo</th>
          <th>Tipo</th>
          <th>Fecha carga</th>
          <th>Tamaño</th>
          <th>JSON</th>
          <th>Acción</th>
        </tr>
      </thead>
      <tbody>
      <?php if (count($items) === 0): ?>
        <tr><td colspan="7" class="muted">No hay archivos aún.</td></tr>
      <?php else: ?>
        <?php foreach ($items as $it): ?>
          <tr>
            <td><span class="pill"><?= h($it['id']) ?></span></td>
            <td><?= h($it['file']) ?></td>
            <td><?= h(strtoupper($it['ext'])) ?></td>
            <td><?= h($it['uploaded_at']) ?></td>
            <td><?= h(number_format($it['size']/1024, 1)) ?> KB</td>
            <td><?= $it['has_json'] ? '✅' : '❌' ?></td>
            <td>
              <?php if ($it['has_json']): ?>
                <button class="btn" data-view-id="<?= h($it['id']) ?>">Ver</button>
              <?php else: ?>
                <span class="muted">—</span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Modal -->
<div class="modal-backdrop" id="modalBackdrop" role="dialog" aria-modal="true">
  <div class="modal">
    <div class="modal-head">
      <div>
        <div style="font-weight:700" id="modalTitle">JSON</div>
        <div class="muted" id="modalSub">—</div>
      </div>
      <button class="x" id="modalClose">Cerrar</button>
    </div>
    <div class="modal-body">
      <pre id="modalPre">Cargando...</pre>
    </div>
  </div>
</div>

<script>
  const backdrop = document.getElementById('modalBackdrop');
  const pre = document.getElementById('modalPre');
  const title = document.getElementById('modalTitle');
  const sub = document.getElementById('modalSub');
  const closeBtn = document.getElementById('modalClose');

  function openModal() {
    backdrop.style.display = 'flex';
  }
  function closeModal() {
    backdrop.style.display = 'none';
    pre.textContent = '';
  }

  closeBtn.addEventListener('click', closeModal);
  backdrop.addEventListener('click', (e) => {
    if (e.target === backdrop) closeModal();
  });

  document.querySelectorAll('button[data-view-id]').forEach(btn => {
    btn.addEventListener('click', async () => {
      const id = btn.getAttribute('data-view-id');
      title.textContent = 'JSON';
      sub.textContent = id;
      pre.textContent = 'Cargando...';
      openModal();

      try {
        const res = await fetch(`./index.php?action=view&id=${encodeURIComponent(id)}`);
        const text = await res.text();
        if (!res.ok) {
          pre.textContent = 'Error: ' + text;
          return;
        }
        // formatear bonito si viene válido
        try {
          const obj = JSON.parse(text);
          pre.textContent = JSON.stringify(obj, null, 2);
        } catch {
          pre.textContent = text;
        }
      } catch (err) {
        pre.textContent = 'Error de red: ' + err;
      }
    });
  });
</script>
</body>
</html>
