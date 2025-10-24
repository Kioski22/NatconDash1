<?php
// Temporary signup closed landing page
// Note: Do NOT modify api_signup.php or signup.php per request.
// This page is a simple, static notice you can redirect users to.
?><!doctype html>
<html lang="en">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta http-equiv="X-UA-Compatible" content="IE=edge">
	<meta name="robots" content="noindex, nofollow">
	<title>Registration Closed</title>
	<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
	<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
	<style>
		:root{
			--primary:#2563eb; --primary-dark:#1d4ed8;
			--card-bg:rgba(255,255,255,0.94); --shadow:0 14px 40px rgba(0,0,0,0.12);
		}
		body{
			min-height:100vh; margin:0; display:flex; align-items:center; justify-content:center;
			background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
			font-family: system-ui, -apple-system, Segoe UI, Roboto, "Helvetica Neue", Arial, "Noto Sans", "Apple Color Emoji", "Segoe UI Emoji", "Segoe UI Symbol", sans-serif;
		}
		.card-wrap{
			width:min(720px, 92vw);
			background:var(--card-bg);
			backdrop-filter: blur(14px);
			border:1px solid rgba(255,255,255,0.35);
			border-radius:22px;
			box-shadow:var(--shadow);
			overflow:hidden;
		}
		.hero{
			background: linear-gradient(135deg, rgba(37,99,235,0.12), rgba(6,182,212,0.12));
			border-bottom:1px solid rgba(37,99,235,0.15);
			padding:26px 28px;
			text-align:center;
		}
		.hero h1{
			margin:0 0 6px; font-weight:800; font-size: clamp(1.4rem, 1.2rem + 1.2vw, 2rem);
			background: linear-gradient(135deg, var(--primary), var(--primary-dark));
			-webkit-background-clip: text; background-clip:text; -webkit-text-fill-color: transparent;
		}
		.badge-closed{ background:#ffe8e8; color:#b42318; border:1px solid #f1b5b5; font-weight:700; letter-spacing:.4px; }
		.content{ padding:26px 28px; }
		.lead { color:#475569; }
		.list-check{ list-style:none; padding:0; margin:18px 0 0; }
		.list-check li{ display:flex; gap:10px; align-items:flex-start; color:#334155; margin:.4rem 0; }
		.list-check .icon{ color:#10b981; margin-top:2px; }
		.btn-pill{ border-radius:999px; padding:.65rem 1.1rem; font-weight:700; }
		.links .btn{ min-width:160px; }
		.small-note{ color:#64748b; }
	</style>
</head>
<body>
	<main class="card-wrap">
		<div class="hero">
			<div class="d-inline-flex align-items-center gap-2 mb-2">
				<span class="badge rounded-pill badge-closed"><i class="bi bi-slash-circle me-1"></i> Registration Closed</span>
			</div>
			<h1>Signups are temporarily unavailable</h1>
			<div class="small-note">To protect the system from spam and non-registered delegates</div>
		</div>
		<div class="content text-center">
			<p class="lead mb-3">We are not accepting new registrations at the moment.</p>
			<ul class="list-check text-start mx-auto" style="max-width:560px;">
				<li><i class="bi bi-check-circle-fill icon"></i><span>Existing delegates can continue to access their accounts.</span></li>
				<li><i class="bi bi-check-circle-fill icon"></i><span>If you believe this is in error, please contact the organizers.</span></li>
			</ul>
			<div class="links d-flex flex-wrap justify-content-center gap-2 mt-4">
				<a class="btn btn-primary btn-pill" href="/Credentials/login.php"><i class="bi bi-box-arrow-in-right me-1"></i> Go to Login</a>
				<a class="btn btn-outline-secondary btn-pill" href="/"><i class="bi bi-house me-1"></i> Back to Home</a>
			</div>
			<div class="small-note mt-4">Need assistance? Please reach out to the event organizers.</div>
		</div>
	</main>
	<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
