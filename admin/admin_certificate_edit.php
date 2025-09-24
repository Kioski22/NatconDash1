<?php
// filepath: c:\xampp\htdocs\NatconDash1\admin\admin_certificate_edit.php

session_start();
require_once __DIR__ . '/../db.php';

if (isset($conn) && $conn instanceof mysqli) { $db = $conn; }
elseif (isset($mysqli) && $mysqli instanceof mysqli) { $db = $mysqli; }
else { http_response_code(500); echo "Database connection not available."; exit; }

if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') { header('Location: admin_login.php'); exit; }
if (empty($_SESSION['csrf_token'])) { $_SESSION['csrf_token'] = bin2hex(random_bytes(16)); }

function e($s){ return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function asset_url($path){
    $path = (string)$path;
    if ($path === '') return '';
    if (preg_match('#^https?://#i', $path)) return $path;
    $base = rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'])), '/\\');
    if ($base === '' || $base === '.') $base = '';
    return $base . '/' . ltrim($path, '/');
}
function ensure_dir($dir){ if (!is_dir($dir)) mkdir($dir, 0755, true); }
function handle_image_upload(string $field){
    if (empty($_FILES[$field]) || $_FILES[$field]['error'] === UPLOAD_ERR_NO_FILE) return null;
    $f = $_FILES[$field];
    if ($f['error'] !== UPLOAD_ERR_OK) return ['error' => 'Upload error for '.$field];
    if ($f['size'] > 5 * 1024 * 1024) return ['error' => 'Image too large (max 5MB).'];
    $allowed = ['image/png'=>'png','image/jpeg'=>'jpg','image/jpg'=>'jpg'];
    $finfo = finfo_open(FILEINFO_MIME_TYPE); $mime = finfo_file($finfo, $f['tmp_name']); finfo_close($finfo);
    if (!isset($allowed[$mime])) return ['error' => 'Invalid image type.'];
    $uploadsDir = __DIR__ . '/../uploads/certificates'; ensure_dir($uploadsDir);
    $filename = bin2hex(random_bytes(12)) . '.' . $allowed[$mime];
    $dest = $uploadsDir . '/' . $filename;
    if (!move_uploaded_file($f['tmp_name'], $dest)) return ['error' => 'Failed to store file.'];
    return 'uploads/certificates/' . $filename;
}
// for inputs like <input name="new_sig_file[]" type="file">
function handle_image_upload_indexed(string $field, $key){
    if (empty($_FILES[$field]) || !isset($_FILES[$field]['name'][$key])) return null;
    $err = $_FILES[$field]['error'][$key] ?? UPLOAD_ERR_NO_FILE;
    if ($err === UPLOAD_ERR_NO_FILE) return null;
    if ($err !== UPLOAD_ERR_OK) return ['error' => 'Upload error.'];
    $tmp = $_FILES[$field]['tmp_name'][$key];
    $size = $_FILES[$field]['size'][$key];
    if ($size > 5 * 1024 * 1024) return ['error' => 'Image too large (max 5MB).'];
    $allowed = ['image/png'=>'png','image/jpeg'=>'jpg','image/jpg'=>'jpg'];
    $finfo = finfo_open(FILEINFO_MIME_TYPE); $mime = finfo_file($finfo, $tmp); finfo_close($finfo);
    if (!isset($allowed[$mime])) return ['error' => 'Invalid image type.'];
    $uploadsDir = __DIR__ . '/../uploads/certificates'; ensure_dir($uploadsDir);
    $filename = bin2hex(random_bytes(12)) . '.' . $allowed[$mime];
    $dest = $uploadsDir . '/' . $filename;
    if (!move_uploaded_file($tmp, $dest)) return ['error' => 'Failed to store file.'];
    return 'uploads/certificates/' . $filename;
}

// --- NEW: auto background removal helpers for signatures ---
function remove_bg_with_imagick(string $absSrc, string $absDst): bool {
    if (!class_exists('Imagick')) return false;
    try {
        $img = new Imagick($absSrc);
        $img->setImageAlphaChannel(Imagick::ALPHACHANNEL_ACTIVATE);
        // Use WHITE as background target with higher fuzz (18%)
        $bg = new ImagickPixel('white');
        $quantum = $img->getQuantumRange();
        $range = (int)($quantum['quantumRangeLong'] ?? 65535);
        $fuzz = (int)round($range * 0.18);
        $img->transparentPaintImage($bg, 0.0, $fuzz, false);
        // Trim leftover edges
        if (method_exists($img, 'trimImage')) { @$img->trimImage($fuzz); }
        $img->setImageFormat('png');
        $ok = $img->writeImage($absDst);
        $img->destroy();
        return (bool)$ok;
    } catch (Throwable $e) {
        return false;
    }
}

function remove_bg_with_gd(string $absSrc, string $absDst, int $tol = 38): bool {
    if (!function_exists('imagecreatefromstring')) return false;
    $data = @file_get_contents($absSrc);
    if ($data === false) return false;
    $src = @imagecreatefromstring($data);
    if (!$src) return false;

    $w = imagesx($src); $h = imagesy($src);
    $dst = imagecreatetruecolor($w, $h);
    imagealphablending($dst, false);
    imagesavealpha($dst, true);
    $transparent = imagecolorallocatealpha($dst, 0, 0, 0, 127);
    imagefilledrectangle($dst, 0, 0, $w, $h, $transparent);

    // background reference = top-left pixel
    $rgb0 = imagecolorat($src, 0, 0);
    $rb = ($rgb0 >> 16) & 0xFF; $gb = ($rgb0 >> 8) & 0xFF; $bb = $rgb0 & 0xFF;

    for ($y=0; $y<$h; $y++) {
        for ($x=0; $x<$w; $x++) {
            $rgb = imagecolorat($src, $x, $y);
            $r = ($rgb >> 16) & 0xFF; $g = ($rgb >> 8) & 0xFF; $b = $rgb & 0xFF;

            // near background or near-white becomes transparent
            $diff = abs($r-$rb) + abs($g-$gb) + abs($b-$bb);
            $nearWhite = ($r >= 255-$tol) && ($g >= 255-$tol) && ($b >= 255-$tol);

            if ($diff <= ($tol*3) || $nearWhite) {
                imagesetpixel($dst, $x, $y, $transparent);
            } else {
                $col = imagecolorallocatealpha($dst, $r, $g, $b, 0);
                imagesetpixel($dst, $x, $y, $col);
            }
        }
    }

    $ok = imagepng($dst, $absDst, 6);
    imagedestroy($src);
    imagedestroy($dst);
    return (bool)$ok;
}

function process_signature_background(string $relPath): string {
    // convert saved file to PNG with transparent background
    $abs = __DIR__ . '/../' . str_replace('\\', '/', $relPath);
    $pi = pathinfo($abs);
    $absPng = $pi['dirname'] . '/' . $pi['filename'] . '.png';
    $relPng = dirname($relPath) . '/' . $pi['filename'] . '.png';

    // try Imagick then GD
    $ok = remove_bg_with_imagick($abs, $absPng);
    if (!$ok) $ok = remove_bg_with_gd($abs, $absPng, 38);

    if ($ok) {
        // remove original if different extension
        if (strtolower($pi['extension'] ?? '') !== 'png' && file_exists($abs)) @unlink($abs);
        return str_replace('\\', '/', $relPng);
    }
    // fallback: keep original
    return $relPath;
}

function handle_signature_upload(string $field){
    $rel = handle_image_upload($field);
    if (!is_string($rel)) return $rel;
    return process_signature_background($rel);
}

function handle_signature_upload_indexed(string $field, $key){
    $rel = handle_image_upload_indexed($field, $key);
    if (!is_string($rel)) return $rel;
    return process_signature_background($rel);
}
// --- end new helpers ---

$errors = []; $success = '';
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// Step 1: Create template (bg + placeholders; upload signatures here)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_template') {
    if (!hash_equals($_SESSION['csrf_token'], (string)($_POST['csrf_token'] ?? ''))) { $errors[] = 'Invalid CSRF token.'; }
    else {
        $type = substr(trim((string)($_POST['type'] ?? 'attendance')), 0, 50);
        $cert_number_format = trim((string)($_POST['cert_number_format'] ?? 'NC-{YYYY}-{SEQ4}'));
        $cert_awardee_name  = trim((string)($_POST['awardee_placeholder'] ?? '{USER_NAME}'));
        $seminar_title      = trim((string)($_POST['seminar_placeholder'] ?? '{SEMINAR_TITLE}'));

        $bg_url = handle_image_upload('bg_upload');
        if (is_array($bg_url) && isset($bg_url['error'])) $errors[] = $bg_url['error'];
        if (!is_string($bg_url)) $errors[] = 'Background image is required.';

        if (empty($errors)) {
            $bg = is_string($bg_url) ? $bg_url : '';

            $user_id = null;
            $speaker_id = null;

            $sql = "INSERT INTO certificates
                (user_id, type, speaker_id, file_path, issued_at, created_at,
                 bg_url, cert_awardee_name, seminar_title, cert_number_format,
                 pos_signatory_name_x, pos_signatory_name_y,
                 pos_cert_awardee_name_x, pos_cert_awardee_name_y,
                 pos_cert_number_x, pos_cert_number_y,
                 pos_seminar_title_x, pos_seminar_title_y,
                 show_awardee, show_seminar, show_number)
                VALUES (?, ?, ?, '', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP,
                        ?, ?, ?, ?,
                        0,0, 0,0, 0,0, 0,0,
                        1,1,1)";
            $st = $db->prepare($sql);
            if ($st) {
                $st->bind_param(
                    'isissss',
                    $user_id, $type, $speaker_id,
                    $bg, $cert_awardee_name, $seminar_title, $cert_number_format
                );
                if ($st->execute()) {
                    $newId = $st->insert_id;
                    $st->close();

                    // Insert pre-uploaded signatures
                    $inds = $_POST['new_sig_indicator'] ?? [];
                    if (!is_array($inds)) $inds = [$inds];
                    foreach ($inds as $i => $indicator) {
                        $indicator = substr(trim((string)$indicator), 0, 100);
                        if ($indicator === '') continue;
                        $sigUrl = handle_signature_upload_indexed('new_sig_file', $i);
                        if (is_string($sigUrl)) {
                            $ins = $db->prepare("INSERT INTO certificate_signatures (certificate_id, indicator, signature_url, pos_x, pos_y, enabled, width_pct) VALUES (?, ?, ?, 0, 0, 1, 25.00)");
                            if ($ins) { $ins->bind_param('iss', $newId, $indicator, $sigUrl); $ins->execute(); $ins->close(); }
                        }
                    }

                    header('Location: admin_certificate_edit.php?id='.$newId);
                    exit;
                } else {
                    $errors[] = 'Failed to create template.';
                    $st->close();
                }
            } else {
                $errors[] = 'Database error.';
            }
        }
    }
}

