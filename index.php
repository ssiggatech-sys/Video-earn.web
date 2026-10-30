<?php
declare(strict_types=1);
/*
 VIDEOEARN single-file PHP/MySQL starter.
 Before uploading: create a MySQL database, then fill in these settings.
 Upload as index.php. Visit ?page=setup once to create the first admin.
*/
const DB_HOST='localhost';
const DB_NAME='YOUR_DATABASE_NAME';
const DB_USER='YOUR_DATABASE_USER';
const DB_PASS='YOUR_DATABASE_PASSWORD';
const SETUP_SECRET='REPLACE-WITH-A-LONG-RANDOM-SECRET';
const APP_NAME='VideoEarn';
const COOKIE_SECURE=false; // Change to true after HTTPS is working.

ini_set('session.use_strict_mode','1');
session_set_cookie_params(['httponly'=>true,'secure'=>COOKIE_SECURE,'samesite'=>'Lax']);
session_start();
date_default_timezone_set('Africa/Kampala');
function h($v): string { return htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'); }
function go($p='index.php'): never { header('Location: '.$p); exit; }
function msg($s,$bad=false): void { $_SESSION['flash']=[$s,$bad]; }
function token(): string { return $_SESSION['csrf']??=$_SESSION['csrf']??bin2hex(random_bytes(32)); }
function verify(): void { if(!hash_equals($_SESSION['csrf']??'',$_POST['csrf']??'')){http_response_code(419);exit('Session expired. Refresh and try again.');} }
function logged(): bool { return isset($_SESSION['uid']); }
function is_admin(): bool { return ($_SESSION['role']??'')==='admin'; }
function ugx($n): string { return number_format((int)$n).' UGX'; }

try {
 if(DB_NAME==='YOUR_DATABASE_NAME'||DB_USER==='YOUR_DATABASE_USER') throw new RuntimeException('Edit the database settings at the top of index.php first.');
 $db=new PDO('mysql:host='.DB_HOST.';dbname='.DB_NAME.';charset=utf8mb4',DB_USER,DB_PASS,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
} catch(Throwable $e) {
 http_response_code(503); exit('<h2>VideoEarn database setup</h2><p>'.h($e->getMessage()).'</p><p>Create a MySQL database in your hosting control panel and enter its details at the top of this file.</p>');
}
$db->exec("CREATE TABLE IF NOT EXISTS users(id INT AUTO_INCREMENT PRIMARY KEY,name VARCHAR(100) NOT NULL,email VARCHAR(190) UNIQUE NOT NULL,password VARCHAR(255) NOT NULL,role ENUM('user','admin') DEFAULT 'user',balance BIGINT NOT NULL DEFAULT 0,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB");
$db->exec("CREATE TABLE IF NOT EXISTS videos(id INT AUTO_INCREMENT PRIMARY KEY,title VARCHAR(180) NOT NULL,url VARCHAR(500) NOT NULL,reward BIGINT NOT NULL DEFAULT 0,active TINYINT DEFAULT 1,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB");
$db->exec("CREATE TABLE IF NOT EXISTS tasks(id INT AUTO_INCREMENT PRIMARY KEY,user_id INT NOT NULL,video_id INT NOT NULL,status ENUM('pending','approved','rejected') DEFAULT 'pending',created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,UNIQUE KEY once_per_video(user_id,video_id),FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,FOREIGN KEY(video_id) REFERENCES videos(id) ON DELETE CASCADE) ENGINE=InnoDB");
$db->exec("CREATE TABLE IF NOT EXISTS withdrawals(id INT AUTO_INCREMENT PRIMARY KEY,user_id INT NOT NULL,amount BIGINT NOT NULL,phone VARCHAR(40) NOT NULL,status ENUM('pending','paid','rejected') DEFAULT 'pending',created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE) ENGINE=InnoDB");
$db->exec("CREATE TABLE IF NOT EXISTS ledger(id BIGINT AUTO_INCREMENT PRIMARY KEY,user_id INT NOT NULL,amount BIGINT NOT NULL,note VARCHAR(200) NOT NULL,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE) ENGINE=InnoDB");

$action=$_POST['action']??$_GET['action']??''; $page=$_GET['page']??'home'; $errors=[];
$admins=(int)$db->query("SELECT COUNT(*) FROM users WHERE role='admin'")->fetchColumn();
if($action==='setup'&&$_SERVER['REQUEST_METHOD']==='POST'){
 verify();
 if($admins) $errors[]='An admin already exists.';
 elseif(!hash_equals(SETUP_SECRET,(string)($_POST['secret']??''))) $errors[]='Wrong setup secret.';
 else {
  $name=trim($_POST['name']??'');$email=strtolower(trim($_POST['email']??''));$pass=$_POST['password']??'';
  if(!$name||!filter_var($email,FILTER_VALIDATE_EMAIL)||strlen($pass)<14)$errors[]='Enter name, valid email and password of at least 14 characters.';
  else{$s=$db->prepare("INSERT INTO users(name,email,password,role) VALUES(?,?,?,'admin')");$s->execute([$name,$email,password_hash($pass,PASSWORD_DEFAULT)]);msg('Admin created. Please sign in.');go('?page=login');}
 }
}
if($action==='register'&&$_SERVER['REQUEST_METHOD']==='POST'){
 verify();$name=trim($_POST['name']??'');$email=strtolower(trim($_POST['email']??''));$pass=$_POST['password']??'';
 if(!$name||strlen($name)>100||!filter_var($email,FILTER_VALIDATE_EMAIL)||strlen($pass)<10)$errors[]='Enter a name, valid email and password of at least 10 characters.';
 else{try{$s=$db->prepare("INSERT INTO users(name,email,password) VALUES(?,?,?)");$s->execute([$name,$email,password_hash($pass,PASSWORD_DEFAULT)]);msg('Account created. Sign in now.');go('?page=login');}catch(Throwable $e){$errors[]='That email may already be registered.';}}
}
if($action==='login'&&$_SERVER['REQUEST_METHOD']==='POST'){
 verify();$s=$db->prepare("SELECT * FROM users WHERE email=?");$s->execute([strtolower(trim($_POST['email']??''))]);$u=$s->fetch();
 if($u&&password_verify($_POST['password']??'',$u['password'])){session_regenerate_id(true);$_SESSION['uid']=$u['id'];$_SESSION['name']=$u['name'];$_SESSION['role']=$u['role'];msg('Welcome back!');go();}
 else $errors[]='Email or password is incorrect.';
}
if($action==='logout'){$_SESSION=[];session_regenerate_id(true);go();}
if(logged()&&$action==='task'&&$_SERVER['REQUEST_METHOD']==='POST'){
 verify();$vid=(int)($_POST['video_id']??0);$s=$db->prepare("SELECT id FROM videos WHERE id=? AND active=1");$s->execute([$vid]);
 if(!$s->fetch())msg('Video unavailable.',true);
 else try{$s=$db->prepare("INSERT INTO tasks(user_id,video_id) VALUES(?,?)");$s->execute([$_SESSION['uid'],$vid]);msg('Submitted for admin review.');}catch(Throwable $e){msg('You already submitted this task.',true);}
 go();
}
if(logged()&&$action==='withdraw'&&$_SERVER['REQUEST_METHOD']==='POST'){
 verify();$amount=(int)($_POST['amount']??0);$phone=trim($_POST['phone']??'');
 if($amount<1000||!preg_match('/^[0-9+ ]{9,20}$/',$phone))msg('Enter a valid number and amount of at least UGX 1,000.',true);
 else{$db->beginTransaction();try{$s=$db->prepare("SELECT balance FROM users WHERE id=? FOR UPDATE");$s->execute([$_SESSION['uid']]);$bal=(int)$s->fetchColumn();if($amount>$bal)throw new Exception('Insufficient balance.');$db->prepare("UPDATE users SET balance=balance-? WHERE id=?")->execute([$amount,$_SESSION['uid']]);$db->prepare("INSERT INTO withdrawals(user_id,amount,phone) VALUES(?,?,?)")->execute([$_SESSION['uid'],$amount,$phone]);$db->prepare("INSERT INTO ledger(user_id,amount,note) VALUES(?,?,?)")->execute([$_SESSION['uid'],-$amount,'Withdrawal requested']);$db->commit();msg('Withdrawal sent for manual review.');}catch(Throwable $e){$db->rollBack();msg($e->getMessage(),true);}}
 go('?page=wallet');
}
if(is_admin()&&$_SERVER['REQUEST_METHOD']==='POST'&&in_array($action,['add_video','review_task','review_withdrawal','toggle_video'],true)){
 verify();
 if($action==='add_video'){$title=trim($_POST['title']??'');$url=trim($_POST['url']??'');$reward=(int)($_POST['reward']??0);if($title&&filter_var($url,FILTER_VALIDATE_URL)&&in_array(parse_url($url,PHP_URL_SCHEME),['http','https'],true)&&$reward>=0){$db->prepare("INSERT INTO videos(title,url,reward) VALUES(?,?,?)")->execute([$title,$url,$reward]);msg('Video added.');}else msg('Check the title, URL and reward.',true);}
 if($action==='toggle_video'){$db->prepare("UPDATE videos SET active=1-active WHERE id=?")->execute([(int)$_POST['id']]);msg('Video updated.');}
 if($action==='review_task'){$id=(int)$_POST['id'];$decision=$_POST['decision']??'';$db->beginTransaction();try{$s=$db->prepare("SELECT t.*,v.reward FROM tasks t JOIN videos v ON v.id=t.video_id WHERE t.id=? FOR UPDATE");$s->execute([$id]);$t=$s->fetch();if(!$t||$t['status']!=='pending')throw new Exception('Task already reviewed.');if($decision==='approve'){$db->prepare("UPDATE tasks SET status='approved' WHERE id=?")->execute([$id]);$db->prepare("UPDATE users SET balance=balance+? WHERE id=?")->execute([$t['reward'],$t['user_id']]);$db->prepare("INSERT INTO ledger(user_id,amount,note) VALUES(?,?,?)")->execute([$t['user_id'],$t['reward'],'Approved video task']);}else $db->prepare("UPDATE tasks SET status='rejected' WHERE id=?")->execute([$id]);$db->commit();msg('Task reviewed.');}catch(Throwable $e){$db->rollBack();msg($e->getMessage(),true);}}
 if($action==='review_withdrawal'){$id=(int)$_POST['id'];$db->beginTransaction();try{$s=$db->prepare("SELECT * FROM withdrawals WHERE id=? FOR UPDATE");$s->execute([$id]);$w=$s->fetch();if(!$w||$w['status']!=='pending')throw new Exception('Withdrawal already reviewed.');if(($_POST['decision']??'')==='paid')$db->prepare("UPDATE withdrawals SET status='paid' WHERE id=?")->execute([$id]);else{$db->prepare("UPDATE withdrawals SET status='rejected' WHERE id=?")->execute([$id]);$db->prepare("UPDATE users SET balance=balance+? WHERE id=?")->execute([$w['amount'],$w['user_id']]);$db->prepare("INSERT INTO ledger(user_id,amount,note) VALUES(?,?,?)")->execute([$w['user_id'],$w['amount'],'Withdrawal refunded']);}$db->commit();msg('Withdrawal reviewed. A marked-paid transfer must be sent separately.');}catch(Throwable $e){$db->rollBack();msg($e->getMessage(),true);}}
 go('?page=admin');
}
$me=null;if(logged()){$s=$db->prepare("SELECT * FROM users WHERE id=?");$s->execute([$_SESSION['uid']]);$me=$s->fetch();if(!$me){$_SESSION=[];go();}}
$flash=$_SESSION['flash']??null;unset($_SESSION['flash']);
function start_page($title){global $flash,$errors;echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>'.h($title).' - VideoEarn</title><style>
*{box-sizing:border-box}body{margin:0;background:#f5f6fb;color:#20243a;font:15px system-ui,Arial}a{color:#6845d8;text-decoration:none}.nav{background:#19152f;color:white;padding:16px max(18px,calc((100% - 1050px)/2));display:flex;gap:18px;flex-wrap:wrap}.nav a{color:white}.brand{font-size:20px;font-weight:900;margin-right:auto}.wrap{max-width:1050px;margin:28px auto;padding:0 18px}.hero{padding:34px;border-radius:20px;background:linear-gradient(120deg,#251b55,#7655db);color:white;margin-bottom:20px}.hero h1{font-size:clamp(30px,5vw,44px);margin:0}.hero p{color:#eee8ff}.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:16px}.card{background:white;padding:20px;border:1px solid #e5e6ef;border-radius:15px;margin-bottom:16px}.muted{color:#70778c}.price{font-size:26px;font-weight:850}.btn,button{display:inline-block;border:0;border-radius:9px;padding:11px 15px;background:#6845d8;color:white;font-weight:700;cursor:pointer}.alt{background:#eee9fc;color:#5037a8}.bad{background:#a52d45}input{width:100%;padding:11px;border:1px solid #d7d9e5;border-radius:8px;font:inherit}.field{margin:12px 0}label{display:block;font-weight:650;margin-bottom:5px}.notice{background:#e8f7ed;padding:13px;border-radius:9px;margin-bottom:14px}.error{background:#ffeded;color:#8d2430}table{width:100%;border-collapse:collapse;background:white}td,th{text-align:left;padding:10px;border-bottom:1px solid #e5e6ef;vertical-align:top}form.inline{display:inline-block;margin:2px}.pill{background:#eee9fc;padding:4px 8px;border-radius:20px;font-size:12px}footer{text-align:center;color:#777;padding:28px}
</style></head><body><nav class="nav"><a class="brand" href="index.php">▶ VideoEarn</a><a href="index.php">Home</a>';
if(logged()){echo '<a href="?page=wallet">Wallet</a>';if(is_admin())echo '<a href="?page=admin">Admin</a>';echo '<a href="?action=logout">Sign out</a>';}else echo '<a href="?page=login">Sign in</a><a href="?page=register">Register</a>';
echo '</nav><main class="wrap">';if($flash)echo '<div class="notice '.($flash[1]?'error':'').'">'.h($flash[0]).'</div>';foreach($errors as $x)echo '<div class="notice error">'.h($x).'</div>';
}
function end_page(){echo '</main><footer>VideoEarn · UGX rewards platform</footer></body></html>';}
function hidden($a){echo '<input type="hidden" name="csrf" value="'.h(token()).'"><input type="hidden" name="action" value="'.h($a).'">';}
function admin_post($action,$label,$id,$decision,$bad=false){echo '<form class="inline" method="post">';hidden($action);echo '<input type="hidden" name="id" value="'.(int)$id.'"><input type="hidden" name="decision" value="'.h($decision).'"><button class="'.($bad?'bad':'').'">'.h($label).'</button></form>';}

if($page==='setup'&&!$admins){start_page('Admin setup');echo '<div class="card" style="max-width:520px;margin:auto"><h2>Create first admin</h2><p class="muted">Enter the setup secret configured at the top of this file. Do this once, then change the secret.</p><form method="post">';hidden('setup');echo '<div class="field"><label>Setup secret</label><input type="password" name="secret" required></div><div class="field"><label>Name</label><input name="name" required></div><div class="field"><label>Email</label><input type="email" name="email" required></div><div class="field"><label>Password (14+ characters)</label><input type="password" name="password" minlength="14" required></div><button>Create admin</button></form></div>';end_page();exit;}
if($page==='register'||$page==='login'){start_page($page==='register'?'Register':'Sign in');echo '<div class="card" style="max-width:470px;margin:auto"><h2>'.($page==='register'?'Create your account':'Sign in').'</h2><form method="post">';hidden($page);if($page==='register')echo '<div class="field"><label>Name</label><input name="name" required maxlength="100"></div>';echo '<div class="field"><label>Email</label><input type="email" name="email" required></div><div class="field"><label>Password</label><input type="password" name="password" required '.($page==='register'?'minlength="10"':'').'></div><button>'.($page==='register'?'Create account':'Sign in').'</button></form></div>';end_page();exit;}
if($page==='wallet'){if(!logged())go('?page=login');start_page('Wallet');echo '<div class="grid"><div class="card"><div class="muted">Available balance</div><div class="price">'.ugx($me['balance']).'</div></div><div class="card"><h2>Withdraw</h2><form method="post">';hidden('withdraw');echo '<div class="field"><label>Amount (UGX)</label><input type="number" name="amount" min="1000" required></div><div class="field"><label>Mobile money number</label><input name="phone" required placeholder="07XXXXXXXX"></div><button>Request withdrawal</button></form><p class="muted">Manual review. Never enter your mobile money PIN or OTP.</p></div></div>';end_page();exit;}
if($page==='admin'){if(!is_admin())go('?page=login');start_page('Admin dashboard');$users=$db->query("SELECT COUNT(*) FROM users WHERE role='user'")->fetchColumn();$pt=$db->query("SELECT COUNT(*) FROM tasks WHERE status='pending'")->fetchColumn();$pw=$db->query("SELECT COUNT(*) FROM withdrawals WHERE status='pending'")->fetchColumn();echo '<div class="grid"><div class="card">Members <div class="price">'.$users.'</div></div><div class="card">Pending tasks <div class="price">'.$pt.'</div></div><div class="card">Pending withdrawals <div class="price">'.$pw.'</div></div></div><div class="card"><h2>Add video</h2><form method="post">';hidden('add_video');echo '<div class="field"><label>Title</label><input name="title" required></div><div class="field"><label>Video URL</label><input type="url" name="url" required></div><div class="field"><label>Reward in UGX</label><input type="number" name="reward" min="0" required></div><button>Add video</button></form></div>';
$tasks=$db->query("SELECT t.id,u.name,v.title,v.reward FROM tasks t JOIN users u ON u.id=t.user_id JOIN videos v ON v.id=t.video_id WHERE t.status='pending'")->fetchAll();echo '<div class="card"><h2>Review tasks</h2><table><tr><th>Member</th><th>Video</th><th>Reward</th><th>Decision</th></tr>';foreach($tasks as $t){echo '<tr><td>'.h($t['name']).'</td><td>'.h($t['title']).'</td><td>'.ugx($t['reward']).'</td><td>';admin_post('review_task','Approve',$t['id'],'approve');admin_post('review_task','Reject',$t['id'],'reject',true);echo '</td></tr>';}if(!$tasks)echo '<tr><td colspan="4">No pending tasks.</td></tr>';echo '</table></div>';
$ws=$db->query("SELECT w.*,u.name FROM withdrawals w JOIN users u ON u.id=w.user_id WHERE w.status='pending'")->fetchAll();echo '<div class="card"><h2>Review withdrawals</h2><table><tr><th>Member</th><th>Amount</th><th>Number</th><th>Decision</th></tr>';foreach($ws as $w){echo '<tr><td>'.h($w['name']).'</td><td>'.ugx($w['amount']).'</td><td>'.h($w['phone']).'</td><td>';admin_post('review_withdrawal','Mark paid',$w['id'],'paid');admin_post('review_withdrawal','Reject/refund',$w['id'],'reject',true);echo '</td></tr>';}if(!$ws)echo '<tr><td colspan="4">No pending withdrawals.</td></tr>';echo '</table></div>';
$vs=$db->query("SELECT * FROM videos ORDER BY id DESC")->fetchAll();echo '<div class="card"><h2>Videos</h2><table><tr><th>Title</th><th>Reward</th><th>Status</th><th>Action</th></tr>';foreach($vs as $v){echo '<tr><td><a href="'.h($v['url']).'" target="_blank" rel="noopener">'.h($v['title']).'</a></td><td>'.ugx($v['reward']).'</td><td>'.($v['active']?'Active':'Paused').'</td><td><form method="post">';hidden('toggle_video');echo '<input type="hidden" name="id" value="'.$v['id'].'"><button>'.($v['active']?'Pause':'Activate').'</button></form></td></tr>';}echo '</table></div>';end_page();exit;}

start_page('Home');echo '<section class="hero"><h1>Watch. Participate. Earn.</h1><p>Complete video tasks, submit them for review, and receive approved rewards in your UGX wallet.</p>';if(!logged())echo '<a class="btn" style="background:white;color:#5037a8" href="?page=register">Get started</a> <a class="btn alt" href="?page=login">Sign in</a>';else echo '<a class="btn" style="background:white;color:#5037a8" href="?page=wallet">Wallet: '.ugx($me['balance']).'</a>';echo '</section>';
if(logged())echo '<p>Welcome, <b>'.h($me['name']).'</b>.</p>';$videos=$db->query("SELECT * FROM videos WHERE active=1 ORDER BY id DESC")->fetchAll();if(!$videos)echo '<div class="card"><h2>Videos coming soon</h2><p class="muted">The admin has not added any videos yet.</p></div>';echo '<div class="grid">';
foreach($videos as $v){echo '<article class="card"><span class="pill">Video task</span><h2>'.h($v['title']).'</h2><div class="price">'.ugx($v['reward']).'</div><p class="muted">Reward after approval</p><a class="btn alt" href="'.h($v['url']).'" target="_blank" rel="noopener">Open video</a>';if(logged()){$s=$db->prepare("SELECT status FROM tasks WHERE user_id=? AND video_id=?");$s->execute([$me['id'],$v['id']]);$status=$s->fetchColumn();if(!$status){echo '<form method="post" style="margin-top:12px">';hidden('task');echo '<input type="hidden" name="video_id" value="'.$v['id'].'"><button>Submit completed task</button></form>';}else echo '<p><span class="pill">Task: '.h($status).'</span></p>';}else echo '<p><a href="?page=login">Sign in</a> to submit a task.</p>';echo '</article>';}echo '</div><p class="muted">A submission is not proof of a genuine view. Review every task and fund rewards only from verified revenue.</p>';end_page();
