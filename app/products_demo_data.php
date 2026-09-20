<?php
declare(strict_types=1);
/**
 * products.php 데모 데이터 (DB 미연결 시에만 쓰이는 Figma 목업 하드코딩).
 * ⚠️ 실제 상품이 아니며, db() 연결 시엔 절대 사용되지 않는다($demoMode).
 *    데이터 분량이 커 페이지 가독성/500줄 제한을 위해 파셜로 분리.
 * @return array{colorHex:array<string,string>, products:array, similar:array, nextSteps:array}
 */

return [
    // 색상 → hex 맵
    'colorHex' => [
        '회색'    => '#888888',
        '검정'    => '#333333',
        '흰색'    => '#eeeeee',
        '오렌지'  => '#e07020',
        '아이보리' => '#f5ead5',
        '갈색'    => '#7b4f28',
        '적색'    => '#cc2020',
        '커피'    => '#6f4e37',
        '화색'    => '#c0b8a8',
    ],

    // 데모 상품 10행
    'products' => [
        ['name'=>'인조화강블록(투수)','brand'=>'','spec'=>'200*200*60T','specinfo'=>'유입4구/유출1구 사각몸통','colors'=>['회색','검정','흰색','오렌지','아이보리'],'price'=>8500,'weight'=>120,'load'=>204,'freight'=>1500,'desc'=>'보차도블록 인조화강석 패턴의 투수형 블록으로 우수한 배수 성능을 제공합니다.'],
        ['name'=>'인조화강블록(투수)','brand'=>'','spec'=>'200*200*60T','specinfo'=>'가정용','colors'=>['갈색'],'price'=>8800,'weight'=>128,'load'=>218.4,'freight'=>1400,'desc'=>'주거지역 보도 시공에 적합한 가정용 규격입니다.'],
        ['name'=>'인조화강블록(투수)','brand'=>'','spec'=>'200*200*60T','specinfo'=>'고강도콘크리트_KS점형','colors'=>['검정'],'price'=>8800,'weight'=>128,'load'=>218.4,'freight'=>1400,'desc'=>'KS인증 점형 블록. 장애인 보행 안전을 위한 점자 패턴 적용.'],
        ['name'=>'인조화강블록(투수)','brand'=>'','spec'=>'200*200*60T','specinfo'=>'고강도콘크리트_KS 논슬립 점형','colors'=>['회색'],'price'=>8800,'weight'=>128,'load'=>218.4,'freight'=>1400,'desc'=>'미끄럼 방지 논슬립 표면 처리된 KS 인증 점형 블록입니다.'],
        ['name'=>'인조화강블록(투수)','brand'=>'','spec'=>'200*200*60T','specinfo'=>'컬러보도','colors'=>['회색','검정','흰색','적색','갈색','오렌지','아이보리'],'price'=>9500,'weight'=>120,'load'=>216,'freight'=>1400,'desc'=>'다양한 색상으로 도시 미관을 향상시키는 컬러 보도블록.'],
        ['name'=>'인조화강블록(투수)','brand'=>'','spec'=>'200*200*60T','specinfo'=>'200*200*60T','colors'=>['회색','검정','흰색','커피','오렌지','아이보리'],'price'=>9000,'weight'=>128,'load'=>216,'freight'=>1400,'desc'=>'표준 규격의 인조화강 투수블록. 범용 보도 시공용.'],
        ['name'=>'인조화강블록(투수)','brand'=>'','spec'=>'200*200*60T','specinfo'=>'200*200*60T','colors'=>['오렌지'],'price'=>9200,'weight'=>128,'load'=>218.4,'freight'=>1400,'desc'=>'오렌지 단색 마감. 상업지구 보도 포장에 적합.'],
        ['name'=>'인조화강블록(투수)','brand'=>'','spec'=>'200*200*60T','specinfo'=>'200*200*60T','colors'=>['흰색'],'price'=>9200,'weight'=>128,'load'=>218.4,'freight'=>1400,'desc'=>'밝은 흰색 계열. 공원·광장 등 개방형 공간 포장재.'],
        ['name'=>'인조화강블록(투수)','brand'=>'','spec'=>'200*200*60T','specinfo'=>'200*200*60T','colors'=>['아이보리'],'price'=>9400,'weight'=>128,'load'=>218.4,'freight'=>1400,'desc'=>'아이보리 톤으로 자연스럽고 따뜻한 분위기 연출.'],
        ['name'=>'인조화강블록(투수)','brand'=>'','spec'=>'200*200*60T','specinfo'=>'200*200*60T','colors'=>['회색','검정','흰색','갈색','오렌지','아이보리'],'price'=>10000,'weight'=>125,'load'=>204,'freight'=>1500,'desc'=>'프리미엄 라인. 고급 주거단지·상업시설 보도 시공용.'],
    ],

    // 데모 관련 제품(동종/유사)
    'similar' => [
        ['name'=>'안전중하중그레이팅',        'img'=>'/assets/images/products-r1.png'],
        ['name'=>'인터로킹U형',               'img'=>'/assets/images/products-r2.png'],
        ['name'=>'인조화강블록(불투수)',       'img'=>'/assets/images/products-r3.png'],
        ['name'=>'다칼라블랜딩블록',           'img'=>'/assets/images/products-r4.png'],
        ['name'=>'안전중하중그레이팅맨홀(집..)', 'img'=>'/assets/images/products-r5.png'],
        ['name'=>'녹지경계블록',              'img'=>'/assets/images/products-r6.png'],
        ['name'=>'볼라드',                    'img'=>'/assets/images/products-r7.png'],
    ],

    // 데모 공정연결 제품
    'nextSteps' => [
        ['name'=>'인터로킹U형(보도블록)',       'img'=>'/assets/images/products-r1.png'],
        ['name'=>'장애인사각블록(점자블록)',    'img'=>'/assets/images/products-r2.png'],
        ['name'=>'화강경계도로',               'img'=>'/assets/images/products-r3.png'],
        ['name'=>'인조화강경계석',             'img'=>'/assets/images/products-r4.png'],
        ['name'=>'콘크리트경계석',             'img'=>'/assets/images/products-r8.png'],
        ['name'=>'녹지경계블록',              'img'=>'/assets/images/products-r9.png'],
        ['name'=>'볼라드',                    'img'=>'/assets/images/products-r10.png'],
    ],
];
