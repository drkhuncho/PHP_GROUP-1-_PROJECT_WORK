<?php

// index.php — Study Planner (PHP + MySQL)
// Run in XAMPP: place in htdocs, open http://localhost/orig.php
ini_set('display_errors', 0);
error_reporting(0);

header('X-Content-Type-Options: nosniff');
session_start();
if (!isset($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf'];

function check_csrf() {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $ctype = $_SERVER['CONTENT_TYPE'] ?? '';
        if (str_contains($ctype, 'application/json')) {
            $raw = file_get_contents('php://input');
            $data = json_decode($raw, true);
            $token = $data['csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
        } else {
            $token = $_POST['csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
        }
        if (!$token || $token !== ($_SESSION['csrf'] ?? '')) {
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'error' => 'Invalid CSRF token']);
            exit;
        }
    }
}

// ---------------------------
// Connect to MySQL
// ---------------------------
try {
    $pdo = new PDO("mysql:host=localhost;dbname=planner;charset=utf8", "root", "");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (Throwable $e) {
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => 'DB Connection failed: ' . $e->getMessage()]);
    exit;
}

// Create tasks table if missing
$pdo->exec("
CREATE TABLE IF NOT EXISTS tasks (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    due_date DATE NULL,
    subject VARCHAR(255) NULL,
    priority ENUM('Low','Medium','High') DEFAULT 'Low',
    completed TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;
");

$action = $_GET['action'] ?? null;

function get_input() {
    $ctype = $_SERVER['CONTENT_TYPE'] ?? '';
    if (str_contains($ctype, 'application/json')) {
        $raw = file_get_contents('php://input');
        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }
    return $_POST;
}

function fetch_tasks(PDO $pdo) {
    $st = $pdo->query("SELECT * FROM tasks ORDER BY completed ASC, due_date IS NULL, due_date ASC, created_at DESC");
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

// ---------------------------
// API actions
// ---------------------------
if ($action === 'add' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $in = get_input();
    $name = trim($in['name'] ?? '');
    $due  = $in['due_date'] ?? null;
    $subject = $in['subject'] ?? null;
    $priority = in_array($in['priority'] ?? 'Low', ['Low','Medium','High']) ? $in['priority'] : 'Low';

    if ($name === '') {
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'error' => 'Task name required']);
        exit;
    }
    try {
        $st = $pdo->prepare("INSERT INTO tasks (name, due_date, subject, priority) VALUES (?, ?, ?, ?)");
        $st->execute([$name, $due, $subject, $priority]);
        header('Content-Type: application/json');
        echo json_encode(['ok' => true, 'tasks' => fetch_tasks($pdo)]);
    } catch (Throwable $e) {
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'error' => 'DB error: ' . $e->getMessage()]);
    }
    exit;
}

if ($action === 'toggle' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) {
        header('Content-Type: application/json');
        echo json_encode(['ok'=>false,'error'=>'Invalid id']);
        exit;
    }
    try {
        $st = $pdo->prepare("UPDATE tasks SET completed = CASE WHEN completed=1 THEN 0 ELSE 1 END WHERE id = ?");
        $st->execute([$id]);
        header('Content-Type: application/json');
        echo json_encode(['ok' => true, 'tasks' => fetch_tasks($pdo)]);
    } catch (Throwable $e) {
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'error' => 'DB error: ' . $e->getMessage()]);
    }
    exit;
}

if ($action === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) {
        header('Content-Type: application/json');
        echo json_encode(['ok'=>false,'error'=>'Invalid id']);
        exit;
    }
    try {
        $st = $pdo->prepare("DELETE FROM tasks WHERE id = ?");
        $st->execute([$id]);
        header('Content-Type: application/json');
        echo json_encode(['ok' => true, 'tasks' => fetch_tasks($pdo)]);
    } catch (Throwable $e) {
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'error' => 'DB error: ' . $e->getMessage()]);
    }
    exit;
}

if ($action === 'export') {
    $tasks = fetch_tasks($pdo);
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="tasks_' . date('Ymd_His') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['id','name','due_date','subject','priority','completed','created_at']);
    foreach ($tasks as $t) fputcsv($out, [$t['id'],$t['name'],$t['due_date'],$t['subject'],$t['priority'],$t['completed'],$t['created_at']]);
    fclose($out);
    exit;
}

