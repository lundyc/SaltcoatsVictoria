<?php
declare(strict_types=1);

/** Shared validation for the sponsor's inline agreement and payment forms. */
function sponsorWorkspaceDate(string $value, bool $required = false): string
{
    if ($value === '' && !$required) return '';
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    if (!$date || $date->format('Y-m-d') !== $value) {
        throw new InvalidArgumentException('Enter a valid date.');
    }
    return $value;
}

function sponsorWorkspaceAmount(string $value): string
{
    if (!preg_match('/^\d{1,8}(?:\.\d{1,2})?$/D', $value)) {
        throw new InvalidArgumentException('Enter an amount with no more than two decimal places.');
    }
    return number_format((float)$value, 2, '.', '');
}

function sponsorWorkspaceAgreement(PDO $pdo, int $sponsorId, int $agreementId): array
{
    $agreement = getSponsorshipAgreement($pdo, $agreementId);
    if (!$agreement || (int)$agreement['sponsor_id'] !== $sponsorId) {
        throw new InvalidArgumentException('Agreement not found for this sponsor.');
    }
    return $agreement;
}

/** Table identifiers are fixed here, never supplied by a form. */
function sponsorWorkspacePaymentStore(array $agreement): array
{
    if ((int)($agreement['legacy_id'] ?? 0) > 0) {
        if ($agreement['legacy_source'] === 'player') return ['sponsorship_payments', 'sponsorship_id', (int)$agreement['legacy_id']];
        if ($agreement['legacy_source'] === 'match') return ['match_sponsorship_payments', 'match_sponsorship_id', (int)$agreement['legacy_id']];
    }
    return ['sponsorship_agreement_payments', 'agreement_id', (int)$agreement['id']];
}

