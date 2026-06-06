<?php
defined('ASR_ADMIN') || exit;
?>
<style>
.rs2-icon{width:18px;height:18px;display:inline-block;vertical-align:middle;object-fit:contain;opacity:.95;flex:0 0 auto}
.rs2-icon-sm{width:16px;height:16px}
.rs2-icon-lg{width:22px;height:22px}
.rs2-btn{display:inline-flex!important;align-items:center!important;justify-content:center!important;gap:8px!important;line-height:1!important;white-space:nowrap}
.rs2-icon-btn{display:inline-flex!important;align-items:center!important;justify-content:center!important}
.rs2-detail-client-name{font-weight:700!important;text-transform:none!important;letter-spacing:.01em!important;color:#1f2937!important}
.rs2-detail-subhead{color:#FFA048!important;font-weight:700!important}
.rs2-meta{display:inline-flex;align-items:center;gap:4px}

.oca-interpretation h2,
.oca-interpretation h3,
.interpretation h2,
.interpretation h3,
[class*="interpretation"] h2,
[class*="interpretation"] h3{
  color:#FFA048!important;
  font-weight:700!important;
}
</style>
<style>
/* rs2 button icon alignment correction */
.rs2-btn{
  display:inline-flex!important;
  flex-direction:row!important;
  align-items:center!important;
  justify-content:center!important;
  gap:7px!important;
  line-height:1!important;
  white-space:nowrap!important;
  vertical-align:middle!important;
}
.rs2-toolbar-btn{
  min-height:42px!important;
  height:42px!important;
  padding-top:0!important;
  padding-bottom:0!important;
}
.rs2-btn .rs2-icon,
.rs2-btn .rs2-icon-sm{
  width:14px!important;
  height:14px!important;
  display:inline-block!important;
  flex:0 0 14px!important;
}
.rs2-btn .rs2-icon-lg{
  width:18px!important;
  height:18px!important;
  flex:0 0 18px!important;
}
@media(max-width:768px){
  .rs2-toolbar-btn{
    min-height:40px!important;
    height:40px!important;
  }
  .rs2-btn{
    gap:6px!important;
  }
  .rs2-btn .rs2-icon,
  .rs2-btn .rs2-icon-sm{
    width:13px!important;
    height:13px!important;
    flex-basis:13px!important;
  }
}
</style>
            <?php
            $target_ids = $view_id ? [$view_id] : $compare_ids;
            $in = str_repeat('?,', count($target_ids) - 1) . '?';
            $stmt = $pdo->prepare("SELECT * FROM oca_results WHERE id IN ($in) ORDER BY created_at ASC");
            $stmt->execute($target_ids);
            $results_data = $stmt->fetchAll();
            $chart_datasets = []; $users_info = [];
            
            foreach($results_data as $index => $data) {
                $answers_array = json_decode($data['answers'], true);
                
                // Логика облачек на базе предоставленных условий
                $is_manic_e = (
                    (isset($answers_array[21]) && $answers_array[21] === '+')
                    || (!empty($data['false_point_e']))
                );

                $is_manic_b = (
                    (isset($answers_array[196]) && $answers_array[196] === '+')
                    || (!empty($data['false_point_b']))
                );

                $scores = [];
                if (preg_match_all('/-?\d+/', $data['graph_scores'], $sm)) { $scores = array_map('intval', $sm[0]); }
                $color = (count($results_data) === 2 && $index === 0) ? '#bf3030' : '#f97316';
                
                $chart_datasets[] = ['scores'=>$scores, 'manicB'=>$is_manic_b, 'manicE'=>$is_manic_e, 'color'=>$color];
                $users_info[] = [
                    'id'=>$data['id'],
                    'name'=>$data['name'], 'phone'=>$data['phone'], 'email'=>$data['email'], 'city'=>$data['city'], 
                    'role'=>$data['role'], 'utm'=>$data['utm'], 'crm_deal'=>$data['crm_deal'], 'crm_contact'=>$data['crm_contact'],
                    'date'=>date("d.m.Y H:i", strtotime($data['created_at'])), 'color'=>$color, 'raw'=>$data['graph_scores']
                ];
            }
            ?>
            
            <?php if (!$is_shared_view): ?>
            <div class="mb-6 flex items-center gap-4 mobile-stack">
                <a href="admin.php?tab=results" class="inline-flex items-center gap-2 px-6 py-3 bg-white border border-gray-200 text-gray-600 font-bold text-xs uppercase tracking-widest rounded-xl hover:text-[#ffa048] shadow-sm transition-all"><img class="rs2-icon rs2-icon-sm" src="/assets/admin/icons/rs2-back-gray.svg" alt="" aria-hidden="true"> <span>Назад</span></a>
                <button id="downloadPdfBtn" class="inline-flex items-center gap-2 px-6 py-3 bg-[#ffa048] text-white font-bold text-xs uppercase tracking-widest rounded-xl hover:bg-[#ff8f28] shadow-md transition-all"><img class="rs2-icon rs2-icon-sm" src="/assets/admin/icons/rs2-download-white.svg" alt="" aria-hidden="true">Скачать PDF</button>
            </div>
            <?php endif; ?>

            <div class="screenshot-area" id="pdf-content">
                <div class="mb-10 space-y-4">
                    <?php 
                    // Берем самую последнюю анкету для вывода единой шапки
                    $latest_user = end($users_info); 
                    reset($users_info); // Сброс указателя массива на всякий случай
                    ?>
                    <div class="profile-head p-5 bg-gray-50 border border-gray-100 rounded-2xl flex justify-between items-center" style="border-left: 6px solid <?=$latest_user['color']?>;">
                        <div>
                            <div class="rs2-detail-client-name text-xl tracking-tight">
                                <?=htmlspecialchars($latest_user['name'])?> 
                                <?php if(count($users_info) === 1): // Выводим дату в шапке только если график один ?>
                                <span class="text-[10px] text-gray-400 font-bold ml-2"><?=$latest_user['date']?></span>
                                <?php endif; ?>
                            </div>
                            <?php if (!$is_shared_view): ?>
                            <div class="text-sm font-semibold text-gray-600 mt-1"><?=htmlspecialchars($latest_user['phone'])?> • <?=htmlspecialchars($latest_user['email'])?> • <?=htmlspecialchars($latest_user['city'])?></div>
                            <?php endif; ?>
                        </div>
                        <div class="profile-role text-right text-[10px] text-gray-400 font-bold uppercase tracking-widest bg-white p-3 rounded-xl border border-gray-100">Роль <span class="text-gray-800 text-sm block mt-1"><?=htmlspecialchars($latest_user['role'])?></span></div>
                    </div>
                </div>

                <div class="chart-mobile-preview mb-5">
                    <button type="button" id="openChartImageBtn" class="chart-mobile-preview-button" disabled aria-label="Открыть график как изображение">
                        <img id="chartPreviewImage" alt="График результатов теста" src="" loading="eager">
                    </button>
                    <div class="mt-3 text-[11px] leading-relaxed text-gray-400 font-semibold text-center">
                        Нажмите на график, чтобы открыть его как картинку. В открывшемся окне можно увеличить двумя пальцами или сохранить изображение.
                    </div>
                </div>

                <div class="chart-scroll custom-scrollbar">
                    <div class="chart-wrapper">
                        <canvas id="ocaCanvas" width="1100" height="596"></canvas>
                    </div>
                </div>
                
                <?php if (count($users_info) > 1): // Выводим блок с датами под графиком только при сравнении ?>
                <div class="mt-6 mb-2 flex justify-center items-center gap-8">
                    <div class="text-[10px] font-bold text-gray-400 uppercase tracking-widest">Даты заполнения:</div>
                    <div class="flex items-center gap-6">
                        <?php foreach($users_info as $u): ?>
                        <div class="flex items-center gap-2">
                            <span class="w-3 h-3 rounded-full shadow-sm" style="background-color: <?=$u['color']?>;"></span>
                            <span class="text-sm font-black" style="color: <?=$u['color']?>;"><?=$u['date']?></span>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

                <?php if (!$is_shared_view): ?>
                <div class="mt-8 border-t border-gray-100 pt-6 text-center">
                    <div class="font-bold text-gray-400 uppercase text-[10px] tracking-widest mb-4 text-[#FFA048]">Баллы профиля (A-J)</div>
                    <div class="flex flex-col gap-2">
                        <?php foreach($users_info as $u): ?>
                        <div class="p-3 rounded-xl tracking-wider border border-gray-100 text-sm font-bold" style="background-color: <?=$u['color']?>08; color: <?=$u['color']?>;">
                            <span class="opacity-70 mr-2 uppercase"><?= (count($users_info) > 1) ? $u['date'] : htmlspecialchars($u['name']) ?>:</span> <?=$u['raw']?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="mt-6 border-t border-gray-100 pt-6 text-center">
                    <div class="font-bold text-gray-400 uppercase text-[10px] tracking-widest mb-4 text-gray-800">UTM Метки</div>
                    <div class="flex flex-col gap-2">
                        <?php foreach($users_info as $u): ?>
                        <div class="p-3 rounded-xl border border-gray-100 text-xs font-medium text-gray-600 bg-gray-50/50 break-words text-left">
                            <span class="font-bold text-gray-800 mr-2 uppercase">Источник <?= (count($users_info) > 1) ? '('.$u['date'].')' : '' ?>:</span> 
                            <?= !empty($u['utm']) ? htmlspecialchars($u['utm']) : '<span class="text-gray-400 italic">Нет данных</span>' ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>

                        <?php if (!$is_shared_view && count($results_data) === 1): ?>
                <?php echo renderOcaInterpretationBlock($results_data[0]); ?>
            <?php endif; ?>

            <?php if (!$is_shared_view): ?>
            <div class="bg-white rounded-3xl shadow-sm border border-gray-100 overflow-hidden mt-8">
                <div class="p-5 border-b border-gray-50 bg-gray-50/50 font-bold text-gray-400 uppercase text-[10px]">Ссылки для респондента</div>
                <div class="p-6 space-y-4">
                    <?php 
                    
                    // Ссылки на одиночные графики
                    foreach($users_info as $u): 
                        $hash_id = encodeId($u['id']);
                        $share_link = function_exists('asr_config_app_url') ? asr_config_app_url('admin.php?shared=' . $hash_id) : ('admin.php?shared=' . $hash_id);
                    ?>
                    <div class="flex flex-col gap-2">
                        <span class="text-xs font-bold text-gray-400 uppercase"><?= (count($users_info) > 1) ? 'График от ' . $u['date'] : htmlspecialchars($u['name']) ?>:</span>
                        <div class="share-row flex items-center gap-3">
                            <input type="text" readonly value="<?php echo $share_link; ?>" id="link_<?php echo $u['id']; ?>" class="w-full px-4 py-3 rounded-xl border border-gray-200 outline-none text-sm font-semibold bg-gray-50 text-gray-600">
                            <button onclick="document.getElementById('link_<?php echo $u['id']; ?>').select(); document.execCommand('copy'); alert('Ссылка скопирована!');" class="px-6 py-3 bg-[#ffa048] text-white font-bold text-xs uppercase rounded-xl hover:bg-[#ff8f28] shadow-sm transition-all whitespace-nowrap"><img class="rs2-icon rs2-icon-sm" src="/assets/admin/icons/rs2-copy-white.svg" alt="" aria-hidden="true">Скопировать</button>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    
                    <?php 
                    // Третья общая ссылка для сдвоенного графика (выводится только при сравнении)
                    if(count($users_info) > 1): 
                        $combined_hashes = array_map(function($u) { return encodeId($u['id']); }, $users_info);
                        $share_link_combined = function_exists('asr_config_app_url') ? asr_config_app_url('admin.php?shared=' . implode(',', $combined_hashes)) : ('admin.php?shared=' . implode(',', $combined_hashes));
                    ?>
                    <div class="flex flex-col gap-2 mt-4 pt-4 border-t border-gray-100">
                        <span class="text-xs font-bold text-gray-800 uppercase">Сравнение (Оба графика сразу):</span>
                        <div class="share-row flex items-center gap-3">
                            <input type="text" readonly value="<?php echo $share_link_combined; ?>" id="link_combined" class="w-full px-4 py-3 rounded-xl border border-gray-200 outline-none text-sm font-semibold bg-orange-50 text-gray-800">
                            <button onclick="document.getElementById('link_combined').select(); document.execCommand('copy'); alert('Ссылка скопирована!');" class="px-6 py-3 bg-[#FFA048] text-white font-bold text-xs uppercase rounded-xl hover:bg-[#f28d35] shadow-sm transition-all whitespace-nowrap"><img class="rs2-icon rs2-icon-sm" src="/assets/admin/icons/rs2-copy-white.svg" alt="" aria-hidden="true">Скопировать</button>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                </div>
            </div>
            <?php endif; ?>

            <script>
                window.onload = function() {
                    const canvas = document.getElementById('ocaCanvas');
                    const ctx = canvas.getContext('2d');
                    const datasets = <?php echo json_encode($chart_datasets); ?>;
                    const xPositions = [121, 216, 310, 405, 500, 594, 690, 783, 878, 974];
                    const getY = (s) => 306 - (s * 2.0);
                    const chartBgSrc = 'chart_background_analiz_1100.png';
                    let chartImageUrl = '';

                    function isDarkTheme() {
                        return document.documentElement.classList.contains('asr-dark') && !<?php echo $is_shared_view ? 'true' : 'false'; ?>;
                    }

                    function paintChartBackground(targetCtx, bg) {
                        targetCtx.save();
                        if (isDarkTheme()) {
                            targetCtx.fillStyle = '#14181E';
                            targetCtx.fillRect(0, 0, 1100, 596);
                            if (bg) {
                                targetCtx.globalAlpha = 0.64;
                                targetCtx.filter = 'invert(1) hue-rotate(180deg) brightness(0.78) contrast(0.92)';
                                targetCtx.drawImage(bg, 0, 0, 1100, 596);
                                targetCtx.filter = 'none';
                                targetCtx.globalAlpha = 1;
                            }
                        } else if (bg) {
                            targetCtx.drawImage(bg, 0, 0, 1100, 596);
                        }
                        targetCtx.restore();
                    }
                    
                    function drawCloud(targetCtx, x, y, color) {
                        targetCtx.save();
                        targetCtx.translate(x, y);
                        targetCtx.beginPath();
                        targetCtx.moveTo(-6, 5);
                        targetCtx.bezierCurveTo(-14, 6, -16, -2, -10, -5);
                        targetCtx.bezierCurveTo(-10, -12, -2, -14, 0, -8);
                        targetCtx.bezierCurveTo(2, -14, 10, -12, 10, -5);
                        targetCtx.bezierCurveTo(16, -2, 14, 6, 6, 5);
                        targetCtx.bezierCurveTo(2, 9, -2, 9, -6, 5);
                        targetCtx.closePath();
                        targetCtx.fillStyle = '#ffffff';
                        targetCtx.fill();
                        targetCtx.lineWidth = 3;
                        targetCtx.strokeStyle = color;
                        targetCtx.stroke();
                        targetCtx.restore();
                    }

                    function drawLines(targetCtx) {
                        datasets.forEach(ds => {
                            if (!ds.scores || ds.scores.length === 0) return;
                            targetCtx.beginPath();
                            targetCtx.lineWidth = 4;
                            targetCtx.lineJoin = 'round';
                            targetCtx.lineCap = 'round';
                            targetCtx.strokeStyle = ds.color;
                            ds.scores.forEach((v, i) => {
                                i === 0 ? targetCtx.moveTo(xPositions[i], getY(v)) : targetCtx.lineTo(xPositions[i], getY(v));
                            });
                            targetCtx.stroke();
                            if (ds.manicB && ds.scores[1] !== undefined) drawCloud(targetCtx, xPositions[1], getY(ds.scores[1]), ds.color);
                            if (ds.manicE && ds.scores[4] !== undefined) drawCloud(targetCtx, xPositions[4], getY(ds.scores[4]), ds.color);
                        });
                    }

                    function draw() {
                        ctx.clearRect(0, 0, canvas.width, canvas.height);
                        drawLines(ctx);
                    }

                    function buildMobilePreview() {
                        const previewImg = document.getElementById('chartPreviewImage');
                        const openBtn = document.getElementById('openChartImageBtn');
                        if (!previewImg || !openBtn) return;

                        const exportCanvas = document.createElement('canvas');
                        exportCanvas.width = 1100;
                        exportCanvas.height = 596;
                        const exportCtx = exportCanvas.getContext('2d');
                        const bg = new Image();

                        bg.onload = function() {
                            exportCtx.clearRect(0, 0, exportCanvas.width, exportCanvas.height);
                            paintChartBackground(exportCtx, bg);
                            drawLines(exportCtx);

                            exportCanvas.toBlob(function(blob) {
                                if (!blob) return;
                                if (chartImageUrl) URL.revokeObjectURL(chartImageUrl);
                                chartImageUrl = URL.createObjectURL(blob);
                                previewImg.src = chartImageUrl;
                                openBtn.disabled = false;
                            }, 'image/png');
                        };

                        bg.onerror = function() {
                            paintChartBackground(exportCtx, null);
                            drawLines(exportCtx);
                            exportCanvas.toBlob(function(blob) {
                                if (!blob) return;
                                if (chartImageUrl) URL.revokeObjectURL(chartImageUrl);
                                chartImageUrl = URL.createObjectURL(blob);
                                previewImg.src = chartImageUrl;
                                openBtn.disabled = false;
                            }, 'image/png');
                        };

                        bg.src = chartBgSrc;

                        openBtn.addEventListener('click', function() {
                            if (!chartImageUrl) return;
                            const a = document.createElement('a');
                            a.href = chartImageUrl;
                            a.target = '_blank';
                            a.rel = 'noopener';
                            document.body.appendChild(a);
                            a.click();
                            document.body.removeChild(a);
                        });
                    }

                    draw();
                    buildMobilePreview();
                    window.addEventListener('asr-admin-theme-changed', function() {
                        draw();
                        buildMobilePreview();
                    });

                    const pdfBtn = document.getElementById('downloadPdfBtn');
                    if (pdfBtn) {
                        pdfBtn.onclick = function() {
                            const el = document.getElementById('pdf-content');
                            this.innerText = 'Формируем...';
                            html2pdf().set({
                                margin: [10, 10], filename: 'OCA_Report.pdf', image: { type: 'jpeg', quality: 1 },
                                html2canvas: { scale: 2, useCORS: true, width: 1200 },
                                jsPDF: { unit: 'mm', format: 'a4', orientation: 'landscape' }
                            }).from(el).save().then(() => { this.innerText = 'Скачать PDF'; });
                        };
                    }
                };
            </script>
