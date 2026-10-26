<?php
declare(strict_types=1);
/**
 * 기업회원 로그인 페이지
 * POST → /api/auth/login.php (JSON) → redirect
 */
require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/../app/auth.php';

if (is_logged_in()) {
    redirect($_GET['redirect'] ?? '/estimate-quick.php');
}

$redirect = e($_GET['redirect'] ?? '/estimate-quick.php');
?>
<!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>기업회원 로그인 | 건축자재몰</title>
<style>
  @import url('/assets/fonts.css');
  :root{--bg:#F7F7F7;--surf:#FFFFFF;--bdr:#DDDDDD;--txt:#222222;--muted:#777777;--pri:#004EA1;--danger:#C0392B;--font:'Paperozi',-apple-system,sans-serif;}
  *{box-sizing:border-box;margin:0;padding:0;}
  body{font-family:var(--font);background:var(--bg);color:var(--txt);min-height:100dvh;display:flex;align-items:center;justify-content:center;padding:24px;}
  .card{width:100%;max-width:380px;padding:36px 32px;border:1px solid var(--bdr);border-radius:10px;background:var(--surf);box-shadow:0 4px 16px rgba(0,0,0,.06);display:flex;flex-direction:column;gap:24px;}
  h1{font-size:18px;font-weight:600;} .sub{font-size:12px;color:var(--muted);margin-top:4px;}
  .field{display:flex;flex-direction:column;gap:6px;} label{font-size:12px;color:var(--muted);}
  input{background:#fff;border:1px solid var(--bdr);color:var(--txt);padding:10px 12px;font-size:13px;font-family:var(--font);outline:none;transition:border-color .15s;} input:focus{border-color:var(--pri);}
  .btn-submit{background:var(--pri);color:#fff;border:none;padding:12px;font-size:14px;font-family:var(--font);font-weight:500;cursor:pointer;width:100%;} .btn-submit:disabled{opacity:.5;cursor:not-allowed;}
  .error-msg{font-size:12px;color:var(--danger);display:none;} .back-link{font-size:12px;color:var(--muted);text-align:center;}
  .back-link a{color:var(--pri);}
</style>
</head>
<body>
<div class="card">
  <div>
    <h1>기업회원 로그인</h1>
    <p class="sub">즉시견적은 기업회원만 이용 가능합니다</p>
  </div>
  <div class="field">
    <label for="email">이메일</label>
    <input id="email" type="email" autocomplete="email" placeholder="hong@example.com" required>
  </div>
  <div class="field">
    <label for="password">비밀번호</label>
    <input id="password" type="password" autocomplete="current-password" placeholder="비밀번호" required
      onkeydown="if(event.key==='Enter')login()">
  </div>
  <p class="error-msg" id="err"></p>
  <button class="btn-submit" id="btn" onclick="login()">로그인</button>
  <p class="back-link">계정이 없으신가요? <a href="/register.php">기업회원 가입</a></p>
  <p class="back-link"><a href="/ai.php"><svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg> 뒤로가기</a></p>
</div>

<script>
const REDIRECT = '<?= $redirect ?>';
async function login() {
  const email    = document.getElementById('email').value.trim();
  const password = document.getElementById('password').value;
  const err      = document.getElementById('err');
  const btn      = document.getElementById('btn');
  if (!email || !password) { showErr('이메일과 비밀번호를 입력해주세요.'); return; }
  btn.disabled = true; btn.textContent = '로그인 중...';
  try {
    const r = await fetch('/api/auth/login.php', {
      method: 'POST',
      headers: {'Content-Type': 'application/json'},
      body: JSON.stringify({email, password})
    });
    const data = await r.json();
    if (data.ok) { location.href = REDIRECT; }
    else { showErr(data.error || '로그인에 실패했습니다.'); btn.disabled = false; btn.textContent = '로그인'; }
  } catch { showErr('네트워크 오류가 발생했습니다.'); btn.disabled = false; btn.textContent = '로그인'; }
}
function showErr(msg) {
  const el = document.getElementById('err');
  el.textContent = msg; el.style.display = 'block';
}
</script>
</body>
</html>
