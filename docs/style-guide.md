# 스타일 가이드

> 고객·직원·관리자 화면 공통 적용
> UI 레퍼런스: Leonardo.ai (https://app.leonardo.ai/)

---

## 1. 레이아웃 구조

Leonardo.ai와 동일한 **좌측 아이콘 사이드바 + 메인 콘텐츠** 구조.

```
┌──────┬────────────────────────────────────┐
│      │  상단 헤더 (선택)                    │
│ 사이  ├────────────────────────────────────┤
│ 드   │                                    │
│ 바   │  메인 콘텐츠 영역                    │
│ 60px │                                    │
│      │                                    │
└──────┴────────────────────────────────────┘
```

- 사이드바 너비: **60px** (아이콘 + 라벨 수직 배치)
- 메인 콘텐츠: 나머지 전체 (`calc(100vw - 60px)`)
- 콘텐츠 최대 너비: 1280px (중앙 정렬)

---

## 2. 색상 (다크 테마)

파스텔 금지. 명도 대비가 분명한 다크 계열.

### 기본 팔레트

| 토큰 | HEX | 용도 |
|------|-----|------|
| `--color-bg` | `#0D0D0D` | 페이지 배경 |
| `--color-surface` | `#1A1A1A` | 카드·패널·사이드바 |
| `--color-surface-2` | `#242424` | 입력 필드·중첩 카드 |
| `--color-border` | `#2E2E2E` | 구분선·테두리 |
| `--color-text-primary` | `#F0F0F0` | 본문·주요 텍스트 |
| `--color-text-secondary` | `#8A8A8A` | 부설명·라벨 |
| `--color-text-disabled` | `#4A4A4A` | 비활성 |

### 액션 색상

| 토큰 | HEX | 용도 |
|------|-----|------|
| `--color-primary` | `#4A7FD4` | 긍정 버튼·링크·강조 |
| `--color-primary-hover` | `#3A6BBF` | 긍정 버튼 hover |
| `--color-negative` | `#3D3D3D` | 부정 버튼 |
| `--color-negative-hover` | `#2A2A2A` | 부정 버튼 hover |
| `--color-dim` | `#2E2E2E` | 딤 버튼 (라이트 그레이 역할) |
| `--color-dim-hover` | `#3A3A3A` | 딤 버튼 hover |
| `--color-danger` | `#C0392B` | 삭제·경고 상태 표시 전용 |
| `--color-success` | `#27AE60` | 완료·성공 상태 |
| `--color-warning` | `#E6A817` | 주의·대기 상태 |

---

## 3. 폰트

| 항목 | 값 |
|------|-----|
| 패밀리 | Paperozi |
| 허용 굵기 | Normal (400) · Medium (500) · Semi-bold (600) |
| 기본 | 13px / Normal 400 |
| 작은 설명 | 11px / Normal 400 |
| 큰 타이틀 | 24px / Light 300 (타이틀 한정) |
| 섹션 제목 | 16px / Semi-bold 600 |
| 부제·라벨 | 12px / Medium 500 |

> Bold(700) 이상 사용 금지.

---

## 4. 버튼

모서리 `border-radius: 0` — 완전 각진 형태.

### 긍정 버튼 (Primary)

```css
background: #4A7FD4;
color: #FFFFFF;
border: none;
border-radius: 0;
padding: 8px 16px;
font-size: 13px;
font-weight: 500;
font-family: 'Paperozi', sans-serif;
```

### 부정 버튼 (Negative / 다크 그레이)

```css
background: #3D3D3D;
color: #F0F0F0;
border: none;
border-radius: 0;
padding: 8px 16px;
font-size: 13px;
font-weight: 500;
```

### 딤 버튼 (Dim / 라이트 그레이 역할)

```css
background: #2E2E2E;
color: #8A8A8A;
border: 1px solid #3A3A3A;
border-radius: 0;
padding: 8px 16px;
font-size: 13px;
font-weight: 400;
```

### 비활성 (Disabled)

```css
background: #1A1A1A;
color: #4A4A4A;
border: 1px solid #2E2E2E;
border-radius: 0;
cursor: not-allowed;
```

### 버튼 크기

| 크기 | padding | font-size | 사용처 |
|------|---------|-----------|--------|
| SM | 5px 12px | 11px | 테이블 내 인라인 액션 |
| MD | 8px 16px | 13px | 기본 (대부분) |
| LG | 12px 24px | 14px | 주요 CTA (결제·제출) |

---

## 5. 사이드바 네비게이션

Leonardo.ai 참조. 아이콘 + 라벨 수직 스택.

```css
/* 사이드바 래퍼 */
.sidebar {
  width: 60px;
  height: 100vh;
  background: #1A1A1A;
  border-right: 1px solid #2E2E2E;
  display: flex;
  flex-direction: column;
  align-items: center;
  padding: 12px 0;
  position: fixed;
  left: 0; top: 0;
}

/* 네비 아이템 */
.nav-item {
  width: 100%;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 4px;
  padding: 10px 0;
  color: #8A8A8A;
  font-size: 11px;
  font-weight: 400;
  cursor: pointer;
  transition: color 0.15s;
}
.nav-item:hover,
.nav-item.active {
  color: #F0F0F0;
}
.nav-item svg {
  width: 20px; height: 20px;
  stroke: currentColor;
  fill: none;
  stroke-width: 1.5;
}
```

---

## 6. 아이콘

- **스타일**: 싱글라인 Outline 전용 (`stroke`, `fill: none`)
- **추천 라이브러리**: Heroicons Outline / Phosphor Regular
- **크기**: 16px (인라인) / 20px (사이드바·버튼) / 24px (섹션 헤더)
- **색상**: `currentColor` 상속
- Fill·Solid 아이콘 사용 금지

---

## 7. 카드

```css
.card {
  background: #1A1A1A;
  border: 1px solid #2E2E2E;
  border-radius: 0;
  padding: 16px;
}
.card:hover {
  border-color: #4A4A4A;
}
```

---

## 8. 입력 필드

```css
input, select, textarea {
  background: #242424;
  border: 1px solid #2E2E2E;
  border-radius: 0;
  padding: 8px 12px;
  font-size: 13px;
  font-family: 'Paperozi', sans-serif;
  color: #F0F0F0;
  outline: none;
}
input:focus, select:focus, textarea:focus {
  border-color: #4A7FD4;
}
input::placeholder {
  color: #4A4A4A;
}
```

---

## 9. 테이블 / 목록

- 헤더: 11px / Semi-bold 600 / `#8A8A8A`
- 셀: 13px / Normal 400 / `#F0F0F0`
- 행 구분: `border-bottom: 1px solid #2E2E2E`
- 교차 배경(stripe): `#1A1A1A` / `#1E1E1E`
- hover: `#242424`

---

## 10. 상태 뱃지

| 상태 | 텍스트 색 | 배경 |
|------|----------|------|
| 완료 | `#27AE60` | `#0F2318` |
| 진행중 | `#4A7FD4` | `#0F1A2E` |
| 대기 | `#E6A817` | `#2A1F06` |
| 취소·오류 | `#C0392B` | `#2A0F0C` |

---

## 11. 간격 (Spacing)

4px 단위 기반.

| 토큰 | 값 |
|------|-----|
| `--space-1` | 4px |
| `--space-2` | 8px |
| `--space-3` | 12px |
| `--space-4` | 16px |
| `--space-6` | 24px |
| `--space-8` | 32px |

---

## 12. CSS 변수 선언 (공통 base.css)

```css
@import url('/* Paperozi 폰트 URL */');

:root {
  /* Color - Dark Theme */
  --color-bg: #0D0D0D;
  --color-surface: #1A1A1A;
  --color-surface-2: #242424;
  --color-border: #2E2E2E;
  --color-text-primary: #F0F0F0;
  --color-text-secondary: #8A8A8A;
  --color-text-disabled: #4A4A4A;

  /* Action */
  --color-primary: #4A7FD4;
  --color-primary-hover: #3A6BBF;
  --color-negative: #3D3D3D;
  --color-negative-hover: #2A2A2A;
  --color-dim: #2E2E2E;
  --color-dim-hover: #3A3A3A;
  --color-danger: #C0392B;
  --color-success: #27AE60;
  --color-warning: #E6A817;

  /* Typography */
  --font-family: 'Paperozi', -apple-system, sans-serif;
  --font-size-xs: 11px;
  --font-size-sm: 12px;
  --font-size-base: 13px;
  --font-size-md: 14px;
  --font-size-title: 24px;

  /* Spacing */
  --space-1: 4px;
  --space-2: 8px;
  --space-3: 12px;
  --space-4: 16px;
  --space-6: 24px;
  --space-8: 32px;

  /* Border */
  --radius: 0;
  --border: 1px solid var(--color-border);
}

* { box-sizing: border-box; margin: 0; padding: 0; }

body {
  font-family: var(--font-family);
  font-size: var(--font-size-base);
  font-weight: 400;
  color: var(--color-text-primary);
  background: var(--color-bg);
  line-height: 1.5;
}
```
