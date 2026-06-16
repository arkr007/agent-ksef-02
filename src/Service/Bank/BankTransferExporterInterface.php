<?php

declare(strict_types=1);

namespace App\Service\Bank;

interface BankTransferExporterInterface
{
    public function export(array $transfers, array $payerData): string;
}
