<?php

declare(strict_types=1);

namespace App\Service\Bank;

final class Pain00100109Exporter implements BankTransferExporterInterface
{
    public function export(array $transfers, array $payerData): string
    {
        if ($transfers === []) {
            throw new \RuntimeException('Brak przelewow do eksportu.');
        }

        $payerName = trim((string) ($payerData['payer_name'] ?? ''));
        $payerIban = $this->normalizeIban((string) ($payerData['payer_iban'] ?? ''));
        $currency = strtoupper((string) ($payerData['default_currency'] ?? 'PLN'));

        if ($payerName === '' || $payerIban === '') {
            throw new \RuntimeException('Brakuje danych platnika do wygenerowania pain.001.');
        }

        $document = new \DOMDocument('1.0', 'UTF-8');
        $document->formatOutput = true;

        $documentNode = $document->createElementNS('urn:iso:std:iso:20022:tech:xsd:pain.001.001.09', 'Document');
        $customerTransferInitiation = $document->createElement('CstmrCdtTrfInitn');
        $documentNode->appendChild($customerTransferInitiation);
        $document->appendChild($documentNode);

        $messageId = 'AKSEF-' . date('YmdHis');
        $controlSum = 0.0;

        $groupHeader = $document->createElement('GrpHdr');
        $groupHeader->appendChild($document->createElement('MsgId', $messageId));
        $groupHeader->appendChild($document->createElement('CreDtTm', date('c')));
        $groupHeader->appendChild($document->createElement('NbOfTxs', (string) count($transfers)));

        foreach ($transfers as $transfer) {
            $controlSum += (float) ($transfer['amount'] ?? 0);
        }

        $groupHeader->appendChild($document->createElement('CtrlSum', $this->formatAmount($controlSum)));

        $initiatingParty = $document->createElement('InitgPty');
        $initiatingParty->appendChild($document->createElement('Nm', $payerName));
        $groupHeader->appendChild($initiatingParty);
        $customerTransferInitiation->appendChild($groupHeader);

        foreach ($this->groupByExecutionDate($transfers) as $executionDate => $dateTransfers) {
            $paymentInfo = $document->createElement('PmtInf');
            $paymentInfo->appendChild($document->createElement('PmtInfId', 'PMT-' . $messageId . '-' . str_replace('-', '', $executionDate)));
            $paymentInfo->appendChild($document->createElement('PmtMtd', 'TRF'));
            $paymentInfo->appendChild($document->createElement('BtchBookg', 'true'));
            $paymentInfo->appendChild($document->createElement('NbOfTxs', (string) count($dateTransfers)));
            $paymentInfo->appendChild($document->createElement('CtrlSum', $this->formatAmount($this->sumTransfers($dateTransfers))));

            $paymentTypeInfo = $document->createElement('PmtTpInf');
            $serviceLevel = $document->createElement('SvcLvl');
            $serviceLevel->appendChild($document->createElement('Cd', 'NURG'));
            $paymentTypeInfo->appendChild($serviceLevel);
            $paymentInfo->appendChild($paymentTypeInfo);

            $paymentInfo->appendChild($document->createElement('ReqdExctnDt', $executionDate));

            $debtor = $document->createElement('Dbtr');
            $debtor->appendChild($document->createElement('Nm', $payerName));
            $paymentInfo->appendChild($debtor);

            $debtorAccount = $document->createElement('DbtrAcct');
            $debtorAccountId = $document->createElement('Id');
            $debtorAccountId->appendChild($document->createElement('IBAN', $payerIban));
            $debtorAccount->appendChild($debtorAccountId);
            $paymentInfo->appendChild($debtorAccount);

            $debtorAgent = $document->createElement('DbtrAgt');
            $financialInstitution = $document->createElement('FinInstnId');
            $other = $document->createElement('Othr');
            $other->appendChild($document->createElement('Id', 'NOTPROVIDED'));
            $financialInstitution->appendChild($other);
            $debtorAgent->appendChild($financialInstitution);
            $paymentInfo->appendChild($debtorAgent);

            $paymentInfo->appendChild($document->createElement('ChrgBr', 'SLEV'));

            foreach ($dateTransfers as $index => $transfer) {
                $creditTransfer = $document->createElement('CdtTrfTxInf');

                $paymentId = $document->createElement('PmtId');
                $paymentId->appendChild($document->createElement(
                    'EndToEndId',
                    $this->sanitizeText((string) ($transfer['end_to_end_id'] ?? 'E2E-' . ($index + 1)), 35)
                ));
                $creditTransfer->appendChild($paymentId);

                $amount = $document->createElement('Amt');
                $instructedAmount = $document->createElement('InstdAmt', $this->formatAmount((float) ($transfer['amount'] ?? 0)));
                $instructedAmount->setAttribute('Ccy', strtoupper((string) ($transfer['currency'] ?? $currency)));
                $amount->appendChild($instructedAmount);
                $creditTransfer->appendChild($amount);

                $creditor = $document->createElement('Cdtr');
                $creditor->appendChild($document->createElement('Nm', $this->sanitizeText((string) ($transfer['recipient_name'] ?? ''), 140)));
                $creditTransfer->appendChild($creditor);

                $creditorAccount = $document->createElement('CdtrAcct');
                $creditorAccountId = $document->createElement('Id');
                $creditorAccountId->appendChild($document->createElement('IBAN', $this->normalizeIban((string) ($transfer['recipient_account'] ?? ''))));
                $creditorAccount->appendChild($creditorAccountId);
                $creditTransfer->appendChild($creditorAccount);

                $remittance = $document->createElement('RmtInf');
                $remittance->appendChild($document->createElement(
                    'Ustrd',
                    $this->sanitizeText((string) ($transfer['title'] ?? ''), 140)
                ));
                $creditTransfer->appendChild($remittance);

                $paymentInfo->appendChild($creditTransfer);
            }

            $customerTransferInitiation->appendChild($paymentInfo);
        }

        $xml = $document->saveXML();
        if ($xml === false) {
            throw new \RuntimeException('Nie udalo sie wygenerowac pliku pain.001.');
        }

        return $xml;
    }

    private function formatAmount(float $value): string
    {
        return number_format($value, 2, '.', '');
    }

    private function sanitizeText(string $value, int $maxLength): string
    {
        $clean = trim(preg_replace('/\s+/', ' ', $value) ?? '');
        if ($clean === '') {
            return 'BRAK';
        }

        return mb_substr($clean, 0, $maxLength);
    }

    private function groupByExecutionDate(array $transfers): array
    {
        $grouped = [];

        foreach ($transfers as $transfer) {
            $executionDate = (string) ($transfer['execution_date'] ?? date('Y-m-d'));
            $grouped[$executionDate][] = $transfer;
        }

        ksort($grouped);

        return $grouped;
    }

    private function sumTransfers(array $transfers): float
    {
        $sum = 0.0;

        foreach ($transfers as $transfer) {
            $sum += (float) ($transfer['amount'] ?? 0);
        }

        return $sum;
    }

    private function normalizeIban(string $value): string
    {
        $normalized = strtoupper(preg_replace('/\s+/', '', $value) ?? '');
        if (preg_match('/^\d{26}$/', $normalized) === 1) {
            return 'PL' . $normalized;
        }

        return $normalized;
    }
}
