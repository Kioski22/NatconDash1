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
  $show_number  = isset($_POST['show_number']) ? 1 : 0;

  // Font settings
  $allowedFonts = ['Helvetica','Arial','Times New Roman','Georgia','Courier'];
  $sanitizeFont = function($f) use ($allowedFonts){ $f = trim((string)$f); return in_array($f,$allowedFonts,true)?$f:'Helvetica'; };
  $sanitizeColor = function($c){ $c = trim((string)$c); return preg_match('/^#[0-9A-Fa-f]{6}$/',$c)?strtoupper($c):'#000000'; };
  $clampSize = function($s,$def){ $n = (int)$s; if ($n<6) $n=6; if ($n>200) $n=200; return $n?:$def; };

  $font_awardee_family = $sanitizeFont($_POST['font_awardee_family'] ?? 'Helvetica');
  $font_awardee_size_px = $clampSize($_POST['font_awardee_size_px'] ?? 46,46);
  $font_awardee_color = $sanitizeColor($_POST['font_awardee_color'] ?? '#000000');
  $font_seminar_family = $sanitizeFont($_POST['font_seminar_family'] ?? 'Helvetica');
  $font_seminar_size_px = $clampSize($_POST['font_seminar_size_px'] ?? 24,24);
  $font_seminar_color = $sanitizeColor($_POST['font_seminar_color'] ?? '#000000');
  $font_number_family = $sanitizeFont($_POST['font_number_family'] ?? 'Helvetica');
  $font_number_size_px = $clampSize($_POST['font_number_size_px'] ?? 16,16);
  $font_number_color = $sanitizeColor($_POST['font_number_color'] ?? '#000000');

        $new_bg = handle_image_upload('bg_upload');
        if (is_array($new_bg) && isset($new_bg['error'])) $errors[] = $new_bg['error'];

        $bg_url = '';
        $curr = $db->prepare("SELECT bg_url FROM certificates WHERE id = ? LIMIT 1");
        if ($curr) { $curr->bind_param('i', $id); $curr->execute(); $curr->bind_result($bg_url); $curr->fetch(); $curr->close(); }
        if (is_string($new_bg)) $bg_url = $new_bg;

        if (empty($errors)) {
      // Ensure font columns exist (light auto-migration)
      $needCols = [
        'font_awardee_family' => "ALTER TABLE certificates ADD COLUMN font_awardee_family VARCHAR(60) DEFAULT 'Helvetica'",
        'font_awardee_size_px' => "ALTER TABLE certificates ADD COLUMN font_awardee_size_px INT DEFAULT 46",
        'font_awardee_color' => "ALTER TABLE certificates ADD COLUMN font_awardee_color VARCHAR(15) DEFAULT '#000000'",
        'font_seminar_family' => "ALTER TABLE certificates ADD COLUMN font_seminar_family VARCHAR(60) DEFAULT 'Helvetica'",
        'font_seminar_size_px' => "ALTER TABLE certificates ADD COLUMN font_seminar_size_px INT DEFAULT 24",
        'font_seminar_color' => "ALTER TABLE certificates ADD COLUMN font_seminar_color VARCHAR(15) DEFAULT '#000000'",
        'font_number_family' => "ALTER TABLE certificates ADD COLUMN font_number_family VARCHAR(60) DEFAULT 'Helvetica'",
        'font_number_size_px' => "ALTER TABLE certificates ADD COLUMN font_number_size_px INT DEFAULT 16",
        'font_number_color' => "ALTER TABLE certificates ADD COLUMN font_number_color VARCHAR(15) DEFAULT '#000000'",
      ];
      $colsNow = [];
      if ($c=$db->query("SHOW COLUMNS FROM certificates")) { while($cr=$c->fetch_assoc()){ $colsNow[$cr['Field']]=true; } $c->close(); }
      foreach ($needCols as $cName=>$ddl) { if (!isset($colsNow[$cName])) { @$db->query($ddl); } }

      $sql = "UPDATE certificates SET
        bg_url = ?, cert_awardee_name = ?, seminar_title = ?, cert_number_format = ?,
        pos_cert_awardee_name_x = ?, pos_cert_awardee_name_y = ?,
        pos_cert_number_x = ?, pos_cert_number_y = ?,
        pos_seminar_title_x = ?, pos_seminar_title_y = ?,
        show_awardee = ?, show_seminar = ?, show_number = ?,
        font_awardee_family = ?, font_awardee_size_px = ?, font_awardee_color = ?,
        font_seminar_family = ?, font_seminar_size_px = ?, font_seminar_color = ?,
        font_number_family = ?, font_number_size_px = ?, font_number_color = ?
        WHERE id = ?";
            $st = $db->prepare($sql);
            if ($st) {
        $st->bind_param(
          'ssssddddddiiisssisssisi',
          $bg_url, $cert_awardee_name, $seminar_title, $cert_number_format,
          $pos_cert_awardee_name_x, $pos_cert_awardee_name_y,
          $pos_cert_number_x, $pos_cert_number_y,
          $pos_seminar_title_x, $pos_seminar_title_y,
          $show_awardee, $show_seminar, $show_number,
          $font_awardee_family, $font_awardee_size_px, $font_awardee_color,
          $font_seminar_family, $font_seminar_size_px, $font_seminar_color,
          $font_number_family, $font_number_size_px, $font_number_color,
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
  ,font_awardee_family,font_awardee_size_px,font_awardee_color,
  font_seminar_family,font_seminar_size_px,font_seminar_color,
  font_number_family,font_number_size_px,font_number_color
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
<title><?= $id>0 ? 'Certificate Editor — Layout #'.(int)$id : 'Create New Certificate Template' ?></title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
<style>
:root {
  --primary-gradient: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
  --success-gradient: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);
  --surface-color: #ffffff;
  --bg-subtle: #f8fafc;
  --bg-canvas: #fafbfc;
  --border-light: #e2e8f0;
  --border-medium: #cbd5e1;
  --text-primary: #1e293b;
  --text-secondary: #64748b;
  --text-muted: #94a3b8;
  --shadow-sm: 0 1px 2px 0 rgb(0 0 0 / 0.05);
  --shadow-md: 0 4px 6px -1px rgb(0 0 0 / 0.1), 0 2px 4px -1px rgb(0 0 0 / 0.06);
  --shadow-lg: 0 10px 15px -3px rgb(0 0 0 / 0.1), 0 4px 6px -2px rgb(0 0 0 / 0.05);
  --shadow-xl: 0 20px 25px -5px rgb(0 0 0 / 0.1), 0 10px 10px -5px rgb(0 0 0 / 0.04);
  --radius-sm: 6px;
  --radius-md: 8px;
  --radius-lg: 12px;
  --radius-xl: 16px;
}

* {
  box-sizing: border-box;
}

body {
  background: var(--bg-subtle);
  font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
  color: var(--text-primary);
  line-height: 1.6;
  margin: 0;
  padding: 0;
}

.header {
  background: var(--primary-gradient);
  color: white;
  padding: 1.5rem 0;
  box-shadow: var(--shadow-lg);
  position: sticky;
  top: 0;
  z-index: 100;
}

.header-content {
  max-width: 1600px;
  margin: 0 auto;
  padding: 0 1rem;
  display: flex;
  justify-content: space-between;
  align-items: center;
  flex-wrap: wrap;
  gap: 1rem;
}

.header-title {
  display: flex;
  align-items: center;
  gap: 0.75rem;
}

.header-title h1 {
  font-size: 1.5rem;
  font-weight: 600;
  margin: 0;
}

.header-title .subtitle {
  opacity: 0.9;
  font-size: 0.875rem;
  margin-left: 0.5rem;
}

.header-actions {
  display: flex;
  gap: 0.75rem;
  align-items: center;
}

.btn-header {
  background: rgba(255, 255, 255, 0.15);
  border: 1px solid rgba(255, 255, 255, 0.2);
  color: white;
  padding: 0.5rem 1rem;
  border-radius: var(--radius-md);
  text-decoration: none;
  font-weight: 500;
  transition: all 0.2s ease;
  backdrop-filter: blur(10px);
}

.btn-header:hover {
  background: rgba(255, 255, 255, 0.25);
  color: white;
  transform: translateY(-1px);
}

.main-container {
  max-width: 1600px;
  margin: 0 auto;
  padding: 2rem 1rem;
}

.editor-layout {
  display: flex;
  flex-direction: column;
  gap: 2rem;
}

.canvas-section {
  background: var(--surface-color);
  border-radius: var(--radius-xl);
  padding: 2rem;
  box-shadow: var(--shadow-lg);
  border: 1px solid var(--border-light);
}

.canvas-header {
  display: flex;
  justify-content: between;
  align-items: center;
  margin-bottom: 1.5rem;
  padding-bottom: 1rem;
  border-bottom: 1px solid var(--border-light);
}

.canvas-title {
  font-size: 1.25rem;
  font-weight: 600;
  color: var(--text-primary);
  margin: 0;
  display: flex;
  align-items: center;
  gap: 0.5rem;
}

.canvas-tools {
  display: flex;
  align-items: center;
  gap: 0.75rem;
}

.tool-btn {
  width: 36px;
  height: 36px;
  border-radius: var(--radius-sm);
  border: 1px solid var(--border-light);
  background: var(--surface-color);
  color: var(--text-secondary);
  display: flex;
  align-items: center;
  justify-content: center;
  cursor: pointer;
  transition: all 0.2s ease;
  font-size: 0.875rem;
}

.tool-btn:hover {
  background: var(--bg-subtle);
  color: var(--text-primary);
  transform: scale(1.05);
  box-shadow: var(--shadow-sm);
}

.tool-btn.active {
  background: var(--primary-gradient);
  color: white;
  border-color: transparent;
}

.canvas-wrapper {
  display: flex;
  justify-content: center;
  align-items: center;
  min-height: 70vh;
  background: var(--bg-canvas);
  border-radius: var(--radius-lg);
  border: 2px dashed var(--border-medium);
  position: relative;
  overflow: auto;
  padding: 2rem;
}

.canvas {
  position: relative;
  background: #fff;
  border-radius: var(--radius-md);
  box-shadow: var(--shadow-xl);
  overflow: hidden;
  transition: transform 0.3s ease;
  max-width: 95%;
  max-height: none;
}

.canvas:hover {
  transform: scale(1.01);
}

.bg-holder {
  display: block;
  width: auto;
  height: auto;
  max-width: none;
  max-height: 70vh;
}

.overlay {
  position: absolute;
  inset: 0;
  pointer-events: auto;
  z-index: 2;
}

.draggable {
  position: absolute;
  cursor: move;
  pointer-events: auto;
  user-select: none;
  transition: all 0.2s ease;
}

.draggable:hover {
  transform: scale(1.05);
  z-index: 10;
}

.draggable.dragging {
  transform: scale(1.1);
  z-index: 20;
  box-shadow: var(--shadow-lg);
}

.ph {
  background: rgba(99, 102, 241, 0.1);
  border: 2px dashed #6366f1;
  border-radius: var(--radius-sm);
  padding: 8px 12px;
  color: var(--text-primary);
  font-weight: 500;
  backdrop-filter: blur(8px);
  min-height: 32px;
  display: flex;
  align-items: center;
  justify-content: center;
}

.ph:hover {
  background: rgba(99, 102, 241, 0.15);
  border-color: #4f46e5;
}

.ph.sig {
  background: rgba(168, 85, 247, 0.1);
  border-color: #a855f7;
  padding: 0;
  overflow: hidden;
}

.ph.sig:hover {
  background: rgba(168, 85, 247, 0.15);
  border-color: #9333ea;
}

.sidebar {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
  gap: 1.5rem;
  width: 100%;
}

.sidebar-card {
  background: var(--surface-color);
  border-radius: var(--radius-lg);
  border: 1px solid var(--border-light);
  box-shadow: var(--shadow-md);
  overflow: hidden;
}

.sidebar-header {
  padding: 1rem 1.25rem;
  background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
  border-bottom: 1px solid var(--border-light);
  display: flex;
  align-items: center;
  justify-content: between;
  gap: 0.5rem;
}

.sidebar-title {
  font-size: 1rem;
  font-weight: 600;
  color: var(--text-primary);
  margin: 0;
  display: flex;
  align-items: center;
  gap: 0.5rem;
}

.sidebar-body {
  padding: 1.25rem;
}

.form-section {
  margin-bottom: 1.5rem;
}

.form-section:last-child {
  margin-bottom: 0;
}

.form-section-title {
  font-size: 0.875rem;
  font-weight: 600;
  color: var(--text-primary);
  margin-bottom: 0.75rem;
  text-transform: uppercase;
  letter-spacing: 0.025em;
  display: flex;
  align-items: center;
  gap: 0.5rem;
}

.form-group {
  margin-bottom: 1rem;
}

.form-label {
  display: block;
  font-size: 0.875rem;
  font-weight: 500;
  color: var(--text-secondary);
  margin-bottom: 0.5rem;
}

.form-control-modern {
  width: 100%;
  padding: 0.75rem;
  border: 1px solid var(--border-light);
  border-radius: var(--radius-sm);
  font-size: 0.875rem;
  color: var(--text-primary);
  background: var(--surface-color);
  transition: all 0.2s ease;
}

.form-control-modern:focus {
  outline: none;
  border-color: #6366f1;
  box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.1);
}

.form-control-sm {
  padding: 0.5rem 0.75rem;
  font-size: 0.8125rem;
}

.switch-container {
  display: flex;
  align-items: center;
  gap: 0.75rem;
  padding: 0.75rem;
  background: var(--bg-subtle);
  border-radius: var(--radius-sm);
  margin-bottom: 0.75rem;
}

.switch {
  position: relative;
  width: 44px;
  height: 24px;
  background: var(--border-medium);
  border-radius: 12px;
  cursor: pointer;
  transition: background 0.2s ease;
}

.switch.active {
  background: #22c55e;
}

.switch-thumb {
  position: absolute;
  top: 2px;
  left: 2px;
  width: 20px;
  height: 20px;
  background: white;
  border-radius: 50%;
  transition: transform 0.2s ease;
  box-shadow: var(--shadow-sm);
}

.switch.active .switch-thumb {
  transform: translateX(20px);
}

.switch-label {
  font-size: 0.875rem;
  font-weight: 500;
  color: var(--text-primary);
  cursor: pointer;
}

.position-grid {
  display: grid;
  grid-template-columns: auto 1fr 1fr;
  gap: 0.5rem;
  align-items: center;
  padding: 0.75rem;
  background: var(--bg-subtle);
  border-radius: var(--radius-sm);
  margin-bottom: 0.75rem;
}

.position-label {
  font-size: 0.8125rem;
  font-weight: 500;
  color: var(--text-secondary);
  min-width: 80px;
}

.num-input {
  width: 100%;
  padding: 0.5rem;
  border: 1px solid var(--border-light);
  border-radius: var(--radius-sm);
  font-size: 0.8125rem;
  text-align: center;
  font-family: 'SF Mono', Monaco, 'Cascadia Code', monospace;
}

.font-config {
  display: grid;
  grid-template-columns: 2fr 1fr 1fr;
  gap: 0.5rem;
  align-items: end;
  padding: 1rem;
  background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
  border: 1px solid var(--border-light);
  border-radius: var(--radius-sm);
  margin-bottom: 1rem;
}

.font-config-title {
  grid-column: 1 / -1;
  font-size: 0.8125rem;
  font-weight: 600;
  color: var(--text-secondary);
  margin-bottom: 0.5rem;
  text-transform: uppercase;
  letter-spacing: 0.025em;
}

.sig-item {
  border: 1px solid var(--border-light);
  border-radius: var(--radius-sm);
  padding: 1rem;
  margin-bottom: 1rem;
  background: var(--bg-subtle);
}

.sig-item:last-child {
  margin-bottom: 0;
}

.sig-header {
  display: flex;
  align-items: center;
  justify-content: between;
  margin-bottom: 1rem;
}

.sig-indicator {
  font-weight: 600;
  color: var(--text-primary);
}

.sig-thumb {
  max-width: 120px;
  max-height: 60px;
  border-radius: var(--radius-sm);
  border: 1px solid var(--border-light);
  box-shadow: var(--shadow-sm);
}

.width-slider-container {
  margin: 0.75rem 0;
}

.width-slider {
  width: 100%;
  height: 6px;
  border-radius: 3px;
  background: var(--border-light);
  outline: none;
  -webkit-appearance: none;
}

.width-slider::-webkit-slider-thumb {
  -webkit-appearance: none;
  width: 18px;
  height: 18px;
  border-radius: 50%;
  background: #6366f1;
  cursor: pointer;
  box-shadow: var(--shadow-sm);
}

.width-slider::-moz-range-thumb {
  width: 18px;
  height: 18px;
  border-radius: 50%;
  background: #6366f1;
  cursor: pointer;
  border: none;
  box-shadow: var(--shadow-sm);
}

.btn-modern {
  padding: 0.75rem 1.5rem;
  border-radius: var(--radius-sm);
  font-weight: 500;
  font-size: 0.875rem;
  border: none;
  cursor: pointer;
  transition: all 0.2s ease;
  display: inline-flex;
  align-items: center;
  gap: 0.5rem;
  text-decoration: none;
}

.btn-primary-modern {
  background: var(--primary-gradient);
  color: white;
  box-shadow: var(--shadow-sm);
}

.btn-primary-modern:hover {
  transform: translateY(-1px);
  box-shadow: var(--shadow-md);
  color: white;
}

.btn-success-modern {
  background: var(--success-gradient);
  color: white;
  box-shadow: var(--shadow-sm);
}

.btn-success-modern:hover {
  transform: translateY(-1px);
  box-shadow: var(--shadow-md);
  color: white;
}

.btn-outline-modern {
  background: var(--surface-color);
  border: 1px solid var(--border-light);
  color: var(--text-primary);
}

.btn-outline-modern:hover {
  background: var(--bg-subtle);
  transform: translateY(-1px);
  box-shadow: var(--shadow-sm);
  color: var(--text-primary);
}

.btn-danger-modern {
  background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
  color: white;
  box-shadow: var(--shadow-sm);
}

.btn-danger-modern:hover {
  transform: translateY(-1px);
  box-shadow: var(--shadow-md);
  color: white;
}

.action-buttons {
  display: flex;
  gap: 0.75rem;
  margin-top: 1.5rem;
}

.help-text {
  font-size: 0.8125rem;
  color: var(--text-muted);
  margin-top: 0.5rem;
  line-height: 1.4;
}

.create-section {
  background: var(--surface-color);
  border: 1px solid var(--border-light);
  border-radius: var(--radius-xl);
  padding: 2rem;
  box-shadow: var(--shadow-lg);
}

.create-header {
  text-align: center;
  margin-bottom: 2rem;
}

.create-title {
  font-size: 2rem;
  font-weight: 700;
  background: var(--primary-gradient);
  -webkit-background-clip: text;
  -webkit-text-fill-color: transparent;
  background-clip: text;
  margin-bottom: 0.5rem;
}

.create-subtitle {
  color: var(--text-secondary);
  font-size: 1.125rem;
}

@media (max-width: 768px) {
  .sidebar {
    grid-template-columns: 1fr;
  }
  
  .canvas-wrapper {
    min-height: 50vh;
    padding: 1rem;
  }
  
  .main-container {
    padding: 1rem;
  }
  
  .canvas-section,
  .sidebar-card {
    padding: 1rem;
  }
  
  .header-content {
    flex-direction: column;
    align-items: stretch;
    text-align: center;
  }
  
  .header-actions {
    justify-content: center;
  }
  
  .action-buttons {
    flex-direction: column;
  }
}

.loading-state {
  display: none;
  position: absolute;
  inset: 0;
  background: rgba(255, 255, 255, 0.9);
  backdrop-filter: blur(4px);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 1000;
}

.spinner {
  width: 32px;
  height: 32px;
  border: 3px solid var(--border-light);
  border-top-color: #6366f1;
  border-radius: 50%;
  animation: spin 1s linear infinite;
}

@keyframes spin {
  to { transform: rotate(360deg); }
}
</style>
</head>
<body>
  <div class="header">
    <div class="header-content">
      <div class="header-title">
        <i class="bi bi-palette"></i>
        <div>
          <h1><?= $id>0 ? 'Certificate Layout Editor' : 'Create New Template' ?></h1>
          <?php if ($id > 0): ?>
            <span class="subtitle">Template ID: <?= (int)$id ?> • Type: <?= e($row['type'] ?? 'Unknown') ?></span>
          <?php endif; ?>
        </div>
      </div>
      <div class="header-actions">
        <?php if ($id > 0): ?>
          <a href="admin_certificate_preview.php?id=<?= (int)$id ?>" class="btn-header" target="_blank">
            <i class="bi bi-eye me-2"></i>Preview
          </a>
        <?php endif; ?>
        <a href="admin_certificate_settings.php" class="btn-header">
          <i class="bi bi-arrow-left me-2"></i>Back to Settings
        </a>
      </div>
    </div>
  </div>

  <div class="main-container">
    <?php if (!empty($errors)): ?>
      <div class="alert alert-danger">
        <h6 class="alert-heading"><i class="bi bi-exclamation-triangle me-2"></i>Please fix the following errors:</h6>
        <ul class="mb-0"><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul>
      </div>
    <?php endif; ?>
    
    <?php if ($success): ?>
      <div class="alert alert-success">
        <i class="bi bi-check-circle me-2"></i><?= e($success) ?>
      </div>
    <?php endif; ?>

    <?php if ($id <= 0): ?>
      <div class="create-section">
        <div class="create-header">
          <h2 class="create-title">Create Certificate Template</h2>
          <p class="create-subtitle">Design a new certificate template with background, placeholders, and signatures</p>
        </div>

        <form method="post" enctype="multipart/form-data">
          <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
          <input type="hidden" name="action" value="create_template">
          
          <div class="row g-4">
            <div class="col-lg-4">
              <div class="form-group">
                <label class="form-label">
                  <i class="bi bi-tag me-2"></i>Template Type
                </label>
                <input type="text" class="form-control-modern" name="type" value="attendance" placeholder="e.g. attendance, participation" required>
                <div class="help-text">Enter a descriptive name for this certificate type</div>
              </div>
            </div>
            
            <div class="col-lg-8">
              <div class="form-group">
                <label class="form-label">
                  <i class="bi bi-image me-2"></i>Background Image
                </label>
                <input type="file" class="form-control-modern" name="bg_upload" accept="image/png,image/jpeg" required>
                <div class="help-text">Upload a high-quality PNG or JPEG background (recommended: 1920x1080 or higher)</div>
              </div>
            </div>

            <div class="col-lg-6">
              <div class="form-group">
                <label class="form-label">
                  <i class="bi bi-person me-2"></i>Awardee Name Placeholder
                </label>
                <input type="text" class="form-control-modern" name="awardee_placeholder" value="{USER_NAME}">
                <div class="help-text">Text that will be replaced with the recipient's name</div>
              </div>
            </div>
            
            <div class="col-lg-6">
              <div class="form-group">
                <label class="form-label">
                  <i class="bi bi-calendar-event me-2"></i>Seminar Title Placeholder
                </label>
                <input type="text" class="form-control-modern" name="seminar_placeholder" value="{SEMINAR_TITLE}">
                <div class="help-text">Text that will be replaced with the event/seminar name</div>
              </div>
            </div>
            
            <div class="col-lg-6">
              <div class="form-group">
                <label class="form-label">
                  <i class="bi bi-hash me-2"></i>Certificate Number Format
                </label>
                <input type="text" class="form-control-modern" name="cert_number_format" value="NC-{YYYY}-{SEQ4}">
                <div class="help-text">Available tokens: {YYYY}, {MM}, {DD}, {SEQ4}, {USER_ID}</div>
              </div>
            </div>

            <div class="col-12">
              <div class="sidebar-card">
                <div class="sidebar-header">
                  <h3 class="sidebar-title">
                    <i class="bi bi-pen"></i>Signatures
                  </h3>
                  <button type="button" class="btn btn-sm btn-outline-primary" id="addSigBtn">
                    <i class="bi bi-plus me-1"></i>Add Signature
                  </button>
                </div>
                <div class="sidebar-body">
                  <div id="sigUploadList">
                    <div class="sig-upload-item">
                      <div class="row g-3 align-items-end">
                        <div class="col-lg-4">
                          <label class="form-label">Role/Title</label>
                          <input type="text" class="form-control-modern form-control-sm" name="new_sig_indicator[]" placeholder="e.g. Chair, Director">
                        </div>
                        <div class="col-lg-6">
                          <label class="form-label">Signature Image</label>
                          <input type="file" class="form-control-modern form-control-sm" name="new_sig_file[]" accept="image/png,image/jpeg">
                        </div>
                        <div class="col-lg-2">
                          <button type="button" class="btn btn-outline-danger btn-modern btn-sm removeSigBtn">
                            <i class="bi bi-trash"></i>
                          </button>
                        </div>
                      </div>
                    </div>
                  </div>
                  <div class="help-text mt-2">
                    <i class="bi bi-info-circle me-1"></i>
                    Upload signature images with transparent backgrounds for best results. You can position them after creating the template.
                  </div>
                </div>
              </div>
            </div>
          </div>

          <div class="action-buttons">
            <button class="btn btn-primary-modern btn-modern">
              <i class="bi bi-plus-circle me-2"></i>Create Template & Open Layout Editor
            </button>
            <a href="admin_certificate_settings.php" class="btn btn-outline-modern btn-modern">
              <i class="bi bi-x-circle me-2"></i>Cancel
            </a>
          </div>
        </form>
      </div>
    <?php else: ?>
      <form method="post" enctype="multipart/form-data" id="editorForm" novalidate autocomplete="off">
        <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
        <input type="hidden" name="action" value="save_layout">
        
        <div class="editor-layout">
          <div class="canvas-section">
            <div class="canvas-header">
              <h2 class="canvas-title">
                <i class="bi bi-layout-wtf"></i>
                Certificate Canvas
              </h2>
              <div class="canvas-tools">
                <button type="button" class="tool-btn" id="resetBtn" title="Reset all positions">
                  <i class="bi bi-arrow-clockwise"></i>
                </button>
                <button type="button" class="tool-btn" id="gridToggle" title="Toggle grid">
                  <i class="bi bi-grid"></i>
                </button>
                <button type="button" class="tool-btn" id="zoomFit" title="Fit to screen">
                  <i class="bi bi-arrows-fullscreen"></i>
                </button>
              </div>
            </div>
            
            <div class="canvas-wrapper">
              <div id="canvas" class="canvas">
                <img id="bgImage" class="bg-holder" src="<?= e($row['bg_url'] ? asset_url($row['bg_url']) : '') ?>" alt="Background">
                <div id="overlay" class="overlay">
                  <div id="ph-awardee" class="draggable ph" style="left:0;top:0;<?= $show_awardee? '' : 'display:none;' ?>" title="Drag to reposition awardee name">
                    <?= e($row['cert_awardee_name'] ?: '{USER_NAME}') ?>
                  </div>
                  <div id="ph-seminar" class="draggable ph" style="left:0;top:0;<?= $show_seminar? '' : 'display:none;' ?>" title="Drag to reposition seminar title">
                    <?= e($row['seminar_title'] ?: '{SEMINAR_TITLE}') ?>
                  </div>
                  <div id="ph-number" class="draggable ph" style="left:0;top:0;<?= $show_number? '' : 'display:none;' ?>" title="Drag to reposition certificate number">
                    <?= e($row['cert_number_format'] ?: 'NC-{YYYY}-{SEQ4}') ?>
                  </div>

                  <?php foreach ($sigs as $sg): ?>
                    <?php $sid = (int)$sg['id']; $enabled = isset($sg['enabled']) ? (int)$sg['enabled'] : 1; ?>
                    <?php if (!empty($sg['signature_url'])): ?>
                      <img
                        id="sig-<?= $sid ?>"
                        class="draggable ph sig sig-node"
                        data-id="<?= $sid ?>"
                        src="<?= e(asset_url($sg['signature_url'])) ?>"
                        alt="<?= e($sg['indicator']) ?>"
                        title="Drag to reposition <?= e($sg['indicator']) ?> signature"
                        style="left:0;top:0;width:<?= (float)($sg['width_pct'] ?? 25) ?>%;height:auto;max-width:none;<?= $enabled? '' : 'display:none;' ?>">
                    <?php else: ?>
                      <div id="sig-<?= $sid ?>" class="draggable ph sig sig-node" data-id="<?= $sid ?>" 
                           title="Drag to reposition <?= e($sg['indicator']) ?> signature"
                           style="left:0;top:0;width:<?= (float)($sg['width_pct'] ?? 25) ?>%;height:60px;display:flex;align-items:center;justify-content:center;<?= $enabled? '' : 'display:none;' ?>">
                        <i class="bi bi-pen me-2"></i><?= e($sg['indicator'] ?: '{SIGNATURE}') ?>
                      </div>
                    <?php endif; ?>
                  <?php endforeach; ?>
                </div>
              </div>
            </div>
          </div>

          <div class="sidebar">
            <div class="sidebar-card">
              <div class="sidebar-header">
                <h3 class="sidebar-title">
                  <i class="bi bi-image"></i>Background
                </h3>
              </div>
              <div class="sidebar-body">
                <div class="form-group">
                  <label class="form-label">Replace Background Image</label>
                  <input type="file" class="form-control-modern" name="bg_upload" accept="image/png,image/jpeg">
                  <div class="help-text">Upload a new background to replace the current one</div>
                </div>
              </div>
            </div>

            <div class="sidebar-card">
              <div class="sidebar-header">
                <h3 class="sidebar-title">
                  <i class="bi bi-type"></i>Text Elements
                </h3>
              </div>
              <div class="sidebar-body">
                <div class="form-section">
                  <div class="form-section-title">
                    <i class="bi bi-eye"></i>Visibility
                  </div>
                  
                  <div class="switch-container">
                    <div class="switch <?= $show_awardee ? 'active' : '' ?>" data-target="toggle-awardee">
                      <div class="switch-thumb"></div>
                    </div>
                    <label class="switch-label" for="toggle-awardee">Awardee Name</label>
                    <input type="checkbox" id="toggle-awardee" name="show_awardee" <?= $show_awardee ? 'checked' : '' ?> style="display:none;">
                  </div>
                  
                  <div class="switch-container">
                    <div class="switch <?= $show_seminar ? 'active' : '' ?>" data-target="toggle-seminar">
                      <div class="switch-thumb"></div>
                    </div>
                    <label class="switch-label" for="toggle-seminar">Seminar Title</label>
                    <input type="checkbox" id="toggle-seminar" name="show_seminar" <?= $show_seminar ? 'checked' : '' ?> style="display:none;">
                  </div>
                  
                  <div class="switch-container">
                    <div class="switch <?= $show_number ? 'active' : '' ?>" data-target="toggle-number">
                      <div class="switch-thumb"></div>
                    </div>
                    <label class="switch-label" for="toggle-number">Certificate Number</label>
                    <input type="checkbox" id="toggle-number" name="show_number" <?= $show_number ? 'checked' : '' ?> style="display:none;">
                  </div>
                </div>

                <div class="form-section">
                  <div class="form-section-title">
                    <i class="bi bi-pencil"></i>Content
                  </div>
                  
                  <div class="form-group">
                    <label class="form-label">Awardee Name Placeholder</label>
                    <input type="text" class="form-control-modern" name="awardee_placeholder" id="inp-awardee" value="<?= e($row['cert_awardee_name'] ?: '{USER_NAME}') ?>">
                  </div>
                  
                  <div class="form-group">
                    <label class="form-label">Seminar Title Placeholder</label>
                    <input type="text" class="form-control-modern" name="seminar_placeholder" id="inp-seminar" value="<?= e($row['seminar_title'] ?: '{SEMINAR_TITLE}') ?>">
                  </div>
                  
                  <div class="form-group">
                    <label class="form-label">Certificate Number Format</label>
                    <input type="text" class="form-control-modern" name="cert_number_format" id="inp-number" value="<?= e($row['cert_number_format'] ?: 'NC-{YYYY}-{SEQ4}') ?>">
                    <div class="help-text">Available: {YYYY}, {MM}, {DD}, {SEQ4}, {USER_ID}</div>
                  </div>
                </div>

                <div class="form-section">
                  <div class="form-section-title">
                    <i class="bi bi-fonts"></i>Typography
                  </div>
                  
                  <?php
                    $fontDefaults = [
                      'font_awardee_family'=>'Helvetica','font_awardee_size_px'=>46,'font_awardee_color'=>'#000000',
                      'font_seminar_family'=>'Helvetica','font_seminar_size_px'=>24,'font_seminar_color'=>'#000000',
                      'font_number_family'=>'Helvetica','font_number_size_px'=>16,'font_number_color'=>'#000000'
                    ];
                    foreach($fontDefaults as $k=>$v){ if(!isset($row[$k])||$row[$k]==''){ $row[$k]=$v; } }
                    $fontOptions = ['Helvetica','Arial','Times New Roman','Georgia','Courier'];
                    function fontSelectModern($name,$current,$options){
                      $h='<select class="form-control-modern form-control-sm" name="'.$name.'">';
                      foreach($options as $o){ $sel=$o===$current?'selected':''; $h.='<option '.$sel.'>'.htmlspecialchars($o).'</option>'; } $h.='</select>'; return $h; }
                  ?>
                  
                  <div class="font-config">
                    <div class="font-config-title">Awardee Name</div>
                    <?= fontSelectModern('font_awardee_family',$row['font_awardee_family'],$fontOptions) ?>
                    <input type="number" min="6" max="200" class="form-control-modern form-control-sm" name="font_awardee_size_px" value="<?= (int)$row['font_awardee_size_px'] ?>" placeholder="Size">
                    <input type="color" class="form-control-modern form-control-sm" name="font_awardee_color" value="<?= e($row['font_awardee_color']) ?>">
                  </div>
                  
                  <div class="font-config">
                    <div class="font-config-title">Seminar Title</div>
                    <?= fontSelectModern('font_seminar_family',$row['font_seminar_family'],$fontOptions) ?>
                    <input type="number" min="6" max="200" class="form-control-modern form-control-sm" name="font_seminar_size_px" value="<?= (int)$row['font_seminar_size_px'] ?>" placeholder="Size">
                    <input type="color" class="form-control-modern form-control-sm" name="font_seminar_color" value="<?= e($row['font_seminar_color']) ?>">
                  </div>
                  
                  <div class="font-config">
                    <div class="font-config-title">Certificate Number</div>
                    <?= fontSelectModern('font_number_family',$row['font_number_family'],$fontOptions) ?>
                    <input type="number" min="6" max="200" class="form-control-modern form-control-sm" name="font_number_size_px" value="<?= (int)$row['font_number_size_px'] ?>" placeholder="Size">
                    <input type="color" class="form-control-modern form-control-sm" name="font_number_color" value="<?= e($row['font_number_color']) ?>">
                  </div>
                </div>
              </div>
            </div>

            <div class="sidebar-card">
              <div class="sidebar-header">
                <h3 class="sidebar-title">
                  <i class="bi bi-crosshair"></i>Positions
                </h3>
              </div>
              <div class="sidebar-body">
                <div class="help-text mb-3">
                  <i class="bi bi-info-circle me-1"></i>
                  Coordinates are percentages relative to the background image. Drag elements on the canvas or enter values manually.
                </div>
                
                <div class="position-grid">
                  <div class="position-label">Awardee</div>
                  <input type="number" step="any" class="num-input" name="pos_cert_awardee_name_x" id="pos-awardee-x" value="<?= (float)($row['pos_cert_awardee_name_x'] ?? 0) ?>" placeholder="X%">
                  <input type="number" step="any" class="num-input" name="pos_cert_awardee_name_y" id="pos-awardee-y" value="<?= (float)($row['pos_cert_awardee_name_y'] ?? 0) ?>" placeholder="Y%">
                </div>
                
                <div class="position-grid">
                  <div class="position-label">Seminar</div>
                  <input type="number" step="any" class="num-input" name="pos_seminar_title_x" id="pos-seminar-x" value="<?= (float)($row['pos_seminar_title_x'] ?? 0) ?>" placeholder="X%">
                  <input type="number" step="any" class="num-input" name="pos_seminar_title_y" id="pos-seminar-y" value="<?= (float)($row['pos_seminar_title_y'] ?? 0) ?>" placeholder="Y%">
                </div>
                
                <div class="position-grid">
                  <div class="position-label">Number</div>
                  <input type="number" step="any" class="num-input" name="pos_cert_number_x" id="pos-number-x" value="<?= (float)($row['pos_cert_number_x'] ?? 0) ?>" placeholder="X%">
                  <input type="number" step="any" class="num-input" name="pos_cert_number_y" id="pos-number-y" value="<?= (float)($row['pos_cert_number_y'] ?? 0) ?>" placeholder="Y%">
                </div>
              </div>
            </div>

            <div class="sidebar-card">
              <div class="sidebar-header">
                <h3 class="sidebar-title">
                  <i class="bi bi-pen"></i>Signatures
                </h3>
              </div>
              <div class="sidebar-body">
                <?php if (empty($sigs)): ?>
                  <div class="help-text text-center py-3">
                    <i class="bi bi-pen me-1"></i>
                    No signatures added yet. Add signatures when creating the template.
                  </div>
                <?php else: ?>
                  <?php foreach ($sigs as $sg): $sid=(int)$sg['id']; $enabled=(int)($sg['enabled'] ?? 1); ?>
                    <div class="sig-item">
                      <div class="sig-header">
                        <div class="sig-indicator">
                          <i class="bi bi-pen me-2"></i><?= e($sg['indicator']) ?>
                        </div>
                        <div class="switch <?= $enabled ? 'active' : '' ?>" data-target="sig-enabled-<?= $sid ?>">
                          <div class="switch-thumb"></div>
                        </div>
                        <input type="checkbox" class="sig-enabled-toggle" id="sig-enabled-<?= $sid ?>" name="sig_enabled[<?= $sid ?>]" data-id="<?= $sid ?>" <?= $enabled ? 'checked' : '' ?> style="display:none;">
                      </div>
                      
                      <div class="form-group">
                        <label class="form-label">Role/Title</label>
                        <input type="text" class="form-control-modern form-control-sm" name="sig_indicator[<?= $sid ?>]" value="<?= e($sg['indicator']) ?>">
                      </div>
                      
                      <div class="form-group">
                        <label class="form-label">Replace Image</label>
                        <input type="file" class="form-control-modern form-control-sm" name="sig_file[<?= $sid ?>]" accept="image/png,image/jpeg">
                        <?php if (!empty($sg['signature_url'])): ?>
                          <img class="sig-thumb mt-2" src="<?= e(asset_url($sg['signature_url'])) ?>" alt="Current signature">
                        <?php else: ?>
                          <div class="help-text mt-1">No image uploaded - using placeholder</div>
                        <?php endif; ?>
                      </div>
                      
                      <div class="width-slider-container">
                        <label class="form-label">Width: <span id="sig-width-label-<?= $sid ?>"><?= (float)($sg['width_pct'] ?? 25) ?></span>%</label>
                        <input type="range" min="5" max="100" step="1" class="width-slider" name="sig_width[<?= $sid ?>]" id="sig-width-<?= $sid ?>" value="<?= (float)($sg['width_pct'] ?? 25) ?>">
                      </div>
                      
                      <input type="hidden" name="sig_pos_x[<?= $sid ?>]" id="sig-pos-x-<?= $sid ?>" value="<?= (float)$sg['pos_x'] ?>">
                      <input type="hidden" name="sig_pos_y[<?= $sid ?>]" id="sig-pos-y-<?= $sid ?>" value="<?= (float)$sg['pos_y'] ?>">
                      
                      <div class="switch-container">
                        <input type="checkbox" name="sig_remove[<?= $sid ?>]" id="sig-remove-<?= $sid ?>" value="1" style="display:none;">
                        <label class="switch-label" for="sig-remove-<?= $sid ?>" style="color: #ef4444; cursor: pointer;">
                          <i class="bi bi-trash me-1"></i>Delete this signature
                        </label>
                      </div>
                    </div>
                  <?php endforeach; ?>
                <?php endif; ?>
              </div>
            </div>

            <div class="action-buttons">
              <button class="btn btn-success-modern btn-modern" formnovalidate>
                <i class="bi bi-check-circle me-2"></i>Save Layout
              </button>
              <a href="admin_certificate_settings.php" class="btn btn-outline-modern btn-modern">
                <i class="bi bi-x-circle me-2"></i>Cancel
              </a>
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

  // Static placeholders with enhanced feedback
  makeDraggable(phAwardee, { set: function(x,y){ syncStaticInputs(x,y,null,null,null,null); } });
  makeDraggable(phSeminar, { set: function(x,y){ syncStaticInputs(null,null,x,y,null,null); } });
  makeDraggable(phNumber,  { set: function(x,y){ syncStaticInputs(null,null,null,null,x,y); } });

  // Enhanced dragging feedback  
  function makeDraggable(el, getSet){
    var dragging=false,startX=0,startY=0,baseLeft=0,baseTop=0;
    el.addEventListener('mousedown',function(e){
      e.preventDefault();dragging=true;startX=e.clientX;startY=e.clientY;
      baseLeft=parseFloat(el.style.left||'0');baseTop=parseFloat(el.style.top||'0');
      document.body.style.userSelect='none';
      el.classList.add('dragging');
    });
    window.addEventListener('mousemove',function(e){
      if(!dragging)return;
      var dx=e.clientX-startX,dy=e.clientY-startY;
      var W=bg.clientWidth,H=bg.clientHeight;
      var newLeft=clamp(baseLeft+dx,0,W),newTop=clamp(baseTop+dy,0,H);
      el.style.left=newLeft+'px';el.style.top=newTop+'px';
      var xPct=newLeft/W*100,yPct=newTop/H*100;
      getSet.set(xPct,yPct);
    });
    window.addEventListener('mouseup',function(){
      if(dragging) el.classList.remove('dragging');
      dragging=false;document.body.style.userSelect='';
    });
  }

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

  // Custom switch functionality
  document.querySelectorAll('.switch').forEach(function(switchEl) {
    switchEl.addEventListener('click', function() {
      var targetId = this.getAttribute('data-target');
      var checkbox = document.getElementById(targetId);
      if (checkbox) {
        checkbox.checked = !checkbox.checked;
        this.classList.toggle('active', checkbox.checked);
        checkbox.dispatchEvent(new Event('change'));
      }
    });
  });

  // Toggle visibility of placeholders
  var tglAwardee = document.getElementById('toggle-awardee');
  var tglSeminar = document.getElementById('toggle-seminar');
  var tglNumber  = document.getElementById('toggle-number');
  if (tglAwardee) tglAwardee.addEventListener('change', function(){ 
    phAwardee.style.display = this.checked ? '' : 'none'; 
  });
  if (tglSeminar) tglSeminar.addEventListener('change', function(){ 
    phSeminar.style.display = this.checked ? '' : 'none'; 
  });
  if (tglNumber) tglNumber.addEventListener('change', function(){ 
    phNumber.style.display = this.checked ? '' : 'none'; 
  });

  // Toggle visibility per signature
  document.querySelectorAll('.sig-enabled-toggle').forEach(function(chk){
    chk.addEventListener('change', function(){
      var sid = this.dataset.id;
      var el = document.getElementById('sig-'+sid);
      if (el) el.style.display = this.checked ? '' : 'none';
    });
  });

  // Canvas tools functionality
  var gridToggle = document.getElementById('gridToggle');
  var zoomFit = document.getElementById('zoomFit');
  
  if (gridToggle) {
    gridToggle.addEventListener('click', function() {
      this.classList.toggle('active');
      // Could add grid overlay functionality here
    });
  }
  
  if (zoomFit) {
    zoomFit.addEventListener('click', function() {
      var canvas = document.getElementById('canvas');
      var wrapper = canvas.parentElement;
      if (canvas && wrapper) {
        // Reset any existing transform first
        canvas.style.transform = 'scale(1)';
        
        // Get fresh measurements
        setTimeout(function() {
          var wrapperRect = wrapper.getBoundingClientRect();
          var canvasRect = canvas.getBoundingClientRect();
          var scale = Math.min(
            (wrapperRect.width - 80) / canvasRect.width,
            (wrapperRect.height - 80) / canvasRect.height,
            1.0
          );
          canvas.style.transform = 'scale(' + scale + ')';
          canvas.style.transformOrigin = 'center center';
        }, 10);
      }
    });
  }

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