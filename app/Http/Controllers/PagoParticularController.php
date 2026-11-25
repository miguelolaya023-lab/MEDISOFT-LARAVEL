<?php

namespace App\Http\Controllers;

use App\Http\Requests\RegistrarPagoParticularRequest;
use App\Models\Liquidacion;
use App\Models\PagoParticular;
use App\Services\RegistrarPagoParticular;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class PagoParticularController extends Controller
{
    public function __construct(private readonly RegistrarPagoParticular $pagos) {}

    public function index(): View
    {
        return view('pagos-particulares.pagos.index', ['liquidaciones' => $this->pagos->listar()]);
    }

    public function show(Request $request, string $liquidacionId): View
    {
        $liquidacion = $this->liquidacion($liquidacionId);
        $atencion = $this->pagos->consultar($liquidacion);
        $pago = $liquidacion->pagosParticulares()->orderByDesc('liquidacion_vigente_id')->orderByDesc('id')->first();
        if ($pago?->estado === 'REGISTRADO') {
            return view('pagos-particulares.pagos.resultado', ['liquidacion' => $liquidacion, 'pago' => $pago, 'atencion' => $atencion]);
        }

        $clave = (string) Str::uuid();

        return view('pagos-particulares.pagos.show', [
            'liquidacion' => $liquidacion, 'atencion' => $atencion, 'metodos' => PagoParticular::METODOS,
            'clave' => $clave, 'intencion' => $this->pagos->firmarIntencion($request->user(), $liquidacion, $clave, $pago),
            'desde' => $this->pagos->primeraFechaPago($atencion),
            'hoy' => now('America/Bogota')->toDateString(),
        ]);
    }

    public function store(RegistrarPagoParticularRequest $request, string $liquidacionId): RedirectResponse
    {
        $liquidacion = $this->liquidacion($liquidacionId);
        $pago = $this->pagos->registrar($request->user(), $liquidacion, $request->validated());

        return redirect()->route('pagos-particulares.pagos.resultado', [$liquidacion, $pago]);
    }

    public function resultado(string $liquidacionId, string $pagoId): View
    {
        $liquidacion = $this->liquidacion($liquidacionId);
        $pago = $liquidacion->pagosParticulares()->findOrFail($this->idPositivo($pagoId));

        return view('pagos-particulares.pagos.resultado', [
            'liquidacion' => $liquidacion, 'pago' => $pago, 'atencion' => $this->pagos->consultar($liquidacion),
        ]);
    }

    private function liquidacion(string $id): Liquidacion
    {
        return Liquidacion::query()->findOrFail($this->idPositivo($id));
    }

    private function idPositivo(string $valor): int
    {
        $id = filter_var($valor, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        abort_if($id === false, 404);

        return $id;
    }
}
