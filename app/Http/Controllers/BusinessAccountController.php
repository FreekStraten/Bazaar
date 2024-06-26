<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Contract;
use Illuminate\Http\Request;
use Dompdf\Dompdf;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Smalot\PdfParser\Parser;

class BusinessAccountController extends Controller
{

    public function __construct()
    {
        $this->middleware('check.permissions:view_contracts')->only('index');
        $this->middleware('check.permissions:approve_contracts')->only('approveContract');
        $this->middleware('check.permissions:reject_contracts')->only('rejectContract');
        $this->middleware('check.permissions:export_contracts')->only('exportContract');
        $this->middleware('check.permissions:download_contracts')->only('downloadContract');
        $this->middleware('check.permissions:store_contracts')->only('storeContract');
    }

    /**
     * Display a listing of business accounts and contracts.
     *
     * @return \Illuminate\Contracts\Foundation\Application|\Illuminate\Contracts\View\Factory|\Illuminate\Contracts\View\View|\Illuminate\Foundation\Application | \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        $businessAccounts = User::whereHas('role', function ($query) {
            $query->where('name', 'Company');
        })
            ->with(['address', 'contracts'])
            ->orderBy('name')
            ->paginate(10);

        if ($request->wantsJson()) {
            return response()->json($businessAccounts);
        }

        $contracts = Contract::with('user')->paginate(10);

        return view('business-accounts-contract.index', compact('businessAccounts', 'contracts'));
    }

    /**
     * Store a new contract.
     *
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function storeContract(Request $request)
    {
        $validatedData = $request->validate([
            'contract_name' => 'required|string',
            'contract_file' => 'required|file|mimes:pdf',
        ]);

        $pdfFile = $request->file('contract_file')->getRealPath();
        $userId = $this->extractUserId($pdfFile);

        $contract = new Contract([
            'contract_name' => $validatedData['contract_name'],
            'contract_file' => $request->file('contract_file')->store('contracts'),
            'user_id' => $userId,
        ]);

        $contract->save();
        return redirect()->route('business-accounts-contract.index')->with('success', 'Contract uploaded successfully.');
    }

    private function extractUserId($pdfFile)
    {
        $parser = new Parser();
        $pdfContent = file_get_contents($pdfFile);

        try {
            $pdf = $parser->parseContent($pdfContent);
            $text = $pdf->getText();
            $lines = explode("\n", $text);
            foreach ($lines as $line) {
                if (str_starts_with(trim($line), 'User Id')) {
                    $parts = explode(' ', trim($line));
                    $userId = end($parts);
                    return (int)preg_replace('/\D/', '', $userId); // Return only the integer value
                }
            }
        } catch (\Exception $e) {
            Log::error('Error extracting user ID from PDF: ' . $e->getMessage());
        }

        return null;
    }


    /**
     * Approve a contract.
     *
     * @param int $id
     * @return \Illuminate\Http\RedirectResponse
     */
    public function approveContract($id)
    {
        $contract = Contract::findOrFail($id);
        $contract->approved = 1;
        $contract->save();

        return redirect()->route('business-accounts-contract.index')->with('success', 'Contract approved successfully.');
    }

    /**
     * Reject a contract.
     *
     * @param int $id
     * @return \Illuminate\Http\RedirectResponse
     */
    public function rejectContract($id)
    {
        $contract = Contract::findOrFail($id);
        $contract->approved = 2;
        $contract->save();

        return redirect()->route('business-accounts-contract.index')->with('success', 'Contract rejected successfully.');
    }

    /**
     * Download a business account contract as PDF.
     *
     * @param int $id
     * @return \Illuminate\Http\Response
     */
    public function exportContract($id)
    {
        $user = User::findOrFail($id);

        $dompdf = new Dompdf();
        $html = view('business-accounts-contract.business-registration-contract', compact('user'))->render();
        $dompdf->loadHtml($html);
        $dompdf->render();
        $fileName = $user->name . '_BusinessRegisterContract.pdf';

        return $dompdf->stream($fileName);
    }

    public function downloadContract($id)
    {
        $contract = Contract::findOrFail($id);
        return Storage::download($contract->contract_file);

    }
}
