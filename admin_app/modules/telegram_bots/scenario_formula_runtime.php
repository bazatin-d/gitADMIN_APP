<?php
defined('ASR_ADMIN') || exit;

function asr_tg_runtime_formula_settings(array $block): array {
    $settings = json_decode((string)($block['settings_json'] ?? ''), true);
    if (!is_array($settings)) $settings = [];
    $code = (string)($settings['formula_code'] ?? $settings['code'] ?? '');
    $lines = preg_split('/\R/u', $code) ?: [];
    return [
        'code' => $code,
        'lines' => $lines,
        'line_count' => count($lines),
        'char_count' => mb_strlen($code, 'UTF-8'),
        'valid' => !empty($settings['formula_valid']) && trim($code) !== '',
    ];
}

function asr_tg_runtime_formula_strip_comment(string $line): string {
    $out = '';
    $len = strlen($line);
    $quote = '';
    $escape = false;
    for ($i = 0; $i < $len; $i++) {
        $ch = $line[$i];
        if ($quote !== '') {
            $out .= $ch;
            if ($escape) { $escape = false; continue; }
            if ($ch === '\\') { $escape = true; continue; }
            if ($ch === $quote) $quote = '';
            continue;
        }
        if ($ch === '"' || $ch === "'") { $quote = $ch; $out .= $ch; continue; }
        if ($ch === '#') break;
        $out .= $ch;
    }
    return trim($out);
}

function asr_tg_runtime_formula_target_parse(string $left): array {
    $left = trim($left);
    if ($left === '') return ['ok' => false, 'error' => 'Не указано поле слева от знака =.'];
    if (preg_match('/^client\.([A-Za-zА-Яа-яЁё_][A-Za-zА-Яа-яЁё0-9_]*)$/u', $left, $m)) {
        return ['ok' => true, 'scope' => 'client', 'name' => (string)$m[1]];
    }
    if (preg_match('/^client\[[\"\'](.+?)[\"\']\]$/u', $left, $m)) {
        return ['ok' => true, 'scope' => 'client', 'name' => trim((string)$m[1])];
    }
    if (preg_match('/^([A-Za-zА-Яа-яЁё_][A-Za-zА-Яа-яЁё0-9_]*)$/u', $left, $m)) {
        return ['ok' => true, 'scope' => 'local', 'name' => (string)$m[1]];
    }
    return ['ok' => false, 'error' => 'Некорректное имя переменной: ' . $left];
}

function asr_tg_runtime_formula_field_maps(PDO $pdo): array {
    $system = [
        'first_name' => ['code' => 'first_name', 'type' => 'text', 'title' => 'Имя', 'writable' => true],
        'last_name' => ['code' => 'last_name', 'type' => 'text', 'title' => 'Фамилия', 'writable' => true],
        'phone' => ['code' => 'phone', 'type' => 'text', 'title' => 'Телефон', 'writable' => true],
        'email' => ['code' => 'email', 'type' => 'text', 'title' => 'Email', 'writable' => true],
        'username' => ['code' => 'username', 'type' => 'text', 'title' => 'Username', 'writable' => false],
        'telegram_user_id' => ['code' => 'telegram_user_id', 'type' => 'number', 'title' => 'Telegram ID', 'writable' => false],
        'chat_id' => ['code' => 'chat_id', 'type' => 'number', 'title' => 'Chat ID', 'writable' => false],
        'subscriber_id' => ['code' => 'id', 'type' => 'number', 'title' => 'ID подписчика', 'writable' => false],
        'bot_id' => ['code' => 'bot_id', 'type' => 'number', 'title' => 'ID бота', 'writable' => false],
    ];
    $custom = [];
    try {
        if (function_exists('asr_tg_custom_fields_all')) {
            foreach (asr_tg_custom_fields_all($pdo, 0, true) as $field) {
                $code = trim((string)($field['code'] ?? ''));
                if ($code === '') continue;
                $custom[$code] = [
                    'id' => (int)($field['id'] ?? 0),
                    'code' => $code,
                    'type' => (string)($field['field_type'] ?? 'text'),
                    'title' => trim((string)($field['title'] ?? $code)),
                    'writable' => true,
                ];
                $title = trim((string)($field['title'] ?? ''));
                if ($title !== '') $custom[$title] = $custom[$code];
            }
        }
    } catch (Throwable $e) {}
    return ['system' => $system, 'custom' => $custom];
}