// Default: render HTML
$tasks = fetch_tasks($pdo);
$csrf = $_SESSION['csrf'];
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width,initial-scale=1" />
<title>Study Planner</title>
<style>
  :root{
    --bg:#f1f5f9; --card:#ffffff; --muted:#6b7280; --accent:#10b981;
    --danger:#ef4444; --yellow:#f59e0b; --green:#10b981; --radius:12px;
    --shadow: 0 6px 20px rgba(2,6,23,0.08);
  }
  *{box-sizing:border-box}
  body{font-family:Inter,system-ui,Segoe UI,Roboto,'Helvetica Neue',Arial; margin:0; background:var(--bg); color:#0f172a}
  .wrap{min-height:100vh; display:flex; align-items:center; justify-content:center; padding:28px}
  .app{width:100%; max-width:1100px; background:var(--card); border-radius:16px; overflow:hidden; box-shadow:var(--shadow)}
  .hero{background:linear-gradient(90deg,#059669 0%,#10b981 100%); color:white; padding:28px 32px}
  .hero h1{margin:0;font-size:28px;letter-spacing:-0.02em}
  .hero p{margin:6px 0 0; opacity:0.9}
  .content{display:grid; grid-template-columns:1fr 420px; gap:26px; padding:26px}
  @media (max-width:880px){ .content{grid-template-columns:1fr; padding:18px} .right {order:2} .left{order:1} }
  .form{background:#fafafa;border-radius:12px;padding:18px; box-shadow: 0 4px 12px rgba(2,6,23,0.04)}
  .form h2{color:var(--accent); margin:0 0 10px; font-size:18px}
  .grid{display:grid; grid-template-columns:repeat(2,1fr); gap:10px}
  .grid-4{display:grid; grid-template-columns:repeat(4,1fr); gap:10px}
  .input, select{width:100%; padding:10px 12px; border-radius:8px; border:1px solid #e6e9ee; background:white; font-size:14px}
  .full{grid-column: span 2}
  .btn{background:var(--accent); color:white; padding:10px 14px; border-radius:10px; border:0; cursor:pointer; font-weight:600}
  .panel{padding:6px 0}
  .panel h3{margin:0 0 12px; font-size:16px; color:var(--accent)}
  .task{display:flex; align-items:center; justify-content:space-between; gap:12px; background:var(--card); padding:12px; border-radius:12px; border:1px solid #f1f5f9}
  .task + .task{margin-top:10px}
  .leftcol{display:flex; align-items:center; gap:12px; min-width:0}
  .chk{width:20px;height:20px;border-radius:6px;border:1px solid #e6e9ee; display:inline-grid; place-items:center; cursor:pointer}
  .title{font-weight:600; font-size:15px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap}
  .meta{font-size:13px; color:var(--muted); display:flex; gap:10px; margin-top:6px; flex-wrap:wrap}
  .badge{padding:6px 8px;border-radius:999px;font-size:12px; font-weight:700; color:white}
  .badge.low{background:var(--green)}
  .badge.medium{background:var(--yellow)}
  .badge.high{background:var(--danger)}
  .del{background:transparent;border:0;color:#ef4444;cursor:pointer;padding:8px;border-radius:8px}
  .empty{padding:28px; text-align:center; color:var(--muted)}
  .stats{display:flex; gap:10px; margin-bottom:10px}
  .stat{background:#ffffff;padding:10px;border-radius:10px;flex:1;text-align:center;border:1px solid #f1f5f9}
  .export{background:#111827;color:white;padding:8px 12px;border-radius:8px;border:0;cursor:pointer}
</style>
</head>
<body>
<div class="wrap">
  <div class="app" role="application" aria-label="Study Planner">
    <div class="hero">
      <h1>Study Planner</h1>
      <p>Organize your assignments and deadlines.</p>
    </div>

    <div class="content">
      <!-- left: task form + list -->
      <div class="left">
        <div class="form" style="margin-bottom:14px">
          <h2>Add New Task</h2>
          <form id="taskForm">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
            <div style="display:grid; grid-template-columns:2fr 1fr; gap:10px;">
              <input class="input" name="name" id="name" placeholder="Task name (e.g., History Essay)" required>
              <input class="input" type="date" name="due_date" id="due_date">
            </div>
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:10px; margin-top:10px;">
              <input class="input" name="subject" id="subject" placeholder="Subject (e.g., Math)">
              <select class="input" name="priority" id="priority">
                <option>Low</option>
                <option>Medium</option>
                <option>High</option>
              </select>
            </div>
            <div style="margin-top:12px; display:flex; gap:10px;">
              <button class="btn" type="submit">Add Task</button>
              <button class="export" id="exportBtn" type="button">Export CSV</button>
            </div>
          </form>
        </div>

        <div class="panel">
          <div class="stats" style="margin-bottom:8px">
            <div class="stat"><div style="font-size:12px;color:var(--muted)">Total</div><div style="font-weight:700"><?= count($tasks) ?></div></div>
            <div class="stat"><div style="font-size:12px;color:var(--muted)">Completed</div><div style="font-weight:700"><?= array_sum(array_column($tasks, 'completed')) ?></div></div>
            <div class="stat"><div style="font-size:12px;color:var(--muted)">Pending</div><div style="font-weight:700"><?= count($tasks) - array_sum(array_column($tasks, 'completed')) ?></div></div>
          </div>

          <h3>My Tasks</h3>
          <div id="tasksContainer">
            <?php if (empty($tasks)): ?>
              <div class="empty">No tasks added yet. Add your first task above!</div>
            <?php else: foreach($tasks as $t): ?>
              <div class="task" data-id="<?= (int)$t['id'] ?>">
                <div class="leftcol">
                  <label class="chk" role="checkbox" aria-checked="<?= $t['completed']? 'true':'false' ?>">
                    <input type="checkbox" style="display:none" <?= $t['completed'] ? 'checked':'' ?>>
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" style="display:<?= $t['completed'] ? 'block':'none' ?>;color:var(--card)"><path d="M20 6L9 17l-5-5" stroke="white" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                  </label>
                </div>
                <div>
                  <div class="title" style="<?= $t['completed'] ? 'text-decoration:line-through;color:#6b7280':'' ?>">
                    <?= htmlspecialchars($t['name'] ?? '') ?>
                  </div>
                  <div class="meta">
                    <?php if (!empty($t['due_date'])): ?><span>📅 <?= htmlspecialchars($t['due_date']) ?></span><?php endif; ?>
                    <?php if (!empty($t['subject'])): ?><span>📘 <?= htmlspecialchars($t['subject']) ?></span><?php endif; ?>
                    <span class="badge <?= strtolower($t['priority'] ?? 'low') ?>"><?= htmlspecialchars($t['priority'] ?? 'Low') ?></span>
                  </div>
                </div>
                <div style="display:flex; align-items:center">
                  <button class="del" data-id="<?= (int)$t['id'] ?>" title="Delete task">🗑</button>
                </div>
              </div>
            <?php endforeach; endif; ?>
          </div>
        </div>
      </div>

      <!-- right: quick stats or upcoming tasks -->
      <div class="right">
        <div class="form">
          <h2>Overview</h2>
          <p style="color:var(--muted); margin-top:6px">Quick view of your upcoming deadlines and priorities.</p>
          <div id="upcoming" style="margin-top:12px">
            <?php
              $upcoming = array_filter($tasks, fn($x) => !empty($x['due_date']) && !$x['completed']);
              usort($upcoming, fn($a,$b)=>strcmp($a['due_date'],$b['due_date']));
            ?>
            <?php if (empty($upcoming)): ?>
              <div class="empty">No upcoming tasks.</div>
            <?php else: foreach(array_slice($upcoming,0,6) as $u): ?>
              <div style="display:flex; justify-content:space-between; gap:8px; padding:8px 0; border-bottom:1px solid #f3f4f6">
                <div>
                  <div style="font-weight:700"><?= htmlspecialchars($u['name'] ?? '') ?></div>
                  <div style="font-size:13px;color:var(--muted)">
                    <?= htmlspecialchars($u['due_date'] ?? '') ?>
                    <?= !empty($u['subject']) ? ' · ' . htmlspecialchars($u['subject']) : '' ?>
                  </div>
                </div>
                <div style="display:flex; align-items:center">
                  <span class="badge <?= strtolower($u['priority'] ?? 'low') ?>"><?= htmlspecialchars($u['priority'] ?? 'Low') ?></span>
                </div>
              </div>
            <?php endforeach; endif; ?>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<script>
const csrf = <?= json_encode($csrf) ?>;

function elem(selector, root=document) { return root.querySelector(selector); }
function qAll(selector, root=document) { return Array.from(root.querySelectorAll(selector)); }

function renderTasks(tasks){
  const container = document.getElementById('tasksContainer');
  if(!tasks || tasks.length === 0){ container.innerHTML = '<div class="empty">No tasks added yet. Add your first task above!</div>'; return;}
  container.innerHTML = '';
  tasks.forEach(t => {
    const div = document.createElement('div');
    div.className = 'task';
    div.dataset.id = t.id;
    const checked = t.completed == 1;
    div.innerHTML = `
      <div class="leftcol">
        <label class="chk" role="checkbox" aria-checked="${checked}">
          <input type="checkbox" style="display:none" ${checked ? 'checked':''}>
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" style="display:${checked ? 'block':'none'};color:var(--card)"><path d="M20 6L9 17l-5-5" stroke="white" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
        </label>
        <div>
          <div class="title" style="${checked ? 'text-decoration:line-through;color:#6b7280':''}">${escapeHtml(t.name)}</div>
          <div class="meta">
            ${ t.due_date ? `<span>📅 ${escapeHtml(t.due_date)}</span>` : '' }
            ${ t.subject ? `<span>📘 ${escapeHtml(t.subject)}</span>` : '' }
            <span class="badge ${t.priority ? t.priority.toLowerCase() : 'low'}">${escapeHtml(t.priority || 'Low')}</span>
          </div>
        </div>
      </div>
      <div style="display:flex; align-items:center">
        <button class="del" data-id="${t.id}" title="Delete task">🗑</button>
      </div>`;
    container.appendChild(div);
  });
  attachHandlers();
}

function escapeHtml(s){ return (s+'').replaceAll('&','&amp;').replaceAll('<','&lt;').replaceAll('>','&gt;').replaceAll('"','&quot;') }

function attachHandlers(){
  qAll('.chk').forEach(chk=>{
    chk.onclick = (e)=>{
      const id = chk.closest('.task').dataset.id;
      toggleTask(id);
    };
  });
  qAll('.del').forEach(d=>{
    d.onclick = (e)=> {
      const id = d.dataset.id;
      if(confirm('Delete this task?')) deleteTask(id);
    };
  });
}

async function addTask(formData){
  const body = new URLSearchParams(formData);
  try {
    const res = await fetch('?action=add', {
      method:'POST',
      headers: {'X-CSRF-TOKEN': csrf, 'Content-Type':'application/x-www-form-urlencoded'},
      body: body
    });
    const data = await res.json();
    if(data.ok) renderTasks(data.tasks);
    else alert(data.error || 'Could not add task');
  } catch (err) { console.error(err); alert('Network error'); }
}

async function toggleTask(id){
  try {
    const res = await fetch('?action=toggle', {
      method:'POST',
      headers:{'Content-Type':'application/x-www-form-urlencoded','X-CSRF-TOKEN':csrf},
      body: 'id='+encodeURIComponent(id)
    });
    const data = await res.json();
    if(data.ok) renderTasks(data.tasks);
    else alert(data.error || 'Could not toggle');
  } catch (e){ console.error(e); alert('Network error'); }
}

async function deleteTask(id){
  try {
    const res = await fetch('?action=delete', {
      method:'POST',
      headers:{'Content-Type':'application/x-www-form-urlencoded','X-CSRF-TOKEN':csrf},
      body: 'id='+encodeURIComponent(id)
    });
    const data = await res.json();
    if(data.ok) renderTasks(data.tasks);
    else alert(data.error || 'Could not delete');
  } catch (e){ console.error(e); alert('Network error'); }
}

document.getElementById('taskForm').addEventListener('submit', (ev)=>{
  ev.preventDefault();
  const fm = new FormData(ev.currentTarget);
  fm.set('csrf', csrf);
  addTask(fm);
  ev.currentTarget.reset();
});

document.getElementById('exportBtn').addEventListener('click', ()=> location.href='?action=export');

// attach handlers to initial server-rendered items
attachHandlers();
</script>
</body>
</html>