<?php

declare(strict_types=1);

namespace App\Service\Bank;

final class UnzExporter implements BankTransferExporterInterface
{
    public function export(array $transfers, array $payerData): string
    {
        unset($transfers, $payerData);

        throw new \LogicException('Format UNZ wymaga pełnej specyfikacji banku i pozostaje placeholderem.');
    }
}
