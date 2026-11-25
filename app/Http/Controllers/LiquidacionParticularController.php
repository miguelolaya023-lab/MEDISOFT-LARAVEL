<?php

namespace App\Http\Controllers;

use App\Http\Requests\ConfirmarLiquidacionParticularRequest;
use App\Services\LiquidarConsultaParticular;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class LiquidacionParticularController extends Controller
{
    public function __construct(private readonly LiquidarConsultaParticular $liquidar) {}

    public function index(): View
    {
        return view('pagos-particulares.liquidaciones.index', ['atenciones' => $this->liquidar->listar()]);
    }

    public function show(Request $request, string $atencionId): View
    {
        return view('pagos-particulares.liquidaciones.show', $this->liquidar->consultar($request->user(), $atencionId));
    }

    public function store(ConfirmarLiquidacionParticularRequest $request, string $atencionId): RedirectResponse
    {
        $this->liquidar->liquidar($request->user(), $atencionId, $request->validated('version_importe'));

        return redirect()->route('pagos-particulares.liquidaciones.show', $atencionId);
    }
}
