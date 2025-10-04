<?php
session_start();
require_once __DIR__ . '/../db.php';
if (isset($conn) && $conn instanceof mysqli) { $db = $conn; }
elseif (isset($mysqli) && $mysqli instanceof mysqli) { $db = $mysqli; }
else { http_response_code(500); exit('DB unavailable'); }
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') { header('Location: admin_login.php'); exit; }
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
if (!function_exists('e')) { function e($s){ return htmlspecialchars((string)$s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'); } }

// ----------------------------------------------------------------------------
// NEW LAYOUT-BASED DEPARTMENT CERTIFICATE SYSTEM (no raw HTML editing)
// ----------------------------------------------------------------------------
// Table creation & light migrations (idempotent)
@$db->query("CREATE TABLE IF NOT EXISTS department_certificate_templates (
  id INT AUTO_INCREMENT PRIMARY KEY,
  technical_department_id INT NOT NULL UNIQUE,
  -- legacy column retained for backward compat but no longer used actively
  template_html MEDIUMTEXT NULL,
  bg_url VARCHAR(255) DEFAULT '',
  user_name_placeholder VARCHAR(120) DEFAULT '{USER_NAME}',
  department_name_placeholder VARCHAR(160) DEFAULT '{DEPARTMENT_NAME}',
  speakers_list_placeholder VARCHAR(160) DEFAULT '{SPEAKERS_LIST}',
  pos_user_name_x DOUBLE DEFAULT 0, pos_user_name_y DOUBLE DEFAULT 0,
  pos_department_name_x DOUBLE DEFAULT 0, pos_department_name_y DOUBLE DEFAULT 0,
  pos_speakers_list_x DOUBLE DEFAULT 0, pos_speakers_list_y DOUBLE DEFAULT 0,
  show_user_name TINYINT(1) DEFAULT 1,
  show_department_name TINYINT(1) DEFAULT 1,
  show_speakers_list TINYINT(1) DEFAULT 1,
  font_user_name_family VARCHAR(60) DEFAULT 'Helvetica', font_user_name_size_px INT DEFAULT 40, font_user_name_color VARCHAR(15) DEFAULT '#000000',
  font_department_name_family VARCHAR(60) DEFAULT 'Helvetica', font_department_name_size_px INT DEFAULT 28, font_department_name_color VARCHAR(15) DEFAULT '#000000',
  font_speakers_list_family VARCHAR(60) DEFAULT 'Helvetica', font_speakers_list_size_px INT DEFAULT 16, font_speakers_list_color VARCHAR(15) DEFAULT '#000000',
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Add missing columns (in case table existed previously with only template_html)
$needCols = [
  'bg_url' => "ALTER TABLE department_certificate_templates ADD COLUMN bg_url VARCHAR(255) DEFAULT ''",
  'user_name_placeholder' => "ALTER TABLE department_certificate_templates ADD COLUMN user_name_placeholder VARCHAR(120) DEFAULT '{USER_NAME}'",
  'department_name_placeholder' => "ALTER TABLE department_certificate_templates ADD COLUMN department_name_placeholder VARCHAR(160) DEFAULT '{DEPARTMENT_NAME}'",
  'speakers_list_placeholder' => "ALTER TABLE department_certificate_templates ADD COLUMN speakers_list_placeholder VARCHAR(160) DEFAULT '{SPEAKERS_LIST}'",
  'pos_user_name_x' => "ALTER TABLE department_certificate_templates ADD COLUMN pos_user_name_x DOUBLE DEFAULT 0",
  'pos_user_name_y' => "ALTER TABLE department_certificate_templates ADD COLUMN pos_user_name_y DOUBLE DEFAULT 0",
  'pos_department_name_x' => "ALTER TABLE department_certificate_templates ADD COLUMN pos_department_name_x DOUBLE DEFAULT 0",
  'pos_department_name_y' => "ALTER TABLE department_certificate_templates ADD COLUMN pos_department_name_y DOUBLE DEFAULT 0",
  'pos_speakers_list_x' => "ALTER TABLE department_certificate_templates ADD COLUMN pos_speakers_list_x DOUBLE DEFAULT 0",
  'pos_speakers_list_y' => "ALTER TABLE department_certificate_templates ADD COLUMN pos_speakers_list_y DOUBLE DEFAULT 0",
  'show_user_name' => "ALTER TABLE department_certificate_templates ADD COLUMN show_user_name TINYINT(1) DEFAULT 1",
  'show_department_name' => "ALTER TABLE department_certificate_templates ADD COLUMN show_department_name TINYINT(1) DEFAULT 1",
  'show_speakers_list' => "ALTER TABLE department_certificate_templates ADD COLUMN show_speakers_list TINYINT(1) DEFAULT 1",
  'font_user_name_family' => "ALTER TABLE department_certificate_templates ADD COLUMN font_user_name_family VARCHAR(60) DEFAULT 'Helvetica'",
  'font_user_name_size_px' => "ALTER TABLE department_certificate_templates ADD COLUMN font_user_name_size_px INT DEFAULT 40",
  'font_user_name_color' => "ALTER TABLE department_certificate_templates ADD COLUMN font_user_name_color VARCHAR(15) DEFAULT '#000000'",
  'font_department_name_family' => "ALTER TABLE department_certificate_templates ADD COLUMN font_department_name_family VARCHAR(60) DEFAULT 'Helvetica'",
  'font_department_name_size_px' => "ALTER TABLE department_certificate_templates ADD COLUMN font_department_name_size_px INT DEFAULT 28",
  'font_department_name_color' => "ALTER TABLE department_certificate_templates ADD COLUMN font_department_name_color VARCHAR(15) DEFAULT '#000000'",
  'font_speakers_list_family' => "ALTER TABLE department_certificate_templates ADD COLUMN font_speakers_list_family VARCHAR(60) DEFAULT 'Helvetica'",
  'font_speakers_list_size_px' => "ALTER TABLE department_certificate_templates ADD COLUMN font_speakers_list_size_px INT DEFAULT 16",
  'font_speakers_list_color' => "ALTER TABLE department_certificate_templates ADD COLUMN font_speakers_list_color VARCHAR(15) DEFAULT '#000000'"
];
$colsNow=[]; if($c=$db->query("SHOW COLUMNS FROM department_certificate_templates")){ while($cr=$c->fetch_assoc()){ $colsNow[$cr['Field']]=true; } $c->close(); }
foreach($needCols as $cName=>$ddl){ if(!isset($colsNow[$cName])) { @$db->query($ddl); }}

// New: department certificate signatures table (if not exists)
@$db->query("CREATE TABLE IF NOT EXISTS department_certificate_signatures (
  id INT AUTO_INCREMENT PRIMARY KEY,
  technical_department_id INT NOT NULL,
  indicator VARCHAR(120) NOT NULL DEFAULT '',
  signature_url VARCHAR(255) DEFAULT '',
  pos_x DOUBLE DEFAULT 0,
  pos_y DOUBLE DEFAULT 0,
  width_pct DOUBLE DEFAULT 25.00,
  enabled TINYINT(1) DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX (technical_department_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Utility helpers
function ensure_dir($dir){ if(!is_dir($dir)) mkdir($dir,0755,true); }
$dept_signatures=[];
// Ensure $sel_dept defined before first use
$sel_dept = isset($_GET['dept_id']) ? (int)$_GET['dept_id'] : 0;
if($sel_dept>0){
  if($sg=$db->prepare('SELECT id,indicator,signature_url,pos_x,pos_y,width_pct,enabled FROM department_certificate_signatures WHERE technical_department_id=? ORDER BY id ASC')){
    $sg->bind_param('i',$sel_dept); $sg->execute(); $rs=$sg->get_result(); $dept_signatures=$rs->fetch_all(MYSQLI_ASSOC); $sg->close();
  }
}
function handle_image_upload(string $field){
  if (empty($_FILES[$field]) || $_FILES[$field]['error']===UPLOAD_ERR_NO_FILE) return null;
  $f=$_FILES[$field]; if($f['error']!==UPLOAD_ERR_OK) return ['error'=>'Upload error.'];
  if($f['size']>5*1024*1024) return ['error'=>'Image too large (5MB max).'];
  $allowed=['image/png'=>'png','image/jpeg'=>'jpg','image/jpg'=>'jpg'];
  $finfo=finfo_open(FILEINFO_MIME_TYPE); $mime=finfo_file($finfo,$f['tmp_name']); finfo_close($finfo);
  if(!isset($allowed[$mime])) return ['error'=>'Invalid image type.'];
  $uploadsDir=__DIR__.'/../uploads/certificates'; ensure_dir($uploadsDir);
  $filename=bin2hex(random_bytes(12)).'.'.$allowed[$mime];
  $dest=$uploadsDir.'/'.$filename; if(!move_uploaded_file($f['tmp_name'],$dest)) return ['error'=>'Store failed'];
  return 'uploads/certificates/'.$filename; }

// Fetch departments
$departments=[]; if($r=$db->query("SELECT id,name FROM technical_departments ORDER BY name ASC")){ while($row=$r->fetch_assoc()) $departments[]=$row; }

$errors=[]; $success='';

// Load existing layout row (if any)
$layout=null;
if($sel_dept>0){
  if($st=$db->prepare("SELECT * FROM department_certificate_templates WHERE technical_department_id=? LIMIT 1")){
    $st->bind_param('i',$sel_dept); $st->execute(); $res=$st->get_result(); $layout=$res->fetch_assoc(); $st->close();
  }
}

// Handle create/update layout
if($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='save_layout'){
  $token=$_POST['csrf_token']??''; if(!hash_equals($_SESSION['csrf_token'],$token)){ $errors[]='Invalid CSRF token.'; }
  else {
    $dept_id=(int)($_POST['dept_id']??0); if($dept_id<=0) $errors[]='Department required.';
    $user_ph=substr(trim((string)($_POST['user_name_placeholder']??'{USER_NAME}')),0,120)?:'{USER_NAME}';
    $dept_ph=substr(trim((string)($_POST['department_name_placeholder']??'{DEPARTMENT_NAME}')),0,160)?:'{DEPARTMENT_NAME}';
    $speak_ph=substr(trim((string)($_POST['speakers_list_placeholder']??'{SPEAKERS_LIST}')),0,160)?:'{SPEAKERS_LIST}';
    $pos_user_x=(float)($_POST['pos_user_name_x']??0); $pos_user_y=(float)($_POST['pos_user_name_y']??0);
    $pos_dept_x=(float)($_POST['pos_department_name_x']??0); $pos_dept_y=(float)($_POST['pos_department_name_y']??0);
    $pos_speakers_x=(float)($_POST['pos_speakers_list_x']??0); $pos_speakers_y=(float)($_POST['pos_speakers_list_y']??0);
    $show_user = isset($_POST['show_user_name'])?1:0;
    $show_dept = isset($_POST['show_department_name'])?1:0;
    $show_speakers = isset($_POST['show_speakers_list'])?1:0;

    $allowedFonts=['Helvetica','Arial','Times New Roman','Georgia','Courier'];
    $sanitizeFont=function($f) use ($allowedFonts){ $f=trim((string)$f); return in_array($f,$allowedFonts,true)?$f:'Helvetica'; };
    $sanitizeColor=function($c){ $c=trim((string)$c); return preg_match('/^#[0-9A-Fa-f]{6}$/',$c)?strtoupper($c):'#000000'; };
    $clampSize=function($v,$def){ $n=(int)$v; if($n<6)$n=6; if($n>200)$n=200; return $n?:$def; };

    $font_user_family=$sanitizeFont($_POST['font_user_name_family']??'Helvetica');
    $font_user_size=$clampSize($_POST['font_user_name_size_px']??40,40);
    $font_user_color=$sanitizeColor($_POST['font_user_name_color']??'#000000');
    $font_dept_family=$sanitizeFont($_POST['font_department_name_family']??'Helvetica');
    $font_dept_size=$clampSize($_POST['font_department_name_size_px']??28,28);
    $font_dept_color=$sanitizeColor($_POST['font_department_name_color']??'#000000');
    $font_speakers_family=$sanitizeFont($_POST['font_speakers_list_family']??'Helvetica');
    $font_speakers_size=$clampSize($_POST['font_speakers_list_size_px']??16,16);
    $font_speakers_color=$sanitizeColor($_POST['font_speakers_list_color']??'#000000');

    $new_bg = handle_image_upload('bg_upload');
    if(is_array($new_bg) && isset($new_bg['error'])) $errors[]=$new_bg['error'];
    $bg_url='';
    if($dept_id>0){
      if($st=$db->prepare('SELECT bg_url FROM department_certificate_templates WHERE technical_department_id=? LIMIT 1')){
        $st->bind_param('i',$dept_id); $st->execute(); $st->bind_result($bg_url); $st->fetch(); $st->close();
      }
    }
    if(is_string($new_bg)) $bg_url=$new_bg; // replace if uploaded

    if(empty($errors)){
      // Upsert row
      if($st=$db->prepare("INSERT INTO department_certificate_templates (
          technical_department_id,bg_url,
          user_name_placeholder,department_name_placeholder,speakers_list_placeholder,
          pos_user_name_x,pos_user_name_y,pos_department_name_x,pos_department_name_y,pos_speakers_list_x,pos_speakers_list_y,
          show_user_name,show_department_name,show_speakers_list,
          font_user_name_family,font_user_name_size_px,font_user_name_color,
          font_department_name_family,font_department_name_size_px,font_department_name_color,
          font_speakers_list_family,font_speakers_list_size_px,font_speakers_list_color
        ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
        ON DUPLICATE KEY UPDATE
          bg_url=VALUES(bg_url),
          user_name_placeholder=VALUES(user_name_placeholder),
          department_name_placeholder=VALUES(department_name_placeholder),
          speakers_list_placeholder=VALUES(speakers_list_placeholder),
          pos_user_name_x=VALUES(pos_user_name_x), pos_user_name_y=VALUES(pos_user_name_y),
          pos_department_name_x=VALUES(pos_department_name_x), pos_department_name_y=VALUES(pos_department_name_y),
          pos_speakers_list_x=VALUES(pos_speakers_list_x), pos_speakers_list_y=VALUES(pos_speakers_list_y),
          show_user_name=VALUES(show_user_name), show_department_name=VALUES(show_department_name), show_speakers_list=VALUES(show_speakers_list),
          font_user_name_family=VALUES(font_user_name_family), font_user_name_size_px=VALUES(font_user_name_size_px), font_user_name_color=VALUES(font_user_name_color),
          font_department_name_family=VALUES(font_department_name_family), font_department_name_size_px=VALUES(font_department_name_size_px), font_department_name_color=VALUES(font_department_name_color),
          font_speakers_list_family=VALUES(font_speakers_list_family), font_speakers_list_size_px=VALUES(font_speakers_list_size_px), font_speakers_list_color=VALUES(font_speakers_list_color)
        ")){
        // 23 parameters -> types string length 23
        // i s s s s d d d d d d i i i s i s s i s s i s
        $st->bind_param('issssddddddiiisississis',
          $dept_id,$bg_url,
          $user_ph,$dept_ph,$speak_ph,
          $pos_user_x,$pos_user_y,$pos_dept_x,$pos_dept_y,$pos_speakers_x,$pos_speakers_y,
          $show_user,$show_dept,$show_speakers,
          $font_user_family,$font_user_size,$font_user_color,
          $font_dept_family,$font_dept_size,$font_dept_color,
          $font_speakers_family,$font_speakers_size,$font_speakers_color
        );
        if($st->execute()){
          $success='Layout saved.';
        } else { $errors[]='Save failed: '.$st->error; }
        $st->close();
      } else { $errors[]='Prepare failed: '.$db->error; }
    }

    // Signatures CRUD
    if($dept_id>0 && empty($errors)){
      // Update existing
      $sigIndicators = $_POST['sig_indicator'] ?? [];
      $sigPosX = $_POST['sig_pos_x'] ?? [];
      $sigPosY = $_POST['sig_pos_y'] ?? [];
      $sigWidth = $_POST['sig_width'] ?? [];
      $sigEnabled = $_POST['sig_enabled'] ?? [];
      $sigRemove = $_POST['sig_remove'] ?? [];
      if(is_array($sigIndicators)){
        foreach($sigIndicators as $sid=>$indicator){
          $sid=(int)$sid; if($sid<=0) continue;
          if(isset($sigRemove[$sid])){
            if($del=$db->prepare('DELETE FROM department_certificate_signatures WHERE id=? AND technical_department_id=?')){ $del->bind_param('ii',$sid,$dept_id); $del->execute(); $del->close(); }
            continue;
          }
            $indicator=substr(trim((string)$indicator),0,120);
            $px=isset($sigPosX[$sid])?(float)$sigPosX[$sid]:0.0;
            $py=isset($sigPosY[$sid])?(float)$sigPosY[$sid]:0.0;
            $width=isset($sigWidth[$sid])?max(5,min(100,(float)$sigWidth[$sid])):25.0;
            $enabled=isset($sigEnabled[$sid])?1:0;
            $newFile = null;
            if(!empty($_FILES['sig_file']['name'][$sid] ?? '')){
              // emulate single file structure
              $tmpArr = [
                'name'=>$_FILES['sig_file']['name'][$sid],
                'type'=>$_FILES['sig_file']['type'][$sid] ?? '',
                'tmp_name'=>$_FILES['sig_file']['tmp_name'][$sid],
                'error'=>$_FILES['sig_file']['error'][$sid],
                'size'=>$_FILES['sig_file']['size'][$sid]
              ];
              $_FILES['__one_sig']=$tmpArr;
              $newFile = handle_image_upload('__one_sig');
              unset($_FILES['__one_sig']);
              if(is_array($newFile)&&isset($newFile['error'])){ $errors[]='Signature upload error: '.$newFile['error']; $newFile=null; }
            }
            if(empty($errors)){
              if(is_string($newFile)){
                if($up=$db->prepare('UPDATE department_certificate_signatures SET indicator=?, signature_url=?, pos_x=?, pos_y=?, width_pct=?, enabled=? WHERE id=? AND technical_department_id=?')){
                  $up->bind_param('ssdddiii',$indicator,$newFile,$px,$py,$width,$enabled,$sid,$dept_id);
                  $up->execute(); $up->close();
                }
              } else {
                if($up=$db->prepare('UPDATE department_certificate_signatures SET indicator=?, pos_x=?, pos_y=?, width_pct=?, enabled=? WHERE id=? AND technical_department_id=?')){
                  $up->bind_param('sdddiii',$indicator,$px,$py,$width,$enabled,$sid,$dept_id); $up->execute(); $up->close();
                }
              }
            }
        }
      }
      // New signatures
      $newInd = $_POST['new_sig_indicator'] ?? [];
      if(is_array($newInd)){
        foreach($newInd as $i=>$indicator){
          $indicator = substr(trim((string)$indicator),0,120);
          if($indicator==='') continue;
          if(!empty($_FILES['new_sig_file']['name'][$i] ?? '')){
            $tmpArr=[
              'name'=>$_FILES['new_sig_file']['name'][$i],
              'type'=>$_FILES['new_sig_file']['type'][$i] ?? '',
              'tmp_name'=>$_FILES['new_sig_file']['tmp_name'][$i],
              'error'=>$_FILES['new_sig_file']['error'][$i],
              'size'=>$_FILES['new_sig_file']['size'][$i]
            ];
            $_FILES['__one_new_sig']=$tmpArr;
            $file = handle_image_upload('__one_new_sig');
            unset($_FILES['__one_new_sig']);
            if(is_array($file)&&isset($file['error'])){ $errors[]='New signature upload error: '.$file['error']; continue; }
            if(is_string($file)){
              if($ins=$db->prepare('INSERT INTO department_certificate_signatures (technical_department_id,indicator,signature_url,pos_x,pos_y,width_pct,enabled) VALUES (?,?,?,?,?,?,1)')){
                $zero=0; $w=25.0; $ins->bind_param('issddd',$dept_id,$indicator,$file,$zero,$zero,$w); $ins->execute(); $ins->close();
              }
            }
          }
        }
      }
    }

    // reload signatures after save
    if($dept_id>0){
      $dept_signatures=[]; if($sg=$db->prepare('SELECT id,indicator,signature_url,pos_x,pos_y,width_pct,enabled FROM department_certificate_signatures WHERE technical_department_id=? ORDER BY id ASC')){ $sg->bind_param('i',$dept_id); $sg->execute(); $rs=$sg->get_result(); $dept_signatures=$rs->fetch_all(MYSQLI_ASSOC); $sg->close(); }
    }
    // reload layout after save
    if(empty($errors)){
      if($st=$db->prepare("SELECT * FROM department_certificate_templates WHERE technical_department_id=? LIMIT 1")){
        $st->bind_param('i',$dept_id); $st->execute(); $res=$st->get_result(); $layout=$res->fetch_assoc(); $st->close();
      }
      $sel_dept=$dept_id;
    }
  }
  // Only rotate CSRF token after a successful save to reduce accidental invalid token errors
  if(empty($errors)){
    $_SESSION['csrf_token']=bin2hex(random_bytes(16));
  }
}

// Provide default layout if none yet
if($sel_dept>0 && !$layout){
  $layout=[
    'bg_url'=>'',
    'user_name_placeholder'=>'{USER_NAME}',
    'department_name_placeholder'=>'{DEPARTMENT_NAME}',
    'speakers_list_placeholder'=>'{SPEAKERS_LIST}',
    'pos_user_name_x'=>0,'pos_user_name_y'=>0,
    'pos_department_name_x'=>0,'pos_department_name_y'=>0,
    'pos_speakers_list_x'=>0,'pos_speakers_list_y'=>0,
    'show_user_name'=>1,'show_department_name'=>1,'show_speakers_list'=>1,
    'font_user_name_family'=>'Helvetica','font_user_name_size_px'=>40,'font_user_name_color'=>'#000000',
    'font_department_name_family'=>'Helvetica','font_department_name_size_px'=>28,'font_department_name_color'=>'#000000',
    'font_speakers_list_family'=>'Helvetica','font_speakers_list_size_px'=>16,'font_speakers_list_color'=>'#000000'
  ];
}

?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Department Certificate Layouts</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
<style>
:root { --grad:linear-gradient(135deg,#6366f1 0%,#8b5cf6 100%); --bg:#f1f5f9; --surface:#ffffff; --border:#e2e8f0; --text:#1e293b; --muted:#64748b; --radius:14px; --shadow:0 4px 12px rgba(0,0,0,.06);} 
body{background:var(--bg);font-family:'Segoe UI',system-ui,Arial,sans-serif;color:var(--text);margin:0}
.page-header{background:var(--grad);color:#fff;padding:1rem 1.4rem;display:flex;justify-content:space-between;align-items:center;box-shadow:0 6px 16px -6px rgba(0,0,0,.3)}
.page-header h1{margin:0;font-size:1.3rem;font-weight:600;display:flex;align-items:center;gap:.55rem}
.header-actions a{background:rgba(255,255,255,.18);border:1px solid rgba(255,255,255,.35);padding:.45rem .85rem;border-radius:8px;color:#fff;font-size:.75rem;font-weight:500;text-decoration:none;backdrop-filter:blur(6px);transition:.2s}
.header-actions a:hover{background:rgba(255,255,255,.32)}
.container-wide{max-width:1700px;margin:0 auto;padding:1.4rem 1.1rem}
.card-modern{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);box-shadow:var(--shadow);overflow:hidden;position:relative}
.card-head{padding:.9rem 1.1rem;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center;background:linear-gradient(135deg,#f8fafc 0%,#eef2f7 100%)}
.card-head h2{margin:0;font-size:.95rem;font-weight:600;display:flex;align-items:center;gap:.5rem;color:#334155}
.card-body{padding:1.1rem 1.2rem}
.btn-modern{border:none;border-radius:10px;padding:.65rem 1rem;font-size:.75rem;font-weight:500;display:inline-flex;align-items:center;gap:.45rem;cursor:pointer;transition:.22s;background:#6366f1;color:#fff;text-decoration:none}
.btn-modern:hover{background:#4f46e5;color:#fff}
.btn-outline{background:#fff;color:#334155;border:1px solid var(--border)}.btn-outline:hover{background:#f1f5f9}
.layout-wrapper{display:flex;flex-direction:column;gap:1.4rem}
.editor-grid{display:grid;grid-template-columns:1fr 370px;gap:1.4rem;align-items:start}
@media(max-width:1400px){.editor-grid{grid-template-columns:1fr 400px}}@media(max-width:1100px){.editor-grid{grid-template-columns:1fr}} 
.canvas-shell{background:#fff;border:1px solid var(--border);border-radius:16px;padding:1.2rem;box-shadow:0 8px 22px -8px rgba(0,0,0,.08);position:relative}
.canvas-tools{display:flex;gap:.5rem;margin-bottom:.75rem;flex-wrap:wrap}
.tool-btn{width:38px;height:38px;border-radius:8px;border:1px solid var(--border);background:#fff;display:flex;align-items:center;justify-content:center;color:#64748b;cursor:pointer;transition:.18s;font-size:.9rem}
.tool-btn:hover{background:#f1f5f9;color:#1e293b}
.canvas-stage{position:relative;overflow:auto;border:2px dashed #cbd5e1;border-radius:12px;min-height:70vh;background:#f8fafc;display:flex;align-items:center;justify-content:center;padding:1.2rem}
.cert-canvas{position:relative;box-shadow:0 10px 24px -8px rgba(0,0,0,.22);border-radius:8px;overflow:hidden;display:inline-block;transition:transform .3s ease;background:#fff}
.cert-canvas:hover{transform:scale(1.01)}
.cert-bg{display:block;width:auto;height:auto;max-height:72vh;max-width:100%;}
.cert-canvas.no-bg{width:1200px;height:675px;background:repeating-linear-gradient(45deg,#f8fafc,#f8fafc 10px,#eef2f7 10px,#eef2f7 20px);} /* 16:9 placeholder */
.overlay{position:absolute;inset:0;z-index:2;pointer-events:auto}
.draggable{position:absolute;cursor:move;user-select:none;transition:transform .15s ease;font-weight:500;display:inline-block;padding:.35rem .55rem;border-radius:6px;backdrop-filter:blur(6px);line-height:1.1}
.draggable.dragging{z-index:20;transform:scale(1.08);box-shadow:0 6px 18px -6px rgba(0,0,0,.35)}
.ph-block{background:rgba(99,102,241,.14);border:2px dashed #6366f1;color:#1e293b;font-size:.85rem}
.ph-block.alt{background:rgba(16,185,129,.14);border-color:#10b981}
.ph-block.list{background:rgba(234,179,8,.16);border-color:#eab308}
.sidebar-panel{background:#fff;border:1px solid var(--border);border-radius:14px;box-shadow:var(--shadow);padding:1rem;display:flex;flex-direction:column;gap:1rem}
.side-section-title{font-size:.65rem;font-weight:700;letter-spacing:.05em;text-transform:uppercase;color:#475569;margin-bottom:.35rem;display:flex;align-items:center;gap:.35rem}
.switch-row{display:flex;align-items:center;justify-content:space-between;background:#f8fafc;padding:.55rem .7rem;border:1px solid var(--border);border-radius:8px;font-size:.7rem}
.switch-row input{margin-right:.5rem}
.coord-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:.45rem}
.coord-grid label{font-size:.6rem;text-transform:uppercase;font-weight:600;color:#64748b;margin-bottom:.15rem}
.coord-grid .cell{background:#f8fafc;padding:.45rem .5rem;border:1px solid var(--border);border-radius:8px}
.coord-grid input{width:100%;border:1px solid var(--border);background:#fff;border-radius:6px;padding:.25rem .35rem;font-size:.65rem;font-family:monospace;text-align:center}
.font-config{display:grid;grid-template-columns:2fr 1fr 1fr;gap:.4rem;align-items:end;background:#f8fafc;padding:.6rem .65rem;border:1px solid var(--border);border-radius:10px;margin-bottom:.6rem}
.font-config label{grid-column:1/-1;font-size:.58rem;letter-spacing:.05em;font-weight:600;color:#475569;margin:0 0 .25rem}
.font-config select,.font-config input[type=number],.font-config input[type=color]{width:100%;border:1px solid var(--border);background:#fff;border-radius:6px;padding:.3rem .4rem;font-size:.65rem}
.bg-upload-box{border:2px dashed #94a3b8;padding:1rem;border-radius:12px;background:#f8fafc;display:flex;flex-direction:column;gap:.4rem;align-items:flex-start}
.actions{display:flex;gap:.65rem;flex-wrap:wrap;margin-top:.6rem}
.badge-hint{background:#6366f1;color:#fff;padding:.25rem .55rem;font-size:.55rem;border-radius:30px;letter-spacing:.05em}
.save-indicator{font-size:.6rem;color:#64748b;margin-left:.35rem}
.alert{border-radius:12px}
.help-block{font-size:.6rem;color:#64748b;line-height:1.25;margin-top:.3rem}
@media(max-width:1000px){.canvas-stage{min-height:55vh}.cert-bg{max-height:60vh}}
</style>
</head>
<body>
  <header class="page-header">
    <h1><i class="bi bi-layout-text-window"></i> <span>Department Certificate Layouts</span></h1>
    <div class="header-actions d-flex gap-2">
      <a href="Admin_dashboard.php"><i class="bi bi-speedometer2"></i> Dashboard</a>
      <a href="admin_certificate_edit.php"><i class="bi bi-palette"></i> Main Certificates</a>
    </div>
  </header>
  <div class="container-wide">
    <?php if(!empty($errors)): ?>
      <div class="alert alert-danger mb-3"><strong>Errors:</strong><ul class="mb-0">
        <?php foreach($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?>
      </ul></div>
    <?php endif; ?>
    <?php if($success): ?><div class="alert alert-success mb-3"><i class="bi bi-check2-circle"></i> <?= e($success) ?></div><?php endif; ?>
    <div class="card-modern mb-4">
      <div class="card-head"><h2><i class="bi bi-folder2-open"></i>Select Department</h2></div>
      <div class="card-body">
        <form method="get" class="row g-3 align-items-end">
          <div class="col-sm-6 col-md-4">
            <label class="form-label small text-uppercase fw-semibold">Department</label>
            <select name="dept_id" class="form-select form-select-sm" onchange="this.form.submit()">
              <option value="">-- Choose Department --</option>
              <?php foreach($departments as $d): ?>
                <option value="<?= (int)$d['id'] ?>" <?= $sel_dept===(int)$d['id']?'selected':'' ?>><?= e($d['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-sm-auto">
            <button class="btn btn-modern btn-outline" type="submit"><i class="bi bi-arrow-repeat"></i> Load</button>
          </div>
        </form>
        <?php if(!$sel_dept): ?><div class="mt-3 text-muted" style="font-size:.75rem;">Select a department to begin creating its certificate layout.</div><?php endif; ?>
      </div>
    </div>
  </div>
    <?php if($sel_dept>0): ?>
    <form method="post" enctype="multipart/form-data" class="card-modern mt-4" id="deptLayoutForm" autocomplete="off" novalidate>
      <div class="card-head">
        <h2><i class="bi bi-palette"></i> Department Layout Editor <span class="text-muted">#<?= (int)$sel_dept ?></span></h2>
        <div class="d-flex gap-2">
          <button class="btn-modern" type="submit"><i class="bi bi-save2"></i>Save Layout</button>
          <a href="admin_department_certificate_templates.php?dept_id=<?= (int)$sel_dept ?>" class="btn-modern btn-outline"><i class="bi bi-arrow-counterclockwise"></i>Reset</a>
        </div>
      </div>
      <div class="card-body">
        <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
        <input type="hidden" name="dept_id" value="<?= (int)$sel_dept ?>">
        <input type="hidden" name="action" value="save_layout">
        <div class="editor-grid">
          <div class="canvas-shell">
            <div class="canvas-tools">
              <button type="button" class="tool-btn" id="btnReset" title="Reset Positions"><i class="bi bi-arrow-clockwise"></i></button>
              <button type="button" class="tool-btn" id="btnFit" title="Fit to Screen"><i class="bi bi-arrows-fullscreen"></i></button>
              <button type="button" class="tool-btn" id="btnToggleGrid" title="Toggle Grid"><i class="bi bi-grid"></i></button>
            </div>
            <div class="canvas-stage" id="stage">
              <div id="certCanvas" class="cert-canvas">
                <img id="bgImage" class="cert-bg" src="<?= e(!empty($layout['bg_url'])?('../'.ltrim($layout['bg_url'],'/')):'') ?>" alt="Background" onerror="this.style.display='none'">
                <div class="overlay" id="overlay">
                  <div id="ph-user" class="draggable ph-block <?= $layout['show_user_name']? '' : 'd-none' ?>" title="Drag User Name">
                    <?= e($layout['user_name_placeholder']??'{USER_NAME}') ?>
                  </div>
                  <div id="ph-dept" class="draggable ph-block alt <?= $layout['show_department_name']? '' : 'd-none' ?>" title="Drag Department Name">
                    <?= e($layout['department_name_placeholder']??'{DEPARTMENT_NAME}') ?>
                  </div>
                  <div id="ph-speakers" class="draggable ph-block list <?= $layout['show_speakers_list']? '' : 'd-none' ?>" title="Drag Speakers List">
                    <?= e($layout['speakers_list_placeholder']??'{SPEAKERS_LIST}') ?>
                  </div>
                  <?php foreach($dept_signatures as $sg): $sid=(int)$sg['id']; $enabled=(int)$sg['enabled']; $w=(float)($sg['width_pct']??25); ?>
                    <?php if(!empty($sg['signature_url'])): ?>
                      <img id="sig-<?= $sid ?>" data-id="<?= $sid ?>" class="draggable ph-block sig-node" src="<?= e('../'.ltrim($sg['signature_url'],'/')) ?>" alt="Signature" style="left:0;top:0;width:<?= $w ?>%;height:auto;<?= $enabled? '' : 'display:none;' ?>">
                    <?php else: ?>
                      <div id="sig-<?= $sid ?>" data-id="<?= $sid ?>" class="draggable ph-block sig-node" style="left:0;top:0;width:<?= $w ?>%;<?= $enabled? '' : 'display:none;' ?>;text-align:center;min-height:50px;">SIGNATURE<br><small><?= e($sg['indicator']) ?></small></div>
                    <?php endif; ?>
                  <?php endforeach; ?>
                </div>
              </div>
            </div>
          </div>
          <div class="sidebar-panel">
            <div>
              <div class="side-section-title"><i class="bi bi-image"></i>Background</div>
              <div class="bg-upload-box">
                <input type="file" name="bg_upload" accept="image/png,image/jpeg" class="form-control form-control-sm">
                <div class="help-block">Upload PNG/JPG (max 5MB). Leave empty to keep current.</div>
                <?php if(!empty($layout['bg_url'])): ?><div class="help-block"><i class="bi bi-check2-circle text-success"></i> Current: <?= e(basename($layout['bg_url'])) ?></div><?php endif; ?>
              </div>
            </div>
            <div>
              <div class="side-section-title"><i class="bi bi-eye"></i>Visibility</div>
              <div class="switch-row"><label class="mb-0"><input type="checkbox" name="show_user_name" id="toggle-user" <?= !empty($layout['show_user_name'])?'checked':'' ?>> User Name</label></div>
              <div class="switch-row"><label class="mb-0"><input type="checkbox" name="show_department_name" id="toggle-dept" <?= !empty($layout['show_department_name'])?'checked':'' ?>> Department Name</label></div>
              <div class="switch-row"><label class="mb-0"><input type="checkbox" name="show_speakers_list" id="toggle-speakers" <?= !empty($layout['show_speakers_list'])?'checked':'' ?>> Speakers List</label></div>
            </div>
            <div>
              <div class="side-section-title"><i class="bi bi-braces"></i>Placeholders</div>
              <div class="mb-2">
                <label class="form-label mb-1" style="font-size:.65rem">User Name Placeholder</label>
                <input type="text" class="form-control form-control-sm" name="user_name_placeholder" id="inp-user-ph" value="<?= e($layout['user_name_placeholder']) ?>">
              </div>
              <div class="mb-2">
                <label class="form-label mb-1" style="font-size:.65rem">Department Name Placeholder</label>
                <input type="text" class="form-control form-control-sm" name="department_name_placeholder" id="inp-dept-ph" value="<?= e($layout['department_name_placeholder']) ?>">
              </div>
              <div class="mb-2">
                <label class="form-label mb-1" style="font-size:.65rem">Speakers List Placeholder</label>
                <input type="text" class="form-control form-control-sm" name="speakers_list_placeholder" id="inp-speakers-ph" value="<?= e($layout['speakers_list_placeholder']) ?>">
              </div>
              <div class="help-block">Use consistent tokens. They will be replaced when generating the PDF.</div>
            </div>
            <div>
              <div class="side-section-title"><i class="bi bi-crosshair"></i>Positions (Percent)</div>
              <div class="coord-grid mb-2">
                <div class="cell"><label>User X</label><input type="number" step="any" name="pos_user_name_x" id="pos-user-x" value="<?= (float)$layout['pos_user_name_x'] ?>"></div>
                <div class="cell"><label>User Y</label><input type="number" step="any" name="pos_user_name_y" id="pos-user-y" value="<?= (float)$layout['pos_user_name_y'] ?>"></div>
                <div class="cell d-flex align-items-center justify-content-center"><span class="badge-hint">User</span></div>
                <div class="cell"><label>Dept X</label><input type="number" step="any" name="pos_department_name_x" id="pos-dept-x" value="<?= (float)$layout['pos_department_name_x'] ?>"></div>
                <div class="cell"><label>Dept Y</label><input type="number" step="any" name="pos_department_name_y" id="pos-dept-y" value="<?= (float)$layout['pos_department_name_y'] ?>"></div>
                <div class="cell d-flex align-items-center justify-content-center"><span class="badge-hint" style="background:#10b981">Dept</span></div>
                <div class="cell"><label>List X</label><input type="number" step="any" name="pos_speakers_list_x" id="pos-speakers-x" value="<?= (float)$layout['pos_speakers_list_x'] ?>"></div>
                <div class="cell"><label>List Y</label><input type="number" step="any" name="pos_speakers_list_y" id="pos-speakers-y" value="<?= (float)$layout['pos_speakers_list_y'] ?>"></div>
                <div class="cell d-flex align-items-center justify-content-center"><span class="badge-hint" style="background:#eab308">List</span></div>
              </div>
              <div class="help-block">Drag blocks or enter precise coordinates (0–100).</div>
            </div>
            <div>
              <div class="side-section-title"><i class="bi bi-fonts"></i>Typography</div>
              <?php $fontOptions=['Helvetica','Arial','Times New Roman','Georgia','Courier']; function fontSel($n,$cur,$opts){$h='<select name="'.$n.'" class="form-select form-select-sm">'; foreach($opts as $o){$sel=$o===$cur?'selected':''; $h.='<option '.$sel.'>'.htmlspecialchars($o).'</option>'; } return $h.'</select>'; } ?>
              <div class="font-config">
                <label>User Name</label>
                <?= fontSel('font_user_name_family',$layout['font_user_name_family'],$fontOptions) ?>
                <input type="number" min="6" max="200" name="font_user_name_size_px" value="<?= (int)$layout['font_user_name_size_px'] ?>" class="form-control form-control-sm">
                <input type="color" name="font_user_name_color" value="<?= e($layout['font_user_name_color']) ?>" class="form-control form-control-sm">
              </div>
              <div class="font-config">
                <label>Department Name</label>
                <?= fontSel('font_department_name_family',$layout['font_department_name_family'],$fontOptions) ?>
                <input type="number" min="6" max="200" name="font_department_name_size_px" value="<?= (int)$layout['font_department_name_size_px'] ?>" class="form-control form-control-sm">
                <input type="color" name="font_department_name_color" value="<?= e($layout['font_department_name_color']) ?>" class="form-control form-control-sm">
              </div>
              <div class="font-config">
                <label>Speakers List</label>
                <?= fontSel('font_speakers_list_family',$layout['font_speakers_list_family'],$fontOptions) ?>
                <input type="number" min="6" max="200" name="font_speakers_list_size_px" value="<?= (int)$layout['font_speakers_list_size_px'] ?>" class="form-control form-control-sm">
                <input type="color" name="font_speakers_list_color" value="<?= e($layout['font_speakers_list_color']) ?>" class="form-control form-control-sm">
              </div>
            </div>
            <div>
              <div class="side-section-title"><i class="bi bi-pen"></i>Signatures</div>
              <?php if(empty($dept_signatures)): ?>
                <div class="help-block">No signatures yet. Add new below.</div>
              <?php else: foreach($dept_signatures as $sg): $sid=(int)$sg['id']; ?>
                <div class="p-2 mb-2 border rounded" style="background:#f8fafc;">
                  <div class="d-flex justify-content-between align-items-center mb-1" style="font-size:.7rem;font-weight:600;">
                    <span>#<?= $sid ?> <?= e($sg['indicator']) ?></span>
                    <label class="m-0" style="font-weight:400;">
                      <input type="checkbox" name="sig_enabled[<?= $sid ?>]" <?= !empty($sg['enabled'])?'checked':'' ?>> Enabled
                    </label>
                  </div>
                  <div class="mb-1"><input type="text" class="form-control form-control-sm" name="sig_indicator[<?= $sid ?>]" value="<?= e($sg['indicator']) ?>" placeholder="Indicator"></div>
                  <div class="mb-1"><input type="file" class="form-control form-control-sm" name="sig_file[<?= $sid ?>]" accept="image/png,image/jpeg"></div>
                  <div class="mb-1" style="font-size:.6rem;">Width <input type="range" min="5" max="100" step="1" value="<?= (float)$sg['width_pct'] ?>" class="sig-width-slider form-range" data-id="<?= $sid ?>" name="sig_width[<?= $sid ?>]"></div>
                  <input type="hidden" id="sig-pos-x-<?= $sid ?>" name="sig_pos_x[<?= $sid ?>]" value="<?= (float)$sg['pos_x'] ?>">
                  <input type="hidden" id="sig-pos-y-<?= $sid ?>" name="sig_pos_y[<?= $sid ?>]" value="<?= (float)$sg['pos_y'] ?>">
                  <div class="form-check" style="font-size:.6rem;">
                    <input class="form-check-input" type="checkbox" value="1" id="sig-remove-<?= $sid ?>" name="sig_remove[<?= $sid ?>]">
                    <label for="sig-remove-<?= $sid ?>" class="form-check-label text-danger">Delete</label>
                  </div>
                </div>
              <?php endforeach; endif; ?>
              <div class="mt-2 border rounded p-2" style="background:#fff;">
                <div style="font-size:.65rem;font-weight:600;" class="mb-1">Add Signatures</div>
                <div id="newSigContainer"></div>
                <button type="button" id="addNewSigBtn" class="btn btn-sm btn-outline-secondary" style="font-size:.6rem;">+ Add Signature Row</button>
              </div>
            </div>
            <div class="actions">
              <button class="btn-modern" type="submit"><i class="bi bi-save"></i>Save</button>
              <button class="btn-modern btn-outline" type="button" id="btnPreview"><i class="bi bi-eye"></i>Preview PDF</button>
            </div>
          </div>
        </div>
        <div class="save-indicator" id="saveStatus"></div>
      </div>
    </form>
    <?php endif; ?>
  </div>

<form id="previewForm" action="admin_department_certificate_preview.php" method="post" target="_blank" style="display:none;">
  <input type="hidden" name="dept_id" value="<?= (int)$sel_dept ?>">
  <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
  <input type="hidden" name="layout_preview" id="layoutPreviewJson" value="">
</form>
<script>
(function(){
  const id = <?= (int)$sel_dept ?>;
  if(!id) return; // nothing to init
  const bg = document.getElementById('bgImage');
  const canvas = document.getElementById('certCanvas');
  const stage = document.getElementById('stage');
  const phUser = document.getElementById('ph-user');
  const phDept = document.getElementById('ph-dept');
  const phSpeakers = document.getElementById('ph-speakers');

  const posUserX = document.getElementById('pos-user-x');
  const posUserY = document.getElementById('pos-user-y');
  const posDeptX = document.getElementById('pos-dept-x');
  const posDeptY = document.getElementById('pos-dept-y');
  const posSpeakersX = document.getElementById('pos-speakers-x');
  const posSpeakersY = document.getElementById('pos-speakers-y');

  const inpUserPh = document.getElementById('inp-user-ph');
  const inpDeptPh = document.getElementById('inp-dept-ph');
  const inpSpeakersPh = document.getElementById('inp-speakers-ph');

  const tUser = document.getElementById('toggle-user');
  const tDept = document.getElementById('toggle-dept');
  const tSpeakers = document.getElementById('toggle-speakers');

  function clamp(v,min,max){return Math.max(min,Math.min(max,v));}
  // Place an element using percentage coordinates relative to background if present, otherwise the canvas fallback size
  function place(el,xPct,yPct){
    const hasBg = bg && bg.getAttribute('src') && bg.style.display !== 'none';
    const ref = hasBg ? bg : canvas; // fallback to canvas when no background image
    const W = ref.clientWidth || 1;
    const H = ref.clientHeight || 1;
    el.style.left = (xPct/100*W) + 'px';
    el.style.top  = (yPct/100*H) + 'px';
  }
  function applyAll(){
    place(phUser, parseFloat(posUserX.value||0), parseFloat(posUserY.value||0));
    place(phDept, parseFloat(posDeptX.value||0), parseFloat(posDeptY.value||0));
    place(phSpeakers, parseFloat(posSpeakersX.value||0), parseFloat(posSpeakersY.value||0));
    applySignaturePositions();
  }

  function applySignaturePositions(){
    document.querySelectorAll('.sig-node').forEach(function(sig){
      var sid = sig.getAttribute('data-id');
      var ix = document.getElementById('sig-pos-x-'+sid);
      var iy = document.getElementById('sig-pos-y-'+sid);
      if(ix && iy){
        var x = parseFloat(ix.value||0);
        var y = parseFloat(iy.value||0);
        place(sig,x,y);
      }
    });
  }

  function makeDraggable(el,setters){
    let drag=false,sx=0,sy=0,baseL=0,baseT=0;
    el.addEventListener('mousedown',e=>{ e.preventDefault(); drag=true; sx=e.clientX; sy=e.clientY; baseL=parseFloat(el.style.left||'0'); baseT=parseFloat(el.style.top||'0'); el.classList.add('dragging'); document.body.style.userSelect='none'; });
    window.addEventListener('mousemove',e=>{ if(!drag) return; const dx=e.clientX-sx, dy=e.clientY-sy; const W=bg.clientWidth||1,H=bg.clientHeight||1; let nl=clamp(baseL+dx,0,W); let nt=clamp(baseT+dy,0,H); el.style.left=nl+'px'; el.style.top=nt+'px'; const xPct=nl/W*100, yPct=nt/H*100; setters(xPct,yPct); });
    window.addEventListener('mouseup',()=>{ if(drag) el.classList.remove('dragging'); drag=false; document.body.style.userSelect=''; });
  }
  makeDraggable(phUser,(x,y)=>{ posUserX.value=x.toFixed(2); posUserY.value=y.toFixed(2); });
  makeDraggable(phDept,(x,y)=>{ posDeptX.value=x.toFixed(2); posDeptY.value=y.toFixed(2); });
  makeDraggable(phSpeakers,(x,y)=>{ posSpeakersX.value=x.toFixed(2); posSpeakersY.value=y.toFixed(2); });

  [posUserX,posUserY,posDeptX,posDeptY,posSpeakersX,posSpeakersY].forEach(inp=>{ inp&&inp.addEventListener('input',applyAll); });
  if(inpUserPh) inpUserPh.addEventListener('input',()=>{ phUser.textContent=inpUserPh.value||'{USER_NAME}'; });
  if(inpDeptPh) inpDeptPh.addEventListener('input',()=>{ phDept.textContent=inpDeptPh.value||'{DEPARTMENT_NAME}'; });
  if(inpSpeakersPh) inpSpeakersPh.addEventListener('input',()=>{ phSpeakers.textContent=inpSpeakersPh.value||'{SPEAKERS_LIST}'; });

  if(tUser) tUser.addEventListener('change',()=>{ phUser.classList.toggle('d-none',!tUser.checked); });
  if(tDept) tDept.addEventListener('change',()=>{ phDept.classList.toggle('d-none',!tDept.checked); });
  if(tSpeakers) tSpeakers.addEventListener('change',()=>{ phSpeakers.classList.toggle('d-none',!tSpeakers.checked); });

  function fitToScreen(){ if(!canvas || !stage) return; canvas.style.transform='scale(1)'; setTimeout(()=>{ const wrap=stage.getBoundingClientRect(); const rect=canvas.getBoundingClientRect(); const scale=Math.min((wrap.width-60)/rect.width,(wrap.height-60)/rect.height,1); canvas.style.transform='scale('+scale+')'; canvas.style.transformOrigin='center center'; },25); }

  document.getElementById('btnFit')?.addEventListener('click',fitToScreen);
  document.getElementById('btnReset')?.addEventListener('click',()=>{
    posUserX.value=posUserY.value=posDeptX.value=posDeptY.value=posSpeakersX.value=posSpeakersY.value='0'; applyAll();
  });
  document.getElementById('btnToggleGrid')?.addEventListener('click',()=>{ stage.classList.toggle('with-grid'); });
  window.addEventListener('resize',applyAll);
  bg?.addEventListener('load',()=>{ applyAll(); fitToScreen(); });
  // If no background image, give canvas a default working size so placeholders are visible
  if(bg && (!bg.getAttribute('src') || bg.getAttribute('src')==='')){
    canvas.classList.add('no-bg');
  } else {
    canvas.classList.remove('no-bg');
  }
  applyAll();

  // Preview PDF: send JSON of layout (future server endpoint can render)
  // --- Signatures Drag / Width ---
  document.querySelectorAll('.sig-node').forEach(function(sig){
    makeDraggable(sig, function(x,y){
      var sid = sig.getAttribute('data-id');
      var ix = document.getElementById('sig-pos-x-'+sid);
      var iy = document.getElementById('sig-pos-y-'+sid);
      if(ix) ix.value = x.toFixed(2);
      if(iy) iy.value = y.toFixed(2);
    });
  });
  document.querySelectorAll('.sig-width-slider').forEach(function(sl){
    sl.addEventListener('input', function(){
      var sid=this.dataset.id; var node=document.getElementById('sig-'+sid); if(node){ node.style.width=this.value+'%'; }
    });
  });

  // Dynamic new signature rows
  (function(){
    var addBtn = document.getElementById('addNewSigBtn');
    var container = document.getElementById('newSigContainer');
    if(!addBtn || !container) return;
    var idx = 0;
    addBtn.addEventListener('click', function(){
      var wrap = document.createElement('div');
      wrap.className = 'p-2 mb-2 border rounded';
      wrap.style.background = '#f8fafc';
      wrap.innerHTML = '<div style="font-size:.6rem;font-weight:600;" class="mb-1">New Signature</div>'+
        '<div class="mb-1"><input type="text" name="new_sig_indicator['+idx+']" class="form-control form-control-sm" placeholder="Indicator / Title"></div>'+
        '<div class="mb-1"><input type="file" name="new_sig_file['+idx+']" class="form-control form-control-sm" accept="image/png,image/jpeg"></div>'+
        '<div class="help-block">Saved after clicking Save Layout.</div>';
      container.appendChild(wrap);
      idx++;
    });
  })();

  document.getElementById('btnPreview')?.addEventListener('click',()=>{
    const payload = {
      dept_id:id,
      user_name_placeholder: inpUserPh.value,
      department_name_placeholder: inpDeptPh.value,
      speakers_list_placeholder: inpSpeakersPh.value,
      show_user_name: tUser.checked?1:0,
      show_department_name: tDept.checked?1:0,
      show_speakers_list: tSpeakers.checked?1:0,
      positions: {
        user:{x:parseFloat(posUserX.value||0),y:parseFloat(posUserY.value||0)},
        department:{x:parseFloat(posDeptX.value||0),y:parseFloat(posDeptY.value||0)},
        speakers:{x:parseFloat(posSpeakersX.value||0),y:parseFloat(posSpeakersY.value||0)}
      }
    };
    document.getElementById('layoutPreviewJson').value = JSON.stringify(payload);
    document.getElementById('previewForm').submit();
  });
})();
</script>
</body></html>