// Step 2: Save layout (positions, toggles, background, and signatures CRUD)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_layout' && $id > 0) {
    if (!hash_equals($_SESSION['csrf_token'], (string)($_POST['csrf_token'] ?? ''))) { $errors[] = 'Invalid CSRF token.'; }
    else {
        $cert_number_format = trim((string)($_POST['cert_number_format'] ?? ''));
        $cert_awardee_name  = trim((string)($_POST['awardee_placeholder'] ?? ''));
        $seminar_title      = trim((string)($_POST['seminar_placeholder'] ?? ''));

        $pos_cert_awardee_name_x= (float)($_POST['pos_cert_awardee_name_x'] ?? 0);
        $pos_cert_awardee_name_y= (float)($_POST['pos_cert_awardee_name_y'] ?? 0);
        $pos_cert_number_x      = (float)($_POST['pos_cert_number_x'] ?? 0);
        $pos_cert_number_y      = (float)($_POST['pos_cert_number_y'] ?? 0);
        $pos_seminar_title_x    = (float)($_POST['pos_seminar_title_x'] ?? 0);
        $pos_seminar_title_y    = (float)($_POST['pos_seminar_title_y'] ?? 0);

        $show_awardee = isset($_POST['show_awardee']) ? 1 : 0;
        $show_seminar = isset($_POST['show_seminar']) ? 1 : 0;
        $show_number  = isset($_POST['show_number'])  ? 1 : 0;

        $new_bg = handle_image_upload('bg_upload');
        if (is_array($new_bg) && isset($new_bg['error'])) $errors[] = $new_bg['error'];

        $bg_url = '';
        $curr = $db->prepare("SELECT bg_url FROM certificates WHERE id = ? LIMIT 1");
        if ($curr) { $curr->bind_param('i', $id); $curr->execute(); $curr->bind_result($bg_url); $curr->fetch(); $curr->close(); }
        if (is_string($new_bg)) $bg_url = $new_bg;

        if (empty($errors)) {
            $sql = "UPDATE certificates SET
                bg_url = ?, cert_awardee_name = ?, seminar_title = ?, cert_number_format = ?,
                pos_cert_awardee_name_x = ?, pos_cert_awardee_name_y = ?,
                pos_cert_number_x = ?, pos_cert_number_y = ?,
                pos_seminar_title_x = ?, pos_seminar_title_y = ?,
                show_awardee = ?, show_seminar = ?, show_number = ?
                WHERE id = ?";
            $st = $db->prepare($sql);
            if ($st) {
                $st->bind_param(
                    'ssssddddddiiii',
                    $bg_url, $cert_awardee_name, $seminar_title, $cert_number_format,
                    $pos_cert_awardee_name_x, $pos_cert_awardee_name_y,
                    $pos_cert_number_x, $pos_cert_number_y,
                    $pos_seminar_title_x, $pos_seminar_title_y,
                    $show_awardee, $show_seminar, $show_number,
                    $id
                );
                if ($st->execute()) {
                    $success = 'Certificate layout saved.';
                } else {
                    $errors[] = 'Update failed.';
                }
                $st->close();
            } else {
                $errors[] = 'Database error.';
            }

            // Signatures: update/remove/enable
            $sigIndicators = $_POST['sig_indicator'] ?? [];
            $sigPosX = $_POST['sig_pos_x'] ?? [];
            $sigPosY = $_POST['sig_pos_y'] ?? [];
            $sigRemove = $_POST['sig_remove'] ?? [];
            $sigEnabled = $_POST['sig_enabled'] ?? [];
            $sigWidth = $_POST['sig_width'] ?? [];

            if (is_array($sigIndicators)) {
                foreach ($sigIndicators as $sigId => $indicator) {
                    $sigId = (int)$sigId;
                    if ($sigId <= 0) continue;

                    if (isset($sigRemove[$sigId])) {
                        $del = $db->prepare("DELETE FROM certificate_signatures WHERE id = ? AND certificate_id = ?");
                        if ($del) { $del->bind_param('ii', $sigId, $id); $del->execute(); $del->close(); }
                        continue;
                    }

                    $indicator = substr(trim((string)$indicator), 0, 100);
                    $px = isset($sigPosX[$sigId]) ? (float)$sigPosX[$sigId] : 0.0;
                    $py = isset($sigPosY[$sigId]) ? (float)$sigPosY[$sigId] : 0.0;
                    $enabled = isset($sigEnabled[$sigId]) ? 1 : 0;
                    $widthPct = isset($sigWidth[$sigId]) ? max(5.0, min(100.0, (float)$sigWidth[$sigId])) : 25.0;

                    $newSigUrl = handle_signature_upload_indexed('sig_file', $sigId);
                    if (is_array($newSigUrl) && isset($newSigUrl['error'])) { $errors[] = $newSigUrl['error']; continue; }

                    if (is_string($newSigUrl)) {
                        $upd = $db->prepare("UPDATE certificate_signatures SET indicator = ?, signature_url = ?, pos_x = ?, pos_y = ?, width_pct = ?, enabled = ? WHERE id = ? AND certificate_id = ?");
                        if ($upd) { $upd->bind_param('ssdddiii', $indicator, $newSigUrl, $px, $py, $widthPct, $enabled, $sigId, $id); $upd->execute(); $upd->close(); }
                    } else {
                        $upd = $db->prepare("UPDATE certificate_signatures SET indicator = ?, pos_x = ?, pos_y = ?, width_pct = ?, enabled = ? WHERE id = ? AND certificate_id = ?");
                        if ($upd) { $upd->bind_param('sdddiii', $indicator, $px, $py, $widthPct, $enabled, $sigId, $id); $upd->execute(); $upd->close(); }
                    }
                }
            }
        }
    }
}

