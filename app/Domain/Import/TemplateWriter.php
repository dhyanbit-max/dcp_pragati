<?php

namespace App\Domain\Import;

use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;

/** Writes the blank import workbook: a "Read me" sheet, then one sheet per Template sheet with headers and an example row. */
class TemplateWriter
{
    private const EXAMPLES = [
        'Districts' => ['EX01', 'Example district', '', '', '', 'yes'],
        'CoDistricts' => ['EX01C', 'EX01', 'Example co-district', '', '', '', 'yes'],
        'Constituencies' => ['999', 'EX01', 'Example constituency', '', '', 'Example PC'],
        'Users' => ['9876500000', 'Example Field Officer', 'field_user', 'JE', '', '', '', '', 'as', 'yes'],
        'Works' => ['EX-0001', 'ER-12345', 'Example road from A to B', '', 'EX01', 'EX01C', '999', 'ROAD', 'Road',
            'MLA_ADF', 'MLA Area Development Fund', '2026-27', 'PWD_X', 'PWD Example Division', 'DEMO_ROAD',
            '5000000', 'SO/123/2026', '2026-05-01', '2027-02-28', '26.1445', '91.7362', '200', '9876500000'],
        'Releases' => ['EX-0001', '2000000', '2026-06-01', 'REL/45/2026'],
    ];

    public function write(string $path): void
    {
        $bold = (new Style)->setFontBold();
        $writer = new Writer;
        $writer->openToFile($path);

        $writer->getCurrentSheet()->setName('Read me');
        foreach ([
            ['DCP Pragati import workbook'],
            ['Fill the sheets you need. Sheet names must stay as they are. Delete the example rows before importing.'],
            ['Order: Districts → CoDistricts → Constituencies → Users → Works → Releases. Works are matched by erupantor_id first, then by code; users by mobile.'],
            ['Passwords are never imported. Field users sign in to the app with an OTP.'],
            ['Amounts are in rupees. Dates as YYYY-MM-DD or DD-MM-YYYY. Columns marked * are required.'],
            ['Always use Preview first: nothing is saved until you press Import.'],
            [],
            ['Sheet', 'Column', 'Description'],
        ] as $line) {
            $writer->addRow(Row::fromValues($line));
        }
        foreach (Template::SHEETS as $sheet => $cols) {
            foreach ($cols as $col => $desc) {
                $writer->addRow(Row::fromValues([$sheet, $col, $desc]));
            }
        }

        foreach (Template::SHEETS as $sheet => $cols) {
            $writer->addNewSheetAndMakeItCurrent()->setName($sheet);
            $writer->addRow(Row::fromValues(array_keys($cols), $bold));
            $writer->addRow(Row::fromValues(self::EXAMPLES[$sheet]));
        }

        $writer->close();
    }
}
