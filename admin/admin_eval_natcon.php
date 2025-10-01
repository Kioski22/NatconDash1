<?php
// Front-end only admin page for NatCon Evaluation Questions (server logic moved to /api endpoints)
session_start();
require_once __DIR__ . '/../db.php';

if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
	header('Location: admin_login.php');
	exit;
}

if (empty($_SESSION['csrf_token'])) {
	$_SESSION['csrf_token'] = bin2hex(random_bytes(16));
}
$csrf = $_SESSION['csrf_token'];

function e($s){ return htmlspecialchars((string)$s, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8'); }
?>
<!doctype html>
<html lang="en">
<head>
	<meta charset="utf-8">
	<title>NatCon Evaluation Questions — Admin</title>
	<meta name="viewport" content="width=device-width,initial-scale=1">
	<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
	<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" integrity="sha512-" crossorigin="anonymous" referrerpolicy="no-referrer"/>
	<style>
		body { background:#f7f7f9; }
		.drag-handle { cursor:grab; color:#888; }
		.dragging { opacity:0.45; }
		.question-card { background:#fff; border:1px solid #ddd; border-radius:6px; padding:0.75rem 0.9rem; margin-bottom:0.6rem; box-shadow:0 1px 2px rgba(0,0,0,.05); }
		.question-card.active-false { opacity:0.55; background:#fcfcfd; }
		.badge-type { text-transform:uppercase; letter-spacing:.5px; }
		.options-list { list-style:none; padding-left:0; margin:0.25rem 0 0; }
		.options-list li { padding:2px 0 2px 2px; display:flex; align-items:center; gap:.5rem; }
		.options-list li span.opt-text { flex:1; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
		.small-muted { font-size:.75rem; color:#6c757d; }
		.reorder-hint { font-size:.75rem; color:#666; }
		.scale-meta { font-size:.7rem; color:#555; }
		.spinner { width:1rem; height:1rem; border:2px solid #ccc; border-top-color:#333; border-radius:50%; animation:spin .8s linear infinite; display:inline-block; }
		@keyframes spin { to { transform:rotate(360deg);} }
	</style>
</head>
<body class="p-3">
	<div class="container-lg">
		<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
			<h4 class="mb-0">NatCon Evaluation Questions</h4>
			<div class="d-flex gap-2">
				<button class="btn btn-primary btn-sm" id="btnNewQuestion"><i class="fa fa-plus me-1"></i> New Question</button>
				<a href="Admin_dashboard.php" class="btn btn-secondary btn-sm">Back</a>
			</div>
		</div>
		<div class="card mb-3">
			<div class="card-body py-2">
				<div class="d-flex flex-wrap align-items-center gap-3">
					<div class="reorder-hint"><i class="fa fa-arrows-alt"></i> Drag cards to reorder. Changes save automatically.</div>
					<div class="small text-muted">Toggle active state to hide a question from the public form.</div>
				</div>
			</div>
		</div>

		<div id="alertContainer"></div>

		<div id="questionsContainer" class="mb-5 position-relative" aria-live="polite">
			<div class="text-center text-muted py-5" id="loadingState">
				<div class="spinner mb-2" role="status" aria-label="Loading"></div><br>
				Loading questions...
			</div>
		</div>
	</div>

	<!-- Question Modal -->
	<div class="modal fade" id="questionModal" tabindex="-1" aria-hidden="true">
		<div class="modal-dialog modal-lg modal-dialog-scrollable">
			<div class="modal-content">
				<form id="questionForm">
					<div class="modal-header">
						<h5 class="modal-title" id="questionModalTitle">New Question</h5>
						<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
					</div>
					<div class="modal-body">
						<input type="hidden" name="id" id="qId">
						<div class="mb-3">
							<label class="form-label">Question Text <span class="text-danger">*</span></label>
							<textarea class="form-control" name="question_text" id="qText" rows="2" required></textarea>
						</div>
						<div class="row g-3">
							<div class="col-md-4">
								<label class="form-label">Type</label>
								<select class="form-select" name="question_type" id="qType" required>
									<option value="scale">Scale (Likert)</option>
									<option value="text">Short Text</option>
									<option value="textarea">Long Text</option>
									<option value="multiple_choice_single">Multiple Choice (Single)</option>
									<option value="multiple_choice_multi">Multiple Choice (Multi)</option>
									<option value="dropdown">Dropdown</option>
									<option value="checkbox">Checkbox (Single Binary)</option>
								</select>
							</div>
							<div class="col-md-4">
								<label class="form-label">Required?</label>
								<select class="form-select" name="is_required" id="qRequired">
									<option value="1">Yes</option>
									<option value="0">No</option>
								</select>
							</div>
							<div class="col-md-4">
								<label class="form-label">Active?</label>
								<select class="form-select" name="is_active" id="qActive">
									<option value="1">Active</option>
									<option value="0">Inactive</option>
								</select>
							</div>
						</div>
						<hr class="my-3">
						<div id="scaleConfig" class="row g-3">
							<div class="col-12"><div class="fw-semibold small text-muted">Scale Configuration</div></div>
							<div class="col-md-2">
								<label class="form-label">Min</label>
								<input type="number" class="form-control" name="min_scale" id="qMin" min="1" value="1">
							</div>
							<div class="col-md-2">
								<label class="form-label">Max</label>
								<input type="number" class="form-control" name="max_scale" id="qMax" min="2" value="5">
							</div>
							<div class="col-md-4">
								<label class="form-label">Left Label</label>
								<input type="text" class="form-control" name="scale_label_left" id="qLeft" value="Poor">
							</div>
							<div class="col-md-4">
								<label class="form-label">Right Label</label>
								<input type="text" class="form-control" name="scale_label_right" id="qRight" value="Excellent">
							</div>
						</div>
						<div class="small text-muted mt-3" id="typeHint"></div>
					</div>
					<div class="modal-footer">
						<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
						<button class="btn btn-primary" id="saveQuestionBtn">Save</button>
					</div>
				</form>
			</div>
		</div>
	</div>

	<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
	<script>
		const CSRF_TOKEN = <?= json_encode($csrf) ?>;
		const questionsContainer = document.getElementById('questionsContainer');
		const alertContainer = document.getElementById('alertContainer');
		let questions = [];
		let dragSrcEl = null;

		function showAlert(msg, type='success', dismissMs=4000) {
			const id = 'alert-' + Date.now();
			const div = document.createElement('div');
			div.className = 'alert alert-' + type + ' alert-dismissible fade show';
			div.id = id;
			div.innerHTML = `<div>${msg}</div><button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>`;
			alertContainer.appendChild(div);
			if (dismissMs) setTimeout(()=>{ const el=document.getElementById(id); if (el) bootstrap.Alert.getOrCreateInstance(el).close(); }, dismissMs);
		}

			async function api(action, payload={}) {
				const fd = new FormData();
				fd.append('action', action);
				fd.append('csrf_token', CSRF_TOKEN);
				for (const k in payload) {
					if (payload[k] !== undefined && payload[k] !== null) fd.append(k, payload[k]);
				}
				const res = await fetch('../api/natcon_eval_admin.php', { method: 'POST', body: fd });
				const raw = await res.text();
				let data;
				try { data = JSON.parse(raw); } catch(parseErr) {
					console.error('Non-JSON response from admin API:', raw);
					throw new Error('Unexpected non-JSON response (first 180 chars): '+ raw.slice(0,180));
				}
				if (!res.ok || !data.ok) {
					console.error('Admin API error payload:', data);
					throw new Error(data.error || ('Request failed ('+res.status+')'));
				}
				return data;
			}

			async function loadQuestions() {
				questionsContainer.innerHTML = '<div class="text-center text-muted py-4"><div class="spinner mb-2"></div> Loading...</div>';
				try {
					const res = await fetch('../api/natcon_eval_questions.php?all=1');
					const raw = await res.text();
					let data;
					try { data = JSON.parse(raw); } catch(err) {
						console.error('Questions API non-JSON response:', raw);
						throw new Error('Questions API returned non-JSON (first 180 chars): '+ raw.slice(0,180));
					}
					if (!data.ok) throw new Error(data.error||'Load failed');
					questions = data.questions || [];
					renderQuestions();
				} catch(e) {
					questionsContainer.innerHTML = '<div class="alert alert-danger">'+ e.message +'</div>';
				}
			}

		function renderQuestions() {
			if (!questions.length) {
				questionsContainer.innerHTML = '<div class="text-muted py-4">No questions yet. Click "New Question" to start.</div>';
				return;
			}
			const frag = document.createDocumentFragment();
			questions.sort((a,b)=> a.sort_order - b.sort_order);
			questions.forEach(q => frag.appendChild(renderQuestionCard(q)) );
			questionsContainer.innerHTML='';
			questionsContainer.appendChild(frag);
			enableDrag();
		}

		function renderQuestionCard(q) {
			const card = document.createElement('div');
			card.className = 'question-card'+ (q.is_active==1?'':' active-false');
			card.setAttribute('data-id', q.id);
			card.setAttribute('draggable','true');
			card.innerHTML = `
				<div class="d-flex justify-content-between align-items-start gap-2">
					<div class="d-flex align-items-start gap-2 flex-grow-1">
						<div class="drag-handle mt-1" title="Drag to reorder"><i class="fa fa-grip-vertical"></i></div>
						<div class="flex-grow-1">
							<div class="d-flex align-items-center gap-2 flex-wrap">
								<strong>${escapeHtml(q.question_text)}</strong>
								<span class="badge bg-info-subtle border border-info text-info badge-type">${formatType(q.question_type)}</span>
								${q.is_required==1?'<span class="badge bg-danger-subtle text-danger border border-danger">Required</span>':''}
								${q.is_active!=1?'<span class="badge bg-secondary">Inactive</span>':''}
							</div>
							${q.question_type==='scale' ? `<div class="scale-meta mt-1">Scale ${q.min_scale}-${q.max_scale} (${escapeHtml(q.scale_label_left||'')}&nbsp;→&nbsp;${escapeHtml(q.scale_label_right||'')})</div>`:''}
							${needsOptions(q.question_type) ? renderOptionsHTML(q) : ''}
						</div>
					</div>
					<div class="btn-group btn-group-sm">
						<button class="btn btn-outline-secondary" data-action="toggle" title="Toggle Active"><i class="fa ${q.is_active==1? 'fa-eye-slash':'fa-eye'}"></i></button>
						<button class="btn btn-outline-primary" data-action="edit" title="Edit"><i class="fa fa-pen"></i></button>
						<button class="btn btn-outline-danger" data-action="delete" title="Delete"><i class="fa fa-trash"></i></button>
					</div>
				</div>`;
			card.addEventListener('click', (ev) => {
				const btn = ev.target.closest('button[data-action]');
				if (!btn) return;
				const action = btn.getAttribute('data-action');
				if (action === 'edit') openEditModal(q.id);
				else if (action === 'delete') deleteQuestion(q.id);
				else if (action === 'toggle') toggleActive(q.id);
			});
			// Option interactions
			if (needsOptions(q.question_type)) {
				card.querySelector('.add-option-form')?.addEventListener('submit', ev => {
					ev.preventDefault();
						const input = ev.target.querySelector('input[name="option_text"]');
						const val = input.value.trim();
						if (!val) return;
						addOption(q.id, val).then(()=>{ input.value=''; });
				});
				card.querySelectorAll('button[data-del-option]')?.forEach(btn => {
					btn.addEventListener('click', ev => {
						ev.preventDefault();
						const oid = btn.getAttribute('data-del-option');
						if (!oid) return;
						deleteOption(oid);
					});
				});
			}
			return card;
		}

		function renderOptionsHTML(q) {
			const opts = q.options || [];
			const list = opts.map(o => `<li><span class="opt-text" title="${escapeHtml(o.option_text)}">${escapeHtml(o.option_text)}</span><button class="btn btn-outline-danger btn-sm py-0 px-1" data-del-option="${o.id}"><i class="fa fa-xmark"></i></button></li>`).join('');
			return `<ul class="options-list">${list||'<li class="text-muted">(No options)</li>'}</ul>
							<form class="add-option-form mt-1 d-flex gap-2">
								<input type="text" class="form-control form-control-sm" name="option_text" placeholder="Add option" maxlength="255">
								<button class="btn btn-sm btn-outline-success"><i class="fa fa-plus"></i></button>
							</form>`;
		}

		function formatType(t){
			const map = {
				scale:'Scale', text:'Text', textarea:'Long Text', multiple_choice_single:'MCQ Single', multiple_choice_multi:'MCQ Multi', dropdown:'Dropdown', checkbox:'Checkbox'};
			return map[t]||t;
		}
		function needsOptions(t){ return ['multiple_choice_single','multiple_choice_multi','dropdown'].includes(t); }
		function escapeHtml(s){ return (s||'').replace(/[&<>"']/g, c=>({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;','\'':'&#39;' }[c]||c)); }

		function enableDrag(){
			const cards = questionsContainer.querySelectorAll('.question-card');
			cards.forEach(card => {
				card.addEventListener('dragstart', dragStart);
				card.addEventListener('dragover', dragOver);
				card.addEventListener('drop', dropCard);
				card.addEventListener('dragend', dragEnd);
			});
		}
		function dragStart(e){
			dragSrcEl = this;
			this.classList.add('dragging');
			e.dataTransfer.effectAllowed = 'move';
		}
		function dragOver(e){ e.preventDefault(); e.dataTransfer.dropEffect='move'; }
		function dragEnd(){ this.classList.remove('dragging'); }
		function dropCard(e){
			e.preventDefault();
			if (dragSrcEl === this) return;
			const rect = this.getBoundingClientRect();
			const before = (e.clientY - rect.top) < rect.height/2;
			if (before) this.parentNode.insertBefore(dragSrcEl, this); else this.parentNode.insertBefore(dragSrcEl, this.nextSibling);
			persistReorder();
		}
		async function persistReorder(){
			const ids = Array.from(questionsContainer.querySelectorAll('.question-card')).map(el => el.getAttribute('data-id'));
			try {
				await api('reorder', { order: ids.join(',') });
				showAlert('Order updated','success',2000);
				// refresh in background
				loadQuestions();
			} catch(e){ showAlert(e.message,'danger'); }
		}

		// CRUD Actions
		async function deleteQuestion(id){
			if (!confirm('Delete this question? This cannot be undone.')) return;
			try { await api('delete_question',{ id }); showAlert('Deleted','warning'); loadQuestions(); } catch(e){ showAlert(e.message,'danger'); }
		}
		async function toggleActive(id){
			try { await api('toggle_active',{ id }); loadQuestions(); } catch(e){ showAlert(e.message,'danger'); }
		}
		async function addOption(question_id, option_text){
			try { await api('add_option',{ question_id, option_text }); loadQuestions(); } catch(e){ showAlert(e.message,'danger'); }
		}
		async function deleteOption(option_id){
			if (!confirm('Delete this option?')) return;
			try { await api('delete_option',{ option_id }); loadQuestions(); } catch(e){ showAlert(e.message,'danger'); }
		}

		// Modal Handling
		const qModalEl = document.getElementById('questionModal');
		const qModal = new bootstrap.Modal(qModalEl);
		const qForm = document.getElementById('questionForm');
		const qType = document.getElementById('qType');
		const scaleConfig = document.getElementById('scaleConfig');
		const typeHint = document.getElementById('typeHint');
		const btnNew = document.getElementById('btnNewQuestion');

		btnNew.addEventListener('click', () => {
			qForm.reset();
			document.getElementById('qId').value='';
			document.getElementById('questionModalTitle').textContent='New Question';
			document.getElementById('qMin').value='1';
			document.getElementById('qMax').value='5';
			document.getElementById('qLeft').value='Poor';
			document.getElementById('qRight').value='Excellent';
			qType.value='scale';
			updateTypeVisibility();
			qModal.show();
		});

		function openEditModal(id){
			const q = questions.find(x => String(x.id)===String(id));
			if (!q) return;
			document.getElementById('qId').value = q.id;
			document.getElementById('qText').value = q.question_text;
			document.getElementById('qType').value = q.question_type;
			document.getElementById('qRequired').value = q.is_required;
			document.getElementById('qActive').value = q.is_active;
			document.getElementById('qMin').value = q.min_scale || 1;
			document.getElementById('qMax').value = q.max_scale || 5;
			document.getElementById('qLeft').value = q.scale_label_left || '';
			document.getElementById('qRight').value = q.scale_label_right || '';
			document.getElementById('questionModalTitle').textContent='Edit Question #' + q.id;
			updateTypeVisibility();
			qModal.show();
		}

		qType.addEventListener('change', updateTypeVisibility);
		function updateTypeVisibility(){
			const t = qType.value;
			scaleConfig.style.display = (t==='scale')? 'flex':'none';
			let hint='';
			if (needsOptions(t)) hint='This type requires options. Add them after saving via the card UI.';
			else if (t==='scale') hint='Scale questions produce numeric ratings between Min and Max.';
			typeHint.textContent = hint;
		}

		qForm.addEventListener('submit', async (ev) => {
			ev.preventDefault();
			const formData = new FormData(qForm);
			const id = formData.get('id');
			const payload = Object.fromEntries(formData.entries());
			payload.min_scale = payload.min_scale || '';
			payload.max_scale = payload.max_scale || '';
			try {
				await api(id? 'update_question':'create_question', payload);
				qModal.hide();
				showAlert(id? 'Updated':'Created','success');
				loadQuestions();
			} catch(e){ showAlert(e.message,'danger'); }
		});

		// Initial load
		loadQuestions();
	</script>
</body>
</html>
