<?php

declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'lending_domain.php';

function payoff_date_with_clamped_dom(int $year, int $month, int $dom): DateTimeImmutable
{
    $first = new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month));
    $lastDay = (int) $first->modify('last day of this month')->format('d');
    $d = min(max(1, $dom), $lastDay);

    return new DateTimeImmutable(sprintf('%04d-%02d-%02d', $year, $month, $d));
}

/**
 * First day of the loan's billing cycle (origin day-of-month D, clamped) that contains $asOfDateYmd.
 */
function payoff_cycle_start_for_date(DateTimeImmutable $origin, string $asOfDateYmd): DateTimeImmutable
{
    $asOf = new DateTimeImmutable($asOfDateYmd);
    $D = (int) $origin->format('d');
    $ay = (int) $asOf->format('Y');
    $am = (int) $asOf->format('m');
    $ad = (int) $asOf->format('d');
    if ($ad >= $D) {
        return payoff_date_with_clamped_dom($ay, $am, $D);
    }
    $prev = $asOf->modify('first day of this month')->modify('-1 month');

    return payoff_date_with_clamped_dom((int) $prev->format('Y'), (int) $prev->format('m'), $D);
}

/**
 * Contract remaining principal for payoff (no cash-event ledger). Declining-balance loans use
 * paydown count aligned to the calendar month of {@see payoff_cycle_start_for_date()}.
 *
 * @param array<string, mixed> $loan Row must include origin_date and principal_amount; optional interest_calc_method, principal_payment_monthly.
 */
function compute_principal_balance(array $loan, string $asOfDateYmd): string
{
    $principalNorm = checks_normalize_money_2(
        $loan['principal_amount'] !== null && (string) $loan['principal_amount'] !== ''
            ? (string) $loan['principal_amount']
            : '0'
    );

    $icm = strtolower(trim((string) ($loan['interest_calc_method'] ?? 'fixed')));
    $mppRaw = $loan['principal_payment_monthly'] ?? null;
    $mppNorm = $mppRaw !== null && (string) $mppRaw !== ''
        ? checks_normalize_money_2((string) $mppRaw)
        : '0.00';

    $hasMonthlyPaydown = extension_loaded('bcmath')
        ? bccomp($mppNorm, '0', 2) === 1
        : (float) $mppNorm > 0;
    $useDeclining = $icm === 'declining_balance'
        && trim((string) ($loan['principal_amount'] ?? '')) !== ''
        && $hasMonthlyPaydown;

    if (!$useDeclining) {
        return $principalNorm;
    }

    $originRaw = $loan['origin_date'] ?? null;
    if ($originRaw === null || (string) $originRaw === '') {
        return $principalNorm;
    }
    $originYmd = (string) $originRaw;
    $origin = DateTimeImmutable::createFromFormat('Y-m-d', $originYmd);
    if (!$origin instanceof DateTimeImmutable || $origin->format('Y-m-d') !== $originYmd) {
        return $principalNorm;
    }

    $cycleStart = payoff_cycle_start_for_date($origin, $asOfDateYmd);
    $selectedYm = $cycleStart->format('Y-m');
    $cyclesElapsed = loan_months_elapsed_to_calendar_month($originYmd, $selectedYm);

    return loan_remaining_principal_after_paydowns($principalNorm, $mppNorm, $cyclesElapsed);
}
