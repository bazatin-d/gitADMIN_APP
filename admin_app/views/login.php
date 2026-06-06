<?php
defined('ASR_ADMIN') || exit;
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Вход в систему OCA</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Montserrat', sans-serif; background-image: url('<?php echo htmlspecialchars(function_exists('asr_config_grid_background_url') ? asr_config_grid_background_url() : '/img4bitrix/grid_amo.png', ENT_QUOTES, 'UTF-8'); ?>'); background-repeat: repeat; }
        * { box-sizing: border-box; }
        @media (max-width: 480px) {
            .login-card { padding: 24px !important; border-radius: 24px !important; }
            .login-logo { width: 120px !important; height: auto !important; }
            input, button { font-size: 16px; }
        }
    </style>
</head>
<body class="min-h-screen flex items-center justify-center p-4 text-left">
    <div class="login-card w-full max-w-sm bg-white rounded-3xl shadow-2xl p-8 border border-gray-100 font-semibold text-left relative overflow-hidden">
        <div class="text-center mb-8">
            <img src="/logo.png" alt="АВМ" style="width: 150px; height: 51px; object-fit: contain;" class="login-logo mx-auto mb-6 block">
            <h1 style="color: #FFA048;" class="text-2xl font-black uppercase tracking-tight">Панель доступа</h1>
            <p class="text-[10px] text-gray-400 font-bold uppercase tracking-widest mt-1">Академия Вадима Мальчикова</p>
        </div>
        
        <?php if($msg_success): ?><div class="text-green-600 text-[10px] font-bold text-center bg-green-50 py-3 rounded-xl border border-green-200 uppercase mb-4"><?php echo $msg_success; ?></div><?php endif; ?>
        <?php if($error): ?><div class="text-red-500 text-[10px] font-bold text-center bg-red-50 py-3 rounded-xl border border-red-100 uppercase mb-4"><?php echo $error; ?></div><?php endif; ?>

        <!-- Форма входа -->
        <form method="POST" class="space-y-4 <?php echo (isset($_POST['action']) && $_POST['action'] === 'forgot_password' && !$msg_success) ? 'hidden' : ''; ?>" id="form-login">
            <input type="hidden" name="login_action" value="1">
            <div>
                <label class="text-[10px] font-bold text-gray-400 uppercase ml-2 mb-1 block">Логин</label>
                <input type="text" name="username" required class="w-full px-5 py-3 rounded-xl border-2 border-gray-100 focus:border-[#ffa048] outline-none transition-all font-bold text-gray-700">
            </div>
            <div>
                <label class="text-[10px] font-bold text-gray-400 uppercase ml-2 mb-1 block">Пароль</label>
                <input type="password" name="password" required class="w-full px-5 py-3 rounded-xl border-2 border-gray-100 focus:border-[#ffa048] outline-none transition-all font-bold text-gray-700">
            </div>
            <button type="submit" class="w-full bg-[#ffa048] text-white font-bold py-4 rounded-xl shadow-lg uppercase hover:bg-[#ff8f28] transition-all tracking-widest text-xs mt-2">Войти в систему</button>
            <div class="text-center mt-4 pt-2">
                <a href="javascript:void(0)" onclick="document.getElementById('form-login').classList.add('hidden'); document.getElementById('form-forgot').classList.remove('hidden');" class="text-[10px] font-bold text-gray-400 hover:text-[#ffa048] uppercase tracking-widest transition-colors">Забыли пароль?</a>
            </div>
        </form>

        <!-- Форма сброса пароля -->
        <form method="POST" class="space-y-4 <?php echo (isset($_POST['action']) && $_POST['action'] === 'forgot_password' && !$msg_success) ? '' : 'hidden'; ?>" id="form-forgot">
            <input type="hidden" name="action" value="forgot_password">
            <div class="mb-2">
                <p class="text-xs text-gray-500 font-medium text-center leading-relaxed">Введите ваш Email (он же логин). На него будет отправлен новый пароль для входа.</p>
            </div>
            <div>
                <label class="text-[10px] font-bold text-gray-400 uppercase ml-2 mb-1 block">Ваш Email</label>
                <input type="email" name="reset_email" required class="w-full px-5 py-3 rounded-xl border-2 border-gray-100 focus:border-[#FFA048] outline-none transition-all font-bold text-gray-700">
            </div>
            <button type="submit" class="w-full bg-[#FFA048] text-white font-bold py-4 rounded-xl shadow-lg uppercase hover:bg-[#f28d35] transition-all tracking-widest text-xs mt-2">Получить новый пароль</button>
            <div class="text-center mt-4 pt-2">
                <a href="javascript:void(0)" onclick="document.getElementById('form-forgot').classList.add('hidden'); document.getElementById('form-login').classList.remove('hidden');" class="inline-flex items-center justify-center gap-1 text-[10px] font-bold text-gray-400 hover:text-[#FFA048] uppercase tracking-widest transition-colors"><img src="/assets/admin/icons/mo2-back-gray.svg" alt="" aria-hidden="true" style="width:16px;height:16px;display:inline-block;vertical-align:middle;margin-right:6px;object-fit:contain;"> Вернуться ко входу</a>
            </div>
        </form>
    </div>
</body>
</html>
