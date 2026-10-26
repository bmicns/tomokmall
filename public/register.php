<?php
declare(strict_types=1);
/**
 * 기업회원 회원가입 페이지
 * POST → /api/auth/register.php (JSON)
 */
require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/../app/auth.php';

if (is_logged_in()) {
    redirect('/estimate-quick.php');
}
?>
<!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>기업회원 가입 | 건축자재몰</title>
<style>
  @import url('/assets/fonts.css');
  :root{--bg:#F7F7F7;--surf:#FFFFFF;--bdr:#DDDDDD;--txt:#222222;--muted:#777777;--pri:#004EA1;--danger:#C0392B;--font:'Paperlogy',-apple-system,sans-serif;}
  *{box-sizing:border-box;margin:0;padding:0;}
  body{font-family:var(--font);background:var(--bg);color:var(--txt);min-height:100dvh;display:flex;align-items:center;justify-content:center;padding:24px;}
  .card{width:100%;max-width:400px;padding:36px 32px;border:1px solid var(--bdr);background:var(--surf);display:flex;flex-direction:column;gap:20px;}
  h1{font-size:18px;font-weight:600;} .sub{font-size:12px;color:var(--muted);margin-top:4px;}
  .field{display:flex;flex-direction:column;gap:6px;} label{font-size:12px;color:var(--muted);}
  .label-opt{color:#555;font-size:11px;margin-left:4px;}
  input{background:#fff;border:1px solid var(--bdr);color:var(--txt);padding:10px 12px;font-size:13px;font-family:var(--font);outline:none;transition:border-color .15s;width:100%;}
  input:focus{border-color:var(--pri);}
  .btn-submit{background:var(--pri);color:#fff;border:none;padding:12px;font-size:14px;font-family:var(--font);font-weight:500;cursor:pointer;width:100%;} .btn-submit:disabled{opacity:.5;cursor:not-allowed;}
  .error-msg{font-size:12px;color:var(--danger);display:none;}
  .success-msg{font-size:13px;color:#2ecc71;display:none;text-align:center;}
  .back-link{font-size:12px;color:var(--muted);text-align:center;}
  .back-link a{color:var(--pri);}
  .divider{border:none;border-top:1px solid var(--bdr);}
</style>
</head>
<body>
<div class="card">
  <div>
    <h1>기업회원 가입</h1>
    <p class="sub">즉시견적 서비스 이용을 위한 기업회원 등록</p>
  </div>
  <div class="field">
    <label for="name">담당자명</label>
    <input id="name" type="text" autocomplete="name" placeholder="홍길동" maxlength="80" required>
  </div>
  <div class="field">
    <label for="company">회사명 <span class="label-opt">(선택)</span></label>
    <input id="company" type="text" autocomplete="organization" placeholder="(주)비엠아이씨앤에스" maxlength="120">
  </div>
  <div class="field">
    <label for="phone">전화번호 <span class="label-opt">(선택)</span></label>
    <input id="phone" type="tel" autocomplete="tel" placeholder="010-0000-0000" maxlength="40">
  </div>
  <hr class="divider">
  <div class="field">
    <label for="email">이메일</label>
    <input id="email" type="email" autocomplete="email" placeholder="hong@example.com" maxlength="160" required>
  </div>
  <div class="field">
    <label for="password">비밀번호 <span class="label-opt">(8자 이상)</span></label>
    <input id="password" type="password" autocomplete="new-password" placeholder="비밀번호" maxlength="100" required>
  </div>
  <div class="field">
    <label for="password2">비밀번호 확인</label>
    <input id="password2" type="password" autocomplete="new-password" placeholder="비밀번호 재입력" maxlength="100" required
      onkeydown="if(event.key==='Enter')register()">
  </div>
  <p class="error-msg" id="err"></p>
  <p class="success-msg" id="ok-msg">가입이 완료됐습니다! 로그인 페이지로 이동합니다...</p>
  <button class="btn-submit" id="btn" onclick="register()">가입하기</button>
  <p class="back-link">이미 계정이 있으신가요? <a href="/login.php">로그인</a></p>
</div>

<script>
async function register() {
  const name     = document.getElementById('name').value.trim();
  const company  = document.getElementById('company').value.trim();
  const phone    = document.getElementById('phone').value.trim();
  const email    = document.getElementById('email').value.trim();
  const password = document.getElementById('password').value;
  const password2= document.getElementById('password2').value;
  const btn      = document.getElementById('btn');

  if (!name)     { showErr('담당자명을 입력해주세요.'); return; }
  if (!email)    { showErr('이메일을 입력해주세요.'); return; }
  if (password.length < 8) { showErr('비밀번호는 8자 이상이어야 합니다.'); return; }
  if (password !== password2) { showErr('비밀번호가 일치하지 않습니다.'); return; }

  btn.disabled = true; btn.textContent = '처리 중...';
  hideErr();

  try {
    const r = await fetch('/api/auth/register.php', {
      method: 'POST',
      headers: {'Content-Type': 'application/json'},
      body: JSON.stringify({name, company_name: company, phone, email, password})
    });
    const data = await r.json();
    if (data.ok) {
      document.getElementById('ok-msg').style.display = 'block';
      btn.style.display = 'none';
      setTimeout(() => { location.href = '/login.php'; }, 1800);
    } else {
      showErr(data.error || '가입에 실패했습니다.');
      btn.disabled = false; btn.textContent = '가입하기';
    }
  } catch {
    showErr('네트워크 오류가 발생했습니다.');
    btn.disabled = false; btn.textContent = '가입하기';
  }
}
function showErr(msg) {
  const el = document.getElementById('err');
  el.textContent = msg; el.style.display = 'block';
}
function hideErr() {
  document.getElementById('err').style.display = 'none';
}
</script>
</body>
</html>
