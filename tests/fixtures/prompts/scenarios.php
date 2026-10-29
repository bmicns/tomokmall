<?php
// 상담 프롬프트 검증용 고정 시나리오 7개(API 호출 없음). 여러 턴은 assistant 답변을 고정 문장으로 둔다(재현성).
// expect: rag_road_context() 기대 판정
return [
    's1_public_road_80t' => ['expect' => 'public_road', 'title' => '공공도로 차도에 80T 블록 사용 문의',
        'messages' => [['role' => 'user', 'content' => '시청에서 발주한 도로 공사인데 차도에 80T 인터로킹 블록 깔아도 되나요? 두께 몇 mm 써야 해요?']]],
    's2_public_road_multi' => ['expect' => 'public_road', 'title' => '관급 도로포장 공사의 블록 두께 문의(여러 턴)',
        'messages' => [
            ['role' => 'user', 'content' => '차가 다니는 곳에 블록 깔려는데 두께 몇 T 써야 돼요?'],
            ['role' => 'assistant', 'content' => '개인 부지(마당·주차장·진입로)인가요, 도로(인도·차도) 공사인가요? 발주처 설계도서나 시방서가 있나요?'],
            ['role' => 'user', 'content' => '구청이 발주처인 관급 도로 포장 공사예요'],
        ]],
    's3_public_sidewalk' => ['expect' => 'public_sidewalk', 'title' => '차량이 횡단하는 공공 보도',
        'messages' => [['role' => 'user', 'content' => '가게 앞 인도를 차가 가로질러 들어오는데 인도 블록 두께는요?']]],
    's4_private_yard' => ['expect' => 'private', 'title' => '개인 주택 마당 주차 공간',
        'messages' => [['role' => 'user', 'content' => '집 앞 마당에 차 두 대 주차할 건데 블록 두께 몇 T?']]],
    's5_apartment_parking' => ['expect' => 'private', 'title' => '아파트 단지 내부 주차장',
        'messages' => [['role' => 'user', 'content' => '아파트 단지 주차장 블록 80T면 되나요?']]],
    's6_unclear' => ['expect' => 'unclear', 'title' => '공사 장소가 불명확한 경우',
        'messages' => [['role' => 'user', 'content' => '차가 올라가는 곳에 블록 깔려면 몇 mm 써야 돼요?']]],
    's7_garden_path' => ['expect' => 'none', 'title' => '보행자 전용 정원길',
        'messages' => [['role' => 'user', 'content' => '사람만 다니는 정원 길에 블록 깔고 싶어요']]],
];
