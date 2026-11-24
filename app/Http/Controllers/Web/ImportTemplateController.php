<?php

namespace App\Http\Controllers\Web;

use App\Domain\Import\TemplateWriter;
use App\Http\Controllers\Controller;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ImportTemplateController extends Controller
{
    public function __invoke(TemplateWriter $writer): BinaryFileResponse
    {
        $path = tempnam(sys_get_temp_dir(), 'dcp-template').'.xlsx';
        $writer->write($path);

        return response()->download($path, 'dcp-pragati-import-template.xlsx')->deleteFileAfterSend();
    }
}