// Load certificate + signatures
$row = null; $sigs = [];
if ($id > 0) {
    $st = $db->prepare("SELECT id, type, bg_url, cert_awardee_name, seminar_title, cert_number_format,
        pos_cert_awardee_name_x, pos_cert_awardee_name_y, pos_cert_number_x, pos_cert_number_y, pos_seminar_title_x, pos_seminar_title_y,
        show_awardee, show_seminar, show_number
        FROM certificates WHERE id = ? LIMIT 1");
    if ($st) { $st->bind_param('i', $id); $st->execute(); $res = $st->get_result(); $row = $res->fetch_assoc(); $st->close(); }

    $ss = $db->prepare("SELECT id, indicator, signature_url, pos_x, pos_y, enabled, width_pct FROM certificate_signatures WHERE certificate_id = ? ORDER BY id ASC");
    if ($ss) { $ss->bind_param('i', $id); $ss->execute(); $rs = $ss->get_result(); $sigs = $rs->fetch_all(MYSQLI_ASSOC); $ss->close(); }
}

$show_awardee = isset($row['show_awardee']) ? (int)$row['show_awardee'] : 1;
$show_seminar = isset($row['show_seminar']) ? (int)$row['show_seminar'] : 1;
$show_number  = isset($row['show_number'])  ? (int)$row['show_number']  : 1;
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title><?= $id>0 ? 'Layout Certificate #'.(int)$id : 'Create Certificate Template' ?></title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
body{background:#f8f9fa}
.editor-wrap{display:flex;gap:18px}
.canvas{position:relative;width:100%;max-width:960px;background:#fff;border:1px solid #e5e7eb;border-radius:8px;overflow:hidden}
.bg-holder{width:100%;display:block}
.overlay{position:absolute;inset:0;pointer-events:auto;z-index:2}  /* was: pointer-events:none */
.draggable{position:absolute;cursor:move;pointer-events:auto;user-select:none}
.ph{background:rgba(13,110,253,.08);border:1px dashed #0d6efd;border-radius:6px;padding:6px 10px;color:#212529}
.ph.sig{background:transparent;border:1px dashed #6c757d;padding:0}
.side{width:460px;max-width:100%}
.label-sm{font-size:.85rem;color:#6c757d}
.num{width:100px}
.sig-row{border-bottom:1px solid #eee;padding-bottom:10px;margin-bottom:10px}
.sig-thumb{max-width:180px;max-height:70px;display:block}
</style>
</head>
<body class="p-3">
<div class="container-fluid">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="mb-0"><?= $id>0 ? 'Drag & Drop Layout' : 'New Certificate Template' ?></h4>
    <a href="admin_certificate_settings.php" class="btn btn-outline-secondary btn-sm">Back</a>
  </div>

  <?php if (!empty($errors)): ?>
    <div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul></div>
  <?php endif; ?>
  <?php if ($success): ?>
    <div class="alert alert-success"><?= e($success) ?></div>
  <?php endif; ?>

  <?php if ($id <= 0): ?>
    <div class="card">
      <div class="card-body">
        <form method="post" enctype="multipart/form-data" class="row g-3">
          <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
          <input type="hidden" name="action" value="create_template">
          <div class="col-md-3">
            <label class="form-label">Type</label>
            <input type="text" class="form-control" name="type" value="attendance" placeholder="e.g. attendance" required>
          </div>
          <div class="col-md-9">
            <label class="form-label">Background Image</label>
            <input type="file" class="form-control" name="bg_upload" accept="image/png,image/jpeg" required>
          </div>

          <div class="col-md-6">
            <label class="form-label">Awardee Placeholder</label>
            <input type="text" class="form-control" name="awardee_placeholder" value="{USER_NAME}">
          </div>
          <div class="col-md-6">
            <label class="form-label">Seminar Title Placeholder</label>
            <input type="text" class="form-control" name="seminar_placeholder" value="{SEMINAR_TITLE}">
          </div>
          <div class="col-md-6">
            <label class="form-label">Certificate Number Format</label>
            <input type="text" class="form-control" name="cert_number_format" value="NC-{YYYY}-{SEQ4}">
            <div class="label-sm mt-1">Tokens: {YYYY}, {MM}, {DD}, {SEQ4}, {USER_ID}</div>
          </div>

          <div class="col-12">
            <div class="card mt-2">
              <div class="card-header d-flex justify-content-between align-items-center">
                <span>Pre-uploaded Signatures</span>
                <button type="button" class="btn btn-sm btn-outline-primary" id="addSigBtn">+ Add another</button>
              </div>
              <div class="card-body" id="sigUploadList">
                <div class="row g-2 sig-upload-item">
                  <div class="col-sm-4">
                    <input type="text" class="form-control form-control-sm" name="new_sig_indicator[]" placeholder="Indicator (e.g. Chair)">
                  </div>
                  <div class="col-sm-6">
                    <input type="file" class="form-control form-control-sm" name="new_sig_file[]" accept="image/png,image/jpeg">
                  </div>
                  <div class="col-sm-2 d-grid">
                    <button type="button" class="btn btn-sm btn-outline-danger removeSigBtn">Remove</button>
                  </div>
                </div>
              </div>
              <div class="label-sm px-3 pb-2">You can drag and position them on the next screen.</div>
            </div>
          </div>

          <div class="col-12 d-flex gap-2">
            <button class="btn btn-primary">Create & Configure Layout</button>
          </div>
        </form>
      </div>
    </div>
  <?php else: ?>
    <form method="post" enctype="multipart/form-data" id="editorForm" novalidate autocomplete="off">
      <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
      <input type="hidden" name="action" value="save_layout">
      <div class="editor-wrap">
        <div class="canvas">
          <img id="bgImage" class="bg-holder" src="<?= e($row['bg_url'] ? asset_url($row['bg_url']) : '') ?>" alt="Background">
          <div id="overlay" class="overlay">
            <div id="ph-awardee" class="draggable ph" style="left:0;top:0;<?= $show_awardee? '' : 'display:none;' ?>"><?= e($row['cert_awardee_name'] ?: '{USER_NAME}') ?></div>
            <div id="ph-seminar" class="draggable ph" style="left:0;top:0;<?= $show_seminar? '' : 'display:none;' ?>"><?= e($row['seminar_title'] ?: '{SEMINAR_TITLE}') ?></div>
            <div id="ph-number" class="draggable ph" style="left:0;top:0;<?= $show_number? '' : 'display:none;' ?>"><?= e($row['cert_number_format'] ?: 'NC-{YYYY}-{SEQ4}') ?></div>

            <?php foreach ($sigs as $sg): ?>
              <?php $sid = (int)$sg['id']; $enabled = isset($sg['enabled']) ? (int)$sg['enabled'] : 1; ?>
              <?php if (!empty($sg['signature_url'])): ?>
                <img
                  id="sig-<?= $sid ?>"
                  class="draggable ph sig sig-node"
                  data-id="<?= $sid ?>"
                  src="<?= e(asset_url($sg['signature_url'])) ?>"
                  alt="<?= e($sg['indicator']) ?>"
                  style="left:0;top:0;width:<?= (float)($sg['width_pct'] ?? 25) ?>%;height:auto;max-width:none;<?= $enabled? '' : 'display:none;' ?>">
              <?php else: ?>
                <div id="sig-<?= $sid ?>" class="draggable ph sig sig-node" data-id="<?= $sid ?>" style="left:0;top:0;width:<?= (float)($sg['width_pct'] ?? 25) ?>%;height:60px;display:flex;align-items:center;justify-content:center;<?= $enabled? '' : 'display:none;' ?>">
                  <?= e($sg['indicator'] ?: '{SIGNATURE_URL}') ?>
                </div>
              <?php endif; ?>
            <?php endforeach; ?>
          </div>
        </div>

        <div class="side">
          <div class="card mb-3">
            <div class="card-header">Assets</div>
            <div class="card-body">
              <div class="mb-2">
                <label class="form-label">Background Image</label>
                <input type="file" class="form-control" name="bg_upload" accept="image/png,image/jpeg">
              </div>
            </div>
          </div>

          <div class="card mb-3">
            <div class="card-header d-flex justify-content-between align-items-center">
              <span>Placeholders</span>
              <span class="label-sm">Toggle visibility</span>
            </div>
            <div class="card-body">
              <div class="form-check form-switch mb-2">
                <input class="form-check-input" type="checkbox" id="toggle-awardee" name="show_awardee" <?= $show_awardee ? 'checked' : '' ?>>
                <label class="form-check-label" for="toggle-awardee">Show Awardee</label>
              </div>
              <div class="form-check form-switch mb-2">
                <input class="form-check-input" type="checkbox" id="toggle-seminar" name="show_seminar" <?= $show_seminar ? 'checked' : '' ?>>
                <label class="form-check-label" for="toggle-seminar">Show Seminar Title</label>
              </div>
              <div class="form-check form-switch mb-3">
                <input class="form-check-input" type="checkbox" id="toggle-number" name="show_number" <?= $show_number ? 'checked' : '' ?>>
                <label class="form-check-label" for="toggle-number">Show Certificate Number</label>
              </div>

              <div class="mb-2">
                <label class="form-label">Awardee Placeholder</label>
                <input type="text" class="form-control" name="awardee_placeholder" id="inp-awardee" value="<?= e($row['cert_awardee_name'] ?: '{USER_NAME}') ?>">
              </div>
              <div class="mb-2">
                <label class="form-label">Seminar Title Placeholder</label>
                <input type="text" class="form-control" name="seminar_placeholder" id="inp-seminar" value="<?= e($row['seminar_title'] ?: '{SEMINAR_TITLE}') ?>">
              </div>
              <div class="mb-2">
                <label class="form-label">Cert Number Format</label>
                <input type="text" class="form-control" name="cert_number_format" id="inp-number" value="<?= e($row['cert_number_format'] ?: 'NC-{YYYY}-{SEQ4}') ?>">
              </div>
            </div>
          </div>

          <div class="card mb-3">
            <div class="card-header d-flex justify-content-between">
              <span>Positions (%)</span>
              <span class="label-sm">Top/Left as % of image</span>
            </div>
            <div class="card-body">
              <div class="mb-2 d-flex align-items-center gap-2">
                <span class="label-sm">Awardee</span>
                <input type="number" step="any" class="form-control form-control-sm num" name="pos_cert_awardee_name_x" id="pos-awardee-x" value="<?= (float)($row['pos_cert_awardee_name_x'] ?? 0) ?>">
                <input type="number" step="any" class="form-control form-control-sm num" name="pos_cert_awardee_name_y" id="pos-awardee-y" value="<?= (float)($row['pos_cert_awardee_name_y'] ?? 0) ?>">
              </div>
              <div class="mb-2 d-flex align-items-center gap-2">
                <span class="label-sm">Seminar Title</span>
                <input type="number" step="any" class="form-control form-control-sm num" name="pos_seminar_title_x" id="pos-seminar-x" value="<?= (float)($row['pos_seminar_title_x'] ?? 0) ?>">
                <input type="number" step="any" class="form-control form-control-sm num" name="pos_seminar_title_y" id="pos-seminar-y" value="<?= (float)($row['pos_seminar_title_y'] ?? 0) ?>">
              </div>
              <div class="mb-2 d-flex align-items-center gap-2">
                <span class="label-sm">Cert Number</span>
                <input type="number" step="any" class="form-control form-control-sm num" name="pos_cert_number_x" id="pos-number-x" value="<?= (float)($row['pos_cert_number_x'] ?? 0) ?>">
                <input type="number" step="any" class="form-control form-control-sm num" name="pos_cert_number_y" id="pos-number-y" value="<?= (float)($row['pos_cert_number_y'] ?? 0) ?>">
              </div>
              <div class="label-sm mt-3 mb-1">Signatures are toggled and positioned per row below.</div>
            </div>
          </div>

          <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
              <span>Signatures</span>
              <span class="label-sm">Toggle visibility per signature</span>
            </div>
            <div class="card-body">
              <?php foreach ($sigs as $sg): $sid=(int)$sg['id']; $enabled=(int)($sg['enabled'] ?? 1); ?>
                <div class="sig-row">
                  <div class="form-check form-switch mb-2">
                    <input class="form-check-input sig-enabled-toggle" type="checkbox" id="sig-enabled-<?= $sid ?>" name="sig_enabled[<?= $sid ?>]" data-id="<?= $sid ?>" <?= $enabled ? 'checked' : '' ?>>
                    <label class="form-check-label" for="sig-enabled-<?= $sid ?>">Enabled (<?= e($sg['indicator']) ?>)</label>
                  </div>
                  <div class="mb-1">
                    <label class="form-label">Indicator</label>
                    <input type="text" class="form-control form-control-sm" name="sig_indicator[<?= $sid ?>]" value="<?= e($sg['indicator']) ?>">
                  </div>
                  <div class="mb-1">
                    <label class="form-label">Replace Image</label>
                    <input type="file" class="form-control form-control-sm" name="sig_file[<?= $sid ?>]" accept="image/png,image/jpeg">
                    <?php if (!empty($sg['signature_url'])): ?>
                      <img class="sig-thumb mt-1" src="<?= e(asset_url($sg['signature_url'])) ?>" alt="">
                    <?php else: ?>
                      <div class="label-sm mt-1">No image uploaded (using placeholder box)</div>
                    <?php endif; ?>
                  </div>
                  <div class="mb-2">
                    <label class="form-label">Width: <span id="sig-width-label-<?= $sid ?>"><?= (float)($sg['width_pct'] ?? 25) ?></span>%</label>
                    <input type="range" min="5" max="100" step="1" class="form-range" name="sig_width[<?= $sid ?>]" id="sig-width-<?= $sid ?>" value="<?= (float)($sg['width_pct'] ?? 25) ?>">
                  </div>
                  <input type="hidden" name="sig_pos_x[<?= $sid ?>]" id="sig-pos-x-<?= $sid ?>" value="<?= (float)$sg['pos_x'] ?>">
                  <input type="hidden" name="sig_pos_y[<?= $sid ?>]" id="sig-pos-y-<?= $sid ?>" value="<?= (float)$sg['pos_y'] ?>">
                  <div class="form-check mt-1">
                    <input class="form-check-input" type="checkbox" name="sig_remove[<?= $sid ?>]" id="sig-remove-<?= $sid ?>" value="1">
                    <label class="form-check-label" for="sig-remove-<?= $sid ?>">Remove this signature</label>
                  </div>
                </div>
              <?php endforeach; ?>

              <div class="d-flex gap-2 mt-3">
                <button class="btn btn-success w-100" formnovalidate>Save Layout</button>
                <button type="button" id="resetBtn" class="btn btn-outline-secondary">Reset positions</button>
              </div>
            </div>
          </div>

        </div>
      </div>
    </form>
  <?php endif; ?>
</div>

<script>
(function(){
  var id = <?= (int)$id ?>;

  // Step 1: add/remove rows for pre-upload signatures
  var addBtn = document.getElementById('addSigBtn');
  var list = document.getElementById('sigUploadList');
  if (addBtn && list) {
    addBtn.addEventListener('click', function(){
      var div = document.createElement('div');
      div.className = 'row g-2 sig-upload-item';
      div.innerHTML = `
        <div class="col-sm-4">
          <input type="text" class="form-control form-control-sm" name="new_sig_indicator[]" placeholder="Indicator (e.g. Chair)">
        </div>
        <div class="col-sm-6">
          <input type="file" class="form-control form-control-sm" name="new_sig_file[]" accept="image/png,image/jpeg">
        </div>
        <div class="col-sm-2 d-grid">
          <button type="button" class="btn btn-sm btn-outline-danger removeSigBtn">Remove</button>
        </div>`;
      list.appendChild(div);
    });
    list.addEventListener('click', function(e){
      if (e.target && e.target.classList.contains('removeSigBtn')) {
        var row = e.target.closest('.sig-upload-item');
        if (row) row.remove();
      }
    });
  }

  if (!id) return;

  var bg = document.getElementById('bgImage');
  var phAwardee = document.getElementById('ph-awardee');
  var phSeminar = document.getElementById('ph-seminar');
  var phNumber = document.getElementById('ph-number');

  var inpAwardee = document.getElementById('inp-awardee');
  var inpSeminar = document.getElementById('inp-seminar');
  var inpNumber = document.getElementById('inp-number');

  function clamp(v,min,max){return Math.max(min,Math.min(max,v))}
  function place(el,xPct,yPct,W,H){el.style.left=(xPct/100*W)+'px';el.style.top=(yPct/100*H)+'px'}

  function placeStatic(){
    var W=bg.clientWidth,H=bg.clientHeight;
    var ax=parseFloat(document.getElementById('pos-awardee-x').value||0), ay=parseFloat(document.getElementById('pos-awardee-y').value||0);
    var sx=parseFloat(document.getElementById('pos-seminar-x').value||0), sy=parseFloat(document.getElementById('pos-seminar-y').value||0);
    var nx=parseFloat(document.getElementById('pos-number-x').value||0), ny=parseFloat(document.getElementById('pos-number-y').value||0);
    place(phAwardee,ax,ay,W,H);
    place(phSeminar,sx,sy,W,H);
    place(phNumber,nx,ny,W,H);
  }

  function syncStaticInputs(ax,ay,sx,sy,nx,ny){
    if (ax!=null) document.getElementById('pos-awardee-x').value=ax.toFixed(2);
    if (ay!=null) document.getElementById('pos-awardee-y').value=ay.toFixed(2);
    if (sx!=null) document.getElementById('pos-seminar-x').value=sx.toFixed(2);
    if (sy!=null) document.getElementById('pos-seminar-y').value=sy.toFixed(2);
    if (nx!=null) document.getElementById('pos-number-x').value=nx.toFixed(2);
    if (ny!=null) document.getElementById('pos-number-y').value=ny.toFixed(2);
  }

  function makeDraggable(el, getSet){
    var dragging=false,startX=0,startY=0,baseLeft=0,baseTop=0;
    el.addEventListener('mousedown',function(e){e.preventDefault();dragging=true;startX=e.clientX;startY=e.clientY;baseLeft=parseFloat(el.style.left||'0');baseTop=parseFloat(el.style.top||'0');document.body.style.userSelect='none';});
    window.addEventListener('mousemove',function(e){
      if(!dragging)return;
      var dx=e.clientX-startX,dy=e.clientY-startY;
      var W=bg.clientWidth,H=bg.clientHeight;
      var newLeft=clamp(baseLeft+dx,0,W),newTop=clamp(baseTop+dy,0,H);
      el.style.left=newLeft+'px';el.style.top=newTop+'px';
      var xPct=newLeft/W*100,yPct=newTop/H*100;
      getSet.set(xPct,yPct);
    });
    window.addEventListener('mouseup',function(){dragging=false;document.body.style.userSelect='';});
  }

  // Static placeholders
  makeDraggable(phAwardee, { set: function(x,y){ syncStaticInputs(x,y,null,null,null,null); } });
  makeDraggable(phSeminar, { set: function(x,y){ syncStaticInputs(null,null,x,y,null,null); } });
  makeDraggable(phNumber,  { set: function(x,y){ syncStaticInputs(null,null,null,null,x,y); } });

  // Signature nodes (multiple)
  function placeSignatures(){
    var W=bg.clientWidth,H=bg.clientHeight;
    document.querySelectorAll('.sig-node').forEach(function(el){
      var id = el.dataset.id;
      var x = parseFloat(document.getElementById('sig-pos-x-'+id).value || 0);
      var y = parseFloat(document.getElementById('sig-pos-y-'+id).value || 0);
      var widthInput = document.getElementById('sig-width-'+id);
      if (widthInput) el.style.width = (parseFloat(widthInput.value || 25)) + '%';
      el.style.height = 'auto';
      el.style.maxWidth = 'none';
      place(el,x,y,W,H);
    });
  }

  // Hook width sliders to live update
  document.querySelectorAll('input[id^="sig-width-"]').forEach(function(inp){
    inp.addEventListener('input', function(){
      var id = this.id.replace('sig-width-','');
      var el = document.getElementById('sig-'+id);
      var lbl = document.getElementById('sig-width-label-'+id);
      if (lbl) lbl.textContent = this.value;
      if (el) { el.style.width = this.value + '%'; el.style.height='auto'; el.style.maxWidth='none'; }
    });
  });

  // Input live updates for text
  if (inpAwardee) inpAwardee.addEventListener('input',function(){ phAwardee.textContent=this.value||'{USER_NAME}'; });
  if (inpSeminar)  inpSeminar.addEventListener('input',function(){ phSeminar.textContent=this.value||'{SEMINAR_TITLE}'; });
  if (inpNumber)   inpNumber.addEventListener('input',function(){ phNumber.textContent=this.value||'NC-{YYYY}-{SEQ4}'; });

  // Toggle visibility of placeholders
  var tglAwardee = document.getElementById('toggle-awardee');
  var tglSeminar = document.getElementById('toggle-seminar');
  var tglNumber  = document.getElementById('toggle-number');
  if (tglAwardee) tglAwardee.addEventListener('change', function(){ phAwardee.style.display = this.checked ? '' : 'none'; });
  if (tglSeminar) tglSeminar.addEventListener('change', function(){ phSeminar.style.display = this.checked ? '' : 'none'; });
  if (tglNumber)  tglNumber.addEventListener('change',  function(){ phNumber.style.display  = this.checked ? '' : 'none'; });

  // Toggle visibility per signature
  document.querySelectorAll('.sig-enabled-toggle').forEach(function(chk){
    chk.addEventListener('change', function(){
      var sid = this.dataset.id;
      var el = document.getElementById('sig-'+sid);
      if (el) el.style.display = this.checked ? '' : 'none';
    });
  });

  var resetBtn=document.getElementById('resetBtn');
  if(resetBtn){
    resetBtn.addEventListener('click',function(){
      document.getElementById('pos-awardee-x').value='0';
      document.getElementById('pos-awardee-y').value='0';
      document.getElementById('pos-seminar-x').value='0';
      document.getElementById('pos-seminar-y').value='0';
      document.getElementById('pos-number-x').value='0';
      document.getElementById('pos-number-y').value='0';
      document.querySelectorAll('.sig-node').forEach(function(el){
        var id = el.dataset.id;
        document.getElementById('sig-pos-x-'+id).value='0';
        document.getElementById('sig-pos-y-'+id).value='0';
      });
      placeStatic(); placeSignatures();
    });
  }

  bg.addEventListener('load',function(){ placeStatic(); placeSignatures(); });
  window.addEventListener('resize',function(){ placeStatic(); placeSignatures(); });

  document.querySelectorAll('.sig-node').forEach(makeSigDraggable);

  function makeSigDraggable(el){
    var sid = el.dataset.id;
    makeDraggable(el, {
      set: function(x,y){
        var ix = document.getElementById('sig-pos-x-'+sid);
        var iy = document.getElementById('sig-pos-y-'+sid);
        if (ix) ix.value = x.toFixed(2);
        if (iy) iy.value = y.toFixed(2);
      }
    });
  }

  // Initial placement
  placeStatic(); placeSignatures();
})();
</script>
</body>
</html>