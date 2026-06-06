<?php
defined('ASR_ADMIN') || exit;

function asr_manual_input_insert_result(PDO $pdo, array $data): int
{
    $stmt = $pdo->prepare("INSERT INTO oca_results
        (name, email, phone, city, gender, age, role, utm, graph_scores, answers, false_point_b, false_point_e, manual_input_mode, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

    $stmt->execute([
        $data['name'],
        $data['email'],
        $data['phone'],
        $data['city'],
        $data['gender'],
        $data['age'],
        $data['role'],
        $data['utm'],
        $data['graph_scores'],
        json_encode($data['answers'], JSON_UNESCAPED_UNICODE),
        (int)$data['false_point_b'],
        (int)$data['false_point_e'],
        $data['manual_input_mode'],
        $data['created_at'],
    ]);

    return (int)$pdo->lastInsertId();
}

function asr_manual_input_update_crm_links(PDO $pdo, int $resultId, string $dealUrl, string $contactUrl = ''): void
{
    $stmt = $pdo->prepare('UPDATE oca_results SET crm_deal = ?, crm_contact = ? WHERE id = ?');
    $stmt->execute([$dealUrl, $contactUrl, $resultId]);
}