function asr_tg_runtime_formula_client_values(PDO $pdo, int $botId, int $subscriberId, array $subscriber, array $maps): array {
    $client = [];
    foreach ($maps['system'] as $name => $meta) {
        $code = (string)($meta['code'] ?? $name);
        $client[$name] = $subscriber[$code] ?? '';
    }
    try {
        if (function_exists('asr_tg_subscriber_custom_values_get')) {
            $values = asr_tg_subscriber_custom_values_get($pdo, $subscriberId);
            foreach ($maps['custom'] as $name => $meta) {
                $fieldId = (int)($meta['id'] ?? 0);
                $row = $fieldId > 0 ? ($values[$fieldId] ?? null) : null;
                if (!is_array($row)) { $client[$name] = ''; continue; }
                $type = (string)($meta['type'] ?? 'text');
                if ($type === 'number') $client[$name] = ($row['value_number'] ?? null) === null ? '' : (string)$row['value_number'];
                elseif ($type === 'date') $client[$name] = (string)($row['value_date'] ?? '');
                elseif ($type === 'datetime') $client[$name] = (string)($row['value_datetime'] ?? '');
                else $client[$name] = (string)($row['value_text'] ?? '');
            }
        }
    } catch (Throwable $e) {}
    return $client;
}

function asr_tg_runtime_formula_value_to_string($value): string {
    if ($value === null) return '';
    if (is_bool($value)) return $value ? 'true' : 'false';
    if (is_float($value)) return rtrim(rtrim(sprintf('%.10F', $value), '0'), '.');
    if (is_int($value)) return (string)$value;
    return (string)$value;
}

function asr_tg_runtime_formula_apply_client(PDO $pdo, int $botId, int $subscriberId, string $name, $value, array $maps): array {
    $name = trim($name);
    $valueString = asr_tg_runtime_formula_value_to_string($value);
    if (isset($maps['system'][$name])) {
        $meta = $maps['system'][$name];
        if (empty($meta['writable'])) return ['ok' => false, 'error' => 'Системное поле «' . $name . '» доступно только для чтения.'];
        if (!function_exists('asr_tg_runtime_actions_apply_system_field')) return ['ok' => false, 'error' => 'Слой записи системных полей недоступен.'];
        $result = asr_tg_runtime_actions_apply_system_field($pdo, $subscriberId, (string)$meta['code'], $valueString === '' ? 'clear' : 'set', $valueString);
        return ['ok' => !empty($result['ok']), 'error' => (string)($result['error'] ?? ''), 'value' => $valueString, 'target' => 'client.' . $name];
    }
    if (isset($maps['custom'][$name])) {
        $meta = $maps['custom'][$name];
        $code = (string)($meta['code'] ?? '');
        if ($code === '' || !function_exists('asr_tg_runtime_actions_apply_custom_field')) return ['ok' => false, 'error' => 'Пользовательское поле «' . $name . '» не найдено.'];
        $result = asr_tg_runtime_actions_apply_custom_field($pdo, $botId, $subscriberId, $code, $valueString === '' ? 'clear' : 'set', $valueString);
        return ['ok' => !empty($result['ok']), 'error' => (string)($result['error'] ?? ''), 'value' => $valueString, 'target' => 'client.' . $code];
    }
    return ['ok' => false, 'error' => 'Поле «' . $name . '» не найдено. Создайте его в настраиваемых полях.'];
}

