<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Gap-free document serials (NF-05).
 *
 * Call next() inside the same transaction that saves the document: the row lock is held
 * until commit, and a rollback hands the number back, so no gaps or duplicates appear.
 */
final class SerialNumberService
{
    public function next(string $docType, int $padLength = 6): string
    {
        return DB::transaction(function () use ($docType, $padLength): string {
            $row = DB::table('document_sequences')
                ->where('doc_type', $docType)
                ->lockForUpdate()
                ->first();

            if ($row === null) {
                throw new InvalidArgumentException("Unknown document type [{$docType}].");
            }

            DB::table('document_sequences')
                ->where('doc_type', $docType)
                ->update(['next_no' => $row->next_no + 1]);

            return str_pad((string) $row->next_no, $padLength, '0', STR_PAD_LEFT);
        });
    }
}
