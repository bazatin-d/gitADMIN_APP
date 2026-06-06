<?php
defined('ASR_ADMIN') || exit;
require_once __DIR__ . '/service.php';

// UTM-конструктор: добавление, изменение и удаление значений справочника.
if (!$is_shared_view && isset($_SESSION['logged_in']) && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && in_array($_POST['action'], ['create_utm_preset','update_utm_preset','delete_utm_preset','deactivate_utm_preset'], true)) {
    if (!isAdmin()) {
        asr_utm_redirect('', 'У вас нет прав на изменение UTM-справочника.');
    }
    if (!asr_utm_presets_table_exists($pdo)) {
        asr_utm_redirect('', 'Таблица UTM-справочника не создана. Проверьте SQL для oca_utm_presets.');
    }
    asr_utm_ensure_presets_schema($pdo);

    if ($_POST['action'] === 'create_utm_preset') {
        try {
            $type = asr_validate_utm_type((string)($_POST['utm_type'] ?? ''));
            $description = trim((string)($_POST['utm_description'] ?? ''));
            if (mb_strlen($description, 'UTF-8') > 255) {
                $description = mb_substr($description, 0, 255, 'UTF-8');
            }
            $value = asr_validate_utm_value((string)($_POST['utm_value'] ?? ''), $type, $description);

            $existingStmt = $pdo->prepare("SELECT id FROM oca_utm_presets WHERE type = ? AND value = ? LIMIT 1");
            $existingStmt->execute([$type, $value]);
            $existingId = (int)$existingStmt->fetchColumn();

            if ($existingId > 0) {
                $stmt = $pdo->prepare("UPDATE oca_utm_presets SET description = ?, is_active = 1, updated_at = NOW() WHERE id = ?");
                $stmt->execute([$description, $existingId]);
                asr_utm_redirect('UTM-значение уже было в справочнике — обновил описание и включил его');
            }

            $sortStmt = $pdo->prepare("SELECT COALESCE(MAX(sort_order), 0) + 10 FROM oca_utm_presets WHERE type = ?");
            $sortStmt->execute([$type]);
            $sortOrder = (int)$sortStmt->fetchColumn();

            $stmt = $pdo->prepare("INSERT INTO oca_utm_presets (type, value, description, sort_order, is_active, created_at, updated_at) VALUES (?, ?, ?, ?, 1, NOW(), NOW())");
            $stmt->execute([$type, $value, $description, $sortOrder]);
            asr_utm_redirect('UTM-значение добавлено');
        } catch (PDOException $e) {
            if ((string)$e->getCode() === '23000') {
                asr_utm_redirect('', 'Такое UTM-значение уже есть в этом разделе. Измените существующее значение или выберите другое название.');
            }
            asr_utm_redirect('', 'Не удалось сохранить UTM-значение.');
        } catch (Throwable $e) {
            asr_utm_redirect('', $e->getMessage());
        }
    }

    if ($_POST['action'] === 'update_utm_preset') {
        try {
            $id = (int)($_POST['utm_preset_id'] ?? 0);
            if ($id <= 0) {
                throw new InvalidArgumentException('UTM-значение не найдено.');
            }
            $type = asr_validate_utm_type((string)($_POST['utm_type'] ?? ''));
            $description = trim((string)($_POST['utm_description'] ?? ''));
            if (mb_strlen($description, 'UTF-8') > 255) {
                $description = mb_substr($description, 0, 255, 'UTF-8');
            }
            $value = asr_validate_utm_value((string)($_POST['utm_value'] ?? ''), $type, $description);

            $dupStmt = $pdo->prepare("SELECT id FROM oca_utm_presets WHERE type = ? AND value = ? AND id <> ? LIMIT 1");
            $dupStmt->execute([$type, $value, $id]);
            $duplicateId = (int)$dupStmt->fetchColumn();

            if ($duplicateId > 0) {
                $pdo->beginTransaction();
                $stmt = $pdo->prepare("UPDATE oca_utm_presets SET description = ?, is_active = 1, updated_at = NOW() WHERE id = ?");
                $stmt->execute([$description, $duplicateId]);
                $stmt = $pdo->prepare("DELETE FROM oca_utm_presets WHERE id = ?");
                $stmt->execute([$id]);
                $pdo->commit();
                asr_utm_redirect('Такое UTM-значение уже было — объединил записи');
            }

            $stmt = $pdo->prepare("UPDATE oca_utm_presets SET type = ?, value = ?, description = ?, is_active = 1, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$type, $value, $description, $id]);
            asr_utm_redirect('UTM-значение изменено');
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ((string)$e->getCode() === '23000') {
                asr_utm_redirect('', 'Такое UTM-значение уже есть в этом разделе. Дубли нельзя сохранить, а то метки начнут плодиться как вкладки в браузере.');
            }
            asr_utm_redirect('', 'Не удалось изменить UTM-значение.');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            asr_utm_redirect('', $e->getMessage());
        }
    }

    if ($_POST['action'] === 'delete_utm_preset') {
        try {
            $id = (int)($_POST['utm_preset_id'] ?? 0);
            if ($id <= 0) {
                throw new InvalidArgumentException('UTM-значение не найдено.');
            }
            $stmt = $pdo->prepare("UPDATE oca_utm_presets SET is_active = 0, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$id]);
            asr_utm_redirect('UTM-значение удалено из справочника');
        } catch (Throwable $e) {
            asr_utm_redirect('', 'Не удалось удалить UTM-значение.');
        }
    }

    if ($_POST['action'] === 'deactivate_utm_preset') {
        try {
            $id = (int)($_POST['utm_preset_id'] ?? 0);
            if ($id <= 0) {
                throw new InvalidArgumentException('UTM-значение не найдено.');
            }
            $stmt = $pdo->prepare("UPDATE oca_utm_presets SET is_active = 0, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$id]);
            asr_utm_redirect('UTM-значение отключено');
        } catch (Throwable $e) {
            asr_utm_redirect('', 'Не удалось отключить UTM-значение.');
        }
    }
}

// URL Shortener: создание, редактирование и удаление коротких ссылок.
if (!$is_shared_view && isset($_SESSION['logged_in']) && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && in_array($_POST['action'], ['create_short_url','update_short_url','delete_short_url'], true)) {
    if (!asr_shortener_table_exists($pdo)) {
        asr_shortener_error_redirect('Таблица коротких ссылок не создана. Выполните migration_url_shortener.sql');
    }
    asr_shortener_ensure_schema($pdo);

    if ($_POST['action'] === 'create_short_url') {
        try {
            $domain = trim((string)($_POST['short_domain'] ?? asr_shortener_default_domain()));
            if (!in_array($domain, asr_shortener_allowed_domains(), true)) {
                throw new InvalidArgumentException('Выберите корректный домен для короткой ссылки.');
            }
            $sourceUrl = trim((string)($_POST['source_url'] ?? ''));
            $normalizedUrl = asr_normalize_shortener_target($sourceUrl);
            $slug = asr_generate_short_slug($pdo, $domain);
            $isPermanent = !empty($_POST['is_permanent']) ? 1 : 0;
            $stmt = $pdo->prepare("INSERT INTO oca_short_urls (source_url, normalized_url, domain, slug, is_permanent, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())");
            $stmt->execute([$sourceUrl, $normalizedUrl, $domain, $slug, $isPermanent, (int)($_SESSION['user_id'] ?? 0) ?: null]);
            asr_shortener_success_redirect('Короткая ссылка создана');
        } catch (Throwable $e) {
            asr_shortener_error_redirect($e->getMessage());
        }
    }

    if ($_POST['action'] === 'update_short_url') {
        try {
            $id = (int)($_POST['short_id'] ?? 0);
            $slug = asr_validate_short_slug((string)($_POST['new_slug'] ?? ''));
            $stmt = $pdo->prepare("SELECT id, domain, created_by FROM oca_short_urls WHERE id = ? LIMIT 1");
            $stmt->execute([$id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                throw new RuntimeException('Короткая ссылка не найдена.');
            }
            $currentUserId = (int)($_SESSION['user_id'] ?? 0);
            if (!isAdmin() && (int)($row['created_by'] ?? 0) !== $currentUserId) {
                throw new RuntimeException('У вас нет прав на редактирование этой короткой ссылки.');
            }
            $dup = $pdo->prepare("SELECT id FROM oca_short_urls WHERE domain = ? AND slug = ? AND id <> ? LIMIT 1");
            $dup->execute([$row['domain'], $slug, $id]);
            if ($dup->fetchColumn()) {
                throw new RuntimeException('Такой хвостик уже используется для выбранного домена. Укажите другой.');
            }
            $isPermanent = !empty($_POST['is_permanent']) ? 1 : 0;
            $upd = $pdo->prepare("UPDATE oca_short_urls SET slug = ?, is_permanent = ?, updated_at = NOW() WHERE id = ?");
            $upd->execute([$slug, $isPermanent, $id]);
            asr_shortener_success_redirect('Хвостик ссылки обновлён');
        } catch (Throwable $e) {
            asr_shortener_error_redirect($e->getMessage());
        }
    }

    if ($_POST['action'] === 'delete_short_url') {
        $id = (int)($_POST['short_id'] ?? 0);
        $stmt = $pdo->prepare("SELECT id, created_by FROM oca_short_urls WHERE id = ? LIMIT 1");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            asr_shortener_error_redirect('Короткая ссылка не найдена.');
        }
        $currentUserId = (int)($_SESSION['user_id'] ?? 0);
        if (!isAdmin() && (int)($row['created_by'] ?? 0) !== $currentUserId) {
            asr_shortener_error_redirect('У вас нет прав на удаление этой короткой ссылки.');
        }
        $stmt = $pdo->prepare("DELETE FROM oca_short_urls WHERE id = ?");
        $stmt->execute([$id]);
        asr_shortener_success_redirect('Короткая ссылка удалена');
    }
}