class AsrTgFormulaParser {
    private array $tokens = [];
    private int $pos = 0;
    private array $locals;
    private array $client;
    public function __construct(string $expr, array $locals, array $client) {
        $this->tokens = $this->tokenize($expr);
        $this->locals = $locals;
        $this->client = $client;
    }
    public function parse() {
        $value = $this->parseComparison();
        if (!$this->peek('eof')) throw new RuntimeException('Лишний текст в выражении.');
        return $value;
    }
    private function tokenize(string $s): array {
        $tokens = [];
        $i = 0; $len = strlen($s);
        while ($i < $len) {
            $ch = $s[$i];
            if (ctype_space($ch)) { $i++; continue; }
            $two = $i + 1 < $len ? substr($s, $i, 2) : '';
            if (in_array($two, ['>=','<=','==','!='], true)) { $tokens[] = ['op', $two]; $i += 2; continue; }
            if (strpos('+-*/%()[],.<>', $ch) !== false) { $tokens[] = ['op', $ch]; $i++; continue; }
            if ($ch === '"' || $ch === "'") {
                $quote = $ch; $i++; $out = '';
                while ($i < $len) {
                    $c = $s[$i++];
                    if ($c === '\\' && $i < $len) {
                        $n = $s[$i++];
                        $out .= $n === 'n' ? "\n" : ($n === 't' ? "\t" : $n);
                        continue;
                    }
                    if ($c === $quote) break;
                    $out .= $c;
                }
                $tokens[] = ['str', $out]; continue;
            }
            if (preg_match('/\G\d+(?:\.\d+)?/A', $s, $m, 0, $i)) { $tokens[] = ['num', $m[0]]; $i += strlen($m[0]); continue; }
            if (preg_match('/\G[A-Za-zА-Яа-яЁё_][A-Za-zА-Яа-яЁё0-9_]*/Au', $s, $m, 0, $i)) { $tokens[] = ['id', $m[0]]; $i += strlen($m[0]); continue; }
            throw new RuntimeException('Неизвестный символ: ' . $ch);
        }
        $tokens[] = ['eof', ''];
        return $tokens;
    }
    private function peek(string $type, ?string $value = null): bool { $t = $this->tokens[$this->pos] ?? ['eof','']; return $t[0] === $type && ($value === null || $t[1] === $value); }
    private function take(string $type, ?string $value = null): ?array { if ($this->peek($type, $value)) return $this->tokens[$this->pos++]; return null; }
    private function expectOp(string $op): void { if (!$this->take('op', $op)) throw new RuntimeException('Ожидался символ «' . $op . '».'); }
    private function parseComparison() {
        $left = $this->parseAddSub();
        while ($this->peek('op') && in_array(($this->tokens[$this->pos][1] ?? ''), ['>','<','>=','<=','==','!='], true)) {
            $op = $this->tokens[$this->pos++][1];
            $right = $this->parseAddSub();
            if (is_numeric($left) && is_numeric($right)) { $a = (float)$left; $b = (float)$right; }
            else { $a = (string)$left; $b = (string)$right; }
            $left = match ($op) { '>' => $a > $b, '<' => $a < $b, '>=' => $a >= $b, '<=' => $a <= $b, '==' => $a == $b, '!=' => $a != $b, default => false };
        }
        return $left;
    }
    private function parseAddSub() {
        $v = $this->parseMulDiv();
        while ($this->peek('op', '+') || $this->peek('op', '-')) {
            $op = $this->tokens[$this->pos++][1];
            $r = $this->parseMulDiv();
            if ($op === '+' && (!is_numeric($v) || !is_numeric($r))) $v = (string)$v . (string)$r;
            else $v = $op === '+' ? ((float)$v + (float)$r) : ((float)$v - (float)$r);
        }
        return $v;
    }
    private function parseMulDiv() {
        $v = $this->parseUnary();
        while ($this->peek('op', '*') || $this->peek('op', '/') || $this->peek('op', '%')) {
            $op = $this->tokens[$this->pos++][1];
            $r = $this->parseUnary();
            if (($op === '/' || $op === '%') && (float)$r == 0.0) throw new RuntimeException('Деление на ноль.');
            $v = $op === '*' ? ((float)$v * (float)$r) : ($op === '/' ? ((float)$v / (float)$r) : ((int)$v % (int)$r));
        }
        return $v;
    }
    private function parseUnary() {
        if ($this->take('op', '-')) return -1 * (float)$this->parseUnary();
        if ($this->take('op', '+')) return +1 * (float)$this->parseUnary();
        return $this->parsePrimary();
    }
    private function parsePrimary() {
        if ($t = $this->take('num')) return strpos($t[1], '.') !== false ? (float)$t[1] : (int)$t[1];
        if ($t = $this->take('str')) return $t[1];
        if ($this->take('op', '(')) { $v = $this->parseComparison(); $this->expectOp(')'); return $v; }
        if ($t = $this->take('id')) {
            $name = (string)$t[1];
            if ($this->take('op', '(')) {
                $args = [];
                if (!$this->peek('op', ')')) {
                    do { $args[] = $this->parseComparison(); } while ($this->take('op', ','));
                }
                $this->expectOp(')');
                return $this->call($name, $args);
            }
            if (strcasecmp($name, 'true') === 0) return true;
            if (strcasecmp($name, 'false') === 0) return false;
            if (strcasecmp($name, 'null') === 0) return null;
            if ($name === 'client') return $this->clientAccess();
            if (array_key_exists($name, $this->locals)) return $this->locals[$name];
            return '';
        }
        throw new RuntimeException('Не удалось прочитать выражение.');
    }
    private function clientAccess() {
        $key = '';
        if ($this->take('op', '.')) {
            $t = $this->take('id'); if (!$t) throw new RuntimeException('После client. нужно имя поля.');
            $key = (string)$t[1];
        } elseif ($this->take('op', '[')) {
            $t = $this->take('str'); if (!$t) throw new RuntimeException('В client[...] нужно указать название поля в кавычках.');
            $key = (string)$t[1]; $this->expectOp(']');
        } else { throw new RuntimeException('Используйте client.field или client["field"].'); }
        return $this->client[$key] ?? '';
    }
    private function call(string $name, array $args) {
        $n = strtolower($name);
        return match ($n) {
            'str' => (string)($args[0] ?? ''),
            'int' => (int)($args[0] ?? 0),
            'float' => (float)($args[0] ?? 0),
            'round' => round((float)($args[0] ?? 0), isset($args[1]) ? (int)$args[1] : 0),
            'abs' => abs((float)($args[0] ?? 0)),
            'min' => min($args ?: [0]),
            'max' => max($args ?: [0]),
            'trim' => trim((string)($args[0] ?? '')),
            'lower' => mb_strtolower((string)($args[0] ?? ''), 'UTF-8'),
            'upper' => mb_strtoupper((string)($args[0] ?? ''), 'UTF-8'),
            'concat' => implode('', array_map('strval', $args)),
            default => throw new RuntimeException('Функция «' . $name . '» не поддерживается.'),
        };
    }
}

