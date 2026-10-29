> 사용자 PC 실행 결과(2026-10-29 03:34) 원본. **주의: 이 판의 검사 스크립트는 Range 요청의 정상 응답(HTTP 206)을 'robots.txt 없음'으로 잘못 판정했다** — 'HTTP 206' 행은 robots.txt 가 있을 수 있음. 수정판(--terms 포함)으로 다시 실행해야 한다.

# 외부 출처 수집 가능성 재확인 (2026-10-29 03:34)

robots.txt 와 이용약관 링크만 확인했다. **robots.txt 에 금지가 없어도 약관·저작권(DB 제작자 권리)·제휴 여부는 사람이 판단**한다.

| 출처 | 권한(등록부) | robots.txt | 판정 | 약관·정책 링크 |
|---|---|---|---|---|
| g2b_mall_items_file www.data.go.kr | allowed | 없음(HTTP 206) | robots.txt 없음 — 약관으로 판단 | [정책](https://www.data.go.kr/#)<br>[이용약관](https://www.data.go.kr/ugs/selectPortalPolicyView.do#use_stplat)<br>[개인정보처리방침](https://www.data.go.kr/ugs/selectPortalPolicyView.do#indvdlinfo_process) |
| g2b_mall_api apis.data.go.kr | allowed | 없음(HTTP 400) | robots.txt 없음 — 약관으로 판단 | 찾지 못함 |
| ktomok ktomok.com | pending | 없음(HTTP 206) | robots.txt 없음 — 약관으로 판단 | 찾지 못함 |
| dacity dacity.kr | pending | 없음(HTTP 206) | robots.txt 없음 — 약관으로 판단 | 찾지 못함 |
| kcon kcon.co.kr | pending | 없음(HTTP 403) | robots.txt 없음 — 약관으로 판단 | 찾지 못함 |
| dyhumepipe dyhumepipe.fzst.kr | pending | 없음(HTTP 206) | robots.txt 없음 — 약관으로 판단 | [개인정보처리방침](http://dyhumepipe.fzst.kr/saveinfo.html)<br>[이용약관](http://dyhumepipe.fzst.kr/stipulation.html) |
| krcon kr-con.co.kr | pending | 없음(HTTP 206) | robots.txt 없음 — 약관으로 판단 | 찾지 못함 |
| dhconc dhconc.co.kr | pending | 없음(HTTP 206) | robots.txt 없음 — 약관으로 판단 | 찾지 못함 |
| tomok114 tomok114.com | blocked | 없음(HTTP 206) | robots.txt 없음 — 약관으로 판단 | [이용약관](https://tomok114.com/member/agreement.html)<br>[개인정보처리방침](https://tomok114.com/member/privacy.html) |
| kcrcic kcrcic.or.kr | pending | 없음(HTTP 404) | robots.txt 없음 — 약관으로 판단 | 찾지 못함 |
| sunroad sunroad.co.kr | pending | 없음(HTTP 206) | robots.txt 없음 — 약관으로 판단 | [개인정보취급방침](http://sunroad.co.kr/javascript:openPrivacy();) |
| enmcon enmcon.co.kr | pending | 있음(HTTP 200) | 일부 경로만 금지(상품 경로 제외) — /wp-admin/ | 찾지 못함 |
| syccrt syccrt.com | pending | 있음(HTTP 200) | 일부 경로만 금지(상품 경로 제외) — /site_join /site_join_agree /shop_cart /?mode* | 찾지 못함 |
| gayasteel gayasteel.kr | pending | 없음(HTTP 404) | robots.txt 없음 — 약관으로 판단 | 찾지 못함 |
| toamconcrete toamconcrete.com | pending | 없음(HTTP 206) | robots.txt 없음 — 약관으로 판단 | [개인정보취급방침](http://toamconcrete.com/sub/pop_privacy.html)<br>[이메일무단수집거부](http://toamconcrete.com/sub/pop_email.html) |
| greenpipe greenpipe.kr | pending | 없음(HTTP 206) | robots.txt 없음 — 약관으로 판단 | 찾지 못함 |
| 88con 88con.com | pending | 있음(HTTP 200) | 일부 경로만 금지(상품 경로 제외) — /rb /rb/_core/ /rb/_var/ /rb/_tmp/ /rb/layouts/ /rb/modules/ | [홈페이지 이용약관](http://88con.com/./?mod=agreement)<br>[개인정보처리방침](http://88con.com/./?mod=private)<br>[이메일무단수집거부](http://88con.com/#layerpop_email) |

다음: 약관 링크를 열어 자동수집·복제 금지 조항을 확인하고, 허락(제휴·카탈로그 제공)을 받은 출처만 data/external/sources.json 의 permission 을 allowed 로 바꾼다(근거를 basis 에 기록).
