<?php

function ocaLoadSettings() {
    $path = __DIR__ . '/ctc-settings-oca.json';

    if (!file_exists($path)) {
        return [];
    }

    $json = file_get_contents($path);
    $settings = json_decode($json, true);

    return is_array($settings) ? $settings : [];
}

function ocaWpAutop($text) {
    $text = trim((string)$text);

    if ($text === '') {
        return '';
    }

    $text = str_replace(["\r\n", "\r"], "\n", $text);
    $parts = preg_split("/\n\s*\n/", $text);

    $html = '';
    foreach ($parts as $part) {
        $part = trim($part);
        if ($part !== '') {
            $html .= '<p>' . $part . '</p>';
        }
    }

    return $html;
}

function calculateOCAInterpretation($points_arr) {
    $result = [];

    list($a, $b, $c, $d, $e, $f, $g, $h, $i, $j) = array_map('intval', $points_arr);

    $cards = range('a', 'j');

    $range1 = 70;
    $range2 = 20;
    $range3 = -39;

    $desirable_condition = 33;
    $acceptable_condition = 7;
    $pay_attention = -18;

    $titi_viti = true;
    foreach ($points_arr as $point) {
        if ((int)$point < $range1) {
            $titi_viti = false;
            break;
        }
    }

    if ($titi_viti) {
        return ['titi-viti' => true];
    }

    if (
        $a <= -38 && $a >=  -95 &&
        $b <= -65 && $b >= -100 &&
        $c <= -63 && $c >=  -96 &&
        $d <=  35 && $d >=  -83 &&
        $e <=  45 && $e >=    3 &&
        $f <=  72 && $f >=   22 &&
        $g <= -55 && $g >=  -92 &&
        $h <= -35 && $h >=  -98 &&
        $i <=  18 && $i >=  -90 &&
        $j <=  22 && $j >=  -72
    ) {
        return ['random-chart' => true];
    }

    if (90 == $g && 90 == $i) {
        $result['g90i90'] = true;
    }

    $result['syndromes_b'] = [];

    if ($a >= $range1 && $b < $range2) { $result['syndromes_b'][] = 'a1-b3-4'; $cards = array_diff($cards, ['a', 'b']); }
    if ($a >= $range1 && $c < $range2) { $result['syndromes_b'][] = 'a1-c3-4'; $cards = array_diff($cards, ['a', 'c']); }
    if ($b >= $range1 && $a < $range2) { $result['syndromes_b'][] = 'b1-a3-4'; $cards = array_diff($cards, ['b', 'a']); }
    if ($b >= $range1 && $c < $range2) { $result['syndromes_b'][] = 'b1-c3-4'; $cards = array_diff($cards, ['b', 'c']); }
    if ($c >= $range1 && $a < $range2) { $result['syndromes_b'][] = 'c1-a3-4'; $cards = array_diff($cards, ['c', 'a']); }
    if ($c >= $range1 && $b < $range2) { $result['syndromes_b'][] = 'c1-b3-4'; $cards = array_diff($cards, ['c', 'b']); }
    if ($d >= $range1 && $a < $range2) { $result['syndromes_b'][] = 'd1-a3-4'; $cards = array_diff($cards, ['d', 'a']); }
    if ($e >= $range1 && $d < $range2) { $result['syndromes_b'][] = 'e1-d3-4'; $cards = array_diff($cards, ['e', 'd']); }
    if ($e >= $range1 && $f < $range2) { $result['syndromes_b'][] = 'e1-f3-4'; $cards = array_diff($cards, ['e', 'f']); }
    if ($f >= $range1 && $e < $range2) { $result['syndromes_b'][] = 'f1-e3-4'; $cards = array_diff($cards, ['f', 'e']); }
    if ($f >= $range1 && $g < $range2) { $result['syndromes_b'][] = 'f1-g3-4'; $cards = array_diff($cards, ['f', 'g']); }
    if ($g >= $range1 && $f < $range2) { $result['syndromes_b'][] = 'g1-f3-4'; $cards = array_diff($cards, ['g', 'f']); }
    if ($h >= $range1 && $i < $range2) { $result['syndromes_b'][] = 'h1-i3-4'; $cards = array_diff($cards, ['h', 'i']); }
    if ($i >= $range1 && $h < $range2) { $result['syndromes_b'][] = 'i1-h3-4'; $cards = array_diff($cards, ['i', 'h']); }

    $result['cards'] = [];

    foreach ($cards as $card) {
        if (${$card} >= $range1) {
            $result['cards'][] = "{$card}1";
        } elseif (${$card} >= $range2) {
            $result['cards'][] = "{$card}2";
        } elseif (${$card} >= $range3) {
            $result['cards'][] = "{$card}3";
        } else {
            $result['cards'][] = "{$card}4";
        }
    }

    $result['syndromes_c'] = [];

    if ($a < $range3 && $b < $range3 && $c < $range3) { $result['syndromes_c'][] = 'a4-b4-c4'; }
    if ($a < $range3 && $e >= $range1) { $result['syndromes_c'][] = 'a4-e1'; }
    if ($a < $range3 && $b < $range3 && $c < $range3 && $e >= $range1) { $result['syndromes_c'][] = 'a4-b4-c4-e1'; }

    if (
        $a < $range3 && $j < $range3 &&
        $b >= $acceptable_condition &&
        $c >= $acceptable_condition &&
        $d >= $acceptable_condition &&
        $e >= $acceptable_condition &&
        $f >= $acceptable_condition &&
        $g >= $acceptable_condition &&
        $h >= $acceptable_condition &&
        $i >= $acceptable_condition
    ) {
        $result['syndromes_c'][] = 'a4-j4';
    }

    if ($a < $range3 && $c < $range3 && $g < $range3 && $f >= $range1) { $result['syndromes_c'][] = 'a4-c4-g4-f1'; }
    if ($a >= $range1 && $h < $range3) { $result['syndromes_c'][] = 'a1-h4'; }
    if ($a >= $range1 && $d >= $pay_attention && $d < $desirable_condition) { $result['syndromes_c'][] = 'a1-d-normal'; }
    if ($b < $range3 && $g < $range3 && $f >= $range1) { $result['syndromes_c'][] = 'b4-g4-f1'; }
    if ($b >= $range1 && $d < $range3) { $result['syndromes_c'][] = 'b1-d4'; }
    if ($c < $range3 && $h < $range3) { $result['syndromes_c'][] = 'c4-h4'; }
    if ($d < $range3 && $j >= $range1) { $result['syndromes_c'][] = 'd4-j1'; }
    if ($d < $range3 && $g >= $range1) { $result['syndromes_c'][] = 'd4-g1'; }

    if (
        $d >= $range1 && $e >= $range1 && $f >= $range1 &&
        $a < $range3 &&
        $b < $range3 &&
        $c < $range3 &&
        $g < $range3 &&
        $h < $range3 &&
        $i < $range3 &&
        $j < $range3
    ) {
        $result['syndromes_c'][] = 'd1-e1-f1-rest-low';
    }

    if ($d < $range3 && $g < $range3 && $h < $range3 && $i < $range3) { $result['syndromes_c'][] = 'd4-g4-h4-i4'; }
    if ($e < $range3 && $f < $range3 && $j < $range3) { $result['syndromes_c'][] = 'e4-f4-j4'; }

    if (
        $e < $range3 && $f < $range3 &&
        $a >= 0 &&
        $b >= 0 &&
        $c >= 0 &&
        $d >= 0 &&
        $g >= 0 &&
        $h >= 0 &&
        $i >= 0 &&
        $j >= 0
    ) {
        $result['syndromes_c'][] = 'e4-f4-rest-normal';
    }

    if ($e >= $range1 && $g < $range3) { $result['syndromes_c'][] = 'e1-g4'; }
    if ($f >= $range1 && $g < $range3 && $h < $range3) { $result['syndromes_c'][] = 'f1-g4-h4'; }
    if ($i >= $range1 && $j >= $range1 && $b >= $range1 && $f < $desirable_condition) { $result['syndromes_c'][] = 'i1-j1-b1-f-middle-or-bottom'; }
    if ($i >= $range1 && $j < $range3) { $result['syndromes_c'][] = 'i1-j4'; }
    if ($a < $range3 && $c < $range3 && $d < $range3 && $g < $range3 && $h < $range3 && $i < $range3 && $j < $range3) { $result['syndromes_c'][] = 'a4-c4-d4-g4-h4-i4-j4'; }

    $result['syndromes_d'] = [];

    if ($c > $a && $c > $b && $c > $d && $c > $e && $c > $f && $c > $g && $c > $h && $c > $i && $c > $j) { $result['syndromes_d'][] = 'c-highest'; }
    if ($d > $a && $d > $b && $d > $c && $d > $e && $d > $f && $d > $g && $d > $h && $d > $i && $d > $j) { $result['syndromes_d'][] = 'd-highest'; }
    if ($e > $f) { $result['syndromes_d'][] = 'e-higher-than-f'; }
    if ($f > $e) { $result['syndromes_d'][] = 'f-higher-than-e'; }
    if ($i > $a && $i > $b && $i > $c && $i > $d && $i > $e && $i > $f && $i > $g && $i > $h && $i > $j) { $result['syndromes_d'][] = 'i-highest'; }

    return $result;
}