function sponsorWorkspacePayment(PDO $pdo, int $sponsorId, array $input): void
{
    $action = (string)($input['workspace_action'] ?? '');
    if (!in_array($action, ['add_payment', 'mark_paid', 'edit_payment', 'delete_payment'], true)) {
        throw new InvalidArgumentException('Unknown payment action.');
    }
    // Initialise the catalogue before opening the payment transaction (schema DDL commits).
    ensureSponsorshipCatalogSchema($pdo);
    ensureMatchSchema($pdo);
    ensureAuditLogSchema($pdo);
    $pdo->beginTransaction();
    try {
        $agreementId = (int)($input['agreement_id'] ?? 0);
        $lock = $pdo->prepare('SELECT id FROM sponsorship_agreements WHERE id=? AND sponsor_id=? FOR UPDATE');
        $lock->execute([$agreementId, $sponsorId]);
        if (!$lock->fetchColumn()) throw new InvalidArgumentException('Agreement not found for this sponsor.');
        $agreement = sponsorWorkspaceAgreement($pdo, $sponsorId, $agreementId);
        [$table, $key, $parentId] = sponsorWorkspacePaymentStore($agreement);
        $paymentId = (int)($input['payment_id'] ?? 0);
        if (in_array($action, ['edit_payment', 'delete_payment'], true)) {
            $check = $pdo->prepare("SELECT id FROM {$table} WHERE id=? AND {$key}=? FOR UPDATE");
            $check->execute([$paymentId, $parentId]);
            if (!$check->fetchColumn()) throw new InvalidArgumentException('Payment not found on this agreement.');
        }
        if ($action === 'delete_payment') {
            $pdo->prepare("DELETE FROM {$table} WHERE id=? AND {$key}=?")->execute([$paymentId, $parentId]);
        } else {
            if (!empty($agreement['is_complimentary'])) throw new InvalidArgumentException('No payment is due on a complimentary agreement.');
            $amount = $action === 'mark_paid'
                ? number_format(max(0, (float)$agreement['agreed_amount'] - (float)$agreement['total_paid']), 2, '.', '')
                : sponsorWorkspaceAmount(trim((string)($input['amount'] ?? '')));
            if ((float)$amount <= 0) throw new InvalidArgumentException($action === 'mark_paid' ? 'This agreement is already paid.' : 'Enter a payment greater than zero.');
            $date = sponsorWorkspaceDate((string)($input['paid_at'] ?? ''), true);
            $method = trim((string)($input['method'] ?? ''));
            if (strlen($method) > 50) throw new InvalidArgumentException('Payment method is too long.');
            $note = trim((string)($input['note'] ?? ''));
            if (mb_strlen($note) > 255) throw new InvalidArgumentException('Keep the payment note to 255 characters or fewer.');
            if ($action === 'edit_payment') {
                $pdo->prepare("UPDATE {$table} SET amount=?,paid_at=?,method=?,note=? WHERE id=? AND {$key}=?")
                    ->execute([$amount, $date, $method ?: null, $note ?: null, $paymentId, $parentId]);
            } else {
                $columns = "{$key},amount,paid_at,method,note";
                $values = [$parentId, $amount, $date, $method ?: null, $note ?: null];
                if ($table !== 'sponsorship_agreement_payments') {
                    $columns .= ',season_id';
                    $values[] = $agreement['season_id'];
                }
                $pdo->prepare("INSERT INTO {$table} ({$columns}) VALUES (" . implode(',', array_fill(0, count($values), '?')) . ')')->execute($values);
            }
        }
        if ($table === 'sponsorship_payments') recomputePaidFlag($pdo, $parentId);
        if ($table === 'match_sponsorship_payments') recomputeMatchPaidFlag($pdo, $parentId);
        auditLog($pdo, 'sponsor_' . $action, "Agreement #{$agreementId}, sponsor #{$sponsorId}" . ($paymentId ? ", payment #{$paymentId}" : ''));
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function sponsorWorkspaceSave(PDO $pdo, int $sponsorId, array $input): int
{
    $agreementId = (int)($input['agreement_id'] ?? 0);
    $existing = $agreementId ? sponsorWorkspaceAgreement($pdo, $sponsorId, $agreementId) : [];
    // Preserve settings not exposed in the simple editor, including bundle membership.
    $data = array_merge(['display_order' => '0', 'logo_variant' => 'package_default', 'is_complimentary' => '0'], $existing);
    foreach (['package_id', 'season_id', 'player_id', 'fixture_id', 'team_id', 'start_date', 'end_date', 'agreed_amount', 'status', 'notes'] as $field) {
        $data[$field] = trim((string)($input[$field] ?? ''));
    }
    $data['sponsor_id'] = (string)$sponsorId;
    $data['is_complimentary'] = !empty($input['is_complimentary']) ? '1' : '0';
    $data['agreed_amount'] = sponsorWorkspaceAmount($data['agreed_amount']);
    sponsorWorkspaceDate($data['start_date']);
    sponsorWorkspaceDate($data['end_date']);
    if ($data['start_date'] && $data['end_date'] && $data['end_date'] < $data['start_date']) throw new InvalidArgumentException('End date must be on or after the start date.');
    if (!in_array($data['status'], ['active', 'scheduled', 'expired', 'cancelled'], true)) throw new InvalidArgumentException('Choose a valid agreement status.');
    $package = getSponsorshipPackage($pdo, (int)$data['package_id']);
    if (!$package) throw new InvalidArgumentException('Choose a package.');
    foreach (['player' => ['player_id', 'players'], 'match' => ['fixture_id', 'match_fixtures'], 'team' => ['team_id', 'teams']] as $scope => [$field, $table]) {
        if ($package['scope'] !== $scope) { $data[$field] = ''; continue; }
        $check = $pdo->prepare("SELECT id FROM {$table} WHERE id=?");
        $check->execute([(int)$data[$field]]);
        if (!$check->fetchColumn()) throw new InvalidArgumentException('Choose a valid ' . ($scope === 'match' ? 'fixture' : $scope) . '.');
    }
    if ($package['scope'] === 'player' && !$data['season_id']) throw new InvalidArgumentException('Choose a season for the player sponsorship.');
    if ($data['season_id']) {
        $check = $pdo->prepare('SELECT id FROM seasons WHERE id=?');
        $check->execute([(int)$data['season_id']]);
        if (!$check->fetchColumn()) throw new InvalidArgumentException('Choose a valid season.');
    }
    if ($package['scope'] === 'match') {
        $check = $pdo->prepare('SELECT season_id FROM match_fixtures WHERE id=?');
        $check->execute([(int)$data['fixture_id']]);
        $data['season_id'] = (string)$check->fetchColumn();
    }
    $result = saveSponsorshipAgreement($pdo, $agreementId, $data);
    if ($result['errors']) throw new InvalidArgumentException(implode(' ', $result['errors']));
    auditLog($pdo, $agreementId ? 'sponsorship_agreement_updated' : 'sponsorship_agreement_created', "Agreement #{$result['id']} for sponsor #{$sponsorId}");
    return (int)$result['id'];
}