function asr_tg_runtime_formula_eval_expr(string $expr, array $locals, array $client) {
    $parser = new AsrTgFormulaParser($expr, $locals, $client);
    return $parser->parse();
}

function asr_tg_runtime_execute_formula_block(PDO $pdo, array $bot, int $botId, int|string $chatId, int $subscriberId, int $scenarioId, array $block, string $source = 'manual', array $sourcePayload = []): bool {
    $blockId = (int)($block['id'] ?? 0);
    $settings = asr_tg_runtime_formula_settings($block);
    if (empty($settings['valid'])) {
        $error = 'Блок «Формула» не настроен.';
        asr_tg_runtime_remember_position($pdo, $botId, $subscriberId, $scenarioId, $blockId, 'error', null, $error);
        asr_tg_runtime_log_event($pdo, $botId, $subscriberId, $scenarioId, $blockId, 'runtime_formula_invalid', $error, ['source' => $source] + $sourcePayload);
        return true;
    }
    $subscriber = function_exists('asr_tg_subscriber_find') ? asr_tg_subscriber_find($pdo, $subscriberId, $botId) : null;
    if (!$subscriber) $subscriber = function_exists('asr_tg_subscriber_find') ? asr_tg_subscriber_find($pdo, $subscriberId, 0) : null;
    if (!is_array($subscriber)) {
        asr_tg_runtime_log_event($pdo, $botId, $subscriberId, $scenarioId, $blockId, 'runtime_formula_subscriber_missing', 'Подписчик для формулы не найден.', ['source' => $source] + $sourcePayload);
        return true;
    }
    $maps = asr_tg_runtime_formula_field_maps($pdo);
    $client = asr_tg_runtime_formula_client_values($pdo, $botId, $subscriberId, $subscriber, $maps);
    $locals = [];
    $results = [];
    $executed = 0;
    foreach ($settings['lines'] as $lineNo => $line) {
        $lineText = asr_tg_runtime_formula_strip_comment((string)$line);
        if ($lineText === '') continue;
        if (!str_contains($lineText, '=')) {
            $error = 'В строке ' . ((int)$lineNo + 1) . ' нет присваивания через =.';
            asr_tg_runtime_log_event($pdo, $botId, $subscriberId, $scenarioId, $blockId, 'runtime_formula_line_failed', $error, ['line' => (int)$lineNo + 1, 'source' => $source] + $sourcePayload);
            asr_tg_runtime_remember_position($pdo, $botId, $subscriberId, $scenarioId, $blockId, 'error', null, $error);
            return true;
        }
        [$left, $expr] = array_map('trim', explode('=', $lineText, 2));
        $target = asr_tg_runtime_formula_target_parse($left);
        if (empty($target['ok'])) {
            $error = 'Строка ' . ((int)$lineNo + 1) . ': ' . (string)($target['error'] ?? 'ошибка цели.');
            asr_tg_runtime_log_event($pdo, $botId, $subscriberId, $scenarioId, $blockId, 'runtime_formula_line_failed', $error, ['line' => (int)$lineNo + 1, 'source' => $source] + $sourcePayload);
            asr_tg_runtime_remember_position($pdo, $botId, $subscriberId, $scenarioId, $blockId, 'error', null, $error);
            return true;
        }
        try { $value = asr_tg_runtime_formula_eval_expr($expr, $locals, $client); }
        catch (Throwable $e) {
            $error = 'Строка ' . ((int)$lineNo + 1) . ': ' . $e->getMessage();
            asr_tg_runtime_log_event($pdo, $botId, $subscriberId, $scenarioId, $blockId, 'runtime_formula_line_failed', $error, ['line' => (int)$lineNo + 1, 'expression' => $expr, 'source' => $source] + $sourcePayload);
            asr_tg_runtime_remember_position($pdo, $botId, $subscriberId, $scenarioId, $blockId, 'error', null, $error);
            return true;
        }
        if (($target['scope'] ?? '') === 'local') {
            $locals[(string)$target['name']] = $value;
            $results[] = ['line' => (int)$lineNo + 1, 'target' => (string)$target['name'], 'value' => asr_tg_runtime_formula_value_to_string($value), 'scope' => 'local'];
        } else {
            $applied = asr_tg_runtime_formula_apply_client($pdo, $botId, $subscriberId, (string)$target['name'], $value, $maps);
            if (empty($applied['ok'])) {
                $error = 'Строка ' . ((int)$lineNo + 1) . ': ' . (string)($applied['error'] ?? 'не удалось записать поле.');
                asr_tg_runtime_log_event($pdo, $botId, $subscriberId, $scenarioId, $blockId, 'runtime_formula_line_failed', $error, ['line' => (int)$lineNo + 1, 'target' => (string)$target['name'], 'source' => $source] + $sourcePayload);
                asr_tg_runtime_remember_position($pdo, $botId, $subscriberId, $scenarioId, $blockId, 'error', null, $error);
                return true;
            }
            $client[(string)$target['name']] = $value;
            $results[] = ['line' => (int)$lineNo + 1, 'target' => (string)($applied['target'] ?? $target['name']), 'value' => (string)($applied['value'] ?? ''), 'scope' => 'client'];
        }
        $executed++;
    }
    asr_tg_runtime_log_event($pdo, $botId, $subscriberId, $scenarioId, $blockId, 'runtime_formula_executed', 'Блок «Формула» выполнен.', ['executed_lines' => $executed, 'results' => $results, 'source' => $source] + $sourcePayload);
    asr_tg_runtime_remember_position($pdo, $botId, $subscriberId, $scenarioId, $blockId, 'active');
    $nextBlockId = function_exists('asr_tg_runtime_first_block_after') ? asr_tg_runtime_first_block_after($pdo, $scenarioId, $blockId) : 0;
    if ($nextBlockId > 0 && $nextBlockId !== $blockId) {
        return asr_tg_runtime_start_scenario($pdo, $bot, $botId, $chatId, $subscriberId, $scenarioId, $nextBlockId, 'formula_next', ['from_block_id' => $blockId, 'source' => $source]);
    }
    return true;
}