function ocaRenderCard($title, $text) {
    if (trim((string)$text) === '') {
        return '';
    }

    return '
        <div class="oca-interpretation-card bg-white rounded-2xl border border-gray-100 shadow-sm p-6">
            <h3 class="text-[#FFA048] font-black text-lg mb-3">' . htmlspecialchars_decode($title, ENT_QUOTES) . '</h3>
            <div class="oca-interpretation-text text-gray-700 text-sm leading-relaxed space-y-3">' . ocaWpAutop(htmlspecialchars_decode($text, ENT_QUOTES)) . '</div>
        </div>
    ';
}

function renderOcaInterpretationBlock($row) {
    $settings = ocaLoadSettings();

    if (empty($settings)) {
        return '
            <div class="bg-white rounded-3xl shadow-sm border border-red-100 overflow-hidden mt-8">
                <div class="p-6 text-red-500 font-bold">Не найден файл ctc-settings-oca.json</div>
            </div>
        ';
    }

    $scores = [];
    if (!empty($row['graph_scores']) && preg_match_all('/-?\d+/', $row['graph_scores'], $sm)) {
        $scores = array_map('intval', $sm[0]);
    }

    if (count($scores) !== 10) {
        return '';
    }

    $answers = [];
    if (!empty($row['answers'])) {
        $answers = json_decode($row['answers'], true);
        if (!is_array($answers)) {
            $answers = [];
        }
    }

    $is_manic_e = isset($answers[21]) && $answers[21] === '+';
    $is_manic_b = isset($answers[196]) && $answers[196] === '+';

    $calc = calculateOCAInterpretation($scores);

    ob_start();
    ?>
    <div class="oca-interpretation-block bg-white rounded-3xl shadow-sm border border-gray-100 overflow-hidden mt-8">
        <div class="oca-interpretation-head p-6 border-b border-gray-50 bg-gray-50/60">
            <div class="oca-interpretation-title text-[#2E3784] font-black text-xl uppercase tracking-tight">Расшифровка графика</div>
            <div class="oca-interpretation-subtitle text-[10px] text-gray-400 font-bold uppercase tracking-widest mt-1">Карточки черт и синдромы для оценщика</div>
        </div>

        <div class="p-6 space-y-8">

            <?php if (isset($calc['titi-viti'])): ?>
                <?= ocaRenderCard('«тити-вити»', $settings['titi-viti'] ?? '') ?>

            <?php elseif (isset($calc['random-chart'])): ?>
                <?= ocaRenderCard('Случайный график', $settings['random-chart'] ?? '') ?>

            <?php else: ?>

                <?php if (isset($calc['g90i90'])): ?>
                    <?= ocaRenderCard('G90 I90', $settings['g90i90'] ?? '') ?>
                <?php endif; ?>

                <?php if ($is_manic_b): ?>
                    <?= ocaRenderCard($settings['transcript-title-manic-b'] ?? 'ЛОЖНАЯ ТОЧКА «B»', $settings['transcript-point-manic-b'] ?? '') ?>
                <?php endif; ?>

                <?php if ($is_manic_e): ?>
                    <?= ocaRenderCard($settings['transcript-title-manic-e'] ?? 'ЛОЖНАЯ ТОЧКА «E»', $settings['transcript-point-manic-e'] ?? '') ?>
                <?php endif; ?>

                <?php if (!empty($calc['syndromes_b'])): ?>
                    <div>
                        <h2 class="oca-interpretation-section-title text-3xl font-light text-gray-900 mb-5">СИНДРОМЫ. РАЗДЕЛ Б</h2>
                        <div class="space-y-4">
                            <?php foreach ($calc['syndromes_b'] as $syndrome): ?>
                                <?= ocaRenderCard($settings["transcript-title-{$syndrome}"] ?? strtoupper($syndrome), $settings[$syndrome] ?? '') ?>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if (!empty($calc['cards'])): ?>
                    <div>
                        <h2 class="oca-interpretation-section-title text-3xl font-light text-gray-900 mb-5">Карточки черт</h2>
                        <div class="space-y-4">
                            <?php foreach ($calc['cards'] as $card): ?>
                                <?= ocaRenderCard(strtoupper($card), $settings["transcript-point-{$card}"] ?? '') ?>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if (!empty($calc['syndromes_c'])): ?>
                    <div>
                        <div class="oca-interpretation-note bg-white rounded-2xl border border-gray-100 shadow-sm p-6 mb-4">
                            <h2 class="oca-interpretation-section-title text-3xl font-light text-gray-900 mb-4">СИНДРОМЫ. РАЗДЕЛ В</h2>
                            <p class="oca-interpretation-text text-gray-700 text-sm leading-relaxed">Карточки для этих синдромов дают данные для оценщика. Эти данные используются для того, чтобы произвести впечатление на человека, проходящего тест. Они не являются данными, которые могли бы быть просто прочитаны человеку. Эти синдромы используются в качестве дополнительных данных для оценки.</p>
                        </div>
                        <div class="space-y-4">
                            <?php foreach ($calc['syndromes_c'] as $syndrome): ?>
                                <?= ocaRenderCard($settings["transcript-title-{$syndrome}"] ?? strtoupper($syndrome), $settings[$syndrome] ?? '') ?>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if (!empty($calc['syndromes_d'])): ?>
                    <div>
                        <div class="oca-interpretation-note bg-white rounded-2xl border border-gray-100 shadow-sm p-6 mb-4">
                            <h2 class="oca-interpretation-section-title text-3xl font-light text-gray-900 mb-4">СИНДРОМЫ. РАЗДЕЛ Г</h2>
                            <p class="oca-interpretation-text text-gray-700 text-sm leading-relaxed uppercase">Их также не следует читать тестируемому человеку, но они могут быть использованы в беседе в качестве дополнительных данных для оценки, а также для того, чтобы помочь в просвещении человека.</p>
                        </div>
                        <div class="space-y-4">
                            <?php foreach ($calc['syndromes_d'] as $syndrome): ?>
                                <?= ocaRenderCard($settings["transcript-title-{$syndrome}"] ?? strtoupper($syndrome), $settings[$syndrome] ?? '') ?>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>

            <?php endif; ?>

        </div>
    </div>
    <?php
    return ob_get_clean();
}